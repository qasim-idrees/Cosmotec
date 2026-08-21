<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Console\Command;

use Cosmotec\EccubeMigration\Model\Reader\CategoryReader;
use Cosmotec\EccubeMigration\Model\Validator\CategoryValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ValidateCategoriesCommand extends Command
{
    private const MAX_ERRORS_PRINTED = 50;

    public function __construct(
        private readonly CategoryReader $reader,
        private readonly CategoryValidator $validator
    ) {
        parent::__construct('cosmotec:eccube:validate:categories');
    }

    protected function configure(): void
    {
        $this->setDescription('Validate all EC-CUBE categories without writing anything to Magento.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $total = 0;
        $validCount = 0;
        $invalidCount = 0;
        $printedErrors = 0;

        foreach ($this->reader->read() as $category) {
            $total++;
            $validation = $this->validator->validate($category);

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

        if ($invalidCount > self::MAX_ERRORS_PRINTED) {
            $output->writeln(sprintf(
                '... and %d more invalid categories not shown.',
                $invalidCount - self::MAX_ERRORS_PRINTED
            ));
        }

        $output->writeln(sprintf(
            'Checked %d categories: %d valid, %d invalid.',
            $total,
            $validCount,
            $invalidCount
        ));

        return $invalidCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
