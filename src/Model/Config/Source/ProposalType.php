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
 * Proposal Type Source Model
 */
class ProposalType implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => ApprovalManagerInterface::TYPE_MERGE, 'label' => __('Attribute Merge')],
            ['value' => ApprovalManagerInterface::TYPE_MIGRATION, 'label' => __('Set Migration')],
            ['value' => ApprovalManagerInterface::TYPE_DELETE, 'label' => __('Attribute Delete')],
        ];
    }
}
