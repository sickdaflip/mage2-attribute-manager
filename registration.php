<?php
/**
 * FlipDev_AttributeManager
 *
 * Comprehensive EAV attribute management for Magento 2
 * - Fill-rate analysis & dashboards
 * - Attribute consolidation & migration
 * - Format normalization
 * - Approval workflows
 *
 * @category  FlipDev
 * @package   FlipDev_AttributeManager
 * @author    Philipp Breitsprecher <philippbreitsprecher@gmail.com>
 * @copyright Copyright (c) 2024-2025 FlipDev
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'FlipDev_AttributeManager',
    __DIR__
);
