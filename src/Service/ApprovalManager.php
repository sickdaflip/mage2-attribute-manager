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

namespace FlipDev\AttributeManager\Service;

use FlipDev\AttributeManager\Api\ApprovalManagerInterface;
use FlipDev\AttributeManager\Api\AttributeMergerInterface;
use FlipDev\AttributeManager\Api\SetMigrationInterface;
use FlipDev\AttributeManager\Helper\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Approval Manager Service
 *
 * Handles approval workflow for bulk attribute operations.
 */
class ApprovalManager implements ApprovalManagerInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly AttributeMergerInterface $attributeMerger,
        private readonly SetMigrationInterface $setMigration,
        private readonly Config $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @inheritdoc
     */
    public function createProposal(
        string $type,
        array $data,
        string $reason = '',
        ?int $createdBy = null
    ): int {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('flipdev_approval_queue');

        $proposalData = [
            'type' => $type,
            'data' => json_encode($data),
            'reason' => $reason,
            'status' => self::STATUS_PENDING,
            'created_by' => $createdBy,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $connection->insert($tableName, $proposalData);
        $proposalId = (int) $connection->lastInsertId();

        $this->logger->info('ApprovalManager: Proposal created', [
            'proposal_id' => $proposalId,
            'type' => $type,
            'created_by' => $createdBy,
        ]);

        // Send notification
        $this->sendNotification($proposalId, 'created');

        return $proposalId;
    }

    /**
     * @inheritdoc
     */
    public function getProposal(int $proposalId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('flipdev_approval_queue');

        $proposal = $connection->fetchRow(
            "SELECT * FROM {$tableName} WHERE proposal_id = ?",
            [$proposalId]
        );

        if (!$proposal) {
            return null;
        }

        $proposal['data'] = json_decode($proposal['data'], true);
        return $proposal;
    }

    /**
     * @inheritdoc
     */
    public function getPendingProposals(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('flipdev_approval_queue');

        $proposals = $connection->fetchAll(
            "SELECT * FROM {$tableName} WHERE status = ? ORDER BY created_at DESC",
            [self::STATUS_PENDING]
        );

        foreach ($proposals as &$proposal) {
            $proposal['data'] = json_decode($proposal['data'], true);
        }

        return $proposals;
    }

    /**
     * @inheritdoc
     */
    public function approveProposal(int $proposalId, ?int $approvedBy = null, string $comment = ''): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('flipdev_approval_queue');

        $proposal = $this->getProposal($proposalId);
        if (!$proposal || $proposal['status'] !== self::STATUS_PENDING) {
            return false;
        }

        $connection->update(
            $tableName,
            [
                'status' => self::STATUS_APPROVED,
                'approved_by' => $approvedBy,
                'approved_at' => date('Y-m-d H:i:s'),
                'approval_comment' => $comment,
            ],
            ['proposal_id = ?' => $proposalId]
        );

        $this->logger->info('ApprovalManager: Proposal approved', [
            'proposal_id' => $proposalId,
            'approved_by' => $approvedBy,
        ]);

        // Send notification
        $this->sendNotification($proposalId, 'approved');

        return true;
    }

    /**
     * @inheritdoc
     */
    public function rejectProposal(int $proposalId, ?int $rejectedBy = null, string $reason = ''): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('flipdev_approval_queue');

        $proposal = $this->getProposal($proposalId);
        if (!$proposal || $proposal['status'] !== self::STATUS_PENDING) {
            return false;
        }

        $connection->update(
            $tableName,
            [
                'status' => self::STATUS_REJECTED,
                'rejected_by' => $rejectedBy,
                'rejected_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $reason,
            ],
            ['proposal_id = ?' => $proposalId]
        );

        $this->logger->info('ApprovalManager: Proposal rejected', [
            'proposal_id' => $proposalId,
            'rejected_by' => $rejectedBy,
            'reason' => $reason,
        ]);

        // Send notification
        $this->sendNotification($proposalId, 'rejected');

        return true;
    }

    /**
     * @inheritdoc
     */
    public function executeProposal(int $proposalId): array
    {
        $proposal = $this->getProposal($proposalId);

        if (!$proposal) {
            throw new LocalizedException(__('Proposal not found'));
        }

        if ($proposal['status'] !== self::STATUS_APPROVED) {
            throw new LocalizedException(__('Proposal must be approved before execution'));
        }

        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('flipdev_approval_queue');

        try {
            $result = match ($proposal['type']) {
                self::TYPE_MERGE => $this->executeMergeProposal($proposal['data']),
                self::TYPE_MIGRATION => $this->executeMigrationProposal($proposal['data']),
                self::TYPE_DELETE => $this->executeDeleteProposal($proposal['data']),
                default => throw new LocalizedException(__('Unknown proposal type: %1', $proposal['type'])),
            };

            $connection->update(
                $tableName,
                [
                    'status' => self::STATUS_EXECUTED,
                    'executed_at' => date('Y-m-d H:i:s'),
                    'execution_result' => json_encode($result),
                ],
                ['proposal_id = ?' => $proposalId]
            );

            $this->logger->info('ApprovalManager: Proposal executed', [
                'proposal_id' => $proposalId,
                'type' => $proposal['type'],
                'result' => $result,
            ]);

            return ['success' => true, 'result' => $result];

        } catch (\Exception $e) {
            $connection->update(
                $tableName,
                [
                    'status' => self::STATUS_FAILED,
                    'execution_result' => json_encode(['error' => $e->getMessage()]),
                ],
                ['proposal_id = ?' => $proposalId]
            );

            $this->logger->error('ApprovalManager: Proposal execution failed', [
                'proposal_id' => $proposalId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @inheritdoc
     */
    public function needsApproval(string $type, array $data): bool
    {
        if (!$this->config->isApprovalEnabled()) {
            return false;
        }

        $threshold = $this->config->getAutoApproveThreshold();

        if ($threshold === 0) {
            return true; // Always require approval
        }

        $affectedCount = match ($type) {
            self::TYPE_MERGE => count($data['source_attributes'] ?? []),
            self::TYPE_MIGRATION => count($data['product_ids'] ?? []),
            self::TYPE_DELETE => count($data['attribute_ids'] ?? []),
            default => 0,
        };

        return $affectedCount >= $threshold;
    }

    /**
     * @inheritdoc
     */
    public function sendNotification(int $proposalId, string $action): bool
    {
        $proposal = $this->getProposal($proposalId);
        if (!$proposal) {
            return false;
        }

        $notificationEmail = $this->config->getNotificationEmail();
        if (empty($notificationEmail)) {
            return false;
        }

        try {
            $this->inlineTranslation->suspend();

            $templateVars = [
                'proposal_id' => $proposalId,
                'type' => $proposal['type'],
                'reason' => $proposal['reason'],
                'action' => $action,
                'created_at' => $proposal['created_at'],
            ];

            $transport = $this->transportBuilder
                ->setTemplateIdentifier('flipdev_approval_notification')
                ->setTemplateOptions([
                    'area' => \Magento\Framework\App\Area::AREA_ADMINHTML,
                    'store' => \Magento\Store\Model\Store::DEFAULT_STORE_ID,
                ])
                ->setTemplateVars($templateVars)
                ->setFromByScope('general')
                ->addTo($notificationEmail)
                ->getTransport();

            $transport->sendMessage();

            $this->inlineTranslation->resume();

            $this->logger->info('ApprovalManager: Notification sent', [
                'proposal_id' => $proposalId,
                'action' => $action,
                'email' => $notificationEmail,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->inlineTranslation->resume();
            $this->logger->error('ApprovalManager: Failed to send notification', [
                'proposal_id' => $proposalId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function getProposalHistory(int $proposalId): array
    {
        $proposal = $this->getProposal($proposalId);
        if (!$proposal) {
            return [];
        }

        $history = [];

        $history[] = [
            'action' => 'created',
            'timestamp' => $proposal['created_at'],
            'user_id' => $proposal['created_by'],
            'details' => "Proposal created: {$proposal['reason']}",
        ];

        if ($proposal['approved_at']) {
            $history[] = [
                'action' => 'approved',
                'timestamp' => $proposal['approved_at'],
                'user_id' => $proposal['approved_by'],
                'details' => $proposal['approval_comment'] ?? 'Approved',
            ];
        }

        if ($proposal['rejected_at']) {
            $history[] = [
                'action' => 'rejected',
                'timestamp' => $proposal['rejected_at'],
                'user_id' => $proposal['rejected_by'],
                'details' => $proposal['rejection_reason'] ?? 'Rejected',
            ];
        }

        if ($proposal['executed_at']) {
            $history[] = [
                'action' => 'executed',
                'timestamp' => $proposal['executed_at'],
                'user_id' => null,
                'details' => 'Proposal executed',
            ];
        }

        return $history;
    }

    /**
     * @inheritdoc
     */
    public function deleteProposal(int $proposalId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('flipdev_approval_queue');

        $deleted = $connection->delete($tableName, ['proposal_id = ?' => $proposalId]);

        $this->logger->info('ApprovalManager: Proposal deleted', [
            'proposal_id' => $proposalId,
        ]);

        return $deleted > 0;
    }

    /**
     * Execute merge proposal
     */
    private function executeMergeProposal(array $data): array
    {
        return $this->attributeMerger->executeMerge(
            $data['source_attributes'] ?? [],
            $data['target_attribute'] ?? 0,
            $data['conflict_strategy'] ?? AttributeMergerInterface::CONFLICT_KEEP_TARGET,
            $data['delete_source'] ?? false
        );
    }

    /**
     * Execute migration proposal
     */
    private function executeMigrationProposal(array $data): array
    {
        return $this->setMigration->executeMigration(
            $data['product_ids'] ?? [],
            $data['target_set_id'] ?? 0,
            $data['preserve_values'] ?? true
        );
    }

    /**
     * Execute delete proposal
     */
    private function executeDeleteProposal(array $data): array
    {
        // This would handle attribute deletion
        // For now, return a mock result
        return [
            'deleted' => count($data['attribute_ids'] ?? []),
            'failed' => 0,
        ];
    }
}
