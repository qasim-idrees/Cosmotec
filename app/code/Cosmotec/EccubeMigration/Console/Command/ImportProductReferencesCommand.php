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
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ProductReferenceImporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Imports dtb_product_reference (document name + reference link) as a
 * true 1:N relation. Every source reference is preserved - the admin
 * UI's two-slot limit is not a data-model limit.
 */
class ImportProductReferencesCommand extends Command
{
    private const OPT_DRY_RUN = 'dry-run';
    private const OPT_BATCH_SIZE = 'batch-size';
    private const OPT_LIMIT = 'limit';
    private const OPT_SOURCE_ID = 'source-id';

    public function __construct(
        private readonly ProductReferenceImporter $importer,
        private readonly EccubeConfigProviderInterface $config
    ) {
        parent::__construct('cosmotec:eccube:import:product-references');
    }

    protected function configure(): void
    {
        $this->setDescription('Import EC-CUBE product references (document name/link, 1:N) into Magento.');
        $this->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Report what would happen without writing.');
        $this->addOption(self::OPT_BATCH_SIZE, null, InputOption::VALUE_REQUIRED, 'Override the configured batch size.');
        $this->addOption(self::OPT_LIMIT, null, InputOption::VALUE_REQUIRED, 'Stop after this many references.');
        $this->addOption(self::OPT_SOURCE_ID, null, InputOption::VALUE_REQUIRED, 'Only process references for this EC-CUBE product id.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>The EC-CUBE Migration module is disabled in Stores > Configuration.</error>');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption(self::OPT_DRY_RUN) || $this->config->isDryRunByDefault();
        $batchSize = $input->getOption(self::OPT_BATCH_SIZE);
        $limit = $input->getOption(self::OPT_LIMIT);
        $sourceId = $input->getOption(self::OPT_SOURCE_ID);

        $runId = 'ref-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $context = new ImportContext($runId, $dryRun, true, $batchSize !== null ? (int) $batchSize : null);

        $output->writeln(sprintf('Product reference import (run %s)%s', $runId, $dryRun ? ' <comment>[DRY RUN]</comment>' : ''));

        $result = $this->importer->importFiltered(
            $context,
            $limit !== null ? (int) $limit : null,
            $sourceId !== null ? (int) $sourceId : null
        );

        $output->writeln(sprintf(
            'Done. Imported: %d, Updated: %d, Skipped: %d, Errors: %d',
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result->getErrors() === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
