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

use FlipDev\AttributeManager\Api\ApprovalManagerInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Approval Status Source Model
 */
class ApprovalStatus implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => ApprovalManagerInterface::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => ApprovalManagerInterface::STATUS_APPROVED, 'label' => __('Approved')],
            ['value' => ApprovalManagerInterface::STATUS_REJECTED, 'label' => __('Rejected')],
            ['value' => ApprovalManagerInterface::STATUS_EXECUTED, 'label' => __('Executed')],
            ['value' => ApprovalManagerInterface::STATUS_FAILED, 'label' => __('Failed')],
        ];
    }
}
