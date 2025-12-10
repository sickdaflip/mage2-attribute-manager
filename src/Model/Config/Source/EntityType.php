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

namespace FlipDev\AttributeManager\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Entity Type Source Model
 *
 * Provides available entity types for configuration dropdown
 */
class EntityType implements OptionSourceInterface
{
    /**
     * Available entity types
     */
    private const ENTITY_TYPES = [
        'catalog_product' => 'Product',
        'catalog_category' => 'Category',
        'customer' => 'Customer',
        'customer_address' => 'Customer Address',
    ];

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (self::ENTITY_TYPES as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => __($label)
            ];
        }
        return $options;
    }
}
