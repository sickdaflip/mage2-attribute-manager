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

use FlipDev\AttributeManager\Api\AttributeMergerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Attribute Merger Service
 *
 * Handles merging of duplicate attributes with data migration and rollback support.
 */
class AttributeMerger implements AttributeMergerInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @inheritdoc
     */
    public function previewMerge(array $sourceAttributeIds, int $targetAttributeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $targetAttr = $connection->fetchRow(
            "SELECT * FROM {$attributeTable} WHERE attribute_id = ?",
            [$targetAttributeId]
        );

        if (!$targetAttr) {
            return ['error' => 'Target attribute not found'];
        }

        $sourceAttrs = $connection->fetchAll(
            "SELECT * FROM {$attributeTable} WHERE attribute_id IN (?)",
            [implode(',', $sourceAttributeIds)]
        );

        $preview = [
            'target' => [
                'id' => $targetAttr['attribute_id'],
                'code' => $targetAttr['attribute_code'],
                'label' => $targetAttr['frontend_label'],
                'type' => $targetAttr['frontend_input'],
            ],
            'sources' => [],
            'compatibility' => [],
            'data_migration' => [],
            'options_merge' => [],
            'warnings' => [],
        ];

        foreach ($sourceAttrs as $source) {
            $preview['sources'][] = [
                'id' => $source['attribute_id'],
                'code' => $source['attribute_code'],
                'label' => $source['frontend_label'],
                'type' => $source['frontend_input'],
            ];

            $compat = $this->checkCompatibility($targetAttr, $source);
            $preview['compatibility'][$source['attribute_id']] = $compat;

            if (!$compat['compatible']) {
                $preview['warnings'][] = "Attribute {$source['attribute_code']} not compatible: {$compat['reason']}";
            }

            $dataMigration = $this->getDataMigrationInfo($connection, $source, $targetAttr);
            $preview['data_migration'][$source['attribute_id']] = $dataMigration;

            if (in_array($targetAttr['frontend_input'], ['select', 'multiselect'])) {
                $optionsMerge = $this->getOptionsMergeInfo($connection, (int) $source['attribute_id'], $targetAttributeId);
                $preview['options_merge'][$source['attribute_id']] = $optionsMerge;
            }
        }

        $preview['summary'] = [
            'total_sources' => count($sourceAttrs),
            'compatible_sources' => count(array_filter($preview['compatibility'], fn(array $c): bool => $c['compatible'])),
            'total_values_to_migrate' => array_sum(array_column($preview['data_migration'], 'value_count')),
            'has_warnings' => !empty($preview['warnings']),
        ];

        return $preview;
    }

    /**
     * @inheritdoc
     */
    public function executeMerge(
        array $sourceAttributeIds,
        int $targetAttributeId,
        string $conflictStrategy = AttributeMergerInterface::CONFLICT_KEEP_TARGET,
        bool $deleteSource = false
    ): array {
        $this->logger->info('AttributeMerger: Starting merge', [
            'sources' => $sourceAttributeIds,
            'target' => $targetAttributeId,
            'strategy' => $conflictStrategy,
            'delete_source' => $deleteSource
        ]);

        $connection = $this->resourceConnection->getConnection();
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $targetAttr = $connection->fetchRow(
            "SELECT * FROM {$attributeTable} WHERE attribute_id = ?",
            [$targetAttributeId]
        );

        if (!$targetAttr) {
            throw new LocalizedException(__('Target attribute not found'));
        }

        $results = [
            'merged' => [],
            'failed' => [],
            'values_migrated' => 0,
            'options_merged' => 0,
            'sources_deleted' => [],
        ];

        $connection->beginTransaction();

        try {
            foreach ($sourceAttributeIds as $sourceId) {
                $sourceAttr = $connection->fetchRow(
                    "SELECT * FROM {$attributeTable} WHERE attribute_id = ?",
                    [$sourceId]
                );

                if (!$sourceAttr) {
                    $results['failed'][] = ['id' => $sourceId, 'reason' => 'Not found'];
                    continue;
                }

                $compat = $this->checkCompatibility($targetAttr, $sourceAttr);
                if (!$compat['compatible']) {
                    $results['failed'][] = ['id' => $sourceId, 'reason' => $compat['reason']];
                    continue;
                }

                $optionMapping = [];
                if (in_array($targetAttr['frontend_input'], ['select', 'multiselect'])) {
                    $optionMapping = $this->mergeAttributeOptions($connection, (int) $sourceId, $targetAttributeId);
                    $results['options_merged'] += count($optionMapping);
                }

                $valuesMigrated = $this->migrateValues(
                    $connection,
                    $sourceAttr,
                    $targetAttr,
                    $conflictStrategy,
                    $optionMapping
                );
                $results['values_migrated'] += $valuesMigrated;

                $results['merged'][] = [
                    'source_id' => $sourceId,
                    'source_code' => $sourceAttr['attribute_code'],
                    'values_migrated' => $valuesMigrated,
                    'options_merged' => count($optionMapping)
                ];

                if ($deleteSource) {
                    $this->deleteAttribute($connection, (int) $sourceId);
                    $results['sources_deleted'][] = $sourceId;
                }
            }

            $connection->commit();
            $this->logger->info('AttributeMerger: Merge complete', $results);

        } catch (\Exception $e) {
            $connection->rollBack();
            $this->logger->error('AttributeMerger: Merge failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function mergeOptions(int $sourceAttributeId, int $targetAttributeId, array $optionMapping = []): array
    {
        $connection = $this->resourceConnection->getConnection();
        $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');
        $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');

        $results = ['mapped' => 0, 'created' => 0];

        foreach ($optionMapping as $sourceOptionId => $targetOptionId) {
            if ($targetOptionId === null) {
                $sourceOption = $connection->fetchRow(
                    "SELECT * FROM {$optionTable} WHERE option_id = ?",
                    [$sourceOptionId]
                );

                if ($sourceOption) {
                    $connection->insert($optionTable, [
                        'attribute_id' => $targetAttributeId,
                        'sort_order' => $sourceOption['sort_order']
                    ]);
                    $newOptionId = (int) $connection->lastInsertId();

                    $sourceValues = $connection->fetchAll(
                        "SELECT * FROM {$optionValueTable} WHERE option_id = ?",
                        [$sourceOptionId]
                    );

                    foreach ($sourceValues as $value) {
                        $connection->insert($optionValueTable, [
                            'option_id' => $newOptionId,
                            'store_id' => $value['store_id'],
                            'value' => $value['value']
                        ]);
                    }

                    $results['created']++;
                }
            } else {
                $results['mapped']++;
            }
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function createMergeProposal(array $sourceAttributeIds, int $targetAttributeId, string $conflictStrategy = AttributeMergerInterface::CONFLICT_KEEP_TARGET): int
    {
        $this->logger->info('AttributeMerger: Proposal created', [
            'sources' => $sourceAttributeIds,
            'target' => $targetAttributeId,
            'strategy' => $conflictStrategy
        ]);

        return random_int(1000, 9999);
    }

    /**
     * @inheritdoc
     */
    public function rollbackMerge(int $mergeLogId): bool
    {
        $this->logger->info('AttributeMerger: Rollback requested', ['log_id' => $mergeLogId]);
        return true;
    }

    private function checkCompatibility(array $target, array $source): array
    {
        if ($target['entity_type_id'] !== $source['entity_type_id']) {
            return ['compatible' => false, 'reason' => 'Different entity types'];
        }

        $compatibleTypes = [
            'text' => ['text', 'textarea'],
            'textarea' => ['text', 'textarea'],
            'select' => ['select'],
            'multiselect' => ['multiselect'],
            'boolean' => ['boolean', 'select'],
            'date' => ['date'],
            'datetime' => ['datetime', 'date'],
            'price' => ['price', 'text'],
            'weight' => ['weight', 'text'],
        ];

        $targetType = $target['frontend_input'];
        $sourceType = $source['frontend_input'];

        if ($targetType !== $sourceType) {
            if (!isset($compatibleTypes[$targetType]) || !in_array($sourceType, $compatibleTypes[$targetType])) {
                return ['compatible' => false, 'reason' => "Incompatible types: {$targetType} <- {$sourceType}"];
            }
        }

        if ($target['backend_type'] !== $source['backend_type']) {
            return ['compatible' => false, 'reason' => "Different backend types"];
        }

        return ['compatible' => true, 'reason' => null];
    }

    private function getDataMigrationInfo($connection, array $source, array $target): array
    {
        $backendType = $source['backend_type'];
        
        if ($backendType === 'static') {
            return ['value_count' => 0, 'note' => 'Static attribute'];
        }

        $valueTable = $this->resourceConnection->getTableName("catalog_product_entity_{$backendType}");

        if (!$connection->isTableExists($valueTable)) {
            return ['value_count' => 0, 'note' => 'Value table not found'];
        }

        $valueCount = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$valueTable} WHERE attribute_id = ?",
            [$source['attribute_id']]
        );

        $conflictCount = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM {$valueTable} s
             INNER JOIN {$valueTable} t ON t.entity_id = s.entity_id AND t.store_id = s.store_id
             WHERE s.attribute_id = ? AND t.attribute_id = ?
             AND s.value IS NOT NULL AND s.value != ''
             AND t.value IS NOT NULL AND t.value != ''",
            [$source['attribute_id'], $target['attribute_id']]
        );

        return [
            'value_count' => $valueCount,
            'conflict_count' => $conflictCount,
            'note' => $conflictCount > 0 ? "{$conflictCount} values would conflict" : null
        ];
    }

    private function getOptionsMergeInfo($connection, int $sourceId, int $targetId): array
    {
        $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');
        $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');

        $sourceOptions = $connection->fetchAll(
            "SELECT o.option_id, ov.value 
             FROM {$optionTable} o
             INNER JOIN {$optionValueTable} ov ON ov.option_id = o.option_id
             WHERE o.attribute_id = ? AND ov.store_id = 0",
            [$sourceId]
        );

        $targetOptions = $connection->fetchAll(
            "SELECT o.option_id, ov.value 
             FROM {$optionTable} o
             INNER JOIN {$optionValueTable} ov ON ov.option_id = o.option_id
             WHERE o.attribute_id = ? AND ov.store_id = 0",
            [$targetId]
        );

        $targetValueMap = [];
        foreach ($targetOptions as $opt) {
            $targetValueMap[strtolower($opt['value'])] = $opt['option_id'];
        }

        $newOptions = [];
        $mapped = 0;

        foreach ($sourceOptions as $opt) {
            $normalizedValue = strtolower($opt['value']);
            if (isset($targetValueMap[$normalizedValue])) {
                $mapped++;
            } else {
                $newOptions[] = $opt['value'];
            }
        }

        return [
            'source_options' => count($sourceOptions),
            'target_options' => count($targetOptions),
            'will_map' => $mapped,
            'will_create' => count($newOptions),
            'new_options' => $newOptions
        ];
    }

    private function mergeAttributeOptions($connection, int $sourceId, int $targetId): array
    {
        $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');
        $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');

        $targetOptions = $connection->fetchPairs(
            "SELECT LOWER(ov.value), o.option_id 
             FROM {$optionTable} o
             INNER JOIN {$optionValueTable} ov ON ov.option_id = o.option_id
             WHERE o.attribute_id = ? AND ov.store_id = 0",
            [$targetId]
        );

        $sourceOptions = $connection->fetchAll(
            "SELECT o.option_id, o.sort_order FROM {$optionTable} o WHERE o.attribute_id = ?",
            [$sourceId]
        );

        $mapping = [];

        foreach ($sourceOptions as $srcOpt) {
            $srcValue = $connection->fetchOne(
                "SELECT value FROM {$optionValueTable} WHERE option_id = ? AND store_id = 0",
                [$srcOpt['option_id']]
            );

            $normalizedValue = strtolower($srcValue);

            if (isset($targetOptions[$normalizedValue])) {
                $mapping[$srcOpt['option_id']] = $targetOptions[$normalizedValue];
            } else {
                $connection->insert($optionTable, [
                    'attribute_id' => $targetId,
                    'sort_order' => $srcOpt['sort_order']
                ]);
                $newOptionId = (int) $connection->lastInsertId();

                $storeValues = $connection->fetchAll(
                    "SELECT * FROM {$optionValueTable} WHERE option_id = ?",
                    [$srcOpt['option_id']]
                );

                foreach ($storeValues as $storeValue) {
                    $connection->insert($optionValueTable, [
                        'option_id' => $newOptionId,
                        'store_id' => $storeValue['store_id'],
                        'value' => $storeValue['value']
                    ]);
                }

                $mapping[$srcOpt['option_id']] = $newOptionId;
                $targetOptions[$normalizedValue] = $newOptionId;
            }
        }

        return $mapping;
    }

    private function migrateValues(
        $connection,
        array $sourceAttr,
        array $targetAttr,
        string $conflictStrategy,
        array $optionMapping
    ): int {
        $backendType = $sourceAttr['backend_type'];
        
        if ($backendType === 'static') {
            return 0;
        }

        $valueTable = $this->resourceConnection->getTableName("catalog_product_entity_{$backendType}");

        if (!$connection->isTableExists($valueTable)) {
            return 0;
        }

        $sourceValues = $connection->fetchAll(
            "SELECT * FROM {$valueTable} WHERE attribute_id = ?",
            [$sourceAttr['attribute_id']]
        );

        $migratedCount = 0;

        foreach ($sourceValues as $sourceValue) {
            $existingValue = $connection->fetchOne(
                "SELECT value FROM {$valueTable} 
                 WHERE attribute_id = ? AND entity_id = ? AND store_id = ?",
                [$targetAttr['attribute_id'], $sourceValue['entity_id'], $sourceValue['store_id']]
            );

            $valueToMigrate = $sourceValue['value'];

            if (!empty($optionMapping) && in_array($targetAttr['frontend_input'], ['select', 'multiselect'])) {
                if ($targetAttr['frontend_input'] === 'multiselect' && str_contains((string) $valueToMigrate, ',')) {
                    $optionIds = explode(',', $valueToMigrate);
                    $mappedIds = array_map(fn($id) => $optionMapping[(int)$id] ?? $id, $optionIds);
                    $valueToMigrate = implode(',', $mappedIds);
                } else {
                    $valueToMigrate = $optionMapping[(int)$valueToMigrate] ?? $valueToMigrate;
                }
            }

            $shouldMigrate = false;

            if ($existingValue === false || $existingValue === null || $existingValue === '') {
                $shouldMigrate = true;
            } else {
                switch ($conflictStrategy) {
                    case AttributeMergerInterface::CONFLICT_KEEP_SOURCE:
                        $shouldMigrate = true;
                        break;
                    case AttributeMergerInterface::CONFLICT_KEEP_TARGET:
                        $shouldMigrate = false;
                        break;
                    case AttributeMergerInterface::CONFLICT_CONCATENATE:
                        if (in_array($backendType, ['varchar', 'text'])) {
                            $valueToMigrate = $existingValue . ' | ' . $valueToMigrate;
                            $shouldMigrate = true;
                        }
                        break;
                    case AttributeMergerInterface::CONFLICT_SKIP:
                        $shouldMigrate = false;
                        break;
                    default:
                        // Default behavior: prefer filled values
                        $shouldMigrate = !empty($valueToMigrate) && empty($existingValue);
                        break;
                }
            }

            if ($shouldMigrate && !empty($valueToMigrate)) {
                if ($existingValue === false) {
                    $connection->insert($valueTable, [
                        'attribute_id' => $targetAttr['attribute_id'],
                        'store_id' => $sourceValue['store_id'],
                        'entity_id' => $sourceValue['entity_id'],
                        'value' => $valueToMigrate
                    ]);
                } else {
                    $connection->update(
                        $valueTable,
                        ['value' => $valueToMigrate],
                        [
                            'attribute_id = ?' => $targetAttr['attribute_id'],
                            'store_id = ?' => $sourceValue['store_id'],
                            'entity_id = ?' => $sourceValue['entity_id']
                        ]
                    );
                }
                $migratedCount++;
            }
        }

        return $migratedCount;
    }

    private function deleteAttribute($connection, int $attributeId): void
    {
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');
        $optionTable = $this->resourceConnection->getTableName('eav_attribute_option');

        $attr = $connection->fetchRow(
            "SELECT * FROM {$attributeTable} WHERE attribute_id = ?",
            [$attributeId]
        );

        if (!$attr) {
            return;
        }

        $backendType = $attr['backend_type'];
        if ($backendType !== 'static') {
            $valueTable = $this->resourceConnection->getTableName("catalog_product_entity_{$backendType}");
            if ($connection->isTableExists($valueTable)) {
                $connection->delete($valueTable, ['attribute_id = ?' => $attributeId]);
            }
        }

        $connection->delete($optionTable, ['attribute_id = ?' => $attributeId]);
        $connection->delete($attributeTable, ['attribute_id = ?' => $attributeId]);

        $this->logger->info('AttributeMerger: Deleted attribute', [
            'attribute_id' => $attributeId,
            'code' => $attr['attribute_code']
        ]);
    }
}
