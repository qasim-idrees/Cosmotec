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
use Cosmotec\EccubeMigration\Model\Import\ProductRelationImporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportProductRelationsCommand extends Command
{
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_RESUME = 'resume';
    private const OPTION_BATCH_SIZE = 'batch-size';

    public function __construct(
        private readonly ProductRelationImporter $importer,
        private readonly EccubeConfigProviderInterface $config
    ) {
        parent::__construct('cosmotec:eccube:import:product-relations');
    }

    protected function configure(): void
    {
        $this->setDescription('Link already-imported Simple Products to their parent Grouped Product ("Group Relations").');
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Report what would be linked without writing to Magento.');
        $this->addOption(self::OPTION_RESUME, null, InputOption::VALUE_NONE, 'Present for CLI consistency; this command is always idempotent (only links what is not yet linked).');
        $this->addOption(self::OPTION_BATCH_SIZE, null, InputOption::VALUE_REQUIRED, 'Override the configured batch size (applies to the item scan, not link count).');
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

        $runId = 'rel-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $output->writeln(sprintf('Starting group relations import (run %s)%s', $runId, $dryRun ? ' [DRY RUN]' : ''));

        $context = new ImportContext($runId, $dryRun, $resume, $batchSize);
        $result = $this->importer->import($context);

        $output->writeln(sprintf(
            'Done. Linked: %d, Skipped items: %d, Errors: %d',
            $result->getImported(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result->getErrors() === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
