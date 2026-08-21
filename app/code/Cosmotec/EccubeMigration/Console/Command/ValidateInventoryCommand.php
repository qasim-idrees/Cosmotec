<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Console\Command;

use Cosmotec\EccubeMigration\Model\Reader\InventoryReader;
use Cosmotec\EccubeMigration\Model\Validator\InventoryValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ValidateInventoryCommand extends Command
{
    private const MAX_ERRORS_PRINTED = 50;

    public function __construct(
        private readonly InventoryReader $reader,
        private readonly InventoryValidator $validator
    ) {
        parent::__construct('cosmotec:eccube:validate:inventory');
    }

    protected function configure(): void
    {
        $this->setDescription('Validate EC-CUBE inventory data without writing anything to Magento.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $total = 0;
        $validCount = 0;
        $invalidCount = 0;
        $printedErrors = 0;

        foreach ($this->reader->read() as $record) {
            $total++;
            $validation = $this->validator->validate($record);

            if ($validation->isValid()) {
                $validCount++;

                continue;
            }

            $invalidCount++;

            if ($printedErrors < self::MAX_ERRORS_PRINTED) {
                $output->writeln('<error>' . $validation->getErrorsAsString() . '</error>');
                $printedErrors++;
            }
        }

        $output->writeln(sprintf(
            'Checked %d inventory records: %d valid, %d invalid.',
            $total,
            $validCount,
            $invalidCount
        ));

        return $invalidCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
