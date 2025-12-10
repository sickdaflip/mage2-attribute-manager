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
 * Fill-Rate Analyzer Service Interface
 *
 * Analyzes attribute fill rates across products, categories, or other entities.
 */
interface FillRateAnalyzerInterface
{
    /**
     * Get fill rates for all attributes of given entity type
     *
     * @param string $entityTypeCode Entity type (catalog_product, catalog_category, etc.)
     * @param int|null $attributeSetId Optional: Filter by attribute set
     * @return array Array of [attribute_id => ['code' => string, 'label' => string, 'filled' => int, 'total' => int, 'rate' => float]]
     */
    public function getAttributeFillRates(string $entityTypeCode, ?int $attributeSetId = null): array;

    /**
     * Get fill rates grouped by attribute set
     *
     * @param string $entityTypeCode Entity type
     * @return array Array of [set_id => ['name' => string, 'product_count' => int, 'attributes' => array]]
     */
    public function getFillRatesBySet(string $entityTypeCode): array;

    /**
     * Get fill rates grouped by manufacturer (for product attributes)
     *
     * @param string $attributeCode Attribute to analyze
     * @return array Array of [manufacturer => ['filled' => int, 'total' => int, 'rate' => float]]
     */
    public function getFillRatesByManufacturer(string $attributeCode): array;

    /**
     * Get attributes below threshold
     *
     * @param string $entityTypeCode Entity type
     * @param float $threshold Minimum fill rate (0-100)
     * @return array Attributes with fill rate below threshold
     */
    public function getCriticalAttributes(string $entityTypeCode, float $threshold): array;

    /**
     * Get unused attributes (0% fill rate)
     *
     * @param string $entityTypeCode Entity type
     * @return array Attributes with no values
     */
    public function getUnusedAttributes(string $entityTypeCode): array;

    /**
     * Generate summary statistics
     *
     * @param string $entityTypeCode Entity type
     * @return array ['total_attributes' => int, 'avg_fill_rate' => float, 'critical' => int, 'warning' => int, 'healthy' => int]
     */
    public function getSummaryStatistics(string $entityTypeCode): array;
}
