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

use FlipDev\AttributeManager\Api\SetMigrationInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Attribute Set Migration Service
 *
 * Handles migration of products between attribute sets.
 */
class SetMigration implements SetMigrationInterface
{
    /**
     * Migration criteria patterns for gastronomy domain
     */
    private const MIGRATION_RULES = [
        'Kühltechnik' => [
            'attributes' => ['kaeltemittel', 'temperaturbereich', 'energieverbrauch'],
            'categories' => ['kühl', 'tiefkühl', 'kälte', 'freezer', 'refriger'],
            'manufacturers' => ['Cool Compact', 'Liebherr', 'Nordcap'],
            'sku_patterns' => ['KT-', 'TK-', 'COOL-'],
        ],
        'GN Behälter' => [
            'attributes' => ['gastronorm', 'gn_groesse'],
            'categories' => ['gastronorm', 'gn behälter', 'gn-behälter'],
            'manufacturers' => [],
            'sku_patterns' => ['GN-', 'GN1/', 'GN2/'],
        ],
        'Kombidämpfer' => [
            'attributes' => ['dampferzeuger', 'garraumgroesse'],
            'categories' => ['kombidämpfer', 'combi', 'steamer'],
            'manufacturers' => ['Rational', 'Convotherm', 'Eloma', 'Unox'],
            'sku_patterns' => [],
        ],
        'Aufschnittmaschinen' => [
            'attributes' => ['knife', 'messerdurchmesser'],
            'categories' => ['aufschnitt', 'slicer', 'schneidemaschine'],
            'manufacturers' => ['ADE'],
            'sku_patterns' => ['AS-', 'SLICE-'],
        ],
        'Edelstahlmöbel' => [
            'attributes' => ['aufkantung', 'edelstahl_staerke'],
            'categories' => ['edelstahlmöbel', 'arbeitstisch', 'spültisch', 'regal'],
            'manufacturers' => ['Edelstahl'],
            'sku_patterns' => ['ES-', 'EST-'],
        ],
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CollectionFactory $collectionFactory,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @inheritdoc
     */
    public function findMigrationCandidates(
        ?int $sourceSetId,
        int $targetSetId,
        array $criteria = []
    ): array {
        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $setTable = $this->resourceConnection->getTableName('eav_attribute_set');

        // Get target set name
        $targetSetName = $connection->fetchOne(
            "SELECT attribute_set_name FROM {$setTable} WHERE attribute_set_id = ?",
            [$targetSetId]
        );

        if (!$targetSetName) {
            throw new LocalizedException(__('Target attribute set not found'));
        }

        // Build query
        $select = $connection->select()
            ->from(['p' => $productTable], ['entity_id', 'sku', 'type_id', 'attribute_set_id']);

        if ($sourceSetId !== null) {
            $select->where('p.attribute_set_id = ?', $sourceSetId);
        } else {
            $select->where('p.attribute_set_id != ?', $targetSetId);
        }

        $products = $connection->fetchAll($select);

        // Filter by criteria
        $candidates = [];
        $rules = self::MIGRATION_RULES[$targetSetName] ?? [];

        foreach ($products as $product) {
            $score = $this->calculateMigrationScore($connection, $product, $targetSetId, $rules, $criteria);
            
            if ($score > 0) {
                $candidates[] = [
                    'entity_id' => $product['entity_id'],
                    'sku' => $product['sku'],
                    'current_set_id' => $product['attribute_set_id'],
                    'target_set_id' => $targetSetId,
                    'target_set_name' => $targetSetName,
                    'score' => $score,
                    'reasons' => $this->getMigrationReasons($connection, $product, $targetSetId, $rules)
                ];
            }
        }

        // Sort by score descending
        usort($candidates, fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return $candidates;
    }

    /**
     * @inheritdoc
     */
    public function previewMigration(array $productIds, int $targetSetId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $setTable = $this->resourceConnection->getTableName('eav_attribute_set');
        $entityAttrTable = $this->resourceConnection->getTableName('eav_entity_attribute');
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        // Get target set info
        $targetSet = $connection->fetchRow(
            "SELECT * FROM {$setTable} WHERE attribute_set_id = ?",
            [$targetSetId]
        );

        if (!$targetSet) {
            return ['error' => 'Target attribute set not found'];
        }

        // Get target set attributes
        $targetAttributes = $connection->fetchCol(
            "SELECT a.attribute_code 
             FROM {$entityAttrTable} ea
             INNER JOIN {$attributeTable} a ON a.attribute_id = ea.attribute_id
             WHERE ea.attribute_set_id = ?",
            [$targetSetId]
        );

        $preview = [
            'target_set' => [
                'id' => $targetSetId,
                'name' => $targetSet['attribute_set_name'],
                'attribute_count' => count($targetAttributes)
            ],
            'products' => [],
            'summary' => [
                'total' => count($productIds),
                'safe' => 0,
                'data_loss_warning' => 0,
            ]
        ];

        foreach ($productIds as $productId) {
            $product = $connection->fetchRow(
                "SELECT entity_id, sku, attribute_set_id FROM {$productTable} WHERE entity_id = ?",
                [$productId]
            );

            if (!$product) {
                continue;
            }

            // Get current set attributes
            $currentAttributes = $connection->fetchCol(
                "SELECT a.attribute_code 
                 FROM {$entityAttrTable} ea
                 INNER JOIN {$attributeTable} a ON a.attribute_id = ea.attribute_id
                 WHERE ea.attribute_set_id = ?",
                [$product['attribute_set_id']]
            );

            // Check for attribute loss
            $lostAttributes = array_diff($currentAttributes, $targetAttributes);
            $gainedAttributes = array_diff($targetAttributes, $currentAttributes);

            // Check which lost attributes have values
            $lostWithValues = $this->checkAttributesWithValues($connection, (int) $productId, $lostAttributes);

            $hasDataLoss = !empty($lostWithValues);

            $preview['products'][] = [
                'entity_id' => $product['entity_id'],
                'sku' => $product['sku'],
                'current_set_id' => $product['attribute_set_id'],
                'lost_attributes' => count($lostAttributes),
                'lost_with_values' => $lostWithValues,
                'gained_attributes' => count($gainedAttributes),
                'has_data_loss_warning' => $hasDataLoss,
            ];

            if ($hasDataLoss) {
                $preview['summary']['data_loss_warning']++;
            } else {
                $preview['summary']['safe']++;
            }
        }

        return $preview;
    }

    /**
     * @inheritdoc
     */
    public function executeMigration(
        array $productIds,
        int $targetSetId,
        bool $preserveValues = true
    ): array {
        $this->logger->info('SetMigration: Starting migration', [
            'product_count' => count($productIds),
            'target_set' => $targetSetId,
            'preserve_values' => $preserveValues
        ]);

        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');

        $results = [
            'migrated' => [],
            'failed' => [],
            'skipped' => [],
        ];

        $connection->beginTransaction();

        try {
            foreach ($productIds as $productId) {
                try {
                    // Get current product info
                    $currentSetId = (int) $connection->fetchOne(
                        "SELECT attribute_set_id FROM {$productTable} WHERE entity_id = ?",
                        [$productId]
                    );

                    if ($currentSetId === $targetSetId) {
                        $results['skipped'][] = [
                            'entity_id' => $productId,
                            'reason' => 'Already in target set'
                        ];
                        continue;
                    }

                    // Update attribute set
                    $connection->update(
                        $productTable,
                        ['attribute_set_id' => $targetSetId],
                        ['entity_id = ?' => $productId]
                    );

                    $results['migrated'][] = [
                        'entity_id' => $productId,
                        'from_set' => $currentSetId,
                        'to_set' => $targetSetId
                    ];

                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'entity_id' => $productId,
                        'error' => $e->getMessage()
                    ];
                }
            }

            $connection->commit();

            $this->logger->info('SetMigration: Migration complete', [
                'migrated' => count($results['migrated']),
                'failed' => count($results['failed']),
                'skipped' => count($results['skipped'])
            ]);

        } catch (\Exception $e) {
            $connection->rollBack();
            $this->logger->error('SetMigration: Migration failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function findMisassignedProducts(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $setTable = $this->resourceConnection->getTableName('eav_attribute_set');

        // Get Default set ID
        $defaultSetId = (int) $connection->fetchOne(
            "SELECT attribute_set_id FROM {$setTable} 
             WHERE attribute_set_name = 'Default' AND entity_type_id = 4"
        );

        if (!$defaultSetId) {
            return [];
        }

        // Get products in Default set
        $defaultProducts = $connection->fetchAll(
            "SELECT entity_id, sku FROM {$productTable} WHERE attribute_set_id = ?",
            [$defaultSetId]
        );

        $misassigned = [];

        foreach (self::MIGRATION_RULES as $targetSetName => $rules) {
            $targetSetId = (int) $connection->fetchOne(
                "SELECT attribute_set_id FROM {$setTable} 
                 WHERE attribute_set_name = ? AND entity_type_id = 4",
                [$targetSetName]
            );

            if (!$targetSetId) {
                continue;
            }

            foreach ($defaultProducts as $product) {
                $score = $this->calculateMigrationScore($connection, $product, $targetSetId, $rules, []);
                
                if ($score >= 50) {
                    $misassigned[] = [
                        'entity_id' => $product['entity_id'],
                        'sku' => $product['sku'],
                        'current_set' => 'Default',
                        'suggested_set' => $targetSetName,
                        'suggested_set_id' => $targetSetId,
                        'confidence' => $score,
                        'reasons' => $this->getMigrationReasons($connection, $product, $targetSetId, $rules)
                    ];
                }
            }
        }

        // Sort by confidence
        usort($misassigned, fn(array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return $misassigned;
    }

    /**
     * @inheritdoc
     */
    public function getSetDistribution(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $setTable = $this->resourceConnection->getTableName('eav_attribute_set');

        $sql = "
            SELECT 
                s.attribute_set_id,
                s.attribute_set_name,
                COUNT(p.entity_id) as product_count,
                COUNT(CASE WHEN p.type_id = 'simple' THEN 1 END) as simple_count,
                COUNT(CASE WHEN p.type_id = 'configurable' THEN 1 END) as configurable_count
            FROM {$setTable} s
            LEFT JOIN {$productTable} p ON p.attribute_set_id = s.attribute_set_id
            WHERE s.entity_type_id = 4
            GROUP BY s.attribute_set_id
            ORDER BY product_count DESC
        ";

        $distribution = $connection->fetchAll($sql);

        $total = array_sum(array_column($distribution, 'product_count'));

        return array_map(fn(array $row): array => [
            'set_id' => $row['attribute_set_id'],
            'set_name' => $row['attribute_set_name'],
            'product_count' => (int) $row['product_count'],
            'simple_count' => (int) $row['simple_count'],
            'configurable_count' => (int) $row['configurable_count'],
            'percentage' => $total > 0 ? round($row['product_count'] / $total * 100, 2) : 0
        ], $distribution);
    }

    /**
     * @inheritdoc
     */
    public function createMigrationProposal(array $productIds, int $targetSetId, string $reason = ''): int
    {
        // This would create a record in a custom table for approval workflow
        // For now, return a mock proposal ID
        $this->logger->info('SetMigration: Proposal created', [
            'product_count' => count($productIds),
            'target_set' => $targetSetId,
            'reason' => $reason
        ]);

        return random_int(1000, 9999);
    }

    /**
     * @inheritdoc
     */
    public function suggestAttributeSet(int $productId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');

        $product = $connection->fetchRow(
            "SELECT entity_id, sku, attribute_set_id FROM {$productTable} WHERE entity_id = ?",
            [$productId]
        );

        if (!$product) {
            return [];
        }

        $suggestions = [];

        foreach (self::MIGRATION_RULES as $setName => $rules) {
            $setId = $this->getSetIdByName($connection, $setName);
            if (!$setId || $setId === (int) $product['attribute_set_id']) {
                continue;
            }

            $score = $this->calculateMigrationScore($connection, $product, $setId, $rules, []);
            
            if ($score > 0) {
                $suggestions[] = [
                    'set_id' => $setId,
                    'set_name' => $setName,
                    'score' => $score,
                    'reasons' => $this->getMigrationReasons($connection, $product, $setId, $rules)
                ];
            }
        }

        usort($suggestions, fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return $suggestions;
    }

    /**
     * Calculate migration score for a product
     */
    private function calculateMigrationScore(
        $connection,
        array $product,
        int $targetSetId,
        array $rules,
        array $criteria
    ): int {
        $score = 0;

        // Check attributes
        if (!empty($rules['attributes'])) {
            foreach ($rules['attributes'] as $attrCode) {
                if ($this->productHasAttributeValue($connection, (int) $product['entity_id'], $attrCode)) {
                    $score += 30;
                }
            }
        }

        // Check categories
        if (!empty($rules['categories'])) {
            $productCategories = $this->getProductCategories($connection, (int) $product['entity_id']);
            foreach ($rules['categories'] as $catPattern) {
                foreach ($productCategories as $cat) {
                    if (str_contains(strtolower($cat), $catPattern)) {
                        $score += 25;
                        break;
                    }
                }
            }
        }

        // Check manufacturer
        if (!empty($rules['manufacturers'])) {
            $manufacturer = $this->getProductManufacturer($connection, (int) $product['entity_id']);
            if (in_array($manufacturer, $rules['manufacturers'])) {
                $score += 40;
            }
        }

        // Check SKU patterns
        if (!empty($rules['sku_patterns'])) {
            foreach ($rules['sku_patterns'] as $pattern) {
                if (str_starts_with($product['sku'], $pattern)) {
                    $score += 20;
                    break;
                }
            }
        }

        return min(100, $score);
    }

    /**
     * Get reasons why product should be migrated
     */
    private function getMigrationReasons(
        $connection,
        array $product,
        int $targetSetId,
        array $rules
    ): array {
        $reasons = [];

        if (!empty($rules['attributes'])) {
            foreach ($rules['attributes'] as $attrCode) {
                if ($this->productHasAttributeValue($connection, (int) $product['entity_id'], $attrCode)) {
                    $reasons[] = "Has '{$attrCode}' attribute value";
                }
            }
        }

        if (!empty($rules['manufacturers'])) {
            $manufacturer = $this->getProductManufacturer($connection, (int) $product['entity_id']);
            if (in_array($manufacturer, $rules['manufacturers'])) {
                $reasons[] = "Manufacturer: {$manufacturer}";
            }
        }

        if (!empty($rules['categories'])) {
            $productCategories = $this->getProductCategories($connection, (int) $product['entity_id']);
            foreach ($rules['categories'] as $catPattern) {
                foreach ($productCategories as $cat) {
                    if (str_contains(strtolower($cat), $catPattern)) {
                        $reasons[] = "Category matches: {$cat}";
                        break;
                    }
                }
            }
        }

        return $reasons;
    }

    /**
     * Check if product has value for attribute
     */
    private function productHasAttributeValue($connection, int $productId, string $attrCode): bool
    {
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $attr = $connection->fetchRow(
            "SELECT attribute_id, backend_type FROM {$attributeTable} 
             WHERE attribute_code = ? AND entity_type_id = 4",
            [$attrCode]
        );

        if (!$attr || $attr['backend_type'] === 'static') {
            return false;
        }

        $valueTable = $this->resourceConnection->getTableName("catalog_product_entity_{$attr['backend_type']}");

        $value = $connection->fetchOne(
            "SELECT value FROM {$valueTable} 
             WHERE entity_id = ? AND attribute_id = ? AND value IS NOT NULL AND value != ''",
            [$productId, $attr['attribute_id']]
        );

        return !empty($value);
    }

    /**
     * Get product categories
     */
    private function getProductCategories($connection, int $productId): array
    {
        $categoryProductTable = $this->resourceConnection->getTableName('catalog_category_product');
        $categoryVarcharTable = $this->resourceConnection->getTableName('catalog_category_entity_varchar');
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $nameAttrId = $connection->fetchOne(
            "SELECT attribute_id FROM {$attributeTable} 
             WHERE attribute_code = 'name' AND entity_type_id = 3"
        );

        return $connection->fetchCol(
            "SELECT cv.value 
             FROM {$categoryProductTable} cp
             INNER JOIN {$categoryVarcharTable} cv ON cv.entity_id = cp.category_id
             WHERE cp.product_id = ? AND cv.attribute_id = ? AND cv.store_id = 0",
            [$productId, $nameAttrId]
        );
    }

    /**
     * Get product manufacturer
     */
    private function getProductManufacturer($connection, int $productId): ?string
    {
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');
        $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');
        $productIntTable = $this->resourceConnection->getTableName('catalog_product_entity_int');

        $manufacturerId = $connection->fetchOne(
            "SELECT attribute_id FROM {$attributeTable} 
             WHERE attribute_code = 'manufacturer' AND entity_type_id = 4"
        );

        if (!$manufacturerId) {
            return null;
        }

        $optionId = $connection->fetchOne(
            "SELECT value FROM {$productIntTable} 
             WHERE entity_id = ? AND attribute_id = ?",
            [$productId, $manufacturerId]
        );

        if (!$optionId) {
            return null;
        }

        return $connection->fetchOne(
            "SELECT value FROM {$optionValueTable} 
             WHERE option_id = ? AND store_id = 0",
            [$optionId]
        );
    }

    /**
     * Check which attributes have values
     */
    private function checkAttributesWithValues($connection, int $productId, array $attributeCodes): array
    {
        $withValues = [];
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        foreach ($attributeCodes as $code) {
            $attr = $connection->fetchRow(
                "SELECT attribute_id, backend_type FROM {$attributeTable} 
                 WHERE attribute_code = ? AND entity_type_id = 4",
                [$code]
            );

            if (!$attr || $attr['backend_type'] === 'static') {
                continue;
            }

            $valueTable = $this->resourceConnection->getTableName("catalog_product_entity_{$attr['backend_type']}");

            if (!$connection->isTableExists($valueTable)) {
                continue;
            }

            $value = $connection->fetchOne(
                "SELECT value FROM {$valueTable} 
                 WHERE entity_id = ? AND attribute_id = ? AND value IS NOT NULL AND value != ''",
                [$productId, $attr['attribute_id']]
            );

            if (!empty($value)) {
                $withValues[] = $code;
            }
        }

        return $withValues;
    }

    /**
     * Get attribute set ID by name
     */
    private function getSetIdByName($connection, string $setName): ?int
    {
        $setTable = $this->resourceConnection->getTableName('eav_attribute_set');

        $id = $connection->fetchOne(
            "SELECT attribute_set_id FROM {$setTable} 
             WHERE attribute_set_name = ? AND entity_type_id = 4",
            [$setName]
        );

        return $id ? (int) $id : null;
    }
}
