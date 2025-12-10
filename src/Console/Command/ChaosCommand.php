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
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;

/**
 * Chaos Detection Command
 *
 * Finds inconsistent formats in text attributes.
 */
class ChaosCommand extends Command
{
    private const COMMAND_NAME = 'flipdev:attributes:chaos';

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
            ->setDescription('Detect format chaos in text attributes')
            ->addOption(
                'attribute',
                'a',
                InputOption::VALUE_OPTIONAL,
                'Analyze specific attribute code'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Limit attributes to analyze',
                20
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Output format: table, json',
                'table'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file path'
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

        $attributeCode = $input->getOption('attribute');
        $limit = (int) $input->getOption('limit');
        $format = $input->getOption('format');
        $outputFile = $input->getOption('output');

        $connection = $this->resourceConnection->getConnection();

        $output->writeln('<info>FlipDev Attribute Manager - Format Chaos Detection</info>');
        $output->writeln('<info>==================================================</info>');
        $output->writeln('');

        if ($attributeCode) {
            $this->analyzeAttribute($connection, $attributeCode, $output, $format);
        } else {
            $this->analyzeAllTextAttributes($connection, $limit, $output, $format, $outputFile);
        }

        return Command::SUCCESS;
    }

    /**
     * Analyze single attribute for format chaos
     */
    private function analyzeAttribute($connection, string $attributeCode, OutputInterface $output, string $format): void
    {
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $attr = $connection->fetchRow(
            "SELECT * FROM {$attributeTable} WHERE attribute_code = ? AND entity_type_id = 4",
            [$attributeCode]
        );

        if (!$attr) {
            $output->writeln("<e>Attribute '{$attributeCode}' not found.</e>");
            return;
        }

        $output->writeln("<info>Attribute: {$attributeCode}</info>");
        $output->writeln("Type: {$attr['frontend_input']} / Backend: {$attr['backend_type']}");
        $output->writeln('');

        $backendType = $attr['backend_type'];
        if ($backendType === 'static') {
            $output->writeln('<comment>Static attribute - no values to analyze.</comment>');
            return;
        }

        $valueTable = $this->resourceConnection->getTableName("catalog_product_entity_{$backendType}");

        // Get value distribution
        $values = $connection->fetchAll(
            "SELECT value, COUNT(*) as count 
             FROM {$valueTable} 
             WHERE attribute_id = ? AND value IS NOT NULL AND value != ''
             GROUP BY value 
             ORDER BY count DESC
             LIMIT 50",
            [$attr['attribute_id']]
        );

        if (empty($values)) {
            $output->writeln('<comment>No values found for this attribute.</comment>');
            return;
        }

        $output->writeln("Unique values: " . count($values) . " (showing top 50)");
        $output->writeln('');

        // Analyze patterns
        $patterns = $this->detectPatterns($values);

        if (!empty($patterns['issues'])) {
            $output->writeln('<e>⚠️  Format Issues Detected:</e>');
            foreach ($patterns['issues'] as $issue) {
                $output->writeln("   • {$issue}");
            }
            $output->writeln('');
        }

        // Show value table
        $table = new Table($output);
        $table->setHeaders(['Value', 'Count', 'Pattern']);

        foreach ($values as $val) {
            $pattern = $this->identifyPattern($val['value']);
            $table->addRow([
                mb_substr($val['value'], 0, 50),
                $val['count'],
                $pattern
            ]);
        }
        $table->render();
    }

    /**
     * Analyze all text attributes for format chaos
     */
    private function analyzeAllTextAttributes($connection, int $limit, OutputInterface $output, string $format, ?string $outputFile): void
    {
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        // Get text/varchar attributes
        $attributes = $connection->fetchAll(
            "SELECT attribute_id, attribute_code, frontend_label, frontend_input, backend_type 
             FROM {$attributeTable} 
             WHERE entity_type_id = 4 
               AND is_user_defined = 1 
               AND frontend_input IN ('text', 'textarea')
             ORDER BY attribute_code
             LIMIT ?",
            [$limit]
        );

        $output->writeln("Analyzing {$limit} text attributes for format chaos...");
        $output->writeln('');

        $results = [];

        foreach ($attributes as $attr) {
            $backendType = $attr['backend_type'];
            if ($backendType === 'static') {
                continue;
            }

            $valueTable = $this->resourceConnection->getTableName("catalog_product_entity_{$backendType}");

            // Get unique value count and sample
            $stats = $connection->fetchRow(
                "SELECT 
                    COUNT(DISTINCT value) as unique_count,
                    COUNT(*) as total_count
                 FROM {$valueTable} 
                 WHERE attribute_id = ? AND value IS NOT NULL AND value != ''",
                [$attr['attribute_id']]
            );

            if ($stats['total_count'] == 0) {
                continue;
            }

            // Get sample values
            $samples = $connection->fetchCol(
                "SELECT DISTINCT value 
                 FROM {$valueTable} 
                 WHERE attribute_id = ? AND value IS NOT NULL AND value != ''
                 LIMIT 10",
                [$attr['attribute_id']]
            );

            // Detect patterns
            $patternInfo = $this->analyzeValuePatterns($samples);

            $chaosScore = $this->calculateChaosScore($stats['unique_count'], $stats['total_count'], $patternInfo);

            $results[] = [
                'code' => $attr['attribute_code'],
                'label' => $attr['frontend_label'] ?? $attr['attribute_code'],
                'unique_values' => $stats['unique_count'],
                'total_values' => $stats['total_count'],
                'chaos_score' => $chaosScore,
                'patterns' => $patternInfo['patterns'],
                'issues' => $patternInfo['issues'],
                'samples' => array_slice($samples, 0, 5)
            ];
        }

        // Sort by chaos score descending
        usort($results, fn($a, $b) => $b['chaos_score'] <=> $a['chaos_score']);

        // Output
        $output->writeln('<info>🌪️  Format Chaos Ranking (Higher = More Chaos)</info>');
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['Attribute', 'Unique', 'Total', 'Chaos', 'Issues', 'Sample Values']);

        foreach ($results as $r) {
            $chaosIndicator = $this->getChaosIndicator($r['chaos_score']);
            $issues = count($r['issues']) > 0 ? implode(', ', array_slice($r['issues'], 0, 2)) : '-';
            $samples = implode(' | ', array_slice($r['samples'], 0, 3));
            
            $table->addRow([
                $r['code'],
                $r['unique_values'],
                $r['total_values'],
                $chaosIndicator . ' ' . $r['chaos_score'],
                mb_substr($issues, 0, 25),
                mb_substr($samples, 0, 40)
            ]);
        }
        $table->render();

        // Export
        if ($outputFile) {
            $content = $format === 'json' 
                ? json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                : print_r($results, true);
            file_put_contents($outputFile, $content);
            $output->writeln("<info>Results exported to: {$outputFile}</info>");
        }
    }

    /**
     * Detect patterns in values
     */
    private function detectPatterns(array $values): array
    {
        $issues = [];
        $patterns = [];

        foreach ($values as $val) {
            $v = $val['value'];
            
            // Check for unit inconsistencies
            if (preg_match('/\d+\s*(mm|cm|m|kg|g|W|kW|V|A)/', $v)) {
                $patterns['has_units'] = true;
            }
            
            // Check for temperature patterns
            if (preg_match('/[+-]?\d+\s*°?C/', $v)) {
                $patterns['has_temperature'] = true;
            }

            // Check for dimension patterns
            if (preg_match('/\d+\s*x\s*\d+/', $v)) {
                $patterns['has_dimensions'] = true;
            }
        }

        // Analyze for issues
        $allValues = array_column($values, 'value');
        
        // Check for inconsistent spacing
        $hasSpace = array_filter($allValues, fn($v) => strpos($v, ' ') !== false);
        $noSpace = array_filter($allValues, fn($v) => strpos($v, ' ') === false);
        if (count($hasSpace) > 0 && count($noSpace) > 0) {
            $issues[] = 'Inconsistent spacing';
        }

        // Check for inconsistent units
        if (preg_grep('/mm/', $allValues) && preg_grep('/cm/', $allValues)) {
            $issues[] = 'Mixed units (mm/cm)';
        }

        return [
            'patterns' => $patterns,
            'issues' => $issues
        ];
    }

    /**
     * Identify pattern of single value
     */
    private function identifyPattern(string $value): string
    {
        $patterns = [];

        if (preg_match('/^\d+$/', $value)) {
            $patterns[] = 'integer';
        }
        if (preg_match('/^\d+[.,]\d+$/', $value)) {
            $patterns[] = 'decimal';
        }
        if (preg_match('/\d+\s*(mm|cm|m)/', $value)) {
            $patterns[] = 'length';
        }
        if (preg_match('/\d+\s*(W|kW)/', $value)) {
            $patterns[] = 'power';
        }
        if (preg_match('/\d+\s*V/', $value)) {
            $patterns[] = 'voltage';
        }
        if (preg_match('/[+-]?\d+.*°C/', $value)) {
            $patterns[] = 'temperature';
        }
        if (preg_match('/\d+\s*x\s*\d+/', $value)) {
            $patterns[] = 'dimensions';
        }

        return empty($patterns) ? 'text' : implode(', ', $patterns);
    }

    /**
     * Analyze patterns in sample values
     */
    private function analyzeValuePatterns(array $samples): array
    {
        $patterns = [];
        $issues = [];

        $hasUnits = false;
        $hasPlainNumbers = false;
        $unitTypes = [];

        foreach ($samples as $value) {
            // Check for units
            if (preg_match('/(mm|cm|m|kg|g|W|kW|V|A|°C|%|Liter|L)/', $value, $matches)) {
                $hasUnits = true;
                $unitTypes[$matches[1]] = true;
            } elseif (preg_match('/^\d+([.,]\d+)?$/', $value)) {
                $hasPlainNumbers = true;
            }
        }

        if ($hasUnits && $hasPlainNumbers) {
            $issues[] = 'Mixed: with/without units';
        }

        if (count($unitTypes) > 1) {
            $issues[] = 'Multiple unit types: ' . implode(', ', array_keys($unitTypes));
        }

        // Check for temperature format variations
        $tempPatterns = [];
        foreach ($samples as $value) {
            if (preg_match('/([+-]?\d+)\s*(°C|°|C|bis|to|-)\s*([+-]?\d+)?/', $value)) {
                $tempPatterns[] = $value;
            }
        }
        if (count($tempPatterns) > 1) {
            $unique = array_unique(array_map(fn($v) => preg_replace('/\d+/', 'X', $v), $tempPatterns));
            if (count($unique) > 1) {
                $issues[] = 'Temperature format variations';
            }
        }

        return [
            'patterns' => $patterns,
            'issues' => $issues
        ];
    }

    /**
     * Calculate chaos score (0-100)
     */
    private function calculateChaosScore(int $uniqueCount, int $totalCount, array $patternInfo): int
    {
        $score = 0;

        // High unique ratio = more chaos
        if ($totalCount > 0) {
            $uniqueRatio = $uniqueCount / $totalCount;
            $score += (int) ($uniqueRatio * 30);
        }

        // Issues add to chaos
        $score += count($patternInfo['issues']) * 15;

        // Many unique values
        if ($uniqueCount > 50) {
            $score += 20;
        } elseif ($uniqueCount > 20) {
            $score += 10;
        }

        return min(100, $score);
    }

    /**
     * Get chaos indicator emoji
     */
    private function getChaosIndicator(int $score): string
    {
        if ($score >= 70) return '🔴';
        if ($score >= 40) return '🟡';
        return '🟢';
    }
}
