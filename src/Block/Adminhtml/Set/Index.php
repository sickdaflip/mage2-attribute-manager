<?php
declare(strict_types=1);

namespace FlipDev\AttributeManager\Block\Adminhtml\Set;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use FlipDev\AttributeManager\Api\SetMigrationInterface;

class Index extends Template
{
    private SetMigrationInterface $setMigration;

    public function __construct(
        Context $context,
        SetMigrationInterface $setMigration,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setMigration = $setMigration;
    }

    public function getSetDistribution(): array
    {
        return $this->setMigration->getSetDistribution();
    }
}
