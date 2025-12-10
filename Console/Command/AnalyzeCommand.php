<?php
/**
 * FlipDev_AttributeManager
 *
 * @category  FlipDev
 * @package   FlipDev_AttributeManager
 * @author    Philipp Breitsprecher <philippbreitsprecher@gmail.com>
 * @copyright Copyright (c) 2024-2025 FlipDev
 */

declare(strict_types=1);

namespace FlipDev\AttributeManager\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Eav\Model\Config as EavConfig;

/**
 * Full Attribute Analysis Command
 *
 * Analyzes all product attributes with fill-rates, duplicates, and format issues.
 */
class AnalyzeCommand extends Command
{
    private const COMMAND_NAME = 'flipdev:attributes:analyze';

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var EavConfig
     */
    private EavConfig $eavConfig;

    /**
     * @var State
     */
    private State $state;

    /**
     * @param ResourceConnection $resourceConnection
     * @param EavConfig $eavConfig
     * @param State $state
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        EavConfig $eavConfig,
        State $state
    ) {
        parent::__construct();
        $this->resourceConnection = $resourceConnection;
        $this->eavConfig = $eavConfig;
        $this->state = $state;
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Full attribute analysis with fill-rates, sets, and statistics')
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Output format: table, json, csv',
                'table'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file path (optional)'
            )
            ->addOption(
                'include-system',
                null,
                InputOption::VALUE_NONE,
                'Include system attributes in analysis'
            );
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area already set
        }

        $format = $input->getOption('format');
        $outputFile = $input->getOption('output');
        $includeSystem = $input->getOption('include-system');

        $output->writeln('<info>FlipDev Attribute Manager - Full Analysis</info>');
        $output->writeln('<info>=========================================</info>');
        $output->writeln('');

        $connection = $this->resourceConnection->getConnection();

        // 1. Basic Statistics
        $output->writeln('<comment>Collecting statistics...</comment>');
        $stats = $this->collectStatistics($connection);

        $output->writeln('');
        $output->writeln('<info>📊 Overview</info>');
        $output->writeln("   Total Products:     <comment>{$stats['total_products']}</comment>");
        $output->writeln("   Simple Products:    <comment>{$stats['simple_products']}</comment>");
        $output->writeln("   Configurable:       <comment>{$stats['configurable_products']}</comment>");
        $output->writeln("   Total Attributes:   <comment>{$stats['total_attributes']}</comment>");
        $output->writeln("   User-Defined:       <comment>{$stats['user_defined_attributes']}</comment>");
        $output->writeln("   Attribute Sets:     <comment>{$stats['attribute_sets']}</comment>");
        $output->writeln('');

        // 2. Attribute Set Distribution
        $output->writeln('<info>📦 Attribute Set Distribution</info>');
        $setDistribution = $this->getSetDistribution($connection);

        $table = new Table($output);
        $table->setHeaders(['Attribute Set', 'Products', '%', 'Bar']);
        
        foreach ($setDistribution as $set) {
            $pct = $stats['total_products'] > 0 
                ? round($set['count'] / $stats['total_products'] * 100, 1) 
                : 0;
            $barLength = (int) ($pct / 2);
            $bar = str_repeat('█', $barLength) . str_repeat('░', 50 - $barLength);
            $table->addRow([
                $set['name'],
                number_format($set['count']),
                $pct . '%',
                $bar
            ]);
        }
        $table->render();
        $output->writeln('');

        // 3. Fill-Rate Analysis
        $output->writeln('<info>📈 Fill-Rate Analysis (User-Defined Attributes)</info>');
        $fillRates = $this->calculateFillRates($connection, $stats['total_products'], !$includeSystem, $output);

        // Group by status
        $critical = array_filter($fillRates, fn($a) => $a['rate'] < 25);
        $warning = array_filter($fillRates, fn($a) => $a['rate'] >= 25 && $a['rate'] < 50);
        $healthy = array_filter($fillRates, fn($a) => $a['rate'] >= 50);

        $output->writeln("   🔴 Critical (<25%):  <error>" . count($critical) . " attributes</error>");
        $output->writeln("   🟡 Warning (25-50%): <comment>" . count($warning) . " attributes</comment>");
        $output->writeln("   🟢 Healthy (>50%):   <info>" . count($healthy) . " attributes</info>");
        $output->writeln('');

        // Show worst 20 attributes
        $output->writeln('<info>🔴 Worst 20 Attributes by Fill-Rate</info>');
        $table = new Table($output);
        $table->setHeaders(['Attribute Code', 'Label', 'Type', 'Filled', 'Total', 'Rate']);
        
        $worst = array_slice($fillRates, 0, 20);
        foreach ($worst as $attr) {
            $table->addRow([
                $attr['code'],
                mb_substr($attr['label'] ?? '-', 0, 25),
                $attr['frontend_input'],
                number_format($attr['filled']),
                number_format($attr['total']),
                $this->formatRate($attr['rate'])
            ]);
        }
        $table->render();
        $output->writeln('');

        // 4. Manufacturer Distribution
        $output->writeln('<info>🏭 Top 20 Manufacturers</info>');
        $manufacturers = $this->getManufacturerDistribution($connection);
        
        $table = new Table($output);
        $table->setHeaders(['Manufacturer', 'Products', '%']);
        
        foreach (array_slice($manufacturers, 0, 20) as $mfr) {
            $pct = $stats['total_products'] > 0 
                ? round($mfr['count'] / $stats['total_products'] * 100, 1) 
                : 0;
            $table->addRow([
                $mfr['name'],
                number_format($mfr['count']),
                $pct . '%'
            ]);
        }
        $table->render();
        $output->writeln('');

        // 5. Potential Duplicates
        $output->writeln('<info>🔍 Potential Duplicate Attributes</info>');
        $duplicates = $this->findPotentialDuplicates($fillRates);
        
        if (!empty($duplicates)) {
            foreach ($duplicates as $group) {
                $codes = implode(', ', array_column($group, 'code'));
                $output->writeln("   ⚠️  <comment>{$codes}</comment>");
            }
        } else {
            $output->writeln('   No obvious duplicates detected.');
        }
        $output->writeln('');

        // 6. Export if requested
        if ($outputFile) {
            $this->exportResults($outputFile, $format, [
                'stats' => $stats,
                'set_distribution' => $setDistribution,
                'fill_rates' => $fillRates,
                'manufacturers' => $manufacturers,
                'duplicates' => $duplicates
            ], $output);
        }

        $output->writeln('<info>✅ Analysis complete!</info>');
        
        return Command::SUCCESS;
    }

    /**
     * Collect basic statistics
     */
    private function collectStatistics($connection): array
    {
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');
        $setTable = $this->resourceConnection->getTableName('eav_attribute_set');

        // Product counts
        $totalProducts = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$productTable}"
        );

        $simpleProducts = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$productTable} WHERE type_id = 'simple'"
        );

        $configurableProducts = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$productTable} WHERE type_id = 'configurable'"
        );

        // Attribute counts (entity_type_id = 4 for products)
        $totalAttributes = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$attributeTable} WHERE entity_type_id = 4"
        );

        $userDefinedAttributes = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$attributeTable} WHERE entity_type_id = 4 AND is_user_defined = 1"
        );

        // Attribute sets
        $attributeSets = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$setTable} WHERE entity_type_id = 4"
        );

        return [
            'total_products' => $totalProducts,
            'simple_products' => $simpleProducts,
            'configurable_products' => $configurableProducts,
            'total_attributes' => $totalAttributes,
            'user_defined_attributes' => $userDefinedAttributes,
            'attribute_sets' => $attributeSets,
        ];
    }

    /**
     * Get attribute set distribution
     */
    private function getSetDistribution($connection): array
    {
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $setTable = $this->resourceConnection->getTableName('eav_attribute_set');

        $sql = "
            SELECT 
                s.attribute_set_name as name,
                COUNT(p.entity_id) as count
            FROM {$setTable} s
            LEFT JOIN {$productTable} p ON p.attribute_set_id = s.attribute_set_id
            WHERE s.entity_type_id = 4
            GROUP BY s.attribute_set_id
            ORDER BY count DESC
        ";

        return $connection->fetchAll($sql);
    }

    /**
     * Calculate fill-rates for all attributes
     */
    private function calculateFillRates($connection, int $totalProducts, bool $userDefinedOnly, OutputInterface $output): array
    {
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $where = 'entity_type_id = 4';
        if ($userDefinedOnly) {
            $where .= ' AND is_user_defined = 1';
        }

        $attributes = $connection->fetchAll(
            "SELECT attribute_id, attribute_code, frontend_label, frontend_input, backend_type 
             FROM {$attributeTable} 
             WHERE {$where}
             ORDER BY attribute_code"
        );

        $results = [];
        $progress = new ProgressBar($output, count($attributes));
        $progress->start();

        foreach ($attributes as $attr) {
            $backendType = $attr['backend_type'];
            
            if ($backendType === 'static') {
                $progress->advance();
                continue;
            }

            $valueTable = $this->resourceConnection->getTableName(
                "catalog_product_entity_{$backendType}"
            );

            if (!$connection->isTableExists($valueTable)) {
                $progress->advance();
                continue;
            }

            $filled = (int) $connection->fetchOne(
                "SELECT COUNT(DISTINCT entity_id) 
                 FROM {$valueTable} 
                 WHERE attribute_id = ? AND value IS NOT NULL AND value != ''",
                [$attr['attribute_id']]
            );

            $rate = $totalProducts > 0 ? round($filled / $totalProducts * 100, 2) : 0;

            $results[] = [
                'attribute_id' => $attr['attribute_id'],
                'code' => $attr['attribute_code'],
                'label' => $attr['frontend_label'],
                'frontend_input' => $attr['frontend_input'],
                'backend_type' => $backendType,
                'filled' => $filled,
                'total' => $totalProducts,
                'rate' => $rate,
            ];

            $progress->advance();
        }

        $progress->finish();
        $output->writeln('');

        // Sort by fill rate ascending (worst first)
        usort($results, fn($a, $b) => $a['rate'] <=> $b['rate']);

        return $results;
    }

    /**
     * Get manufacturer distribution
     */
    private function getManufacturerDistribution($connection): array
    {
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');
        $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');
        $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');
        $productIntTable = $this->resourceConnection->getTableName('catalog_product_entity_int');

        // Get manufacturer attribute ID
        $manufacturerId = $connection->fetchOne(
            "SELECT attribute_id FROM {$attributeTable} 
             WHERE attribute_code = 'manufacturer' AND entity_type_id = 4"
        );

        if (!$manufacturerId) {
            return [];
        }

        $sql = "
            SELECT 
                COALESCE(ov.value, 'N/A') as name,
                COUNT(pv.entity_id) as count
            FROM {$productIntTable} pv
            LEFT JOIN {$optionValueTable} ov ON ov.option_id = pv.value AND ov.store_id = 0
            WHERE pv.attribute_id = ?
            GROUP BY pv.value
            ORDER BY count DESC
        ";

        return $connection->fetchAll($sql, [$manufacturerId]);
    }

    /**
     * Find potential duplicate attributes
     */
    private function findPotentialDuplicates(array $fillRates): array
    {
        $duplicates = [];
        $codes = array_column($fillRates, 'code');

        // Known patterns
        $patterns = [
            ['breite', 'width', 'breite_dropdown', 'breite_mehrfachauswahl'],
            ['tiefe', 'depth', 'tiefe_dropdown'],
            ['hoehe', 'height'],
            ['spannung', 'voltage'],
            ['leistung', 'wattage', 'nennleistung', 'power'],
        ];

        foreach ($patterns as $pattern) {
            $found = [];
            foreach ($fillRates as $attr) {
                if (in_array($attr['code'], $pattern)) {
                    $found[] = $attr;
                }
            }
            if (count($found) > 1) {
                $duplicates[] = $found;
            }
        }

        return $duplicates;
    }

    /**
     * Format fill rate with color indicator
     */
    private function formatRate(float $rate): string
    {
        if ($rate < 25) {
            return "<error>{$rate}%</error>";
        }
        if ($rate < 50) {
            return "<comment>{$rate}%</comment>";
        }
        return "<info>{$rate}%</info>";
    }

    /**
     * Export results to file
     */
    private function exportResults(string $path, string $format, array $data, OutputInterface $output): void
    {
        switch ($format) {
            case 'json':
                $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                break;
            case 'csv':
                $content = $this->arrayToCsv($data['fill_rates']);
                break;
            default:
                $content = print_r($data, true);
        }

        file_put_contents($path, $content);
        $output->writeln("<info>Results exported to: {$path}</info>");
    }

    /**
     * Convert array to CSV
     */
    private function arrayToCsv(array $data): string
    {
        if (empty($data)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        fputcsv($output, array_keys($data[0]), ';');
        
        foreach ($data as $row) {
            fputcsv($output, $row, ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
