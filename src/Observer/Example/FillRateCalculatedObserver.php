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

namespace FlipDev\AttributeManager\Observer\Example;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Example Observer for Fill-Rate Calculated Event
 *
 * This is an example observer that demonstrates how to listen to
 * the 'flipdev_attributes_fillrate_calculated' event.
 *
 * To enable this observer, uncomment the configuration in etc/events.xml
 */
class FillRateCalculatedObserver implements ObserverInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $entityType = $observer->getData('entity_type');
        $fillRates = $observer->getData('fill_rates');
        $criticalCount = $observer->getData('critical_count');

        $this->logger->info('FillRate analysis completed', [
            'entity_type' => $entityType,
            'total_attributes' => count($fillRates),
            'critical_count' => $criticalCount,
        ]);

        // Custom logic here
        // Example: Send notification if too many critical attributes
        if ($criticalCount > 10) {
            $this->logger->warning('High number of critical attributes detected', [
                'count' => $criticalCount,
            ]);
        }
    }
}
