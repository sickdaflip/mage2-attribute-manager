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

namespace FlipDev\AttributeManager\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use FlipDev\AttributeManager\Api\FillRateAnalyzerInterface;
use FlipDev\AttributeManager\Helper\Config;

/**
 * Dashboard Block
 *
 * Main dashboard container block.
 */
class Dashboard extends Template
{
    /**
     * @var FillRateAnalyzerInterface
     */
    private FillRateAnalyzerInterface $fillRateAnalyzer;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var array|null
     */
    private ?array $summaryCache = null;

    /**
     * @param Context $context
     * @param FillRateAnalyzerInterface $fillRateAnalyzer
     * @param Config $config
     * @param array $data
     */
    public function __construct(
        Context $context,
        FillRateAnalyzerInterface $fillRateAnalyzer,
        Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->fillRateAnalyzer = $fillRateAnalyzer;
        $this->config = $config;
    }

    /**
     * Get summary statistics
     */
    public function getSummary(): array
    {
        if ($this->summaryCache === null) {
            $this->summaryCache = $this->fillRateAnalyzer->getSummaryStatistics(
                $this->config->getDefaultEntityType()
            );
        }
        return $this->summaryCache;
    }

    /**
     * Check if module is enabled
     */
    public function isModuleEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Get fill-rate analysis URL
     */
    public function getFillRateUrl(): string
    {
        return $this->getUrl('flipdev_attributes/analysis/fillrate');
    }

    /**
     * Get duplicate detection URL
     */
    public function getDuplicatesUrl(): string
    {
        return $this->getUrl('flipdev_attributes/analysis/duplicates');
    }

    /**
     * Get attribute list URL
     */
    public function getAttributesUrl(): string
    {
        return $this->getUrl('flipdev_attributes/attribute/index');
    }

    /**
     * Get attribute sets URL
     */
    public function getSetsUrl(): string
    {
        return $this->getUrl('flipdev_attributes/set/index');
    }

    /**
     * Get approval queue URL
     */
    public function getApprovalUrl(): string
    {
        return $this->getUrl('flipdev_attributes/approval/index');
    }

    /**
     * Get critical threshold
     */
    public function getCriticalThreshold(): float
    {
        return $this->config->getCriticalThreshold();
    }

    /**
     * Get warning threshold
     */
    public function getWarningThreshold(): float
    {
        return $this->config->getWarningThreshold();
    }
}
