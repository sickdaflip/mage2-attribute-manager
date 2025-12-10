# FlipDev_AttributeManager

Comprehensive EAV attribute management for Magento 2 with fill-rate analysis, duplicate detection, attribute consolidation, and approval workflows.

## Features

### 📊 Fill-Rate Analysis
- Real-time fill-rate calculation for all attributes
- Grouped analysis by attribute set
- Manufacturer-based fill-rate reports
- Critical/Warning/Healthy status indicators
- Configurable thresholds

### 🔍 Duplicate Detection
- Automatic detection of potential duplicate attributes
- Code similarity analysis (Levenshtein distance)
- Label comparison across store views
- Known pattern recognition (e.g., `breite`/`width`/`breite_dropdown`)

### 🔄 Attribute Merger
- Preview merge operations before execution
- Automatic data migration
- Configurable conflict resolution strategies
- Option value mapping for select/multiselect
- Rollback capability

### 📦 Set Migration
- Bulk product migration between attribute sets
- Category/Manufacturer/SKU pattern-based migration
- Preview affected products before migration
- Misassignment detection

### ✅ Approval Workflow
- Optional review process for bulk changes
- Email notifications
- Auto-approve threshold for minor changes
- Audit trail

### 🌍 Internationalization
- Full German/English support
- Store view-aware label handling
- Export with all locales

## Requirements

- Magento 2.4.x
- PHP 8.1+
- FlipDev_Core module

## Installation

### Via Composer

```bash
composer require flipdev/module-attribute-manager
bin/magento module:enable FlipDev_AttributeManager
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Manual Installation

1. Create directory: `app/code/FlipDev/AttributeManager`
2. Upload module files
3. Run setup commands as above

## Configuration

Navigate to **Stores > Configuration > FlipDev Extensions > Attribute Manager**

### General Settings
- **Enable Module**: Turn functionality on/off
- **Default Entity Type**: Primary entity for analysis (default: `catalog_product`)

### Fill-Rate Analysis
- **Critical Threshold**: Fill-rate below this = critical (default: 25%)
- **Warning Threshold**: Fill-rate below this = warning (default: 50%)
- **Exclude System Attributes**: Only analyze user-defined attributes

### Duplicate Detection
- **Similarity Threshold**: Minimum similarity percentage (default: 70%)
- **Compare Labels**: Include frontend labels in comparison

### Approval Workflow
- **Enable Approval**: Require review for bulk changes
- **Notification Email**: Where to send approval requests
- **Auto-Approve Threshold**: Skip approval below X products affected

## Usage

### Dashboard

Access via **FlipDev > Attribute Manager > Dashboard**

Shows:
- Summary statistics (total attributes, average fill-rate, status counts)
- Fill-rate distribution chart
- Attribute set overview
- Critical attributes list
- Quick action buttons

### Fill-Rate Analysis

Access via **FlipDev > Attribute Manager > Analysis > Fill-Rate Report**

Features:
- Sortable/filterable attribute grid
- Per-set breakdown
- Export to CSV
- Bulk actions for low fill-rate attributes

### Duplicate Detection

Access via **FlipDev > Attribute Manager > Analysis > Duplicate Detection**

Features:
- Automatic duplicate group detection
- Side-by-side comparison view
- One-click merge proposals

### Attribute Sets

Access via **FlipDev > Attribute Manager > Attribute Sets**

Features:
- Product distribution across sets
- Misassignment detection
- Bulk migration tools

## API / Service Layer

### FillRateAnalyzerInterface

```php
use FlipDev\AttributeManager\Api\FillRateAnalyzerInterface;

public function __construct(FillRateAnalyzerInterface $analyzer) {
    $this->analyzer = $analyzer;
}

// Get fill rates for all product attributes
$rates = $this->analyzer->getAttributeFillRates('catalog_product');

// Get attributes below 25% fill rate
$critical = $this->analyzer->getCriticalAttributes('catalog_product', 25.0);

// Get summary statistics
$summary = $this->analyzer->getSummaryStatistics('catalog_product');
```

### DuplicateDetectorInterface

```php
use FlipDev\AttributeManager\Api\DuplicateDetectorInterface;

// Find potential duplicates
$duplicates = $this->detector->findDuplicates('catalog_product', 70.0);

// Compare two specific attributes
$comparison = $this->detector->compareTwoAttributes(123, 456);
```

### AttributeMergerInterface

```php
use FlipDev\AttributeManager\Api\AttributeMergerInterface;

// Preview merge
$preview = $this->merger->previewMerge([123, 124], 125);

// Execute merge
$result = $this->merger->executeMerge(
    [123, 124],           // Source attributes
    125,                  // Target attribute
    AttributeMergerInterface::CONFLICT_KEEP_TARGET,
    false                 // Don't delete sources
);
```

### SetMigrationInterface

```php
use FlipDev\AttributeManager\Api\SetMigrationInterface;

// Find candidates for migration
$candidates = $this->migration->findMigrationCandidates(
    4,                    // Source set (Default)
    15,                   // Target set (Kühltechnik)
    ['type' => 'attribute', 'value' => ['kaeltemittel' => 'filled']]
);

// Execute migration
$result = $this->migration->executeMigration($productIds, 15, true);
```

## CLI Commands

### Full Analysis
```bash
# Complete attribute analysis with statistics
bin/magento flipdev:attributes:analyze

# Export as JSON
bin/magento flipdev:attributes:analyze --format=json --output=var/attribute_analysis.json

# Include system attributes
bin/magento flipdev:attributes:analyze --include-system
```

**Output includes:**
- Total products / attributes / sets count
- Attribute set distribution with visual bar chart
- Fill-rate analysis (critical/warning/healthy)
- Top 20 worst-performing attributes
- Manufacturer distribution
- Potential duplicate detection

### Fill-Rate Analysis
```bash
# All attributes
bin/magento flipdev:attributes:fillrate

# Specific attribute set
bin/magento flipdev:attributes:fillrate --set="Kühltechnik"
bin/magento flipdev:attributes:fillrate --set=15  # by ID

# Group by attribute set
bin/magento flipdev:attributes:fillrate --by-set

# Filter by fill-rate range
bin/magento flipdev:attributes:fillrate --min-rate=0 --max-rate=25  # Only critical

# Export
bin/magento flipdev:attributes:fillrate --format=csv --output=var/fillrates.csv
```

### Format Chaos Detection
```bash
# Analyze top 20 text attributes for format inconsistencies
bin/magento flipdev:attributes:chaos

# Analyze specific attribute in detail
bin/magento flipdev:attributes:chaos --attribute=temperaturbereich

# Analyze more attributes
bin/magento flipdev:attributes:chaos --limit=50

# Export
bin/magento flipdev:attributes:chaos --format=json --output=var/chaos_report.json
```

**Chaos detection finds:**
- Mixed units (mm vs cm, W vs kW)
- Inconsistent spacing
- Temperature format variations
- Missing units on numeric values

---

## API / Service Layer

The module dispatches these events:

- `flipdev_attributes_fillrate_calculated` - After fill-rate analysis
- `flipdev_attributes_duplicates_found` - After duplicate detection
- `flipdev_attributes_merge_before` - Before attribute merge
- `flipdev_attributes_merge_after` - After attribute merge
- `flipdev_attributes_migration_before` - Before set migration
- `flipdev_attributes_migration_after` - After set migration

## Logging

All operations are logged to `var/log/flipdev_attributes.log`

## Changelog

### Version 1.0.0
- Initial release
- Dashboard with statistics
- Fill-rate analysis
- Duplicate detection (code similarity)
- Basic attribute merger
- Set migration tools
- Approval workflow
- German/English translations

## Support

- **Email**: philippbreitsprecher@gmail.com
- **Documentation**: See `/docs` folder

## License

Proprietary - Copyright (c) 2024-2025 FlipDev
