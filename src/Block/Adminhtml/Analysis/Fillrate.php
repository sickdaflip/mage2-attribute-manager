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

namespace FlipDev\AttributeManager\Block\Adminhtml\Analysis;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use FlipDev\AttributeManager\Api\FillRateAnalyzerInterface;
use FlipDev\AttributeManager\Helper\Config;

/**
 * Fill-Rate Analysis Block
 */
class Fillrate extends Template
{
    private FillRateAnalyzerInterface $fillRateAnalyzer;
    private Config $config;

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
     * Get attribute fill rates
     */
    public function getAttributeFillRates(): array
    {
        return $this->fillRateAnalyzer->getAttributeFillRates(
            $this->config->getDefaultEntityType()
        );
    }

    /**
     * Get fill rates by set
     */
    public function getFillRatesBySet(): array
    {
        return $this->fillRateAnalyzer->getFillRatesBySet(
            $this->config->getDefaultEntityType()
        );
    }

    /**
     * Get critical attributes
     */
    public function getCriticalAttributes(): array
    {
        return $this->fillRateAnalyzer->getCriticalAttributes(
            $this->config->getDefaultEntityType(),
            $this->config->getCriticalThreshold()
        );
    }

    /**
     * Get unused attributes
     */
    public function getUnusedAttributes(): array
    {
        return $this->fillRateAnalyzer->getUnusedAttributes(
            $this->config->getDefaultEntityType()
        );
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
