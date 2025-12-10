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

use FlipDev\AttributeManager\Api\FillRateAnalyzerInterface;
use FlipDev\AttributeManager\Helper\Config;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * Fill-Rate Analyzer Service
 *
 * Analyzes attribute fill rates across products.
 */
class FillRateAnalyzer implements FillRateAnalyzerInterface
{
    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var EavConfig
     */
    private EavConfig $eavConfig;

    /**
     * @var AttributeCollectionFactory
     */
    private AttributeCollectionFactory $attributeCollectionFactory;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var array Cache for entity type IDs
     */
    private array $entityTypeIdCache = [];

    /**
     * @param ResourceConnection $resourceConnection
     * @param EavConfig $eavConfig
     * @param AttributeCollectionFactory $attributeCollectionFactory
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        EavConfig $eavConfig,
        AttributeCollectionFactory $attributeCollectionFactory,
        Config $config,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->eavConfig = $eavConfig;
        $this->attributeCollectionFactory = $attributeCollectionFactory;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function getAttributeFillRates(string $entityTypeCode, ?int $attributeSetId = null): array
    {
        $connection = $this->getConnection();
        $entityTypeId = $this->getEntityTypeId($entityTypeCode);

        if (!$entityTypeId) {
            return [];
        }

        $results = [];
        $totalEntities = $this->getTotalEntityCount($entityTypeCode, $attributeSetId);

        if ($totalEntities === 0) {
            return [];
        }

        // Get all attributes for entity type
        $attributes = $this->getAttributesForEntityType($entityTypeId, $attributeSetId);

        foreach ($attributes as $attribute) {
            $attributeId = (int) $attribute['attribute_id'];
            $backendType = $attribute['backend_type'];

            if ($backendType === 'static') {
                continue; // Skip static attributes
            }

            $filledCount = $this->getFilledCount($entityTypeCode, $attributeId, $backendType, $attributeSetId);
            $rate = ($filledCount / $totalEntities) * 100;

            $results[$attributeId] = [
                'attribute_id' => $attributeId,
                'code' => $attribute['attribute_code'],
                'label' => $attribute['frontend_label'] ?? $attribute['attribute_code'],
                'type' => $attribute['frontend_input'],
                'backend_type' => $backendType,
                'is_user_defined' => (bool) $attribute['is_user_defined'],
                'filled' => $filledCount,
                'total' => $totalEntities,
                'rate' => round($rate, 2),
                'status' => $this->config->getFillRateStatus($rate),
            ];
        }

        // Sort by fill rate ascending (worst first)
        uasort($results, fn($a, $b) => $a['rate'] <=> $b['rate']);

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function getFillRatesBySet(string $entityTypeCode): array
    {
        $connection = $this->getConnection();
        $entityTypeId = $this->getEntityTypeId($entityTypeCode);

        if (!$entityTypeId) {
            return [];
        }

        // Get all attribute sets
        $setTable = $this->resourceConnection->getTableName('eav_attribute_set');
        $select = $connection->select()
            ->from($setTable, ['attribute_set_id', 'attribute_set_name'])
            ->where('entity_type_id = ?', $entityTypeId);

        $sets = $connection->fetchAll($select);
        $results = [];

        foreach ($sets as $set) {
            $setId = (int) $set['attribute_set_id'];
            $productCount = $this->getTotalEntityCount($entityTypeCode, $setId);

            $results[$setId] = [
                'set_id' => $setId,
                'name' => $set['attribute_set_name'],
                'product_count' => $productCount,
                'attributes' => $this->getAttributeFillRates($entityTypeCode, $setId),
            ];
        }

        // Sort by product count descending
        uasort($results, fn($a, $b) => $b['product_count'] <=> $a['product_count']);

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function getFillRatesByManufacturer(string $attributeCode): array
    {
        $connection = $this->getConnection();

        // Get manufacturer attribute
        $manufacturerAttr = $this->eavConfig->getAttribute('catalog_product', 'manufacturer');
        if (!$manufacturerAttr || !$manufacturerAttr->getId()) {
            return [];
        }

        // Get target attribute
        $targetAttr = $this->eavConfig->getAttribute('catalog_product', $attributeCode);
        if (!$targetAttr || !$targetAttr->getId()) {
            return [];
        }

        // This is a simplified version - full implementation would join properly
        // For now, return empty array as placeholder
        $this->logger->info("getFillRatesByManufacturer called for: {$attributeCode}");

        return [];
    }

    /**
     * @inheritdoc
     */
    public function getCriticalAttributes(string $entityTypeCode, float $threshold): array
    {
        $allRates = $this->getAttributeFillRates($entityTypeCode);

        return array_filter($allRates, fn($attr) => $attr['rate'] < $threshold);
    }

    /**
     * @inheritdoc
     */
    public function getUnusedAttributes(string $entityTypeCode): array
    {
        return $this->getCriticalAttributes($entityTypeCode, 0.01);
    }

    /**
     * @inheritdoc
     */
    public function getSummaryStatistics(string $entityTypeCode): array
    {
        $allRates = $this->getAttributeFillRates($entityTypeCode);

        if (empty($allRates)) {
            return [
                'total_attributes' => 0,
                'avg_fill_rate' => 0,
                'critical' => 0,
                'warning' => 0,
                'healthy' => 0,
            ];
        }

        $criticalThreshold = $this->config->getCriticalThreshold();
        $warningThreshold = $this->config->getWarningThreshold();

        $critical = 0;
        $warning = 0;
        $healthy = 0;
        $totalRate = 0;

        foreach ($allRates as $attr) {
            $totalRate += $attr['rate'];

            if ($attr['rate'] < $criticalThreshold) {
                $critical++;
            } elseif ($attr['rate'] < $warningThreshold) {
                $warning++;
            } else {
                $healthy++;
            }
        }

        $total = count($allRates);

        return [
            'total_attributes' => $total,
            'avg_fill_rate' => round($totalRate / $total, 2),
            'critical' => $critical,
            'warning' => $warning,
            'healthy' => $healthy,
        ];
    }

    /**
     * Get database connection
     */
    private function getConnection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }

    /**
     * Get entity type ID from code
     */
    private function getEntityTypeId(string $entityTypeCode): ?int
    {
        if (!isset($this->entityTypeIdCache[$entityTypeCode])) {
            try {
                $entityType = $this->eavConfig->getEntityType($entityTypeCode);
                $this->entityTypeIdCache[$entityTypeCode] = (int) $entityType->getEntityTypeId();
            } catch (\Exception $e) {
                $this->logger->error("Entity type not found: {$entityTypeCode}");
                return null;
            }
        }

        return $this->entityTypeIdCache[$entityTypeCode];
    }

    /**
     * Get total entity count
     */
    private function getTotalEntityCount(string $entityTypeCode, ?int $attributeSetId = null): int
    {
        $connection = $this->getConnection();

        // Map entity type to table
        $tableMap = [
            'catalog_product' => 'catalog_product_entity',
            'catalog_category' => 'catalog_category_entity',
            'customer' => 'customer_entity',
        ];

        $table = $tableMap[$entityTypeCode] ?? null;
        if (!$table) {
            return 0;
        }

        $table = $this->resourceConnection->getTableName($table);
        $select = $connection->select()->from($table, ['count' => new \Zend_Db_Expr('COUNT(*)')]);

        if ($attributeSetId !== null) {
            $select->where('attribute_set_id = ?', $attributeSetId);
        }

        return (int) $connection->fetchOne($select);
    }

    /**
     * Get attributes for entity type
     */
    private function getAttributesForEntityType(int $entityTypeId, ?int $attributeSetId = null): array
    {
        $connection = $this->getConnection();
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $select = $connection->select()
            ->from($attributeTable)
            ->where('entity_type_id = ?', $entityTypeId);

        if ($this->config->excludeSystemAttributes()) {
            $select->where('is_user_defined = ?', 1);
        }

        if ($attributeSetId !== null) {
            $entityAttrTable = $this->resourceConnection->getTableName('eav_entity_attribute');
            $select->join(
                ['entity_attr' => $entityAttrTable],
                'entity_attr.attribute_id = main_table.attribute_id AND entity_attr.attribute_set_id = ' . $attributeSetId,
                []
            );
        }

        return $connection->fetchAll($select);
    }

    /**
     * Get count of entities with filled attribute value
     */
    private function getFilledCount(
        string $entityTypeCode,
        int $attributeId,
        string $backendType,
        ?int $attributeSetId = null
    ): int {
        $connection = $this->getConnection();

        // Map entity type + backend type to value table
        $valueTablePrefix = str_replace('_', '', $entityTypeCode);
        $valueTable = $this->resourceConnection->getTableName(
            "catalog_product_entity_{$backendType}"
        );

        if (!$this->tableExists($valueTable)) {
            return 0;
        }

        $select = $connection->select()
            ->from(['v' => $valueTable], ['count' => new \Zend_Db_Expr('COUNT(DISTINCT v.entity_id)')])
            ->where('v.attribute_id = ?', $attributeId)
            ->where('v.value IS NOT NULL')
            ->where("v.value != ''");

        if ($attributeSetId !== null) {
            $entityTable = $this->resourceConnection->getTableName('catalog_product_entity');
            $select->join(
                ['e' => $entityTable],
                'e.entity_id = v.entity_id AND e.attribute_set_id = ' . $attributeSetId,
                []
            );
        }

        return (int) $connection->fetchOne($select);
    }

    /**
     * Check if table exists
     */
    private function tableExists(string $tableName): bool
    {
        $connection = $this->getConnection();
        return $connection->isTableExists($tableName);
    }
}
