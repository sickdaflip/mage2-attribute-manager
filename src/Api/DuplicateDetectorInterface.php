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
 * Duplicate Detector Service Interface
 *
 * Detects potential duplicate attributes based on code similarity, label matching, and semantic analysis.
 */
interface DuplicateDetectorInterface
{
    /**
     * Similarity types for detection
     */
    public const SIMILARITY_CODE = 'code';
    public const SIMILARITY_LABEL = 'label';
    public const SIMILARITY_VALUES = 'values';
    public const SIMILARITY_SEMANTIC = 'semantic';

    /**
     * Find potential duplicate attributes
     *
     * @param string $entityTypeCode Entity type
     * @param float $threshold Minimum similarity (0-100)
     * @param array $types Similarity types to check (code, label, values, semantic)
     * @return array Array of duplicate groups [['attributes' => [...], 'similarity' => float, 'type' => string]]
     */
    public function findDuplicates(
        string $entityTypeCode,
        float $threshold = 70.0,
        array $types = [self::SIMILARITY_CODE, self::SIMILARITY_LABEL]
    ): array;

    /**
     * Find attributes similar to a specific attribute
     *
     * @param int $attributeId Source attribute ID
     * @param float $threshold Minimum similarity
     * @return array Similar attributes with similarity scores
     */
    public function findSimilarTo(int $attributeId, float $threshold = 70.0): array;

    /**
     * Check if two attributes are likely duplicates
     *
     * @param int $attributeId1 First attribute ID
     * @param int $attributeId2 Second attribute ID
     * @return array ['is_duplicate' => bool, 'similarity' => float, 'reasons' => array]
     */
    public function compareTwoAttributes(int $attributeId1, int $attributeId2): array;

    /**
     * Get known duplicate patterns (e.g., breite/width/breite_dropdown)
     *
     * @param string $entityTypeCode Entity type
     * @return array Predefined duplicate patterns with suggested merge targets
     */
    public function getKnownPatterns(string $entityTypeCode): array;

    /**
     * Calculate string similarity between two attribute codes
     *
     * @param string $code1 First attribute code
     * @param string $code2 Second attribute code
     * @return float Similarity percentage (0-100)
     */
    public function calculateCodeSimilarity(string $code1, string $code2): float;
}
