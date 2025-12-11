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
use Magento\Ui\Component\MassAction\Filter;

/**
 * Mass Reject Proposals Controller
 */
class MassReject extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level
     */
    public const ADMIN_RESOURCE = 'FlipDev_AttributeManager::approval';

    public function __construct(
        Context $context,
        private readonly ApprovalManagerInterface $approvalManager,
        private readonly Session $authSession,
        private readonly Filter $filter
    ) {
        parent::__construct($context);
    }

    /**
     * Execute action
     */
    public function execute()
    {
        $selected = $this->getRequest()->getParam('selected', []);
        $excluded = $this->getRequest()->getParam('excluded', []);

        if (empty($selected) && empty($excluded)) {
            $this->messageManager->addErrorMessage(__('Please select proposals to reject.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
        }

        $proposalIds = !empty($excluded) ? $excluded : $selected;
        $userId = (int) $this->authSession->getUser()->getId();
        $successCount = 0;
        $failCount = 0;

        foreach ($proposalIds as $proposalId) {
            try {
                if ($this->approvalManager->rejectProposal((int) $proposalId, $userId, 'Mass rejected')) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            } catch (\Exception $e) {
                $failCount++;
            }
        }

        if ($successCount > 0) {
            $this->messageManager->addSuccessMessage(__('%1 proposal(s) have been rejected.', $successCount));
        }
        if ($failCount > 0) {
            $this->messageManager->addErrorMessage(__('%1 proposal(s) failed to reject.', $failCount));
        }

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
    }
}
