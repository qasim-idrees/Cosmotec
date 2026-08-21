<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Console\Command;

use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Api\MediaRepositoryInterface;
use Cosmotec\EccubeMigration\Model\Media\MediaClass;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;
use Cosmotec\EccubeMigration\Model\Validator\MediaValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * READ-ONLY media inventory and dry-run report. Creates nothing.
 *
 * Reports live counts per relation rather than the documented figures, so
 * that a material drift between the analysis and the current database is
 * visible before any import runs.
 */
class AnalyzeMediaCommand extends Command
{
    private const OPTION_RELATION = 'relation';
    private const OPTION_SAMPLE = 'sample';
    private const OPTION_CHECK_FILES = 'check-files';

    /**
     * Counts recorded during the forensic analysis, used only to flag drift.
     */
    private const EXPECTED = [
        'product' => 30876,
        'dimension' => 26417,
        'cad3d' => 10655,
        'cad2d' => 1420,
        'item' => 1093,
        'category' => 325,
        'catalog' => 286,
    ];

    public function __construct(
        private readonly MediaRepositoryInterface $mediaRepository,
        private readonly MediaValidator $validator,
        private readonly EccubeConfigProviderInterface $config
    ) {
        parent::__construct('cosmotec:eccube:analyze:media');
    }

    protected function configure(): void
    {
        $this->setDescription('READ-ONLY media inventory across all EC-CUBE upload-file relations. Creates nothing.');
        $this->addOption(self::OPTION_RELATION, null, InputOption::VALUE_REQUIRED, 'Limit to one relation: product|dimension|cad2d|cad3d|item|catalog|category.');
        $this->addOption(self::OPTION_SAMPLE, null, InputOption::VALUE_REQUIRED, 'Show this many sample rows per relation (default 5).');
        $this->addOption(self::OPTION_CHECK_FILES, null, InputOption::VALUE_NONE, 'Also verify that sampled files exist on disk (slower).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>The EC-CUBE Migration module is disabled in Stores > Configuration.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>READ-ONLY analysis. No Magento media, files or mappings will be created.</info>');
        $output->writeln('');

        $relations = $this->resolveRelations($input);

        if ($relations === []) {
            $output->writeln('<error>Unknown --relation value.</error>');

            return Command::FAILURE;
        }

        $this->renderInventory($output, $relations);
        $this->renderExtensions($output, $relations);
        $this->renderSamples($input, $output, $relations);

        $output->writeln('<comment>Excluded by design: contact_upload_file (Contact), designated_slip_upload_file (Order),</comment>');
        $output->writeln('<comment>dtb_upload_cad_zip_file (customer/session artifacts), dtb_product_image (0 rows).</comment>');

        return Command::SUCCESS;
    }

    /**
     * @return MediaRelationType[]
     */
    private function resolveRelations(InputInterface $input): array
    {
        $requested = $input->getOption(self::OPTION_RELATION);

        if ($requested === null) {
            return MediaRelationType::all();
        }

        $type = MediaRelationType::tryFrom(strtolower((string) $requested));

        return $type === null ? [] : [$type];
    }

    /**
     * @param MediaRelationType[] $relations
     */
    private function renderInventory(OutputInterface $output, array $relations): void
    {
        $output->writeln('<comment>Media inventory (live counts)</comment>');

        $table = new Table($output);
        $table->setHeaders(['Relation', 'Join table', 'Owner', 'Magento target', 'Live rows', 'Expected', 'Drift']);
        $total = 0;

        foreach ($relations as $relation) {
            $count = $this->mediaRepository->countByRelation($relation);
            $total += $count;
            $expected = self::EXPECTED[$relation->value] ?? null;
            $drift = $expected === null ? '-' : $this->describeDrift($count, $expected);

            $table->addRow([
                $relation->value,
                $relation->joinTable(),
                $relation->ownerEntity(),
                $relation->magentoEntityType(),
                $count,
                $expected ?? '-',
                $drift,
            ]);
        }

        $table->render();
        $output->writeln(sprintf('  Total media relations: <info>%d</info>', $total));
        $output->writeln('');
    }

    private function describeDrift(int $actual, int $expected): string
    {
        if ($actual === $expected) {
            return 'none';
        }

        $delta = $actual - $expected;
        $pct = $expected > 0 ? abs($delta) / $expected * 100 : 100.0;
        $marker = $pct > 5.0 ? ' <error>INVESTIGATE</error>' : '';

        return sprintf('%+d (%.1f%%)%s', $delta, $pct, $marker);
    }

    /**
     * @param MediaRelationType[] $relations
     */
    private function renderExtensions(OutputInterface $output, array $relations): void
    {
        $output->writeln('<comment>Extension / media-class breakdown</comment>');

        $table = new Table($output);
        $table->setHeaders(['Relation', 'Extension', 'Media class', 'Count', 'Requires image?', 'OK?']);

        foreach ($relations as $relation) {
            foreach ($this->mediaRepository->getExtensionBreakdown($relation) as $ext => $count) {
                $class = MediaClass::fromExtension((string) $ext);
                $requiresImage = $relation->requiresImage();
                $ok = !$requiresImage || $class->isImage();

                $table->addRow([
                    $relation->value,
                    $ext,
                    $class->value,
                    $count,
                    $requiresImage ? 'yes' : 'no',
                    $ok ? 'yes' : '<error>MISMATCH</error>',
                ]);
            }
        }

        $table->render();
        $output->writeln('');
    }

    /**
     * @param MediaRelationType[] $relations
     */
    private function renderSamples(InputInterface $input, OutputInterface $output, array $relations): void
    {
        $sampleSize = (int) ($input->getOption(self::OPTION_SAMPLE) ?? 5);

        if ($sampleSize < 1) {
            return;
        }

        $checkFiles = (bool) $input->getOption(self::OPTION_CHECK_FILES);
        $output->writeln('<comment>Samples</comment>');

        $table = new Table($output);
        $table->setHeaders(['Relation', 'Owner', 'File ID', 'File name', 'Class', 'Proposed role', 'Action']);

        foreach ($relations as $relation) {
            $seenOwner = null;

            foreach ($this->mediaRepository->getBatch($relation, 0, $sampleSize) as $file) {
                $isFirst = $seenOwner !== $file->getOwnerId();
                $seenOwner = $file->getOwnerId();
                $action = 'CREATE';

                if ($checkFiles) {
                    $result = $this->validator->validate($file);

                    if (!$result->isValid()) {
                        $action = 'NEEDS_REVIEW';
                    }
                }

                $table->addRow([
                    $relation->value,
                    $file->getOwnerId(),
                    $file->getUploadFileId(),
                    $this->truncate($file->getFileName(), 44),
                    $file->getMediaClass()->value,
                    $relation->magentoRole($isFirst) ?? '(gallery)',
                    $action,
                ]);
            }
        }

        $table->render();
        $output->writeln('');
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length - 1) . '…';
    }
}
