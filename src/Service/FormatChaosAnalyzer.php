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

namespace FlipDev\AttributeManager\Service;

use FlipDev\AttributeManager\Api\FormatChaosAnalyzerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Eav\Model\Config as EavConfig;
use Psr\Log\LoggerInterface;

/**
 * Format Chaos Analyzer Service
 *
 * Detects inconsistent formatting patterns in text attributes.
 */
class FormatChaosAnalyzer implements FormatChaosAnalyzerInterface
{
    /**
     * Common unit patterns
     */
    private const UNIT_PATTERNS = [
        'length' => ['mm', 'cm', 'm', 'meter', 'millimeter', 'zentimeter'],
        'power' => ['w', 'kw', 'watt', 'kilowatt'],
        'temperature' => ['°c', '°f', 'c', 'celsius', 'grad'],
        'volume' => ['l', 'liter', 'ml', 'milliliter'],
        'weight' => ['kg', 'g', 'kilogramm', 'gramm'],
        'voltage' => ['v', 'volt'],
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @inheritdoc
     */
    public function analyzeAttributes(string $entityType = 'catalog_product', int $limit = 20): array
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');
        $entityTypeTable = $this->resourceConnection->getTableName('eav_entity_type');

        $entityTypeId = $connection->fetchOne(
            "SELECT entity_type_id FROM {$entityTypeTable} WHERE entity_type_code = ?",
            [$entityType]
        );

        if (!$entityTypeId) {
            return ['error' => 'Entity type not found'];
        }

        $attributes = $connection->fetchAll(
            "SELECT attribute_id, attribute_code, frontend_label, backend_type, frontend_input
             FROM {$attributeTable}
             WHERE entity_type_id = ?
             AND frontend_input IN ('text', 'textarea')
             AND backend_type IN ('varchar', 'text')
             ORDER BY attribute_code ASC
             LIMIT ?",
            [$entityTypeId, $limit]
        );

        $results = [];
        foreach ($attributes as $attr) {
            $analysis = $this->analyzeAttribute($attr['attribute_code'], $entityType);
            if ($analysis['chaos_score'] > 0) {
                $results[] = array_merge(['attribute_code' => $attr['attribute_code']], $analysis);
            }
        }

        usort($results, fn(array $a, array $b): int => $b['chaos_score'] <=> $a['chaos_score']);

        return [
            'analyzed' => count($attributes),
            'chaotic' => count($results),
            'attributes' => $results,
        ];
    }

    /**
     * @inheritdoc
     */
    public function analyzeAttribute(string $attributeCode, string $entityType = 'catalog_product'): array
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');
        $entityTypeTable = $this->resourceConnection->getTableName('eav_entity_type');

        $entityTypeId = $connection->fetchOne(
            "SELECT entity_type_id FROM {$entityTypeTable} WHERE entity_type_code = ?",
            [$entityType]
        );

        $attr = $connection->fetchRow(
            "SELECT * FROM {$attributeTable}
             WHERE attribute_code = ? AND entity_type_id = ?",
            [$attributeCode, $entityTypeId]
        );

        if (!$attr || $attr['backend_type'] === 'static') {
            return ['chaos_score' => 0, 'note' => 'Attribute not found or static'];
        }

        $valueTable = $this->resourceConnection->getTableName("{$entityType}_entity_{$attr['backend_type']}");

        if (!$connection->isTableExists($valueTable)) {
            return ['chaos_score' => 0, 'note' => 'Value table not found'];
        }

        $values = $connection->fetchCol(
            "SELECT DISTINCT value FROM {$valueTable}
             WHERE attribute_id = ? AND value IS NOT NULL AND value != ''
             LIMIT 1000",
            [$attr['attribute_id']]
        );

        if (empty($values)) {
            return ['chaos_score' => 0, 'note' => 'No values found'];
        }

        $unitIssues = $this->detectUnitInconsistencies($values);
        $spacingIssues = $this->detectSpacingIssues($values);
        $tempFormats = $this->detectTemperatureFormats($values);
        $chaosScore = $this->calculateChaosScore($values);

        return [
            'chaos_score' => $chaosScore,
            'value_count' => count($values),
            'unit_issues' => $unitIssues,
            'spacing_issues' => $spacingIssues,
            'temperature_formats' => $tempFormats,
            'examples' => array_slice($values, 0, 10),
        ];
    }

    /**
     * @inheritdoc
     */
    public function detectUnitInconsistencies(array $values): array
    {
        $detectedUnits = [];

        foreach (self::UNIT_PATTERNS as $category => $units) {
            $unitCounts = [];

            foreach ($values as $value) {
                $lowerValue = strtolower((string) $value);
                foreach ($units as $unit) {
                    if (preg_match('/\b' . preg_quote($unit, '/') . '\b/i', $lowerValue)) {
                        $unitCounts[$unit] = ($unitCounts[$unit] ?? 0) + 1;
                    }
                }
            }

            if (count($unitCounts) > 1) {
                $detectedUnits[$category] = [
                    'found_units' => array_keys($unitCounts),
                    'counts' => $unitCounts,
                    'issue' => 'Mixed units detected',
                ];
            }
        }

        return $detectedUnits;
    }

    /**
     * @inheritdoc
     */
    public function detectSpacingIssues(array $values): array
    {
        $withSpaces = 0;
        $withoutSpaces = 0;
        $examples = ['with_space' => [], 'without_space' => []];

        foreach ($values as $value) {
            // Check for number followed immediately by unit vs with space
            if (preg_match('/\d+\s+[a-zA-Z°]/', (string) $value)) {
                $withSpaces++;
                if (count($examples['with_space']) < 3) {
                    $examples['with_space'][] = $value;
                }
            } elseif (preg_match('/\d+[a-zA-Z°]/', (string) $value)) {
                $withoutSpaces++;
                if (count($examples['without_space']) < 3) {
                    $examples['without_space'][] = $value;
                }
            }
        }

        if ($withSpaces > 0 && $withoutSpaces > 0) {
            return [
                'issue' => 'Inconsistent spacing between numbers and units',
                'with_space_count' => $withSpaces,
                'without_space_count' => $withoutSpaces,
                'examples' => $examples,
            ];
        }

        return [];
    }

    /**
     * @inheritdoc
     */
    public function detectTemperatureFormats(array $values): array
    {
        $formats = [
            'celsius_symbol' => 0,    // 20°C
            'celsius_text' => 0,       // 20 Celsius
            'celsius_grad' => 0,       // 20 Grad
            'range_dash' => 0,         // -20 bis +10
            'range_slash' => 0,        // -20/+10
        ];

        $examples = [];

        foreach ($values as $value) {
            $lowerValue = strtolower((string) $value);

            if (preg_match('/[-+]?\d+\s*°c/i', $lowerValue)) {
                $formats['celsius_symbol']++;
                if (!isset($examples['celsius_symbol'])) {
                    $examples['celsius_symbol'] = $value;
                }
            }

            if (preg_match('/[-+]?\d+\s*(celsius|grad)/i', $lowerValue)) {
                if (str_contains($lowerValue, 'celsius')) {
                    $formats['celsius_text']++;
                    if (!isset($examples['celsius_text'])) {
                        $examples['celsius_text'] = $value;
                    }
                } else {
                    $formats['celsius_grad']++;
                    if (!isset($examples['celsius_grad'])) {
                        $examples['celsius_grad'] = $value;
                    }
                }
            }

            if (preg_match('/[-+]?\d+\s*bis\s*[-+]?\d+/i', $lowerValue)) {
                $formats['range_dash']++;
                if (!isset($examples['range_dash'])) {
                    $examples['range_dash'] = $value;
                }
            }

            if (preg_match('/[-+]?\d+\s*\/\s*[-+]?\d+/i', $lowerValue)) {
                $formats['range_slash']++;
                if (!isset($examples['range_slash'])) {
                    $examples['range_slash'] = $value;
                }
            }
        }

        $formats = array_filter($formats);

        if (count($formats) > 1) {
            return [
                'formats_found' => $formats,
                'examples' => $examples,
                'issue' => 'Multiple temperature formats detected',
            ];
        }

        return [];
    }

    /**
     * @inheritdoc
     */
    public function calculateChaosScore(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        $score = 0.0;

        // Check unit inconsistencies (0-40 points)
        $unitIssues = $this->detectUnitInconsistencies($values);
        $score += count($unitIssues) * 10;

        // Check spacing issues (0-20 points)
        $spacingIssues = $this->detectSpacingIssues($values);
        if (!empty($spacingIssues)) {
            $score += 20;
        }

        // Check temperature format issues (0-20 points)
        $tempFormats = $this->detectTemperatureFormats($values);
        if (!empty($tempFormats)) {
            $formatCount = count($tempFormats['formats_found'] ?? []);
            $score += min(20, $formatCount * 7);
        }

        // Check for missing units on numeric values (0-20 points)
        $numericWithoutUnit = 0;
        $numericWithUnit = 0;

        foreach ($values as $value) {
            if (preg_match('/^\d+(\.\d+)?$/', trim((string) $value))) {
                $numericWithoutUnit++;
            } elseif (preg_match('/\d+/', (string) $value)) {
                $numericWithUnit++;
            }
        }

        if ($numericWithoutUnit > 0 && $numericWithUnit > 0) {
            $ratio = $numericWithoutUnit / ($numericWithoutUnit + $numericWithUnit);
            $score += $ratio * 20;
        }

        return min(100.0, round($score, 2));
    }

    /**
     * @inheritdoc
     */
    public function suggestStandardFormat(string $attributeCode, string $entityType = 'catalog_product'): array
    {
        $analysis = $this->analyzeAttribute($attributeCode, $entityType);

        if ($analysis['chaos_score'] === 0) {
            return ['suggestion' => 'No standardization needed', 'rules' => []];
        }

        $rules = [];

        // Suggest unit standardization
        if (!empty($analysis['unit_issues'])) {
            foreach ($analysis['unit_issues'] as $category => $issue) {
                $mostCommon = array_keys($issue['counts'], max($issue['counts']))[0];
                $rules[] = [
                    'type' => 'unit_standardization',
                    'category' => $category,
                    'target_unit' => $mostCommon,
                    'description' => "Standardize all {$category} units to '{$mostCommon}'",
                ];
            }
        }

        // Suggest spacing standardization
        if (!empty($analysis['spacing_issues'])) {
            $withSpace = $analysis['spacing_issues']['with_space_count'];
            $withoutSpace = $analysis['spacing_issues']['without_space_count'];
            $preferredFormat = $withSpace > $withoutSpace ? 'with_space' : 'without_space';

            $rules[] = [
                'type' => 'spacing_standardization',
                'format' => $preferredFormat,
                'description' => $preferredFormat === 'with_space'
                    ? 'Add space between numbers and units'
                    : 'Remove space between numbers and units',
            ];
        }

        // Suggest temperature format standardization
        if (!empty($analysis['temperature_formats'])) {
            $formats = $analysis['temperature_formats']['formats_found'];
            $mostCommonFormat = array_keys($formats, max($formats))[0];

            $rules[] = [
                'type' => 'temperature_standardization',
                'target_format' => $mostCommonFormat,
                'description' => "Standardize temperature format to '{$mostCommonFormat}'",
            ];
        }

        return [
            'suggestion' => 'Apply standardization rules to improve data consistency',
            'chaos_score' => $analysis['chaos_score'],
            'rules' => $rules,
        ];
    }
}
