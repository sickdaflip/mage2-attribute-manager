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

namespace FlipDev\AttributeManager\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use FlipDev\AttributeManager\Api\DuplicateDetectorInterface;
use Magento\Framework\App\State;

/**
 * CLI Command for detecting duplicate attributes
 */
class DuplicatesCommand extends Command
{
    private const COMMAND_NAME = 'flipdev:attributes:duplicates';

    private DuplicateDetectorInterface $duplicateDetector;
    private State $state;

    public function __construct(
        DuplicateDetectorInterface $duplicateDetector,
        State $state
    ) {
        parent::__construct();
        $this->duplicateDetector = $duplicateDetector;
        $this->state = $state;
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName(self::COMMAND_NAME)
            ->setDescription('Detect duplicate or similar attributes')
            ->addOption(
                'threshold',
                't',
                InputOption::VALUE_OPTIONAL,
                'Similarity threshold (0-100)',
                70
            )
            ->addOption(
                'attribute',
                'a',
                InputOption::VALUE_OPTIONAL,
                'Find duplicates for specific attribute ID'
            )
            ->addOption(
                'compare',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Compare two attributes (comma-separated IDs: 123,456)'
            )
            ->addOption(
                'types',
                null,
                InputOption::VALUE_OPTIONAL,
                'Check types: code,label,values (comma-separated)',
                'code,label'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Output format: table, json',
                'table'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file path'
            );
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area already set
        }

        $threshold = (float) $input->getOption('threshold');
        $attributeId = $input->getOption('attribute');
        $compare = $input->getOption('compare');
        $types = explode(',', $input->getOption('types'));
        $format = $input->getOption('format');
        $outputFile = $input->getOption('output');

        $output->writeln('<info>FlipDev Attribute Manager - Duplicate Detection</info>');
        $output->writeln('<info>===============================================</info>');
        $output->writeln('');

        if ($compare) {
            return $this->compareTwoAttributes($compare, $output, $format);
        }

        if ($attributeId) {
            return $this->findSimilarTo((int)$attributeId, $threshold, $output, $format);
        }

        return $this->findAllDuplicates($threshold, $types, $output, $format, $outputFile);
    }

    /**
     * Compare two specific attributes
     */
    private function compareTwoAttributes(string $compare, OutputInterface $output, string $format): int
    {
        $ids = array_map('intval', explode(',', $compare));
        
        if (count($ids) !== 2) {
            $output->writeln('<e>Please provide exactly two attribute IDs (e.g., --compare=123,456)</e>');
            return Command::FAILURE;
        }

        $output->writeln("Comparing attributes: {$ids[0]} vs {$ids[1]}");
        $output->writeln('');

        $result = $this->duplicateDetector->compareTwoAttributes($ids[0], $ids[1]);

        if (isset($result['error'])) {
            $output->writeln("<e>{$result['error']}</e>");
            return Command::FAILURE;
        }

        if ($format === 'json') {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        // Display comparison results
        $output->writeln('<info>Attribute 1:</info>');
        $output->writeln("   Code:   {$result['attribute_1']['code']}");
        $output->writeln("   Label:  {$result['attribute_1']['label']}");
        $output->writeln("   Type:   {$result['attribute_1']['type']}");
        $output->writeln("   Values: {$result['attribute_1']['value_count']}");
        $output->writeln('');

        $output->writeln('<info>Attribute 2:</info>');
        $output->writeln("   Code:   {$result['attribute_2']['code']}");
        $output->writeln("   Label:  {$result['attribute_2']['label']}");
        $output->writeln("   Type:   {$result['attribute_2']['type']}");
        $output->writeln("   Values: {$result['attribute_2']['value_count']}");
        $output->writeln('');

        $output->writeln('<info>Similarity Analysis:</info>');
        $c = $result['comparison'];
        $output->writeln("   Code Similarity:    " . $this->formatSimilarity($c['code_similarity']));
        $output->writeln("   Label Similarity:   " . $this->formatSimilarity($c['label_similarity']));
        $output->writeln("   Value Overlap:      " . $this->formatSimilarity($c['value_overlap']));
        $output->writeln("   Overall:            " . $this->formatSimilarity($c['overall_similarity']));
        $output->writeln("   Same Type:          " . ($c['same_type'] ? '✅ Yes' : '❌ No'));
        $output->writeln("   Mergeable:          " . ($c['mergeable'] ? '✅ Yes' : '❌ No'));
        $output->writeln('');

        $output->writeln('<info>Recommendation:</info>');
        $output->writeln("   {$result['recommendation']}");

        return Command::SUCCESS;
    }

    /**
     * Find similar attributes to a specific one
     */
    private function findSimilarTo(int $attributeId, float $threshold, OutputInterface $output, string $format): int
    {
        $output->writeln("Finding attributes similar to ID: {$attributeId} (threshold: {$threshold}%)");
        $output->writeln('');

        $results = $this->duplicateDetector->findSimilarTo($attributeId, $threshold);

        if (empty($results)) {
            $output->writeln('<comment>No similar attributes found above threshold.</comment>');
            return Command::SUCCESS;
        }

        if ($format === 'json') {
            $output->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Code', 'Label', 'Similarity', 'Reasons']);

        foreach ($results as $attr) {
            $table->addRow([
                $attr['attribute_id'],
                $attr['code'],
                mb_substr($attr['label'] ?? '-', 0, 25),
                $this->formatSimilarity($attr['similarity']),
                implode(', ', array_slice($attr['reasons'], 0, 2))
            ]);
        }

        $table->render();
        $output->writeln('');
        $output->writeln('Found ' . count($results) . ' similar attributes.');

        return Command::SUCCESS;
    }

    /**
     * Find all duplicate groups
     */
    private function findAllDuplicates(float $threshold, array $types, OutputInterface $output, string $format, ?string $outputFile): int
    {
        $output->writeln("Scanning for duplicates (threshold: {$threshold}%, types: " . implode(',', $types) . ")");
        $output->writeln('');

        $groups = $this->duplicateDetector->findDuplicates('catalog_product', $threshold, $types);

        if (empty($groups)) {
            $output->writeln('<info>✅ No duplicate attribute groups found!</info>');
            return Command::SUCCESS;
        }

        $output->writeln("<comment>Found " . count($groups) . " potential duplicate groups:</comment>");
        $output->writeln('');

        if ($format === 'json') {
            $json = json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($outputFile) {
                file_put_contents($outputFile, $json);
                $output->writeln("<info>Results written to: {$outputFile}</info>");
            } else {
                $output->writeln($json);
            }
            return Command::SUCCESS;
        }

        // Display groups
        foreach ($groups as $i => $group) {
            $num = $i + 1;
            $type = $group['type'];
            $reason = $group['reason'];

            $output->writeln("<info>Group #{$num}</info> [{$type}]");
            $output->writeln("   Reason: {$reason}");

            $table = new Table($output);
            $table->setHeaders(['ID', 'Code', 'Label', 'Type']);

            foreach ($group['attributes'] as $attr) {
                $table->addRow([
                    $attr['attribute_id'],
                    $attr['attribute_code'],
                    mb_substr($attr['frontend_label'] ?? '-', 0, 30),
                    $attr['frontend_input']
                ]);
            }

            $table->render();
            $output->writeln('');
        }

        // Show known patterns
        $output->writeln('<info>📋 Known Duplicate Patterns (Gastrodax):</info>');
        $patterns = $this->duplicateDetector->getKnownPatterns();

        foreach ($patterns as $category => $codes) {
            $output->writeln("   {$category}: " . implode(', ', $codes));
        }

        if ($outputFile) {
            file_put_contents($outputFile, json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $output->writeln('');
            $output->writeln("<info>Results written to: {$outputFile}</info>");
        }

        return Command::SUCCESS;
    }

    /**
     * Format similarity with color
     */
    private function formatSimilarity(float $value): string
    {
        $formatted = round($value, 1) . '%';

        if ($value >= 80) {
            return "<fg=red>{$formatted}</>";
        }
        if ($value >= 60) {
            return "<fg=yellow>{$formatted}</>";
        }
        return "<fg=green>{$formatted}</>";
    }
}
