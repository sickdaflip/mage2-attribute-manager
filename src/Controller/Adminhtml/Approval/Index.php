<?php
declare(strict_types=1);

namespace FlipDev\AttributeManager\Controller\Adminhtml\Approval;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'FlipDev_AttributeManager::approval';

    private PageFactory $resultPageFactory;

    public function __construct(Context $context, PageFactory $resultPageFactory)
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('FlipDev_AttributeManager::approval');
        $resultPage->getConfig()->getTitle()->prepend(__('Approval Queue'));
        return $resultPage;
    }
}
