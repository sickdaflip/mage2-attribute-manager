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

namespace FlipDev\AttributeManager\Api;

/**
 * Approval Manager Service Interface
 *
 * Handles approval workflow for bulk attribute operations.
 */
interface ApprovalManagerInterface
{
    /**
     * Proposal statuses
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_FAILED = 'failed';

    /**
     * Proposal types
     */
    public const TYPE_MERGE = 'merge';
    public const TYPE_MIGRATION = 'migration';
    public const TYPE_DELETE = 'delete';

    /**
     * Create approval proposal
     *
     * @param string $type Proposal type (merge, migration, delete)
     * @param array $data Proposal data (operation-specific)
     * @param string $reason Reason for the change
     * @param int|null $createdBy User ID who created proposal
     * @return int Proposal ID
     */
    public function createProposal(
        string $type,
        array $data,
        string $reason = '',
        ?int $createdBy = null
    ): int;

    /**
     * Get proposal by ID
     *
     * @param int $proposalId Proposal ID
     * @return array|null Proposal data
     */
    public function getProposal(int $proposalId): ?array;

    /**
     * Get all pending proposals
     *
     * @return array List of pending proposals
     */
    public function getPendingProposals(): array;

    /**
     * Approve proposal
     *
     * @param int $proposalId Proposal ID
     * @param int|null $approvedBy User ID who approved
     * @param string $comment Approval comment
     * @return bool Success status
     */
    public function approveProposal(int $proposalId, ?int $approvedBy = null, string $comment = ''): bool;

    /**
     * Reject proposal
     *
     * @param int $proposalId Proposal ID
     * @param int|null $rejectedBy User ID who rejected
     * @param string $reason Rejection reason
     * @return bool Success status
     */
    public function rejectProposal(int $proposalId, ?int $rejectedBy = null, string $reason = ''): bool;

    /**
     * Execute approved proposal
     *
     * @param int $proposalId Proposal ID
     * @return array Execution result
     */
    public function executeProposal(int $proposalId): array;

    /**
     * Check if proposal needs approval based on thresholds
     *
     * @param string $type Proposal type
     * @param array $data Proposal data
     * @return bool True if approval needed, false for auto-approve
     */
    public function needsApproval(string $type, array $data): bool;

    /**
     * Send notification email for proposal
     *
     * @param int $proposalId Proposal ID
     * @param string $action Action type (created, approved, rejected)
     * @return bool Success status
     */
    public function sendNotification(int $proposalId, string $action): bool;

    /**
     * Get proposal history/audit trail
     *
     * @param int $proposalId Proposal ID
     * @return array History entries
     */
    public function getProposalHistory(int $proposalId): array;

    /**
     * Delete proposal
     *
     * @param int $proposalId Proposal ID
     * @return bool Success status
     */
    public function deleteProposal(int $proposalId): bool;
}
