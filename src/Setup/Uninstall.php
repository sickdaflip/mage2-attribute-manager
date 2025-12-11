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

namespace FlipDev\AttributeManager\Setup;

use Magento\Framework\Setup\UninstallInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Uninstall Script
 *
 * Removes all module data including database tables and configuration.
 */
class Uninstall implements UninstallInterface
{
    /**
     * Module configuration path prefix
     */
    private const CONFIG_PATH_PREFIX = 'flipdev_attributes/';

    /**
     * Module database tables
     */
    private const MODULE_TABLES = [
        'flipdev_approval_queue',
        'flipdev_merge_log',
        'flipdev_migration_proposal',
    ];

    public function __construct(
        private readonly ConfigCollectionFactory $configCollectionFactory,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Uninstall module
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        $this->logger->info('FlipDev_AttributeManager: Starting uninstall');

        try {
            // Remove database tables
            $this->removeTables($setup);

            // Remove configuration
            $this->removeConfiguration($setup);

            $this->logger->info('FlipDev_AttributeManager: Uninstall completed successfully');

        } catch (\Exception $e) {
            $this->logger->error('FlipDev_AttributeManager: Uninstall failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        $setup->endSetup();
    }

    /**
     * Remove all module database tables
     *
     * @param SchemaSetupInterface $setup
     * @return void
     */
    private function removeTables(SchemaSetupInterface $setup): void
    {
        $connection = $setup->getConnection();

        foreach (self::MODULE_TABLES as $table) {
            $tableName = $setup->getTable($table);

            if ($connection->isTableExists($tableName)) {
                $connection->dropTable($tableName);
                $this->logger->info("FlipDev_AttributeManager: Dropped table '{$table}'");
            }
        }
    }

    /**
     * Remove all module configuration
     *
     * @param SchemaSetupInterface $setup
     * @return void
     */
    private function removeConfiguration(SchemaSetupInterface $setup): void
    {
        $connection = $setup->getConnection();
        $configTable = $setup->getTable('core_config_data');

        // Delete all configuration values for this module
        $connection->delete(
            $configTable,
            ['path LIKE ?' => self::CONFIG_PATH_PREFIX . '%']
        );

        $this->logger->info('FlipDev_AttributeManager: Removed all configuration values');
    }
}
