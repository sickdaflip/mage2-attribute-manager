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
use Magento\Backend\Block\Template\Context;
use FlipDev\AttributeManager\Api\FillRateAnalyzerInterface;
use FlipDev\AttributeManager\Helper\Config;

/**
 * Dashboard Fill Rate Chart Block
 */
class FillRateChart extends Template
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

    public function getFillRatesBySet(): array
    {
        return $this->fillRateAnalyzer->getFillRatesBySet(
            $this->config->getDefaultEntityType()
        );
    }
}
