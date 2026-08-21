<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Console\Command;

use Cosmotec\EccubeMigration\Logger\Diagnostics\ExceptionFormatter;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Isolates whether a Magento product can be saved at all, independently of
 * media.
 *
 * Answers the question "is the gallery operation failing, or is this
 * product simply unsaveable?" without changing any product data: the
 * optional save attempt re-saves the product exactly as loaded.
 */
class DiagnoseProductCommand extends Command
{
    private const OPT_PRODUCT_ID = 'product-id';
    private const OPT_SKU = 'sku';
    private const OPT_ATTEMPT_SAVE = 'attempt-save';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ExceptionFormatter $exceptionFormatter,
        private readonly State $appState
    ) {
        parent::__construct('cosmotec:eccube:diagnose:product');
    }

    protected function configure(): void
    {
        $this->setDescription('Diagnose whether a Magento product loads and can be saved (used to isolate media-gallery failures).');
        $this->addOption(self::OPT_PRODUCT_ID, null, InputOption::VALUE_REQUIRED, 'Magento product entity id.');
        $this->addOption(self::OPT_SKU, null, InputOption::VALUE_REQUIRED, 'Magento product SKU (alternative to --product-id).');
        $this->addOption(
            self::OPT_ATTEMPT_SAVE,
            null,
            InputOption::VALUE_NONE,
            'Attempt a controlled re-save with NO media changes, to surface the real validation error. '
            . 'The product is saved exactly as loaded - no field is modified.'
        );
        $this->setHelp(
            "Loads a Magento product and reports the attributes that most often block a save\n"
            . "(type, status, visibility, attribute set, website assignment, required fields).\n\n"
            . "With --attempt-save the product is re-saved unchanged. If that fails, the problem\n"
            . "is the product itself, not the media import, and the full exception chain is printed."
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Throwable) {
            $this->appState->setAreaCode('adminhtml');
        }

        $productId = $input->getOption(self::OPT_PRODUCT_ID);
        $sku = $input->getOption(self::OPT_SKU);

        if ($productId === null && $sku === null) {
            $output->writeln('<error>Provide --product-id or --sku.</error>');

            return Command::FAILURE;
        }

        try {
            $product = $productId !== null
                ? $this->productRepository->getById((int) $productId)
                : $this->productRepository->get((string) $sku);
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not load product.</error>');
            $output->writeln($this->exceptionFormatter->format($e, ['operation' => 'product_load']));

            return Command::FAILURE;
        }

        $websiteIds = $product->getWebsiteIds();
        $gallery = $product->getMediaGalleryEntries() ?? [];

        $table = new Table($output);
        $table->setHeaders(['Property', 'Value']);
        $table->addRows([
            ['Entity ID', (string) $product->getId()],
            ['SKU', (string) $product->getSku()],
            ['Name', (string) $product->getName()],
            ['Type', (string) $product->getTypeId()],
            ['Attribute set ID', (string) $product->getAttributeSetId()],
            ['Status', (string) $product->getStatus()],
            ['Visibility', (string) $product->getVisibility()],
            ['Website IDs', $websiteIds === [] ? '(none - a product with no website often fails to save)' : implode(', ', $websiteIds)],
            ['Store ID', (string) $product->getStoreId()],
            ['Price', (string) $product->getPrice()],
            ['Gallery entries', (string) count($gallery)],
        ]);
        $table->render();

        foreach ($gallery as $index => $entry) {
            $output->writeln(sprintf(
                '  gallery[%d] file=%s types=[%s] disabled=%s position=%s',
                $index,
                (string) $entry->getFile(),
                implode(',', $entry->getTypes() ?? []),
                $entry->isDisabled() ? 'yes' : 'no',
                (string) $entry->getPosition()
            ));
        }

        $output->writeln('');

        if (!$input->getOption(self::OPT_ATTEMPT_SAVE)) {
            $output->writeln('<comment>Pass --attempt-save to test whether this product can be saved unchanged.</comment>');

            return Command::SUCCESS;
        }

        $output->writeln('<comment>Attempting a controlled re-save with no modifications...</comment>');

        try {
            $this->productRepository->save($product);
            $output->writeln('<info>SAVE OK - the product saves cleanly, so a media import failure is in the gallery operation.</info>');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>SAVE FAILED - the product cannot be saved even without media changes.</error>');
            $output->writeln($this->exceptionFormatter->format($e, [
                'operation' => 'controlled_product_save',
                'magento_product_id' => (string) $product->getId(),
                'sku' => (string) $product->getSku(),
            ]));

            return Command::FAILURE;
        }
    }
}
