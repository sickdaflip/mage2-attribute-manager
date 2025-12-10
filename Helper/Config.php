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

namespace FlipDev\AttributeManager\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

/**
 * Configuration Helper
 *
 * Provides easy access to module configuration values.
 */
class Config extends AbstractHelper
{
    /**
     * Configuration paths
     */
    private const XML_PATH_ENABLED = 'flipdev_attributes/general/enabled';
    private const XML_PATH_ENTITY_TYPE = 'flipdev_attributes/general/entity_type';

    private const XML_PATH_THRESHOLD_CRITICAL = 'flipdev_attributes/fillrate/threshold_critical';
    private const XML_PATH_THRESHOLD_WARNING = 'flipdev_attributes/fillrate/threshold_warning';
    private const XML_PATH_EXCLUDE_SYSTEM = 'flipdev_attributes/fillrate/exclude_system';

    private const XML_PATH_SIMILARITY_THRESHOLD = 'flipdev_attributes/duplicates/similarity_threshold';
    private const XML_PATH_CHECK_LABELS = 'flipdev_attributes/duplicates/check_labels';

    private const XML_PATH_APPROVAL_ENABLED = 'flipdev_attributes/approval/enabled';
    private const XML_PATH_APPROVAL_EMAIL = 'flipdev_attributes/approval/notify_email';
    private const XML_PATH_AUTO_APPROVE_THRESHOLD = 'flipdev_attributes/approval/auto_approve_threshold';

    private const XML_PATH_DEFAULT_LOCALE = 'flipdev_attributes/i18n/default_locale';
    private const XML_PATH_EXPORT_ALL_LOCALES = 'flipdev_attributes/i18n/export_all_locales';

    /**
     * Check if module is enabled
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Get default entity type for analysis
     */
    public function getDefaultEntityType(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_ENTITY_TYPE) ?: 'catalog_product';
    }

    /**
     * Get critical threshold percentage
     */
    public function getCriticalThreshold(): float
    {
        return (float) ($this->scopeConfig->getValue(self::XML_PATH_THRESHOLD_CRITICAL) ?? 25);
    }

    /**
     * Get warning threshold percentage
     */
    public function getWarningThreshold(): float
    {
        return (float) ($this->scopeConfig->getValue(self::XML_PATH_THRESHOLD_WARNING) ?? 50);
    }

    /**
     * Check if system attributes should be excluded
     */
    public function excludeSystemAttributes(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_EXCLUDE_SYSTEM);
    }

    /**
     * Get similarity threshold for duplicate detection
     */
    public function getSimilarityThreshold(): float
    {
        return (float) ($this->scopeConfig->getValue(self::XML_PATH_SIMILARITY_THRESHOLD) ?? 70);
    }

    /**
     * Check if labels should be compared for duplicates
     */
    public function checkLabelsForDuplicates(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CHECK_LABELS);
    }

    /**
     * Check if approval workflow is enabled
     */
    public function isApprovalEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_APPROVAL_ENABLED);
    }

    /**
     * Get approval notification email
     */
    public function getApprovalNotifyEmail(): ?string
    {
        $email = $this->scopeConfig->getValue(self::XML_PATH_APPROVAL_EMAIL);
        return $email ? (string) $email : null;
    }

    /**
     * Get auto-approve threshold (number of products)
     */
    public function getAutoApproveThreshold(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_PATH_AUTO_APPROVE_THRESHOLD) ?? 10);
    }

    /**
     * Get default locale for labels
     */
    public function getDefaultLocale(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_LOCALE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 'de_DE';
    }

    /**
     * Check if all locales should be exported
     */
    public function exportAllLocales(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_EXPORT_ALL_LOCALES);
    }

    /**
     * Get fill rate status based on percentage
     *
     * @return string 'critical', 'warning', or 'healthy'
     */
    public function getFillRateStatus(float $rate): string
    {
        if ($rate < $this->getCriticalThreshold()) {
            return 'critical';
        }
        if ($rate < $this->getWarningThreshold()) {
            return 'warning';
        }
        return 'healthy';
    }
}
