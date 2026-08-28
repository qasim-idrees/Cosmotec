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
use Cosmotec\EccubeMigration\Console\ExecuteModeResolver;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use Cosmotec\EccubeMigration\Model\Media\DimensionCadMediaCorrector;
use Cosmotec\EccubeMigration\Model\Media\DocumentUploader;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-time correction for Dimension Image / CAD 2D / CAD 3D media that
 * was imported under the OLD scheme (dimension: added to the product
 * gallery, disabled; cad2d/cad3d: copied to
 * pub/media/cosmotec/eccube/cad2d|cad3d/) before the dedicated
 * eccube_dimension_image / eccube_cad2d_file / eccube_cad3d_file fields
 * existed. Populates the dedicated field from the same already-tracked
 * eccube_media_map source file, WITHOUT touching the old gallery entry
 * or the old copied file - see DimensionCadMediaCorrector.
 *
 * Dry-run by default, same safety convention as every other media
 * command in this module.
 */
class CorrectDimensionCadMediaCommand extends Command
{
    private const OPT_EXECUTE = 'execute';
    private const OPT_DRY_RUN = 'dry-run';
    private const OPT_TYPE = 'type';
    private const OPT_LIMIT = 'limit';

    public function __construct(
        private readonly DimensionCadMediaCorrector $corrector,
        private readonly EccubeConfigProviderInterface $config,
        private readonly ExecuteModeResolver $executeModeResolver,
        private readonly State $appState
    ) {
        parent::__construct('cosmotec:eccube:correct:dimension-cad-media');
    }

    protected function configure(): void
    {
        $this->setDescription('Correct Dimension Image / CAD 2D / CAD 3D media imported before the dedicated fields existed - populates eccube_dimension_image/eccube_cad2d_file/eccube_cad3d_file from the already-tracked source file, without touching the old gallery entry or old copied file. Dry-run unless --execute is passed.');
        $this->addOption(self::OPT_EXECUTE, null, InputOption::VALUE_NONE, 'Actually write to Magento. Without this flag the command only reports what it would do.');
        $this->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Explicitly request a dry run (this is also the default).');
        $this->addOption(self::OPT_TYPE, null, InputOption::VALUE_REQUIRED, 'Restrict to one type: dimension|cad2d|cad3d. Default: all three.');
        $this->addOption(self::OPT_LIMIT, null, InputOption::VALUE_REQUIRED, 'Stop each type after this many source records examined (for a bounded test run).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Throwable) {
            $this->appState->setAreaCode('adminhtml');
        }

        if (!$this->config->isEnabled()) {
            $output->writeln('<error>The EC-CUBE Migration module is disabled in Stores > Configuration.</error>');

            return Command::FAILURE;
        }

        $execute = (bool) $input->getOption(self::OPT_EXECUTE);
        $dryRun = $this->executeModeResolver->isDryRun((bool) $input->getOption(self::OPT_DRY_RUN), $execute);
        $typeFilter = $input->getOption(self::OPT_TYPE);
        $limitOption = $input->getOption(self::OPT_LIMIT);
        $limit = $limitOption !== null ? (int) $limitOption : null;

        $types = [
            'dimension' => DocumentUploader::SUBDIR_DIMENSION,
            'cad2d' => DocumentUploader::SUBDIR_CAD2D,
            'cad3d' => DocumentUploader::SUBDIR_CAD3D,
        ];
        $attributes = [
            'dimension' => 'eccube_dimension_image',
            'cad2d' => 'eccube_cad2d_file',
            'cad3d' => 'eccube_cad3d_file',
        ];

        if ($typeFilter !== null) {
            if (!isset($types[$typeFilter])) {
                $output->writeln(sprintf('<error>Unknown --type "%s". Must be one of: dimension, cad2d, cad3d.</error>', $typeFilter));

                return Command::FAILURE;
            }

            $types = [$typeFilter => $types[$typeFilter]];
        }

        $output->writeln(sprintf(
            'Dimension/CAD media correction%s',
            $dryRun ? ' <comment>[DRY RUN]</comment>' : ' <comment>[EXECUTE]</comment>'
        ));

        $totalErrors = 0;
        $totalNeedsReview = 0;

        foreach ($types as $relationType => $subDir) {
            $result = new ImportResult();
            // The corrector streams eccube_media_map in pages rather than
            // loading it all into memory (dimension alone is 17,069 rows)
            // - aggregate counts land on $result; only a capped sample of
            // genuine errors comes back for display.
            $errorSample = $this->corrector->correct($relationType, $subDir, $attributes[$relationType], $dryRun, $result, 200, $limit);

            $output->writeln(sprintf(
                '  %-10s source_records=%-6d %s=%-6d already_correct=%-6d needs_review=%-6d errors=%-6d',
                $relationType,
                $result->getTotalProcessed(),
                $dryRun ? 'would_correct' : 'corrected',
                $result->getImported(),
                $result->getSkipped(),
                $result->getNeedsReview(),
                $result->getErrors()
            ));

            // needs_review is not printed row-by-row here - at dimension
            // scale (thousands) that would flood the console for a
            // condition already fully explained (source file genuinely
            // missing, same SOURCE_FILE_NOT_FOUND condition documented
            // for the original import). Real errors (a genuine processing
            // failure, expected to be rare) are shown for investigation.
            foreach ($errorSample as $row) {
                $output->writeln(sprintf(
                    '    <error>error</error> owner_id=%d sku=%s: %s',
                    $row['eccube_owner_id'],
                    $row['sku'] ?? 'n/a',
                    $row['error']
                ));
            }

            $totalErrors += $result->getErrors();
            $totalNeedsReview += $result->getNeedsReview();
        }

        $output->writeln(sprintf('Done. Total errors: %d, Total needs_review: %d', $totalErrors, $totalNeedsReview));

        return $totalErrors === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
