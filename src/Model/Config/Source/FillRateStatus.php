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
 * Fill Rate Status Source Model
 */
class FillRateStatus implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'critical', 'label' => __('Critical (< 25%)')],
            ['value' => 'warning', 'label' => __('Warning (25-50%)')],
            ['value' => 'healthy', 'label' => __('Healthy (> 50%)')],
        ];
    }
}
