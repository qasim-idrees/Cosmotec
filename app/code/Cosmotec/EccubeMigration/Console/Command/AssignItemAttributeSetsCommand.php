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
use Cosmotec\EccubeMigration\Model\Import\ItemAttributeSetAssignmentImporter;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reassigns each already-imported Grouped Product to the Magento attribute
 * set resolved from its EC-CUBE category tree via AttributeSetResolver.
 * Must run before import:item-attribute-values on any product still on the
 * catalog's disposable staging default set - see BUILD_STATUS.md Round 31.
 */
class AssignItemAttributeSetsCommand extends Command
{
    private const OPTION_EXECUTE = 'execute';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_BATCH_SIZE = 'batch-size';

    public function __construct(
        private readonly ItemAttributeSetAssignmentImporter $importer,
        private readonly EccubeConfigProviderInterface $config,
        private readonly ExecuteModeResolver $executeModeResolver,
        private readonly State $appState
    ) {
        parent::__construct('cosmotec:eccube:assign:item-attribute-sets');
    }

    protected function configure(): void
    {
        $this->setDescription('Reassign Grouped Products to their EC-CUBE-category-derived Magento attribute set. Dry-run unless --execute is passed.');
        $this->addOption(self::OPTION_EXECUTE, null, InputOption::VALUE_NONE, 'Actually reassign Magento product attribute sets. Without this flag the command only reports what it would do.');
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Explicitly request a dry run (this is also the default).');
        $this->addOption(self::OPTION_BATCH_SIZE, null, InputOption::VALUE_REQUIRED, 'Override the configured batch size.');
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

        $execute = (bool) $input->getOption(self::OPTION_EXECUTE);
        $dryRun = $this->executeModeResolver->isDryRun((bool) $input->getOption(self::OPTION_DRY_RUN), $execute);

        if ($dryRun) {
            $output->writeln('<comment>DRY RUN — no Magento product attribute-set assignments will be changed.</comment>');
            $output->writeln('<comment>Pass --execute to apply changes.</comment>');
        } else {
            $output->writeln('<info>EXECUTING — Grouped Product attribute-set assignments will be changed.</info>');
        }

        $output->writeln('');

        $batchSizeOption = $input->getOption(self::OPTION_BATCH_SIZE);
        $runId = 'itemsetassign-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $context = new ImportContext(
            $runId,
            $dryRun,
            true,
            $batchSizeOption !== null ? (int) $batchSizeOption : null
        );

        $result = $this->importer->import($context);

        $output->writeln('');
        $output->writeln(sprintf(
            'Done. Assigned: %d, Updated: %d, Skipped (already correct): %d, Errors: %d, Needs review: %d (total: %d)',
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors(),
            $result->getNeedsReview(),
            $result->getTotalProcessed()
        ));
        $output->writeln('<comment>"Needs review" items have no resolvable EC-CUBE top-level category chain and need an explicit fallback bucket before they can be assigned.</comment>');

        return $result->getErrors() === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
