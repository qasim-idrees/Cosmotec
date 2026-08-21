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
use Cosmotec\EccubeMigration\Model\Import\ProductImporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportSimpleProductsCommand extends Command
{
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_RESUME = 'resume';
    private const OPTION_BATCH_SIZE = 'batch-size';

    public function __construct(
        private readonly ProductImporter $importer,
        private readonly EccubeConfigProviderInterface $config
    ) {
        parent::__construct('cosmotec:eccube:import:simple-products');
    }

    protected function configure(): void
    {
        $this->setDescription('Import EC-CUBE products into Magento as Simple Products. Run import:product-relations afterwards to link them to their parent Grouped Product.');
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Report what would happen without writing to Magento.');
        $this->addOption(self::OPTION_RESUME, null, InputOption::VALUE_NONE, 'Resume a previous run; already-imported products are always skipped regardless of this flag.');
        $this->addOption(self::OPTION_BATCH_SIZE, null, InputOption::VALUE_REQUIRED, 'Override the configured batch size.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>The EC-CUBE Migration module is disabled in Stores > Configuration.</error>');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption(self::OPTION_DRY_RUN) || $this->config->isDryRunByDefault();
        $resume = (bool) $input->getOption(self::OPTION_RESUME);
        $batchSizeOption = $input->getOption(self::OPTION_BATCH_SIZE);
        $batchSize = $batchSizeOption !== null ? (int) $batchSizeOption : null;

        $runId = 'prod-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $output->writeln(sprintf(
            'Starting simple product import (run %s)%s%s',
            $runId,
            $dryRun ? ' [DRY RUN]' : '',
            $resume ? ' [RESUME]' : ''
        ));

        $context = new ImportContext($runId, $dryRun, $resume, $batchSize);
        $result = $this->importer->import($context);

        $output->writeln(sprintf(
            'Done. Imported: %d, Updated: %d, Skipped: %d, Errors: %d (total processed: %d)',
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors(),
            $result->getTotalProcessed()
        ));

        return $result->getErrors() === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
