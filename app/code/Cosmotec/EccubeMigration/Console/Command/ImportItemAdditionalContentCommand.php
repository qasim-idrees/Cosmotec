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
use Cosmotec\EccubeMigration\Model\Import\ItemAdditionalContentImporter;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Imports dtb_item_additional_information (database-driven per-item HTML
 * tabs) as a true 1:N relation into a dedicated "EC-CUBE Additional
 * Content" admin section, separate from EC-CUBE Specification.
 */
class ImportItemAdditionalContentCommand extends Command
{
    private const OPT_EXECUTE = 'execute';
    private const OPT_DRY_RUN = 'dry-run';
    private const OPT_BATCH_SIZE = 'batch-size';
    private const OPT_LIMIT = 'limit';

    public function __construct(
        private readonly ItemAdditionalContentImporter $importer,
        private readonly EccubeConfigProviderInterface $config,
        private readonly ExecuteModeResolver $executeModeResolver,
        private readonly State $appState
    ) {
        parent::__construct('cosmotec:eccube:import:additional-content');
    }

    protected function configure(): void
    {
        $this->setDescription('Import EC-CUBE item additional-content tabs (dtb_item_additional_information, 1:N HTML) into Magento. Dry-run unless --execute is passed.');
        $this->addOption(self::OPT_EXECUTE, null, InputOption::VALUE_NONE, 'Actually write to Magento. Without this flag the command only reports what it would do.');
        $this->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Explicitly request a dry run (this is also the default).');
        $this->addOption(self::OPT_BATCH_SIZE, null, InputOption::VALUE_REQUIRED, 'Override the configured batch size (items-with-content per page).');
        $this->addOption(self::OPT_LIMIT, null, InputOption::VALUE_REQUIRED, 'Stop after this many items-with-content examined.');
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
        $batchSize = $input->getOption(self::OPT_BATCH_SIZE);
        $limit = $input->getOption(self::OPT_LIMIT);

        $runId = 'addlcontent-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $context = new ImportContext($runId, $dryRun, true, $batchSize !== null ? (int) $batchSize : null);

        $output->writeln(sprintf('Item additional-content import (run %s)%s', $runId, $dryRun ? ' <comment>[DRY RUN]</comment>' : ''));

        $result = $this->importer->importLimited($context, $limit !== null ? (int) $limit : null);

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
