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
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ProductAttributeValueImporter;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * NOT YET APPROVED FOR EXECUTION - see BUILD_STATUS.md. Writes
 * PRODUCT-scope specification values (193,473 rows, grouped into at most
 * one save per product) onto Simple Product EAV attributes. Requires
 * import:attributes to have already run.
 */
class ImportProductAttributeValuesCommand extends Command
{
    private const OPTION_EXECUTE = 'execute';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_BATCH_SIZE = 'batch-size';
    private const OPTION_LIMIT = 'limit';
    private const OPTION_OFFSET = 'offset';

    public function __construct(
        private readonly ProductAttributeValueImporter $importer,
        private readonly EccubeConfigProviderInterface $config,
        private readonly ExecuteModeResolver $executeModeResolver,
        private readonly State $appState
    ) {
        parent::__construct('cosmotec:eccube:import:product-attribute-values');
    }

    protected function configure(): void
    {
        $this->setDescription('Write PRODUCT-scope EC-CUBE specification values onto Simple Product EAV attributes. Dry-run unless --execute is passed.');
        $this->addOption(self::OPTION_EXECUTE, null, InputOption::VALUE_NONE, 'Actually write Magento product attribute values. Without this flag the command only reports what it would do.');
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Explicitly request a dry run (this is also the default).');
        $this->addOption(self::OPTION_BATCH_SIZE, null, InputOption::VALUE_REQUIRED, 'Override the configured batch size (products-with-values per page).');
        $this->addOption(self::OPTION_LIMIT, null, InputOption::VALUE_REQUIRED, 'Stop after this many products with values (useful for a small real-data test — this table has no natural small scope).');
        $this->addOption(self::OPTION_OFFSET, null, InputOption::VALUE_REQUIRED, 'Skip this many products with values before starting - combine with a small --limit to reach a specific known EC-CUBE product id deep in the product_id-ascending ordering, without processing every product before it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Product-entity save (via magentoProductRepository->save())
        // requires an area code to be set - a plain CLI invocation has
        // none by default, unlike cron. Same guard/root cause as
        // ImportImagesCommand's media gallery write, now proven
        // necessary here too (live-reproduced: "Area code is not set"
        // on every row before this guard existed).
        try {
            $this->appState->getAreaCode();
        } catch (\Throwable) {
            $this->appState->setAreaCode('adminhtml');
        }

        if (!$this->config->isEnabled()) {
            $output->writeln('<error>The EC-CUBE Migration module is disabled in Stores > Configuration.</error>');

            return Command::FAILURE;
        }

        $execute = (bool) $input->getOption(self::OPTION_EXECUTE);
        $dryRun = $this->executeModeResolver->isDryRun((bool) $input->getOption(self::OPTION_DRY_RUN), $execute);

        if ($dryRun) {
            $output->writeln('<comment>DRY RUN — no Magento product attribute values will be written.</comment>');
            $output->writeln('<comment>Pass --execute to apply changes.</comment>');
        } else {
            $output->writeln('<info>EXECUTING — Simple Product attribute values will be written.</info>');
        }

        $output->writeln('');

        $batchSizeOption = $input->getOption(self::OPTION_BATCH_SIZE);
        $runId = 'productattr-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $context = new ImportContext(
            $runId,
            $dryRun,
            true,
            $batchSizeOption !== null ? (int) $batchSizeOption : null
        );

        $limitOption = $input->getOption(self::OPTION_LIMIT);
        $offsetOption = $input->getOption(self::OPTION_OFFSET);
        $result = $this->importer->importLimited(
            $context,
            $limitOption !== null ? (int) $limitOption : null,
            $offsetOption !== null ? (int) $offsetOption : 0
        );

        $output->writeln('');
        $output->writeln(sprintf(
            'Done. Written: %d, Updated: %d, Skipped: %d, Errors: %d (total: %d)',
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors(),
            $result->getTotalProcessed()
        ));
        $output->writeln('<comment>Skipped products include ones not yet imported, or whose values reference specifications/options not yet created — both retry automatically.</comment>');

        return $result->getErrors() === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
