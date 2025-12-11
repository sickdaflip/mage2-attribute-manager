# FlipDev_AttributeManager - Events Documentation

This document describes the events dispatched by the FlipDev_AttributeManager module that you can observe.

## Available Events

### Fill-Rate Analysis Events

#### `flipdev_attributes_fillrate_calculated`
Dispatched after fill-rate analysis is completed.

**Event Data:**
- `entity_type` (string) - The entity type analyzed
- `fill_rates` (array) - Array of fill-rate results
- `critical_count` (int) - Number of critical attributes
- `warning_count` (int) - Number of warning attributes

**Example Observer:**
```php
namespace Vendor\Module\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class FillRateObserver implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        $entityType = $observer->getData('entity_type');
        $fillRates = $observer->getData('fill_rates');
        $criticalCount = $observer->getData('critical_count');

        // Your custom logic here
    }
}
```

### Duplicate Detection Events

#### `flipdev_attributes_duplicates_found`
Dispatched when duplicate attributes are detected.

**Event Data:**
- `entity_type` (string) - The entity type analyzed
- `duplicates` (array) - Array of duplicate groups
- `total_groups` (int) - Total number of duplicate groups

### Format Chaos Events

#### `flipdev_attributes_chaos_detected`
Dispatched when format inconsistencies are detected.

**Event Data:**
- `entity_type` (string) - The entity type analyzed
- `chaotic_attributes` (array) - Array of attributes with format issues
- `chaos_count` (int) - Number of chaotic attributes

### Attribute Merge Events

#### `flipdev_attributes_merge_before`
Dispatched before attribute merge operation.

**Event Data:**
- `source_attributes` (array) - Source attribute IDs
- `target_attribute` (int) - Target attribute ID
- `conflict_strategy` (string) - Conflict resolution strategy
- `delete_source` (bool) - Whether to delete source attributes

#### `flipdev_attributes_merge_after`
Dispatched after successful attribute merge.

**Event Data:**
- `merge_log_id` (int) - Merge log ID
- `values_migrated` (int) - Number of values migrated
- `options_merged` (int) - Number of options merged
- `sources_deleted` (array) - Deleted source attribute IDs

### Set Migration Events

#### `flipdev_attributes_migration_before`
Dispatched before set migration.

**Event Data:**
- `product_ids` (array) - Products to migrate
- `target_set_id` (int) - Target attribute set ID
- `preserve_values` (bool) - Whether to preserve values

#### `flipdev_attributes_migration_after`
Dispatched after successful migration.

**Event Data:**
- `migrated_count` (int) - Number of products migrated
- `failed_count` (int) - Number of failed migrations
- `target_set_id` (int) - Target attribute set ID

### Approval Workflow Events

#### `flipdev_attributes_approval_created`
Dispatched when approval proposal is created.

**Event Data:**
- `proposal_id` (int) - Proposal ID
- `type` (string) - Proposal type
- `data` (array) - Proposal data

#### `flipdev_attributes_approval_approved`
Dispatched when proposal is approved.

**Event Data:**
- `proposal_id` (int) - Proposal ID
- `approved_by` (int|null) - User ID who approved
- `comment` (string) - Approval comment

#### `flipdev_attributes_approval_rejected`
Dispatched when proposal is rejected.

**Event Data:**
- `proposal_id` (int) - Proposal ID
- `rejected_by` (int|null) - User ID who rejected
- `reason` (string) - Rejection reason

#### `flipdev_attributes_approval_executed`
Dispatched after proposal execution.

**Event Data:**
- `proposal_id` (int) - Proposal ID
- `result` (array) - Execution result

## Registering Observers

To observe these events, add them to your module's `etc/events.xml`:

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Event/etc/events.xsd">
    <event name="flipdev_attributes_fillrate_calculated">
        <observer name="vendor_module_fillrate" instance="Vendor\Module\Observer\FillRateObserver"/>
    </event>
</config>
```

## Example Use Cases

### 1. Send Slack Notification on Critical Attributes

```php
class SlackNotificationObserver implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        $criticalCount = $observer->getData('critical_count');

        if ($criticalCount > 20) {
            $this->slackService->sendMessage(
                "⚠️ {$criticalCount} critical attributes detected!"
            );
        }
    }
}
```

### 2. Log Merge Operations to External System

```php
class MergeAuditObserver implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        $mergeLogId = $observer->getData('merge_log_id');
        $valuesMigrated = $observer->getData('values_migrated');

        $this->auditService->logMerge([
            'log_id' => $mergeLogId,
            'values' => $valuesMigrated,
            'timestamp' => time(),
        ]);
    }
}
```

### 3. Auto-Execute Low-Risk Approvals

```php
class AutoApprovalObserver implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        $proposalId = $observer->getData('proposal_id');
        $data = $observer->getData('data');

        if ($this->isLowRisk($data)) {
            $this->approvalManager->approveProposal($proposalId);
        }
    }
}
```

## Event Dispatch Locations

Events are dispatched in the following service methods:

| Service | Method | Events |
|---------|--------|--------|
| FillRateAnalyzer | `getAttributeFillRates()` | `flipdev_attributes_fillrate_calculated` |
| DuplicateDetector | `findDuplicates()` | `flipdev_attributes_duplicates_found` |
| FormatChaosAnalyzer | `analyzeAttributes()` | `flipdev_attributes_chaos_detected` |
| AttributeMerger | `executeMerge()` | `_merge_before`, `_merge_after` |
| SetMigration | `executeMigration()` | `_migration_before`, `_migration_after` |
| ApprovalManager | Various | `_approval_created`, `_approval_approved`, etc. |

## Notes

- All events are prefixed with `flipdev_attributes_`
- Event data is passed via the `Observer` object using `getData()`
- Events are dispatched after successful operations (unless marked as `_before`)
- Failed operations do not dispatch `_after` events
