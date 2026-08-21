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
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestConnectionCommand extends Command
{
    public function __construct(
        private readonly EccubeConnectionFactory $connectionFactory,
        private readonly EccubeConfigProviderInterface $config
    ) {
        parent::__construct('cosmotec:eccube:test-connection');
    }

    protected function configure(): void
    {
        $this->setDescription('Verify connectivity to the configured EC-CUBE database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->isEnabled()) {
            $output->writeln(
                '<error>The EC-CUBE Migration module is disabled. '
                . 'Enable it under Stores > Configuration > Cosmotec > EC-CUBE Migration.</error>'
            );

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Connecting to %s:%d/%s as %s ...',
            $this->config->getHost(),
            $this->config->getPort(),
            $this->config->getDatabase(),
            $this->config->getUsername()
        ));

        try {
            $connection = $this->connectionFactory->create();
            $connection->ping();
        } catch (EccubeConnectionException $e) {
            $output->writeln('<error>Connection failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Connection successful.</info>');

        return Command::SUCCESS;
    }
}
