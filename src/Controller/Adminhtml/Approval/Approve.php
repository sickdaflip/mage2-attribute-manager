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
 * Approve Proposal Controller
 */
class Approve extends Action implements HttpPostActionInterface
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
        $comment = (string) $this->getRequest()->getParam('comment', '');

        if (!$proposalId) {
            $this->messageManager->addErrorMessage(__('Invalid proposal ID.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
        }

        try {
            $userId = (int) $this->authSession->getUser()->getId();
            $success = $this->approvalManager->approveProposal($proposalId, $userId, $comment);

            if ($success) {
                $this->messageManager->addSuccessMessage(__('Proposal has been approved.'));
            } else {
                $this->messageManager->addErrorMessage(__('Failed to approve proposal.'));
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error: %1', $e->getMessage()));
        }

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
    }
}
