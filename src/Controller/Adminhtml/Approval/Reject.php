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

namespace FlipDev\AttributeManager\Controller\Adminhtml\Approval;

use FlipDev\AttributeManager\Api\ApprovalManagerInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Reject Proposal Controller
 */
class Reject extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level
     */
    public const ADMIN_RESOURCE = 'FlipDev_AttributeManager::approval';

    public function __construct(
        Context $context,
        private readonly ApprovalManagerInterface $approvalManager,
        private readonly Session $authSession
    ) {
        parent::__construct($context);
    }

    /**
     * Execute action
     */
    public function execute()
    {
        $proposalId = (int) $this->getRequest()->getParam('id');
        $reason = (string) $this->getRequest()->getParam('reason', '');

        if (!$proposalId) {
            $this->messageManager->addErrorMessage(__('Invalid proposal ID.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
        }

        try {
            $userId = (int) $this->authSession->getUser()->getId();
            $success = $this->approvalManager->rejectProposal($proposalId, $userId, $reason);

            if ($success) {
                $this->messageManager->addSuccessMessage(__('Proposal has been rejected.'));
            } else {
                $this->messageManager->addErrorMessage(__('Failed to reject proposal.'));
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error: %1', $e->getMessage()));
        }

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
    }
}
