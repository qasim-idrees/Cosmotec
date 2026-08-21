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
use Cosmotec\EccubeMigration\Model\Import\RelatedProductImporter;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * NOT YET APPROVED FOR EXECUTION - see BUILD_STATUS.md. Imports
 * dtb_related_product as Magento's native related-product links between
 * Simple Products. Distinct from import:connection-parts - see
 * RelatedProductImporter / ConnectionPartImporter.
 */
class ImportRelatedProductsCommand extends Command
{
    private const OPTION_EXECUTE = 'execute';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_BATCH_SIZE = 'batch-size';

    public function __construct(
        private readonly RelatedProductImporter $importer,
        private readonly EccubeConfigProviderInterface $config,
        private readonly ExecuteModeResolver $executeModeResolver,
        private readonly State $appState
    ) {
        parent::__construct('cosmotec:eccube:import:related-products');
    }

    protected function configure(): void
    {
        $this->setDescription('Link EC-CUBE related products (dtb_related_product) as Magento native related-product links. Dry-run unless --execute is passed.');
        $this->addOption(self::OPTION_EXECUTE, null, InputOption::VALUE_NONE, 'Actually write Magento related-product links. Without this flag the command only reports what it would do.');
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Explicitly request a dry run (this is also the default).');
        $this->addOption(self::OPTION_BATCH_SIZE, null, InputOption::VALUE_REQUIRED, 'Override the configured batch size.');
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
            $output->writeln('<comment>DRY RUN — no Magento related-product links will be written.</comment>');
            $output->writeln('<comment>Pass --execute to apply changes.</comment>');
        } else {
            $output->writeln('<info>EXECUTING — related-product links will be written.</info>');
        }

        $output->writeln('');

        $batchSizeOption = $input->getOption(self::OPTION_BATCH_SIZE);
        $runId = 'related-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $context = new ImportContext(
            $runId,
            $dryRun,
            true,
            $batchSizeOption !== null ? (int) $batchSizeOption : null
        );

        $result = $this->importer->import($context);

        $output->writeln('');
        $output->writeln(sprintf(
            'Done. Linked: %d, Skipped: %d, Errors: %d (total: %d)',
            $result->getImported(),
            $result->getSkipped(),
            $result->getErrors(),
            $result->getTotalProcessed()
        ));
        $output->writeln('<comment>Skipped relations include products not yet imported on either side — retried automatically.</comment>');

        return $result->getErrors() === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
