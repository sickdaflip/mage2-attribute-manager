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

namespace FlipDev\AttributeManager\Api;

/**
 * Set Migration Service Interface
 *
 * Handles migration of products between attribute sets.
 */
interface SetMigrationInterface
{
    /**
     * Migration criteria types
     */
    public const CRITERIA_CATEGORY = 'category';
    public const CRITERIA_MANUFACTURER = 'manufacturer';
    public const CRITERIA_ATTRIBUTE = 'attribute';
    public const CRITERIA_SKU_PATTERN = 'sku_pattern';

    /**
     * Find products that should be migrated based on criteria
     *
     * @param int $sourceSetId Source attribute set (or null for all)
     * @param int $targetSetId Target attribute set
     * @param array $criteria Migration criteria ['type' => string, 'value' => mixed]
     * @return array Products matching criteria ['product_ids' => array, 'count' => int, 'sample_skus' => array]
     */
    public function findMigrationCandidates(
        ?int $sourceSetId,
        int $targetSetId,
        array $criteria
    ): array;

    /**
     * Preview migration - show what will happen
     *
     * @param int[] $productIds Products to migrate
     * @param int $targetSetId Target attribute set
     * @return array Preview ['products' => int, 'attributes_gained' => array, 'attributes_lost' => array]
     */
    public function previewMigration(array $productIds, int $targetSetId): array;

    /**
     * Execute product migration to new attribute set
     *
     * @param int[] $productIds Products to migrate
     * @param int $targetSetId Target attribute set
     * @param bool $preserveValues Try to preserve attribute values where possible
     * @return array Result ['success' => bool, 'migrated' => int, 'failed' => int, 'errors' => array]
     */
    public function executeMigration(array $productIds, int $targetSetId, bool $preserveValues = true): array;

    /**
     * Get misassigned products (in wrong set based on heuristics)
     *
     * @return array Misassigned products by suggested target set
     */
    public function findMisassignedProducts(): array;

    /**
     * Get distribution of products across attribute sets
     *
     * @return array ['set_id' => ['name' => string, 'count' => int, 'percentage' => float]]
     */
    public function getSetDistribution(): array;

    /**
     * Create migration proposal (for approval workflow)
     *
     * @param int[] $productIds Products to migrate
     * @param int $targetSetId Target attribute set
     * @param string $reason Reason for migration
     * @return int Proposal ID for approval queue
     */
    public function createMigrationProposal(array $productIds, int $targetSetId, string $reason = ''): int;

    /**
     * Suggest optimal attribute set for a product
     *
     * @param int $productId Product to analyze
     * @return array ['suggested_set_id' => int, 'confidence' => float, 'reasons' => array]
     */
    public function suggestAttributeSet(int $productId): array;
}
