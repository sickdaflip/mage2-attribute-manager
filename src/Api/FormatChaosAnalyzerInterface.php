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
 * Format Chaos Analyzer Service Interface
 *
 * Detects inconsistent formatting patterns in text attributes.
 */
interface FormatChaosAnalyzerInterface
{
    /**
     * Analyze attributes for format inconsistencies
     *
     * @param string $entityType Entity type code (default: catalog_product)
     * @param int $limit Maximum number of attributes to analyze
     * @return array Analysis results with chaos score and patterns
     */
    public function analyzeAttributes(string $entityType = 'catalog_product', int $limit = 20): array;

    /**
     * Analyze specific attribute for format chaos
     *
     * @param string $attributeCode Attribute code to analyze
     * @param string $entityType Entity type code
     * @return array Detailed analysis with examples and patterns
     */
    public function analyzeAttribute(string $attributeCode, string $entityType = 'catalog_product'): array;

    /**
     * Detect unit inconsistencies (mm vs cm, W vs kW, etc.)
     *
     * @param array $values Array of attribute values
     * @return array Detected unit patterns and inconsistencies
     */
    public function detectUnitInconsistencies(array $values): array;

    /**
     * Detect spacing inconsistencies
     *
     * @param array $values Array of attribute values
     * @return array Detected spacing patterns
     */
    public function detectSpacingIssues(array $values): array;

    /**
     * Detect temperature format variations
     *
     * @param array $values Array of attribute values
     * @return array Detected temperature formats
     */
    public function detectTemperatureFormats(array $values): array;

    /**
     * Calculate chaos score for attribute values
     *
     * @param array $values Array of attribute values
     * @return float Chaos score (0-100, higher = more chaotic)
     */
    public function calculateChaosScore(array $values): float;

    /**
     * Suggest standardized format for attribute
     *
     * @param string $attributeCode Attribute code
     * @param string $entityType Entity type code
     * @return array Suggested format and transformation rules
     */
    public function suggestStandardFormat(string $attributeCode, string $entityType = 'catalog_product'): array;
}
