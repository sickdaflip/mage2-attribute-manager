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

use FlipDev\AttributeManager\Service\FormatChaosAnalyzer;
use Magento\Framework\App\ResourceConnection;
use Magento\Eav\Model\Config as EavConfig;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit test for FormatChaosAnalyzer
 */
class FormatChaosAnalyzerTest extends TestCase
{
    /**
     * @var FormatChaosAnalyzer
     */
    private FormatChaosAnalyzer $analyzer;

    /**
     * @var ResourceConnection|MockObject
     */
    private $resourceConnectionMock;

    /**
     * @var EavConfig|MockObject
     */
    private $eavConfigMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private $loggerMock;

    /**
     * Setup test dependencies
     */
    protected function setUp(): void
    {
        $this->resourceConnectionMock = $this->createMock(ResourceConnection::class);
        $this->eavConfigMock = $this->createMock(EavConfig::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->analyzer = new FormatChaosAnalyzer(
            $this->resourceConnectionMock,
            $this->eavConfigMock,
            $this->loggerMock
        );
    }

    /**
     * Test unit inconsistency detection
     */
    public function testDetectUnitInconsistencies(): void
    {
        $values = [
            '100 mm',
            '50 cm',
            '1 m',
            '200 millimeter',
        ];

        $result = $this->analyzer->detectUnitInconsistencies($values);

        $this->assertArrayHasKey('length', $result);
        $this->assertCount(4, $result['length']['found_units']);
        $this->assertEquals('Mixed units detected', $result['length']['issue']);
    }

    /**
     * Test spacing issue detection
     */
    public function testDetectSpacingIssues(): void
    {
        $values = [
            '100 mm',  // with space
            '50mm',    // without space
            '200 cm',  // with space
        ];

        $result = $this->analyzer->detectSpacingIssues($values);

        $this->assertArrayHasKey('issue', $result);
        $this->assertEquals(2, $result['with_space_count']);
        $this->assertEquals(1, $result['without_space_count']);
    }

    /**
     * Test temperature format detection
     */
    public function testDetectTemperatureFormats(): void
    {
        $values = [
            '20°C',
            '25 Celsius',
            '30 Grad',
            '-5 bis 10',
            '0/5',
        ];

        $result = $this->analyzer->detectTemperatureFormats($values);

        $this->assertArrayHasKey('formats_found', $result);
        $this->assertGreaterThan(1, count($result['formats_found']));
    }

    /**
     * Test chaos score calculation
     */
    public function testCalculateChaosScore(): void
    {
        // Perfect data - no chaos
        $perfectValues = ['100 mm', '200 mm', '300 mm'];
        $perfectScore = $this->analyzer->calculateChaosScore($perfectValues);
        $this->assertEquals(0.0, $perfectScore);

        // Chaotic data - mixed units and spacing
        $chaoticValues = [
            '100 mm',
            '50cm',
            '1 meter',
            '200',
        ];
        $chaoticScore = $this->analyzer->calculateChaosScore($chaoticValues);
        $this->assertGreaterThan(0.0, $chaoticScore);
    }

    /**
     * Test chaos score with empty values
     */
    public function testCalculateChaosScoreWithEmptyValues(): void
    {
        $score = $this->analyzer->calculateChaosScore([]);
        $this->assertEquals(0.0, $score);
    }

    /**
     * Test unit detection with no inconsistencies
     */
    public function testDetectUnitInconsistenciesWithConsistentData(): void
    {
        $values = ['100 mm', '200 mm', '300 mm'];
        $result = $this->analyzer->detectUnitInconsistencies($values);

        $this->assertEmpty($result);
    }
}
