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
 * Attribute Merger Service Interface
 *
 * Handles merging of duplicate attributes with data migration.
 */
interface AttributeMergerInterface
{
    /**
     * Merge conflict strategies
     */
    public const CONFLICT_KEEP_TARGET = 'keep_target';      // Keep existing target value
    public const CONFLICT_KEEP_SOURCE = 'keep_source';      // Overwrite with source value
    public const CONFLICT_CONCATENATE = 'concatenate';      // Combine both values
    public const CONFLICT_SKIP = 'skip';                    // Skip conflicting products

    /**
     * Preview merge operation without executing
     *
     * @param int[] $sourceAttributeIds Attributes to merge from
     * @param int $targetAttributeId Attribute to merge into
     * @return array Preview data ['products_affected' => int, 'values_to_migrate' => int, 'conflicts' => array]
     */
    public function previewMerge(array $sourceAttributeIds, int $targetAttributeId): array;

    /**
     * Execute attribute merge with data migration
     *
     * @param int[] $sourceAttributeIds Attributes to merge from
     * @param int $targetAttributeId Attribute to merge into
     * @param string $conflictStrategy How to handle conflicting values
     * @param bool $deleteSource Delete source attributes after merge
     * @return array Result ['success' => bool, 'migrated' => int, 'skipped' => int, 'errors' => array]
     */
    public function executeMerge(
        array $sourceAttributeIds,
        int $targetAttributeId,
        string $conflictStrategy = self::CONFLICT_KEEP_TARGET,
        bool $deleteSource = false
    ): array;

    /**
     * Merge option values for select/multiselect attributes
     *
     * @param int $sourceAttributeId Source attribute with options
     * @param int $targetAttributeId Target attribute
     * @param array $optionMapping [source_option_id => target_option_id] or empty for auto-match
     * @return array Merged option mapping
     */
    public function mergeOptions(int $sourceAttributeId, int $targetAttributeId, array $optionMapping = []): array;

    /**
     * Create merge proposal (for approval workflow)
     *
     * @param int[] $sourceAttributeIds Attributes to merge from
     * @param int $targetAttributeId Attribute to merge into
     * @param string $conflictStrategy Conflict handling strategy
     * @return int Proposal ID for approval queue
     */
    public function createMergeProposal(
        array $sourceAttributeIds,
        int $targetAttributeId,
        string $conflictStrategy = self::CONFLICT_KEEP_TARGET
    ): int;

    /**
     * Rollback a merge operation
     *
     * @param int $mergeLogId ID of the merge operation to rollback
     * @return bool Success status
     */
    public function rollbackMerge(int $mergeLogId): bool;
}
