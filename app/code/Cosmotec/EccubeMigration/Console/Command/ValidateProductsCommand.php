<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Console\Command;

use Cosmotec\EccubeMigration\Model\Reader\ItemReader;
use Cosmotec\EccubeMigration\Model\Reader\ProductReader;
use Cosmotec\EccubeMigration\Model\Validator\ItemValidator;
use Cosmotec\EccubeMigration\Model\Validator\ProductValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Corresponds to the spec's `cosmotec:eccube:validate:products` command.
 * Validates both dtb_item rows (Grouped Product parents, Milestone 4) and
 * dtb_product rows (Simple Products, Milestone 5) — the spec's CLI naming
 * covers both under "products", so this stays one command rather than two.
 */
class ValidateProductsCommand extends Command
{
    private const MAX_ERRORS_PRINTED = 50;

    public function __construct(
        private readonly ItemReader $itemReader,
        private readonly ItemValidator $itemValidator,
        private readonly ProductReader $productReader,
        private readonly ProductValidator $productValidator
    ) {
        parent::__construct('cosmotec:eccube:validate:products');
    }

    protected function configure(): void
    {
        $this->setDescription('Validate EC-CUBE items and products without writing anything to Magento.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $printedErrors = 0;
        [$itemTotal, $itemValid, $itemInvalid, $printedErrors] = $this->validateItems($output, $printedErrors);
        [$productTotal, $productValid, $productInvalid, $printedErrors] = $this->validateProducts($output, $printedErrors);

        $output->writeln(sprintf(
            'Items: checked %d, %d valid, %d invalid.',
            $itemTotal,
            $itemValid,
            $itemInvalid
        ));
        $output->writeln(sprintf(
            'Products: checked %d, %d valid, %d invalid.',
            $productTotal,
            $productValid,
            $productInvalid
        ));

        return ($itemInvalid === 0 && $productInvalid === 0) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return array{int, int, int, int} [total, valid, invalid, printedErrors]
     */
    private function validateItems(OutputInterface $output, int $printedErrors): array
    {
        $total = 0;
        $valid = 0;
        $invalid = 0;

        foreach ($this->itemReader->read() as $item) {
            $total++;
            $validation = $this->itemValidator->validate($item);

            if ($validation->isValid()) {
                $valid++;

                continue;
            }

            $invalid++;

            if ($printedErrors < self::MAX_ERRORS_PRINTED) {
                $output->writeln('<error>' . $validation->getErrorsAsString() . '</error>');
                $printedErrors++;
            }
        }

        return [$total, $valid, $invalid, $printedErrors];
    }

    /**
     * @return array{int, int, int, int} [total, valid, invalid, printedErrors]
     */
    private function validateProducts(OutputInterface $output, int $printedErrors): array
    {
        $total = 0;
        $valid = 0;
        $invalid = 0;

        foreach ($this->productReader->read() as $product) {
            $total++;
            $validation = $this->productValidator->validate($product);

            if ($validation->isValid()) {
                $valid++;

                continue;
            }

            $invalid++;

            if ($printedErrors < self::MAX_ERRORS_PRINTED) {
                $output->writeln('<error>' . $validation->getErrorsAsString() . '</error>');
                $printedErrors++;
            }
        }

        return [$total, $valid, $invalid, $printedErrors];
    }
}
