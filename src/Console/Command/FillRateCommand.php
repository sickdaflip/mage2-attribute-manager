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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;

/**
 * Fill-Rate Analysis Command
 *
 * Detailed fill-rate analysis, optionally filtered by attribute set.
 */
class FillRateCommand extends Command
{
    private const COMMAND_NAME = 'flipdev:attributes:fillrate';

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var State
     */
    private State $state;

    /**
     * @param ResourceConnection $resourceConnection
     * @param State $state
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        State $state
    ) {
        parent::__construct();
        $this->resourceConnection = $resourceConnection;
        $this->state = $state;
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Detailed fill-rate analysis by attribute set')
            ->addOption(
                'set',
                's',
                InputOption::VALUE_OPTIONAL,
                'Attribute set name or ID (optional, analyzes all if not set)'
            )
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
                'Output file path'
            )
            ->addOption(
                'min-rate',
                null,
                InputOption::VALUE_OPTIONAL,
                'Only show attributes with fill-rate >= this value',
                0
            )
            ->addOption(
                'max-rate',
                null,
                InputOption::VALUE_OPTIONAL,
                'Only show attributes with fill-rate <= this value',
                100
            )
            ->addOption(
                'by-set',
                null,
                InputOption::VALUE_NONE,
                'Group results by attribute set'
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

        $setFilter = $input->getOption('set');
        $format = $input->getOption('format');
        $outputFile = $input->getOption('output');
        $minRate = (float) $input->getOption('min-rate');
        $maxRate = (float) $input->getOption('max-rate');
        $bySet = $input->getOption('by-set');

        $connection = $this->resourceConnection->getConnection();

        $output->writeln('<info>FlipDev Attribute Manager - Fill-Rate Analysis</info>');
        $output->writeln('<info>==============================================</info>');
        $output->writeln('');

        if ($bySet) {
            $this->analyzeBySet($connection, $output, $format, $outputFile, $minRate, $maxRate);
        } elseif ($setFilter) {
            $this->analyzeSet($connection, $setFilter, $output, $format, $outputFile, $minRate, $maxRate);
        } else {
            $this->analyzeAll($connection, $output, $format, $outputFile, $minRate, $maxRate);
        }

        return Command::SUCCESS;
    }

    /**
     * Analyze all attributes across all products
     */
    private function analyzeAll($connection, OutputInterface $output, string $format, ?string $outputFile, float $minRate, float $maxRate): void
    {
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $totalProducts = (int) $connection->fetchOne("SELECT COUNT(*) FROM {$productTable}");
        $output->writeln("Analyzing <comment>{$totalProducts}</comment> products...");
        $output->writeln('');

        $attributes = $connection->fetchAll(
            "SELECT attribute_id, attribute_code, frontend_label, frontend_input, backend_type 
             FROM {$attributeTable} 
             WHERE entity_type_id = 4 AND is_user_defined = 1
             ORDER BY attribute_code"
        );

        $results = $this->calculateFillRates($connection, $attributes, $totalProducts, null, $output);
        
        // Filter by rate
        $results = array_filter($results, fn($a) => $a['rate'] >= $minRate && $a['rate'] <= $maxRate);

        $this->outputResults($output, $results, $format, $outputFile, "All Products ({$totalProducts})");
    }

    /**
     * Analyze specific attribute set
     */
    private function analyzeSet($connection, string $setFilter, OutputInterface $output, string $format, ?string $outputFile, float $minRate, float $maxRate): void
    {
        $setTable = $this->resourceConnection->getTableName('eav_attribute_set');
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');

        // Find set by name or ID
        if (is_numeric($setFilter)) {
            $set = $connection->fetchRow(
                "SELECT * FROM {$setTable} WHERE attribute_set_id = ? AND entity_type_id = 4",
                [$setFilter]
            );
        } else {
            $set = $connection->fetchRow(
                "SELECT * FROM {$setTable} WHERE attribute_set_name LIKE ? AND entity_type_id = 4",
                ['%' . $setFilter . '%']
            );
        }

        if (!$set) {
            $output->writeln("<error>Attribute set '{$setFilter}' not found.</error>");
            return;
        }

        $setId = $set['attribute_set_id'];
        $setName = $set['attribute_set_name'];

        $productCount = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$productTable} WHERE attribute_set_id = ?",
            [$setId]
        );

        $output->writeln("Attribute Set: <comment>{$setName}</comment> (ID: {$setId})");
        $output->writeln("Products: <comment>{$productCount}</comment>");
        $output->writeln('');

        // Get attributes assigned to this set
        $entityAttrTable = $this->resourceConnection->getTableName('eav_entity_attribute');
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $attributes = $connection->fetchAll(
            "SELECT a.attribute_id, a.attribute_code, a.frontend_label, a.frontend_input, a.backend_type 
             FROM {$attributeTable} a
             INNER JOIN {$entityAttrTable} ea ON ea.attribute_id = a.attribute_id
             WHERE ea.attribute_set_id = ? AND a.entity_type_id = 4 AND a.is_user_defined = 1
             ORDER BY a.attribute_code",
            [$setId]
        );

        $results = $this->calculateFillRates($connection, $attributes, $productCount, $setId, $output);
        
        // Filter by rate
        $results = array_filter($results, fn($a) => $a['rate'] >= $minRate && $a['rate'] <= $maxRate);

        $this->outputResults($output, $results, $format, $outputFile, "{$setName} ({$productCount} products)");
    }

    /**
     * Analyze by attribute set (grouped output)
     */
    private function analyzeBySet($connection, OutputInterface $output, string $format, ?string $outputFile, float $minRate, float $maxRate): void
    {
        $setTable = $this->resourceConnection->getTableName('eav_attribute_set');
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');

        $sets = $connection->fetchAll(
            "SELECT s.attribute_set_id, s.attribute_set_name, COUNT(p.entity_id) as product_count
             FROM {$setTable} s
             LEFT JOIN {$productTable} p ON p.attribute_set_id = s.attribute_set_id
             WHERE s.entity_type_id = 4
             GROUP BY s.attribute_set_id
             HAVING product_count > 0
             ORDER BY product_count DESC"
        );

        $allResults = [];

        foreach ($sets as $set) {
            $setId = $set['attribute_set_id'];
            $setName = $set['attribute_set_name'];
            $productCount = (int) $set['product_count'];

            $output->writeln("<info>📦 {$setName}</info> ({$productCount} products)");

            $entityAttrTable = $this->resourceConnection->getTableName('eav_entity_attribute');
            $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

            $attributes = $connection->fetchAll(
                "SELECT a.attribute_id, a.attribute_code, a.frontend_label, a.frontend_input, a.backend_type 
                 FROM {$attributeTable} a
                 INNER JOIN {$entityAttrTable} ea ON ea.attribute_id = a.attribute_id
                 WHERE ea.attribute_set_id = ? AND a.entity_type_id = 4 AND a.is_user_defined = 1
                 ORDER BY a.attribute_code",
                [$setId]
            );

            if (empty($attributes)) {
                $output->writeln("   No user-defined attributes in this set.");
                $output->writeln('');
                continue;
            }

            $results = $this->calculateFillRatesQuiet($connection, $attributes, $productCount, $setId);
            
            // Filter by rate
            $results = array_filter($results, fn($a) => $a['rate'] >= $minRate && $a['rate'] <= $maxRate);

            // Sort by rate
            usort($results, fn($a, $b) => $a['rate'] <=> $b['rate']);

            // Show summary
            $avgRate = count($results) > 0 ? array_sum(array_column($results, 'rate')) / count($results) : 0;
            $critical = count(array_filter($results, fn($a) => $a['rate'] < 25));
            $healthy = count(array_filter($results, fn($a) => $a['rate'] >= 50));

            $output->writeln("   Attributes: " . count($results) . " | Avg: " . round($avgRate, 1) . "% | Critical: {$critical} | Healthy: {$healthy}");
            
            // Top 5 worst
            $worst = array_slice($results, 0, 5);
            foreach ($worst as $attr) {
                $rate = $this->formatRate($attr['rate']);
                $output->writeln("   └─ {$attr['code']}: {$rate}");
            }

            $output->writeln('');

            // Store for export
            foreach ($results as &$r) {
                $r['set_name'] = $setName;
                $r['set_id'] = $setId;
            }
            $allResults = array_merge($allResults, $results);
        }

        // Export if requested
        if ($outputFile) {
            $this->exportResults($outputFile, $format, $allResults, $output);
        }
    }

    /**
     * Calculate fill rates for attributes
     */
    private function calculateFillRates($connection, array $attributes, int $totalProducts, ?int $setId, OutputInterface $output): array
    {
        $results = [];
        $progress = new ProgressBar($output, count($attributes));
        $progress->start();

        foreach ($attributes as $attr) {
            $result = $this->calculateSingleFillRate($connection, $attr, $totalProducts, $setId);
            if ($result) {
                $results[] = $result;
            }
            $progress->advance();
        }

        $progress->finish();
        $output->writeln('');

        usort($results, fn($a, $b) => $a['rate'] <=> $b['rate']);

        return $results;
    }

    /**
     * Calculate fill rates without progress bar
     */
    private function calculateFillRatesQuiet($connection, array $attributes, int $totalProducts, ?int $setId): array
    {
        $results = [];

        foreach ($attributes as $attr) {
            $result = $this->calculateSingleFillRate($connection, $attr, $totalProducts, $setId);
            if ($result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Calculate fill rate for single attribute
     */
    private function calculateSingleFillRate($connection, array $attr, int $totalProducts, ?int $setId): ?array
    {
        $backendType = $attr['backend_type'];
        
        if ($backendType === 'static' || $totalProducts === 0) {
            return null;
        }

        $valueTable = $this->resourceConnection->getTableName(
            "catalog_product_entity_{$backendType}"
        );

        if (!$connection->isTableExists($valueTable)) {
            return null;
        }

        if ($setId) {
            $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
            $filled = (int) $connection->fetchOne(
                "SELECT COUNT(DISTINCT v.entity_id) 
                 FROM {$valueTable} v
                 INNER JOIN {$productTable} p ON p.entity_id = v.entity_id
                 WHERE v.attribute_id = ? AND v.value IS NOT NULL AND v.value != '' AND p.attribute_set_id = ?",
                [$attr['attribute_id'], $setId]
            );
        } else {
            $filled = (int) $connection->fetchOne(
                "SELECT COUNT(DISTINCT entity_id) 
                 FROM {$valueTable} 
                 WHERE attribute_id = ? AND value IS NOT NULL AND value != ''",
                [$attr['attribute_id']]
            );
        }

        $rate = round($filled / $totalProducts * 100, 2);

        return [
            'attribute_id' => $attr['attribute_id'],
            'code' => $attr['attribute_code'],
            'label' => $attr['frontend_label'] ?? $attr['attribute_code'],
            'type' => $attr['frontend_input'],
            'filled' => $filled,
            'total' => $totalProducts,
            'rate' => $rate,
        ];
    }

    /**
     * Output results in specified format
     */
    private function outputResults(OutputInterface $output, array $results, string $format, ?string $outputFile, string $title): void
    {
        $output->writeln("<info>{$title}</info>");
        $output->writeln(str_repeat('-', mb_strlen($title)));
        $output->writeln('');

        if (empty($results)) {
            $output->writeln('No attributes found matching criteria.');
            return;
        }

        if ($format === 'table') {
            $table = new Table($output);
            $table->setHeaders(['Attribute Code', 'Label', 'Type', 'Filled', 'Total', 'Rate']);
            
            foreach ($results as $attr) {
                $table->addRow([
                    $attr['code'],
                    mb_substr($attr['label'], 0, 30),
                    $attr['type'],
                    number_format($attr['filled']),
                    number_format($attr['total']),
                    $this->formatRate($attr['rate'])
                ]);
            }
            $table->render();
        }

        $output->writeln('');
        $output->writeln("Total: " . count($results) . " attributes");

        if ($outputFile) {
            $this->exportResults($outputFile, $format, $results, $output);
        }
    }

    /**
     * Format fill rate with color
     */
    private function formatRate(float $rate): string
    {
        if ($rate < 25) {
            return "<fg=red>{$rate}%</>";
        }
        if ($rate < 50) {
            return "<fg=yellow>{$rate}%</>";
        }
        return "<fg=green>{$rate}%</>";
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
                $content = $this->arrayToCsv($data);
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
