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
 * READ-ONLY. Previews how EC-CUBE specifications would map to Magento
 * product attributes. Creates nothing: no attributes, no options, no
 * attribute sets, no mapping rows. Safe to run repeatedly.
 */
class AnalyzeAttributesCommand extends Command
{
    private const OPTION_CLASSIFICATION = 'classification';
    private const OPTION_LIMIT = 'limit';

    public function __construct(
        private readonly SpecificationRepositoryInterface $specificationRepository,
        private readonly EccubeConfigProviderInterface $config
    ) {
        parent::__construct('cosmotec:eccube:analyze:attributes');
    }

    protected function configure(): void
    {
        $this->setDescription('READ-ONLY preview of EC-CUBE specifications and their proposed Magento attribute mapping. Creates nothing.');
        $this->addOption(self::OPTION_CLASSIFICATION, null, InputOption::VALUE_REQUIRED, 'Filter by classification: CREATE, SKIP_UNUSED, SKIP_INVALID, NEEDS_REVIEW.');
        $this->addOption(self::OPTION_LIMIT, null, InputOption::VALUE_REQUIRED, 'Limit the number of rows shown in the detail table.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>The EC-CUBE Migration module is disabled in Stores > Configuration.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>READ-ONLY analysis. Nothing in Magento will be created or modified.</info>');
        $output->writeln('');

        $specifications = $this->specificationRepository->getAllWithUsage();

        $this->renderSummary($output, $specifications);
        $this->renderSkipReasons($output, $specifications);
        $this->renderDetail($input, $output, $specifications);

        return Command::SUCCESS;
    }

    /**
     * @param SpecificationInterface[] $specifications
     */
    private function renderSummary(OutputInterface $output, array $specifications): void
    {
        $byClassification = [];
        $totalOptions = 0;
        $bothScopes = 0;
        $missingEnglish = 0;

        foreach ($specifications as $specification) {
            $byClassification[$specification->getClassification()] =
                ($byClassification[$specification->getClassification()] ?? 0) + 1;
            $totalOptions += $specification->getOptionCount();

            if ($specification->isUsedAtItemScope() && $specification->isUsedAtProductScope()) {
                $bothScopes++;
            }

            if (trim($specification->getNameEn()) === '') {
                $missingEnglish++;
            }
        }

        $output->writeln('<comment>Summary</comment>');
        $output->writeln(sprintf('  Total specifications          : %d', count($specifications)));
        $output->writeln(sprintf('  Total options                 : %d', $totalOptions));
        $output->writeln(sprintf('  Used at BOTH scopes           : %d', $bothScopes));
        $output->writeln(sprintf('  Missing English name          : %d', $missingEnglish));

        foreach ([
            SpecificationInterface::CLASSIFICATION_CREATE,
            SpecificationInterface::CLASSIFICATION_NEEDS_REVIEW,
            SpecificationInterface::CLASSIFICATION_SKIP_UNUSED,
            SpecificationInterface::CLASSIFICATION_SKIP_INVALID,
        ] as $classification) {
            $output->writeln(sprintf('  %-29s : %d', $classification, $byClassification[$classification] ?? 0));
        }

        $output->writeln('');
    }

    /**
     * Every specification that would NOT be created is listed with its
     * reason — nothing is dropped silently.
     *
     * @param SpecificationInterface[] $specifications
     */
    private function renderSkipReasons(OutputInterface $output, array $specifications): void
    {
        $skipped = array_filter(
            $specifications,
            static fn (SpecificationInterface $s): bool =>
                $s->getClassification() !== SpecificationInterface::CLASSIFICATION_CREATE
        );

        if ($skipped === []) {
            return;
        }

        $output->writeln('<comment>Specifications NOT classified CREATE (with reasons)</comment>');
        $table = new Table($output);
        $table->setHeaders(['ID', 'English Name', 'Opts', 'Classification', 'Reason']);

        foreach ($skipped as $specification) {
            $table->addRow([
                $specification->getId(),
                $this->truncate($specification->getLabel(), 34),
                $specification->getOptionCount(),
                $specification->getClassification(),
                $this->truncate($specification->getClassificationReason(), 76),
            ]);
        }

        $table->render();
        $output->writeln('');
    }

    /**
     * @param SpecificationInterface[] $specifications
     */
    private function renderDetail(InputInterface $input, OutputInterface $output, array $specifications): void
    {
        $filter = $input->getOption(self::OPTION_CLASSIFICATION);

        if ($filter !== null) {
            $filter = strtoupper((string) $filter);
            $specifications = array_values(array_filter(
                $specifications,
                static fn (SpecificationInterface $s): bool => $s->getClassification() === $filter
            ));
        }

        $limit = $input->getOption(self::OPTION_LIMIT);

        if ($limit !== null) {
            $specifications = array_slice($specifications, 0, max(1, (int) $limit));
        }

        $output->writeln('<comment>Specification detail</comment>');
        $table = new Table($output);
        $table->setHeaders(['ID', 'English', 'Japanese', 'Grp', 'Sort', 'Scope', 'Selectable', 'Opts', 'Magento Code', 'Class']);

        foreach ($specifications as $specification) {
            $table->addRow([
                $specification->getId(),
                $this->truncate($specification->getNameEn(), 26),
                $this->truncate($specification->getName(), 18),
                $specification->getSpecificationGroupId() ?? '-',
                $specification->getSortNo(),
                $this->describeScope($specification),
                $specification->getSelectableCount(),
                $specification->getOptionCount(),
                $specification->getMagentoAttributeCode(),
                $specification->getClassification(),
            ]);
        }

        $table->render();
        $output->writeln('');
        $output->writeln('<info>No Magento data was created or modified by this command.</info>');
    }

    private function describeScope(SpecificationInterface $specification): string
    {
        $scopes = [];

        if ($specification->isUsedAtItemScope()) {
            $scopes[] = 'ITEM';
        }

        if ($specification->isUsedAtProductScope()) {
            $scopes[] = 'PRODUCT';
        }

        return $scopes === [] ? 'UNUSED' : implode('+', $scopes);
    }

    private function truncate(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 1) . '…';
    }
}
