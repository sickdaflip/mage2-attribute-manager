<?php
declare(strict_types=1);

namespace FlipDev\AttributeManager\Block\Adminhtml\Attribute;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use FlipDev\AttributeManager\Api\FillRateAnalyzerInterface;
use FlipDev\AttributeManager\Helper\Config;

class Index extends Template
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

    public function getAttributes(): array
    {
        return $this->fillRateAnalyzer->getAttributeFillRates(
            $this->config->getDefaultEntityType()
        );
    }
}
