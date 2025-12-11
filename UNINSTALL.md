# FlipDev_AttributeManager - Uninstallation Guide

This document provides detailed information about the module uninstallation process.

## Automatic Uninstallation

The module includes a proper `Setup/Uninstall.php` script that automatically removes all module data.

### Quick Uninstall

```bash
bin/magento module:uninstall FlipDev_AttributeManager
composer remove sickdaflip/mage2-attribute-manager
bin/magento setup:upgrade
bin/magento cache:flush
```

## What Gets Removed Automatically

### ✅ Database Tables

The following tables are **completely removed**:

1. **`flipdev_approval_queue`**
   - All pending/approved/rejected proposals
   - Approval history and metadata

2. **`flipdev_merge_log`**
   - All merge operation logs
   - Backup data for rollbacks
   - Historical merge information

3. **`flipdev_migration_proposal`**
   - All migration proposals
   - Product migration history
   - Set migration metadata

### ✅ Configuration Values

All module configuration is removed from `core_config_data`:

- `flipdev_attributes/general/*`
- `flipdev_attributes/fillrate/*`
- `flipdev_attributes/duplicates/*`
- `flipdev_attributes/approval/*`
- `flipdev_attributes/i18n/*`

### ✅ Module Files

Composer removes all module files:

- Controllers
- Services
- API interfaces
- UI Components
- Templates
- Translations

## What Does NOT Get Removed

### ⚠️ Attribute Data (Intentional)

The uninstall script **does NOT remove**:

1. **EAV Attribute Values**
   - Product attribute values remain intact
   - Historical data is preserved
   - No data loss on attribute values

2. **Merged Attributes**
   - If you merged attributes, the target attribute remains
   - Data remains in the target attribute
   - Source attributes (if deleted) remain deleted

3. **Migrated Products**
   - Products keep their migrated attribute sets
   - No reversal of migrations
   - Historical migrations not undone

4. **Log Files**
   - `var/log/flipdev_attributes.log` remains
   - Can be manually deleted if needed

### Why?

This is **intentional** to prevent accidental data loss. The module only manages existing attributes and doesn't "own" the data.

## Manual Cleanup (Optional)

If you want to completely remove all traces:

### Remove Log Files

```bash
rm -f var/log/flipdev_attributes.log
```

### Verify Table Removal

```bash
bin/magento db:schema:compare
```

### Clear Cache Completely

```bash
rm -rf var/cache/* var/page_cache/* generated/*
bin/magento setup:di:compile
bin/magento cache:flush
```

## Troubleshooting

### Uninstall Fails

If `module:uninstall` fails, you can manually clean up:

```bash
# 1. Disable module
bin/magento module:disable FlipDev_AttributeManager

# 2. Remove tables manually
mysql -u root -p magento_db << EOF
DROP TABLE IF EXISTS flipdev_approval_queue;
DROP TABLE IF EXISTS flipdev_merge_log;
DROP TABLE IF EXISTS flipdev_migration_proposal;
DELETE FROM core_config_data WHERE path LIKE 'flipdev_attributes/%';
EOF

# 3. Remove module files
composer remove sickdaflip/mage2-attribute-manager --no-update
composer update

# 4. Clean up
bin/magento setup:upgrade
bin/magento cache:flush
```

### Foreign Key Constraints

If you encounter foreign key errors during uninstall:

```sql
-- Check for foreign keys
SELECT * FROM information_schema.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_NAME IN ('flipdev_approval_queue', 'flipdev_merge_log', 'flipdev_migration_proposal');

-- Drop foreign keys first if needed
ALTER TABLE flipdev_merge_log DROP FOREIGN KEY FLIPDEV_MERGE_LOG_PROPOSAL_ID;
ALTER TABLE flipdev_migration_proposal DROP FOREIGN KEY FLIPDEV_MIGRATION_PROPOSAL_APPROVAL_QUEUE_ID;
```

Then retry the uninstall.

### Module Still Shows in Admin

After uninstall, if the module still appears in admin:

```bash
# Clear compiled DI
rm -rf generated/*
bin/magento setup:di:compile

# Clear all caches
bin/magento cache:clean
bin/magento cache:flush

# Rebuild static content
bin/magento setup:static-content:deploy -f
```

## Reinstallation

After uninstallation, you can reinstall the module:

```bash
composer require sickdaflip/mage2-attribute-manager
bin/magento module:enable FlipDev_AttributeManager
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

All database tables will be recreated fresh.

## Logging

The uninstall process logs all operations to:

- Magento System Log: `var/log/system.log`
- Module-specific operations are logged during uninstall

Check logs if uninstall fails:

```bash
tail -f var/log/system.log | grep FlipDev_AttributeManager
```

## Support

If you encounter issues during uninstallation:

1. Check the logs: `var/log/system.log`
2. Verify database state: `bin/magento db:schema:compare`
3. Contact: philippbreitsprecher@gmail.com

## Best Practices

### Before Uninstalling

1. **Backup your database**
   ```bash
   bin/magento setup:backup --db
   ```

2. **Export important data**
   - Export approval queue if you need the history
   - Export merge logs for audit trail
   - Document any ongoing operations

3. **Check dependencies**
   ```bash
   composer show -i | grep flipdev
   ```

### Testing Uninstall

Test in development first:

```bash
# 1. Create database backup
mysqldump -u root -p magento_db > backup_before_uninstall.sql

# 2. Uninstall module
bin/magento module:uninstall FlipDev_AttributeManager

# 3. Verify tables are gone
mysql -u root -p magento_db -e "SHOW TABLES LIKE 'flipdev%';"

# 4. Test your store functionality
```

## Clean Slate Guarantee

After successful uninstallation:

- ✅ No database tables remain
- ✅ No configuration values remain
- ✅ No module code remains
- ✅ No orphaned references
- ✅ Ready for fresh installation

The module follows Magento best practices for clean uninstallation.
