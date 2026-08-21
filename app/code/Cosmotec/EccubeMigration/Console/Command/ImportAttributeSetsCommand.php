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
use Cosmotec\EccubeMigration\Model\Import\AttributeSetImporter;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * NOT YET APPROVED FOR EXECUTION - see BUILD_STATUS.md. Creates/updates
 * the 8 Magento Attribute Sets derived from EC-CUBE's top-level
 * categories and assigns every CREATE-classified specification used
 * anywhere in that category tree. Requires import:attributes to have
 * already run.
 *
 * Mutates Magento EAV configuration - same --execute safety convention as
 * import:attributes: dry-run unless --execute is explicitly passed.
 */
class ImportAttributeSetsCommand extends Command
{
    private const OPTION_EXECUTE = 'execute';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_BATCH_SIZE = 'batch-size';

    public function __construct(
        private readonly AttributeSetImporter $importer,
        private readonly EccubeConfigProviderInterface $config
    ) {
        parent::__construct('cosmotec:eccube:import:attribute-sets');
    }

    protected function configure(): void
    {
        $this->setDescription('Create/update Magento Attribute Sets from EC-CUBE top-level categories. Dry-run unless --execute is passed.');
        $this->addOption(self::OPTION_EXECUTE, null, InputOption::VALUE_NONE, 'Actually create/update Magento attribute sets. Without this flag the command only reports what it would do.');
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Explicitly request a dry run (this is also the default).');
        $this->addOption(self::OPTION_BATCH_SIZE, null, InputOption::VALUE_REQUIRED, 'Override the configured batch size.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('<error>The EC-CUBE Migration module is disabled in Stores > Configuration.</error>');

            return Command::FAILURE;
        }

        $execute = (bool) $input->getOption(self::OPTION_EXECUTE);
        $dryRun = !$execute || (bool) $input->getOption(self::OPTION_DRY_RUN) || $this->config->isDryRunByDefault();

        if ($dryRun) {
            $output->writeln('<comment>DRY RUN — no Magento attribute sets will be created or modified.</comment>');
            $output->writeln('<comment>Pass --execute to apply changes.</comment>');
        } else {
            $output->writeln('<info>EXECUTING — Magento attribute sets will be created/updated.</info>');
        }

        $output->writeln('<comment>Note: EC-CUBE has no Attribute Set concept. This is a Magento design decision (docs/ATTRIBUTE_MIGRATION_PLAN.md §7).</comment>');
        $output->writeln('');

        $batchSizeOption = $input->getOption(self::OPTION_BATCH_SIZE);
        $runId = 'attrset-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $context = new ImportContext(
            $runId,
            $dryRun,
            true,
            $batchSizeOption !== null ? (int) $batchSizeOption : null
        );

        $result = $this->importer->import($context);

        $output->writeln('');
        $output->writeln(sprintf(
            'Done. Created: %d, Updated: %d, Skipped: %d, Errors: %d (total: %d)',
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors(),
            $result->getTotalProcessed()
        ));

        return $result->getErrors() === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
