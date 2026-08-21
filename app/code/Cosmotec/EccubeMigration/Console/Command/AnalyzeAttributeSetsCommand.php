<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Console\Command;

use Cosmotec\EccubeMigration\Api\Data\SpecificationInterface;
use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Api\SpecificationRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * READ-ONLY. Previews the proposed Magento Attribute Set structure
 * derived from EC-CUBE's top-level categories. Creates nothing.
 *
 * Design note surfaced by this command's own output: "one attribute set
 * per top-level category" is a MAGENTO DESIGN DECISION, not an EC-CUBE
 * mapping — EC-CUBE has no attribute-set concept. The overlap matrix and
 * the multi-top-level-category item list exist specifically so that
 * decision can be reviewed against real numbers before anything is
 * created.
 */
class AnalyzeAttributeSetsCommand extends Command
{
    private const OPTION_SHOW_SPECS = 'show-specifications';

    public function __construct(
        private readonly SpecificationRepositoryInterface $specificationRepository,
        private readonly EccubeConfigProviderInterface $config
    ) {
        parent::__construct('cosmotec:eccube:analyze:attribute-sets');
    }

    protected function configure(): void
    {
        $this->setDescription('READ-ONLY preview of the proposed Magento Attribute Sets derived from EC-CUBE top-level categories. Creates nothing.');
        $this->addOption(self::OPTION_SHOW_SPECS, null, InputOption::VALUE_NONE, 'List every specification ID/label per proposed attribute set.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>The EC-CUBE Migration module is disabled in Stores > Configuration.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>READ-ONLY analysis. Nothing in Magento will be created or modified.</info>');
        $output->writeln('<comment>Note: EC-CUBE has no Attribute Set concept. The mapping below is a Magento design decision.</comment>');
        $output->writeln('');

        $topLevels = $this->specificationRepository->getTopLevelCategories();
        $specificationsById = [];

        foreach ($this->specificationRepository->getAllWithUsage() as $specification) {
            $specificationsById[$specification->getId()] = $specification;
        }

        $sets = [];
        $descendantMap = [];

        foreach ($topLevels as $topLevel) {
            $descendants = $this->specificationRepository->getDescendantCategoryIds($topLevel['id']);
            $descendantMap[$topLevel['id']] = $descendants;
            $usage = $this->specificationRepository->getSpecificationUsageForCategories($descendants);

            $allSpecIds = array_values(array_unique(array_merge(
                $usage['item_scope_specification_ids'],
                $usage['product_scope_specification_ids']
            )));
            sort($allSpecIds);

            $sets[$topLevel['id']] = [
                'name' => $topLevel['name_en'] !== '' ? $topLevel['name_en'] : $topLevel['name'],
                'category' => $topLevel,
                'descendants' => $descendants,
                'usage' => $usage,
                'spec_ids' => $allSpecIds,
            ];
        }

        $this->renderSets($output, $sets, $specificationsById, (bool) $input->getOption(self::OPTION_SHOW_SPECS));
        $this->renderOverlap($output, $sets);
        $this->renderMultiCategoryItems($output, $descendantMap, $sets);

        $output->writeln('<info>No Magento data was created or modified by this command.</info>');

        return Command::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $sets
     * @param array<int, SpecificationInterface> $specificationsById
     */
    private function renderSets(OutputInterface $output, array $sets, array $specificationsById, bool $showSpecs): void
    {
        $output->writeln('<comment>Proposed Attribute Sets</comment>');
        $table = new Table($output);
        $table->setHeaders(['Proposed Set', 'Cat ID', 'Desc. Cats', 'Items', 'Products', 'Specs', 'ITEM', 'PRODUCT', 'Selectable']);

        foreach ($sets as $set) {
            $table->addRow([
                $set['name'],
                $set['category']['id'],
                count($set['descendants']),
                $set['usage']['item_count'],
                $set['usage']['product_count'],
                count($set['spec_ids']),
                count(array_unique($set['usage']['item_scope_specification_ids'])),
                count(array_unique($set['usage']['product_scope_specification_ids'])),
                count(array_unique($set['usage']['selectable_specification_ids'])),
            ]);
        }

        $table->render();
        $output->writeln('');

        if (!$showSpecs) {
            $output->writeln('<comment>Pass --show-specifications to list every specification per set.</comment>');
            $output->writeln('');

            return;
        }

        foreach ($sets as $set) {
            $output->writeln(sprintf('<comment>%s</comment> (%d specifications)', $set['name'], count($set['spec_ids'])));

            foreach ($set['spec_ids'] as $specId) {
                $specification = $specificationsById[$specId] ?? null;
                $output->writeln(sprintf(
                    '    %-6d %-40s %s',
                    $specId,
                    $specification !== null ? $specification->getLabel() : '(unknown)',
                    $specification !== null ? $specification->getMagentoAttributeCode() : ''
                ));
            }

            $output->writeln('');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $sets
     */
    private function renderOverlap(OutputInterface $output, array $sets): void
    {
        $output->writeln('<comment>Specification overlap between proposed sets</comment>');
        $output->writeln('<comment>(High overlap is expected and fine — Magento attributes are global; sets only select which apply.)</comment>');

        $table = new Table($output);
        $table->setHeaders(['Set A', 'Set B', 'Shared Specs', 'A only', 'B only']);

        $ids = array_keys($sets);

        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $a = $sets[$ids[$i]];
                $b = $sets[$ids[$j]];
                $shared = array_intersect($a['spec_ids'], $b['spec_ids']);

                if ($shared === []) {
                    continue;
                }

                $table->addRow([
                    $a['name'],
                    $b['name'],
                    count($shared),
                    count(array_diff($a['spec_ids'], $b['spec_ids'])),
                    count(array_diff($b['spec_ids'], $a['spec_ids'])),
                ]);
            }
        }

        $table->render();
        $output->writeln('');
    }

    /**
     * @param array<int, int[]> $descendantMap
     * @param array<int, array<string, mixed>> $sets
     */
    private function renderMultiCategoryItems(OutputInterface $output, array $descendantMap, array $sets): void
    {
        $multi = $this->specificationRepository->getItemsInMultipleTopLevelCategories($descendantMap);

        $output->writeln('<comment>Items belonging to MULTIPLE top-level categories</comment>');

        if ($multi === []) {
            $output->writeln('  None — every item maps to exactly one proposed attribute set.');
            $output->writeln('');

            return;
        }

        $output->writeln(sprintf(
            '  %d items span more than one top-level category tree. These need an explicit attribute-set',
            count($multi)
        ));
        $output->writeln('  decision (union / single-set / shared-set) — this command deliberately does NOT pick one.');
        $output->writeln('');

        $combinations = [];

        foreach ($multi as $topLevelIds) {
            sort($topLevelIds);
            $key = implode('+', array_map(
                static fn (int $id): string => (string) ($sets[$id]['name'] ?? $id),
                $topLevelIds
            ));
            $combinations[$key] = ($combinations[$key] ?? 0) + 1;
        }

        arsort($combinations);

        $table = new Table($output);
        $table->setHeaders(['Top-level category combination', 'Item count']);

        foreach ($combinations as $combination => $count) {
            $table->addRow([$combination, $count]);
        }

        $table->render();
        $output->writeln('');
    }
}
