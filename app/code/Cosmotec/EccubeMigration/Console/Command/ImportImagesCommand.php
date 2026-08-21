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
use Cosmotec\EccubeMigration\Model\Config\ImageFolderResolver;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use Cosmotec\EccubeMigration\Model\Import\MediaImporter;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Relation-aware media importer.
 *
 * Replaces the previous dtb_product_image based implementation - that
 * table has zero rows in production. Media now comes from dtb_upload_file
 * through seven catalog relation tables.
 */
class ImportImagesCommand extends Command
{
    private const OPT_TYPE = 'type';
    private const OPT_EXECUTE = 'execute';
    private const OPT_DRY_RUN = 'dry-run';
    private const OPT_BATCH_SIZE = 'batch-size';
    private const OPT_LIMIT = 'limit';
    private const OPT_OWNER_ID = 'owner-id';
    private const OPT_UPLOAD_FILE_ID = 'upload-file-id';
    /** @deprecated ambiguous - means owner id; use --owner-id */
    private const OPT_SOURCE_ID = 'source-id';
    private const OPT_VERBOSE_LOG = 'verbose-log';

    public function __construct(
        private readonly MediaImporter $importer,
        private readonly EccubeConfigProviderInterface $config,
        private readonly ImageFolderResolver $imageFolderResolver,
        private readonly State $appState
    ) {
        parent::__construct('cosmotec:eccube:import:images');
    }

    protected function configure(): void
    {
        $this->setDescription('Import EC-CUBE media (product, dimension, cad2d, cad3d, item, catalog, category) into Magento.');
        $this->setHelp(
            "Imports EC-CUBE media into Magento, one relation at a time.\n\n"
            . "<comment>--owner-id vs --upload-file-id</comment>\n"
            . "  --owner-id=1124        every product_upload_file row belonging to EC-CUBE product 1124\n"
            . "                         (a product typically has several images)\n"
            . "  --upload-file-id=1124  exactly the row where dtb_upload_file.id = 1124\n\n"
            . "These are different numbers. In EC-CUBE, the images of product 1124 have\n"
            . "upload_file ids such as 2674-2677, while upload_file 1124 belongs to a\n"
            . "different product entirely. Both filters are applied in SQL.\n\n"
            . "Missing source files are reported as SOURCE_FILE_NOT_FOUND and recorded\n"
            . "as needs_review; they are never fabricated, renamed or silently skipped.\n\n"
            . "<comment>Execution mode</comment>\n"
            . "  (no flag)    DRY RUN - nothing is written\n"
            . "  --execute    writes to Magento\n"
            . "  --dry-run    forces a dry run and always wins over --execute"
        );
        $this->addOption(self::OPT_TYPE, null, InputOption::VALUE_REQUIRED, 'all|product|dimension|cad2d|cad3d|item|catalog|category (default: all)');
        $this->addOption(self::OPT_EXECUTE, null, InputOption::VALUE_NONE, 'Actually write to Magento. Without this flag the command always performs a DRY RUN.');
        $this->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Explicitly request a dry run. This is already the default; --dry-run always wins over --execute.');
        $this->addOption(self::OPT_BATCH_SIZE, null, InputOption::VALUE_REQUIRED, 'Override the configured batch size.');
        $this->addOption(self::OPT_LIMIT, null, InputOption::VALUE_REQUIRED, 'Stop after this many files per relation (useful for small real-data tests).');
        $this->addOption(self::OPT_OWNER_ID, null, InputOption::VALUE_REQUIRED, 'EC-CUBE OWNER id: the dtb_product / dtb_item / dtb_category the media belongs to. Processes every file owned by it.');
        $this->addOption(self::OPT_UPLOAD_FILE_ID, null, InputOption::VALUE_REQUIRED, 'EC-CUBE dtb_upload_file.id: one exact file. Not the same as --owner-id.');
        $this->addOption(self::OPT_SOURCE_ID, null, InputOption::VALUE_REQUIRED, '[DEPRECATED] Alias for --owner-id (it always meant owner id, never upload_file id).');
        $this->addOption(self::OPT_VERBOSE_LOG, null, InputOption::VALUE_NONE, 'Print per-relation progress.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Unlike cron (which Magento's own scheduler enters with
        // AREA_CRONTAB already set), a plain bin/magento invocation of this
        // command has no area code set. MediaImporter's gallery write goes
        // through ProductRepository::save() with media_gallery_entries,
        // which requires one - see DiagnoseGalleryCommand, where this exact
        // save operation was first isolated and needed the same guard.
        try {
            $this->appState->getAreaCode();
        } catch (\Throwable) {
            $this->appState->setAreaCode('adminhtml');
        }

        if (!$this->config->isEnabled()) {
            $output->writeln('<error>The EC-CUBE Migration module is disabled in Stores > Configuration.</error>');

            return Command::FAILURE;
        }

        // Validate the source root once, rather than emitting one
        // filesystem error per file across ~71,000 relations.
        $configError = $this->imageFolderResolver->validate();

        if ($configError !== null) {
            $output->writeln('<error>' . $configError . '</error>');
            $output->writeln('');
            $output->writeln('<comment>EC-CUBE stores only the bare filename in dtb_upload_file.</comment>');
            $output->writeln('<comment>Configure the ABSOLUTE path to the EC-CUBE image directory, e.g.:</comment>');
            $output->writeln('<comment>  bin/magento config:set cosmotec_eccube_migration/general/image_folder /path/to/eccube/html/upload/save_image</comment>');

            return Command::FAILURE;
        }

        $relations = $this->resolveRelations($input, $output);

        if ($relations === []) {
            return Command::FAILURE;
        }

        // Write mode is opt-in and must be requested explicitly.
        //
        // Precedence, safest first:
        //   1. --dry-run always wins, even alongside --execute.
        //   2. --execute enables writing, and deliberately overrides the
        //      module's dry-run-by-default configuration - otherwise there
        //      would be no way to run a real import while that setting is
        //      on, which is the situation this flag exists to solve.
        //   3. With neither flag: dry run, always.
        $explicitDryRun = (bool) $input->getOption(self::OPT_DRY_RUN);
        $execute = (bool) $input->getOption(self::OPT_EXECUTE);
        $dryRun = $explicitDryRun || !$execute;

        if ($explicitDryRun && $execute) {
            $output->writeln('<comment>Both --dry-run and --execute given; --dry-run wins. Nothing will be written.</comment>');
        }
        $batchSize = $input->getOption(self::OPT_BATCH_SIZE);
        $limit = $input->getOption(self::OPT_LIMIT);
        $uploadFileIdOption = $input->getOption(self::OPT_UPLOAD_FILE_ID);
        $ownerIdOption = $input->getOption(self::OPT_OWNER_ID) ?? $input->getOption(self::OPT_SOURCE_ID);

        if ($input->getOption(self::OPT_SOURCE_ID) !== null && $input->getOption(self::OPT_OWNER_ID) === null) {
            $output->writeln('<comment>--source-id is deprecated and means OWNER id; use --owner-id instead.</comment>');
        }

        if ($uploadFileIdOption !== null && $ownerIdOption !== null) {
            $output->writeln('<error>Use either --owner-id or --upload-file-id, not both.</error>');

            return Command::FAILURE;
        }

        $runId = 'media-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $context = new ImportContext(
            $runId,
            $dryRun,
            true,
            $batchSize !== null ? (int) $batchSize : null
        );

        $output->writeln(sprintf(
            'Media import (run %s) %s',
            $runId,
            $dryRun ? '<comment>[DRY RUN]</comment>' : '<info>[EXECUTE]</info>'
        ));

        if ($dryRun) {
            $output->writeln('<comment>No Magento media, gallery entries or mappings will be created. Pass --execute to write.</comment>');
        } else {
            $output->writeln('<info>Writing to Magento: media files, gallery entries and eccube_media_map rows will be created/updated.</info>');
        }
        $output->writeln('');

        $totals = new ImportResult();

        foreach ($relations as $relation) {
            $result = $this->importer->importRelation(
                $relation,
                $context,
                null,
                $limit !== null ? (int) $limit : null,
                $ownerIdOption !== null ? (int) $ownerIdOption : null,
                $uploadFileIdOption !== null ? (int) $uploadFileIdOption : null
            );

            $output->writeln(sprintf(
                '  %-10s imported=%d updated=%d skipped=%d needs_review=%d errors=%d',
                $relation->value,
                $result->getImported(),
                $result->getUpdated(),
                $result->getSkipped(),
                $result->getNeedsReview(),
                $result->getErrors()
            ));

            $totals->incrementImported($result->getImported());
            $totals->incrementUpdated($result->getUpdated());
            $totals->incrementSkipped($result->getSkipped());
            $totals->incrementNeedsReview($result->getNeedsReview());
            $totals->incrementErrors($result->getErrors());
        }

        $output->writeln('');
        $output->writeln(sprintf(
            'Done. Imported: %d, Updated: %d, Skipped: %d, Needs review: %d, Errors: %d',
            $totals->getImported(),
            $totals->getUpdated(),
            $totals->getSkipped(),
            $totals->getNeedsReview(),
            $totals->getErrors()
        ));

        if ($totals->getNeedsReview() > 0) {
            $output->writeln(sprintf(
                '<comment>%d file(s) need review - source files missing or unreadable on disk. '
                . 'These are source-data conditions, not import failures; see eccube_media_map '
                . '(status=needs_review) and var/log/eccube_import.log.</comment>',
                $totals->getNeedsReview()
            ));
        }

        if ($totals->getSkipped() > 0) {
            $output->writeln('<comment>Skipped records include media whose owner is not imported yet; re-run after importing products/items/categories.</comment>');
        }

        // needs_review does not fail the run: a missing source file is a
        // data condition, not an import malfunction.
        return $totals->getErrors() === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return MediaRelationType[]
     */
    private function resolveRelations(InputInterface $input, OutputInterface $output): array
    {
        $type = $input->getOption(self::OPT_TYPE);

        if ($type === null || strtolower((string) $type) === 'all') {
            return MediaRelationType::all();
        }

        $relation = MediaRelationType::tryFrom(strtolower((string) $type));

        if ($relation === null) {
            $output->writeln(sprintf(
                '<error>Unknown --type "%s". Valid: all, %s</error>',
                $type,
                implode(', ', array_map(static fn (MediaRelationType $t): string => $t->value, MediaRelationType::all()))
            ));

            return [];
        }

        return [$relation];
    }
}
