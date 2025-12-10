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

namespace FlipDev\AttributeManager\Block\Adminhtml\Dashboard;

use Magento\Backend\Block\Template;

/**
 * Dashboard Recent Activity Block
 */
class RecentActivity extends Template
{
    /**
     * Get recent activity (placeholder implementation)
     */
    public function getRecentActivity(): array
    {
        // This would normally fetch from a log table
        // Returning empty array for now
        return [];
    }
}
