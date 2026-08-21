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
use Cosmotec\EccubeMigration\Model\Sync\RelatedProductSync;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * NOT YET APPROVED FOR EXECUTION - see BUILD_STATUS.md. Also marks
 * removed source relations obsolete (see RelatedProductSync).
 */
class SyncRelatedProductsCommand extends Command
{
    private const OPTION_EXECUTE = 'execute';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_BATCH_SIZE = 'batch-size';

    public function __construct(
        private readonly RelatedProductSync $sync,
        private readonly EccubeConfigProviderInterface $config,
        private readonly ExecuteModeResolver $executeModeResolver
    ) {
        parent::__construct('cosmotec:eccube:sync:related-products');
    }

    protected function configure(): void
    {
        $this->setDescription('Re-sync EC-CUBE related products, including marking removed relations obsolete. Dry-run unless --execute is passed.');
        $this->addOption(self::OPTION_EXECUTE, null, InputOption::VALUE_NONE, 'Actually write. Without this flag the command only reports what it would do.');
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
        $dryRun = $this->executeModeResolver->isDryRun((bool) $input->getOption(self::OPTION_DRY_RUN), $execute);

        $output->writeln($dryRun
            ? '<comment>DRY RUN — nothing will be written, obsolete-marking is skipped. Pass --execute to apply changes.</comment>'
            : '<info>EXECUTING.</info>');

        $batchSizeOption = $input->getOption(self::OPTION_BATCH_SIZE);
        $runId = 'sync-related-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $context = new ImportContext($runId, $dryRun, true, $batchSizeOption !== null ? (int) $batchSizeOption : null);

        $result = $this->sync->import($context);

        $output->writeln(sprintf(
            'Done. Linked: %d, Skipped: %d, Errors: %d (total: %d)',
            $result->getImported(),
            $result->getSkipped(),
            $result->getErrors(),
            $result->getTotalProcessed()
        ));

        return $result->getErrors() === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
