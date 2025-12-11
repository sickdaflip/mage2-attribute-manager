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

namespace FlipDev\AttributeManager\Test\Unit\Service;

use FlipDev\AttributeManager\Api\ApprovalManagerInterface;
use FlipDev\AttributeManager\Service\ApprovalManager;
use FlipDev\AttributeManager\Api\AttributeMergerInterface;
use FlipDev\AttributeManager\Api\SetMigrationInterface;
use FlipDev\AttributeManager\Helper\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit test for ApprovalManager
 */
class ApprovalManagerTest extends TestCase
{
    /**
     * @var ApprovalManager
     */
    private ApprovalManager $approvalManager;

    /**
     * @var ResourceConnection|MockObject
     */
    private $resourceConnectionMock;

    /**
     * @var AttributeMergerInterface|MockObject
     */
    private $attributeMergerMock;

    /**
     * @var SetMigrationInterface|MockObject
     */
    private $setMigrationMock;

    /**
     * @var Config|MockObject
     */
    private $configMock;

    /**
     * @var TransportBuilder|MockObject
     */
    private $transportBuilderMock;

    /**
     * @var StateInterface|MockObject
     */
    private $inlineTranslationMock;

    /**
     * @var StoreManagerInterface|MockObject
     */
    private $storeManagerMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private $loggerMock;

    /**
     * @var AdapterInterface|MockObject
     */
    private $connectionMock;

    /**
     * Setup test dependencies
     */
    protected function setUp(): void
    {
        $this->resourceConnectionMock = $this->createMock(ResourceConnection::class);
        $this->attributeMergerMock = $this->createMock(AttributeMergerInterface::class);
        $this->setMigrationMock = $this->createMock(SetMigrationInterface::class);
        $this->configMock = $this->createMock(Config::class);
        $this->transportBuilderMock = $this->createMock(TransportBuilder::class);
        $this->inlineTranslationMock = $this->createMock(StateInterface::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->connectionMock = $this->createMock(AdapterInterface::class);

        $this->resourceConnectionMock
            ->method('getConnection')
            ->willReturn($this->connectionMock);

        $this->resourceConnectionMock
            ->method('getTableName')
            ->willReturnCallback(fn($table) => $table);

        $this->approvalManager = new ApprovalManager(
            $this->resourceConnectionMock,
            $this->attributeMergerMock,
            $this->setMigrationMock,
            $this->configMock,
            $this->transportBuilderMock,
            $this->inlineTranslationMock,
            $this->storeManagerMock,
            $this->loggerMock
        );
    }

    /**
     * Test needs approval with approval disabled
     */
    public function testNeedsApprovalWhenDisabled(): void
    {
        $this->configMock
            ->method('isApprovalEnabled')
            ->willReturn(false);

        $result = $this->approvalManager->needsApproval(
            ApprovalManagerInterface::TYPE_MERGE,
            ['source_attributes' => [1, 2, 3]]
        );

        $this->assertFalse($result);
    }

    /**
     * Test needs approval with zero threshold
     */
    public function testNeedsApprovalWithZeroThreshold(): void
    {
        $this->configMock
            ->method('isApprovalEnabled')
            ->willReturn(true);

        $this->configMock
            ->method('getAutoApproveThreshold')
            ->willReturn(0);

        $result = $this->approvalManager->needsApproval(
            ApprovalManagerInterface::TYPE_MERGE,
            ['source_attributes' => [1]]
        );

        $this->assertTrue($result);
    }

    /**
     * Test needs approval below threshold
     */
    public function testNeedsApprovalBelowThreshold(): void
    {
        $this->configMock
            ->method('isApprovalEnabled')
            ->willReturn(true);

        $this->configMock
            ->method('getAutoApproveThreshold')
            ->willReturn(5);

        $result = $this->approvalManager->needsApproval(
            ApprovalManagerInterface::TYPE_MERGE,
            ['source_attributes' => [1, 2]]
        );

        $this->assertFalse($result);
    }

    /**
     * Test needs approval above threshold
     */
    public function testNeedsApprovalAboveThreshold(): void
    {
        $this->configMock
            ->method('isApprovalEnabled')
            ->willReturn(true);

        $this->configMock
            ->method('getAutoApproveThreshold')
            ->willReturn(2);

        $result = $this->approvalManager->needsApproval(
            ApprovalManagerInterface::TYPE_MERGE,
            ['source_attributes' => [1, 2, 3]]
        );

        $this->assertTrue($result);
    }

    /**
     * Test proposal type for migration
     */
    public function testNeedsApprovalForMigration(): void
    {
        $this->configMock
            ->method('isApprovalEnabled')
            ->willReturn(true);

        $this->configMock
            ->method('getAutoApproveThreshold')
            ->willReturn(10);

        $result = $this->approvalManager->needsApproval(
            ApprovalManagerInterface::TYPE_MIGRATION,
            ['product_ids' => [1, 2, 3, 4, 5]]
        );

        $this->assertFalse($result);
    }
}
