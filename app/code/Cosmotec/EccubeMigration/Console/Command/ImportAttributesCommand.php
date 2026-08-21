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
use Cosmotec\EccubeMigration\Model\Import\AttributeImporter;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Phase 4 — creates Magento product EAV attributes and options from
 * EC-CUBE specifications.
 *
 * This is the first command in the module that MUTATES Magento EAV.
 * It therefore requires an explicit --execute flag: running it without
 * one performs a dry run, so an accidental invocation cannot create ~319
 * attributes and ~7,364 options unintentionally.
 */
class ImportAttributesCommand extends Command
{
    private const OPTION_EXECUTE = 'execute';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_BATCH_SIZE = 'batch-size';

    public function __construct(
        private readonly AttributeImporter $importer,
        private readonly EccubeConfigProviderInterface $config
    ) {
        parent::__construct('cosmotec:eccube:import:attributes');
    }

    protected function configure(): void
    {
        $this->setDescription('Create Magento product attributes and options from EC-CUBE specifications. Dry-run unless --execute is passed.');
        $this->addOption(self::OPTION_EXECUTE, null, InputOption::VALUE_NONE, 'Actually create Magento EAV attributes/options. Without this flag the command only reports what it would do.');
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
            $output->writeln('<comment>DRY RUN — no Magento attributes, options or mappings will be created.</comment>');
            $output->writeln('<comment>Pass --execute to apply changes.</comment>');
        } else {
            $output->writeln('<info>EXECUTING — Magento EAV attributes and options will be created/updated.</info>');
        }

        $output->writeln('');

        $batchSizeOption = $input->getOption(self::OPTION_BATCH_SIZE);
        $runId = 'attr-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

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
        $output->writeln('<comment>Skipped specifications are recorded with an explicit reason in eccube_specification_map.</comment>');

        return $result->getErrors() === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
