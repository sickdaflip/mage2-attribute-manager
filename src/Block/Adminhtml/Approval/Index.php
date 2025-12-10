<?php
declare(strict_types=1);

namespace FlipDev\AttributeManager\Block\Adminhtml\Approval;

use Magento\Backend\Block\Template;

class Index extends Template
{
    public function getPendingApprovals(): array
    {
        // Placeholder - would fetch from approval queue table
        return [];
    }
}
