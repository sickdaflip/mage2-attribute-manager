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

use FlipDev\AttributeManager\Api\DuplicateDetectorInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Eav\Model\Config as EavConfig;
use Psr\Log\LoggerInterface;

/**
 * Duplicate Attribute Detection Service
 *
 * Finds similar/duplicate attributes using various comparison methods.
 */
class DuplicateDetector implements DuplicateDetectorInterface
{
    /**
     * Known duplicate patterns for gastronomy domain
     */
    private const KNOWN_PATTERNS = [
        'dimensions' => ['breite', 'width', 'laenge', 'length', 'hoehe', 'height', 'tiefe', 'depth', 'abmessungen'],
        'power' => ['leistung', 'nennleistung', 'power', 'wattage', 'anschlussleistung'],
        'voltage' => ['spannung', 'voltage', 'anschluss', 'anschlusswert'],
        'temperature' => ['temperatur', 'temperaturbereich', 'temp_range', 'kaeltebereich'],
        'capacity' => ['kapazitaet', 'capacity', 'fassungsvermoegen', 'inhalt', 'volumen'],
        'weight' => ['gewicht', 'weight', 'nettogewicht', 'bruttogewicht'],
        'material' => ['material', 'werkstoff', 'oberflaeche', 'ausfuehrung_material'],
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @inheritdoc
     */
    public function findDuplicates(
        string $entityTypeCode,
        float $threshold = 70.0,
        array $types = [DuplicateDetectorInterface::SIMILARITY_CODE, DuplicateDetectorInterface::SIMILARITY_LABEL]
    ): array {
        $this->logger->info('DuplicateDetector: Starting scan', [
            'entity_type' => $entityTypeCode,
            'threshold' => $threshold,
            'types' => $types
        ]);

        // Convert percentage (0-100) to decimal (0.0-1.0) for internal calculations
        $threshold = $threshold / 100;

        $connection = $this->resourceConnection->getConnection();
        $entityType = $this->eavConfig->getEntityType($entityTypeCode);
        $entityTypeId = $entityType->getEntityTypeId();

        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $attributes = $connection->fetchAll(
            "SELECT attribute_id, attribute_code, frontend_label, frontend_input, backend_type 
             FROM {$attributeTable} 
             WHERE entity_type_id = ? AND is_user_defined = 1
             ORDER BY attribute_code",
            [$entityTypeId]
        );

        $duplicates = [];

        if (in_array('code', $types)) {
            $duplicates = array_merge($duplicates, $this->findByCodeSimilarity($attributes, $threshold));
        }

        if (in_array('label', $types)) {
            $duplicates = array_merge($duplicates, $this->findByLabelSimilarity($attributes, $threshold));
        }

        if (in_array('values', $types)) {
            $duplicates = array_merge($duplicates, $this->findByValueOverlap($connection, $attributes, $threshold));
        }

        $duplicates = array_merge($duplicates, $this->findByKnownPatterns($attributes));
        $duplicates = $this->deduplicateResults($duplicates);

        $this->logger->info('DuplicateDetector: Scan complete', ['groups_found' => count($duplicates)]);

        return $duplicates;
    }

    /**
     * @inheritdoc
     */
    public function findSimilarTo(int $attributeId, float $threshold = 70.0): array
    {
        // Convert percentage (0-100) to decimal (0.0-1.0) for internal calculations
        $threshold = $threshold / 100;

        $connection = $this->resourceConnection->getConnection();
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $sourceAttr = $connection->fetchRow(
            "SELECT * FROM {$attributeTable} WHERE attribute_id = ?",
            [$attributeId]
        );

        if (!$sourceAttr) {
            return [];
        }

        $allAttributes = $connection->fetchAll(
            "SELECT attribute_id, attribute_code, frontend_label, frontend_input, backend_type 
             FROM {$attributeTable} 
             WHERE entity_type_id = ? AND attribute_id != ? AND is_user_defined = 1",
            [$sourceAttr['entity_type_id'], $attributeId]
        );

        $similar = [];

        foreach ($allAttributes as $attr) {
            $similarity = $this->calculateOverallSimilarity($sourceAttr, $attr);
            
            if ($similarity >= $threshold) {
                $similar[] = [
                    'attribute_id' => $attr['attribute_id'],
                    'code' => $attr['attribute_code'],
                    'label' => $attr['frontend_label'],
                    'similarity' => round($similarity * 100, 1),
                    'reasons' => $this->getSimilarityReasons($sourceAttr, $attr)
                ];
            }
        }

        usort($similar, fn(array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return $similar;
    }

    /**
     * @inheritdoc
     */
    public function compareTwoAttributes(int $attributeId1, int $attributeId2): array
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $attr1 = $connection->fetchRow(
            "SELECT * FROM {$attributeTable} WHERE attribute_id = ?",
            [$attributeId1]
        );

        $attr2 = $connection->fetchRow(
            "SELECT * FROM {$attributeTable} WHERE attribute_id = ?",
            [$attributeId2]
        );

        if (!$attr1 || !$attr2) {
            return ['error' => 'One or both attributes not found'];
        }

        return [
            'attribute_1' => [
                'id' => $attr1['attribute_id'],
                'code' => $attr1['attribute_code'],
                'label' => $attr1['frontend_label'],
                'type' => $attr1['frontend_input'],
            ],
            'attribute_2' => [
                'id' => $attr2['attribute_id'],
                'code' => $attr2['attribute_code'],
                'label' => $attr2['frontend_label'],
                'type' => $attr2['frontend_input'],
            ],
            'comparison' => [
                'code_similarity' => round($this->calculateCodeSimilarity($attr1['attribute_code'], $attr2['attribute_code']) * 100, 1),
                'label_similarity' => round($this->calculateLabelSimilarity($attr1['frontend_label'] ?? '', $attr2['frontend_label'] ?? '') * 100, 1),
                'same_type' => $attr1['frontend_input'] === $attr2['frontend_input'],
                'same_backend' => $attr1['backend_type'] === $attr2['backend_type'],
                'value_overlap' => $this->calculateValueOverlap($connection, $attr1, $attr2),
            ],
            'recommendation' => $this->getRecommendation($attr1, $attr2)
        ];
    }

    /**
     * @inheritdoc
     */
    public function getKnownPatterns(string $entityTypeCode): array
    {
        return self::KNOWN_PATTERNS;
    }

    /**
     * @inheritdoc
     */
    public function calculateCodeSimilarity(string $code1, string $code2): float
    {
        $c1 = $this->normalizeCode($code1);
        $c2 = $this->normalizeCode($code2);

        if ($c1 === $c2) {
            return 1.0;
        }

        $maxLen = max(strlen($c1), strlen($c2));
        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = levenshtein($c1, $c2);
        $levenshteinSimilarity = 1 - ($distance / $maxLen);

        similar_text($c1, $c2, $percent);
        $similarTextScore = $percent / 100;

        $containsBonus = 0;
        if (str_contains($c1, $c2) || str_contains($c2, $c1)) {
            $containsBonus = 0.2;
        }

        return min(1.0, ($levenshteinSimilarity * 0.4) + ($similarTextScore * 0.4) + $containsBonus);
    }

    private function normalizeCode(string $code): string
    {
        $code = preg_replace('/^(attr_|attribute_|custom_|product_)/', '', $code);
        $code = preg_replace('/(_dropdown|_select|_multiselect|_text|_textarea)$/', '', $code);
        $code = strtolower($code);
        $code = str_replace('_', '', $code);
        
        return $code;
    }

    private function calculateLabelSimilarity(?string $label1, ?string $label2): float
    {
        if (empty($label1) || empty($label2)) {
            return 0.0;
        }

        $l1 = strtolower(trim($label1));
        $l2 = strtolower(trim($label2));

        if ($l1 === $l2) {
            return 1.0;
        }

        similar_text($l1, $l2, $percent);
        
        return $percent / 100;
    }

    private function findByCodeSimilarity(array $attributes, float $threshold): array
    {
        $groups = [];
        $processed = [];

        foreach ($attributes as $i => $attr1) {
            if (in_array($attr1['attribute_id'], $processed)) {
                continue;
            }

            $group = [$attr1];

            foreach ($attributes as $j => $attr2) {
                if ($i >= $j || in_array($attr2['attribute_id'], $processed)) {
                    continue;
                }

                $similarity = $this->calculateCodeSimilarity($attr1['attribute_code'], $attr2['attribute_code']);
                
                if ($similarity >= $threshold) {
                    $group[] = $attr2;
                    $processed[] = $attr2['attribute_id'];
                }
            }

            if (count($group) > 1) {
                $processed[] = $attr1['attribute_id'];
                $groups[] = [
                    'type' => 'code_similarity',
                    'attributes' => array_map(fn(array $a): array => [
                        'id' => $a['attribute_id'],
                        'code' => $a['attribute_code'],
                        'label' => $a['frontend_label']
                    ], $group)
                ];
            }
        }

        return $groups;
    }

    private function findByLabelSimilarity(array $attributes, float $threshold): array
    {
        $groups = [];
        $processed = [];

        foreach ($attributes as $i => $attr1) {
            if (empty($attr1['frontend_label']) || in_array($attr1['attribute_id'], $processed)) {
                continue;
            }

            $group = [$attr1];

            foreach ($attributes as $j => $attr2) {
                if ($i >= $j || empty($attr2['frontend_label']) || in_array($attr2['attribute_id'], $processed)) {
                    continue;
                }

                $similarity = $this->calculateLabelSimilarity($attr1['frontend_label'], $attr2['frontend_label']);
                
                if ($similarity >= $threshold) {
                    $group[] = $attr2;
                    $processed[] = $attr2['attribute_id'];
                }
            }

            if (count($group) > 1) {
                $processed[] = $attr1['attribute_id'];
                $groups[] = [
                    'type' => 'label_similarity',
                    'attributes' => array_map(fn(array $a): array => [
                        'id' => $a['attribute_id'],
                        'code' => $a['attribute_code'],
                        'label' => $a['frontend_label']
                    ], $group)
                ];
            }
        }

        return $groups;
    }

    private function findByValueOverlap($connection, array $attributes, float $threshold): array
    {
        $selectAttributes = array_filter($attributes, fn(array $a): bool => in_array($a['frontend_input'], ['select', 'multiselect']));
        
        if (count($selectAttributes) < 2) {
            return [];
        }

        $groups = [];
        $processed = [];

        foreach ($selectAttributes as $attr1) {
            if (in_array($attr1['attribute_id'], $processed)) {
                continue;
            }

            $values1 = $this->getAttributeOptions($connection, (int) $attr1['attribute_id']);
            if (empty($values1)) {
                continue;
            }

            $group = [$attr1];

            foreach ($selectAttributes as $attr2) {
                if ($attr1['attribute_id'] >= $attr2['attribute_id'] || in_array($attr2['attribute_id'], $processed)) {
                    continue;
                }

                $values2 = $this->getAttributeOptions($connection, (int) $attr2['attribute_id']);
                if (empty($values2)) {
                    continue;
                }

                $overlap = $this->calculateSetOverlap($values1, $values2);
                
                if ($overlap >= $threshold) {
                    $group[] = $attr2;
                    $processed[] = $attr2['attribute_id'];
                }
            }

            if (count($group) > 1) {
                $processed[] = $attr1['attribute_id'];
                $groups[] = [
                    'type' => 'value_overlap',
                    'attributes' => array_map(fn(array $a): array => [
                        'id' => $a['attribute_id'],
                        'code' => $a['attribute_code'],
                        'label' => $a['frontend_label']
                    ], $group)
                ];
            }
        }

        return $groups;
    }

    private function findByKnownPatterns(array $attributes): array
    {
        $groups = [];
        $codes = array_column($attributes, 'attribute_code');
        $codeToAttr = array_combine($codes, $attributes);

        foreach (self::KNOWN_PATTERNS as $category => $patterns) {
            $found = [];
            
            foreach ($patterns as $pattern) {
                foreach ($codes as $code) {
                    if (str_contains(strtolower($code), $pattern)) {
                        $found[$code] = $codeToAttr[$code];
                    }
                }
            }

            if (count($found) > 1) {
                $groups[] = [
                    'type' => 'known_pattern',
                    'category' => $category,
                    'attributes' => array_map(fn(array $a): array => [
                        'id' => $a['attribute_id'],
                        'code' => $a['attribute_code'],
                        'label' => $a['frontend_label']
                    ], array_values($found))
                ];
            }
        }

        return $groups;
    }

    private function getAttributeOptions($connection, int $attributeId): array
    {
        $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');
        $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');

        return $connection->fetchCol(
            "SELECT LOWER(ov.value) 
             FROM {$optionTable} o
             INNER JOIN {$optionValueTable} ov ON ov.option_id = o.option_id
             WHERE o.attribute_id = ? AND ov.store_id = 0",
            [$attributeId]
        );
    }

    private function calculateSetOverlap(array $set1, array $set2): float
    {
        $intersection = count(array_intersect($set1, $set2));
        $union = count(array_unique(array_merge($set1, $set2)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function calculateValueOverlap($connection, array $attr1, array $attr2): array
    {
        if ($attr1['frontend_input'] !== $attr2['frontend_input']) {
            return ['compatible' => false, 'overlap' => 0];
        }

        if (in_array($attr1['frontend_input'], ['select', 'multiselect'])) {
            $values1 = $this->getAttributeOptions($connection, (int) $attr1['attribute_id']);
            $values2 = $this->getAttributeOptions($connection, (int) $attr2['attribute_id']);

            $overlap = $this->calculateSetOverlap($values1, $values2);

            return [
                'compatible' => true,
                'overlap' => round($overlap * 100, 1),
                'values_1' => count($values1),
                'values_2' => count($values2),
                'common' => count(array_intersect($values1, $values2))
            ];
        }

        return ['compatible' => true, 'overlap' => null, 'note' => 'Text attributes - manual review needed'];
    }

    private function calculateOverallSimilarity(array $attr1, array $attr2): float
    {
        $codeSim = $this->calculateCodeSimilarity($attr1['attribute_code'], $attr2['attribute_code']);
        $labelSim = $this->calculateLabelSimilarity($attr1['frontend_label'] ?? '', $attr2['frontend_label'] ?? '');
        $typeSim = $attr1['frontend_input'] === $attr2['frontend_input'] ? 0.3 : 0;

        return ($codeSim * 0.4) + ($labelSim * 0.3) + $typeSim;
    }

    private function getSimilarityReasons(array $attr1, array $attr2): array
    {
        $reasons = [];

        $codeSim = $this->calculateCodeSimilarity($attr1['attribute_code'], $attr2['attribute_code']);
        if ($codeSim > 0.5) {
            $reasons[] = 'Similar code (' . round($codeSim * 100) . '%)';
        }

        $labelSim = $this->calculateLabelSimilarity($attr1['frontend_label'] ?? '', $attr2['frontend_label'] ?? '');
        if ($labelSim > 0.5) {
            $reasons[] = 'Similar label (' . round($labelSim * 100) . '%)';
        }

        if ($attr1['frontend_input'] === $attr2['frontend_input']) {
            $reasons[] = 'Same type (' . $attr1['frontend_input'] . ')';
        }

        foreach (self::KNOWN_PATTERNS as $category => $patterns) {
            $match1 = false;
            $match2 = false;
            foreach ($patterns as $pattern) {
                if (str_contains(strtolower($attr1['attribute_code']), $pattern)) {
                    $match1 = true;
                }
                if (str_contains(strtolower($attr2['attribute_code']), $pattern)) {
                    $match2 = true;
                }
            }
            if ($match1 && $match2) {
                $reasons[] = "Both in '{$category}' category";
            }
        }

        return $reasons;
    }

    private function getRecommendation(array $attr1, array $attr2): string
    {
        $codeSim = $this->calculateCodeSimilarity($attr1['attribute_code'], $attr2['attribute_code']);
        $labelSim = $this->calculateLabelSimilarity($attr1['frontend_label'] ?? '', $attr2['frontend_label'] ?? '');
        $sameType = $attr1['frontend_input'] === $attr2['frontend_input'];

        if ($codeSim > 0.8 && $sameType) {
            return 'MERGE_RECOMMENDED: High similarity, same type - strong candidate for merging';
        }

        if ($codeSim > 0.6 || $labelSim > 0.7) {
            return 'REVIEW_RECOMMENDED: Moderate similarity - manual review suggested';
        }

        if (!$sameType) {
            return 'NO_MERGE: Different types - merging not recommended';
        }

        return 'LOW_SIMILARITY: Unlikely to be duplicates';
    }

    private function deduplicateResults(array $groups): array
    {
        $seen = [];
        $unique = [];

        foreach ($groups as $group) {
            $ids = array_column($group['attributes'], 'id');
            sort($ids);
            $key = implode('-', $ids);

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $group;
            }
        }

        return $unique;
    }
}
