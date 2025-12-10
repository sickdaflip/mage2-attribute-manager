<?php
declare(strict_types=1);

namespace FlipDev\AttributeManager\Block\Adminhtml\Analysis;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use FlipDev\AttributeManager\Api\DuplicateDetectorInterface;
use FlipDev\AttributeManager\Helper\Config;

class Duplicates extends Template
{
    private DuplicateDetectorInterface $duplicateDetector;
    private Config $config;

    public function __construct(
        Context $context,
        DuplicateDetectorInterface $duplicateDetector,
        Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->duplicateDetector = $duplicateDetector;
        $this->config = $config;
    }

    public function findDuplicates(): array
    {
        return $this->duplicateDetector->findDuplicates(
            $this->config->getDefaultEntityType(),
            $this->config->getSimilarityThreshold()
        );
    }
}
