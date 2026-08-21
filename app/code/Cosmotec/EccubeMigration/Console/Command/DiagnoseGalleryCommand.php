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
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Isolates exactly which gallery mutation causes a product save to fail.
 *
 * Runs the media-gallery write in escalating stages so the failing step is
 * identifiable rather than guessed:
 *
 *   TEST 1  gallery image only, no roles
 *   TEST 2  + image/small_image/thumbnail roles
 *   TEST 3  + label / position / disabled metadata
 *
 * Each stage prints the product's gallery state before and after, and any
 * failure is reported with the complete exception chain.
 *
 * Writes only with --execute; the default inspects and reports.
 */
class DiagnoseGalleryCommand extends Command
{
    private const OPT_PRODUCT_ID = 'product-id';
    private const OPT_FILE = 'file';
    private const OPT_STAGE = 'stage';
    private const OPT_EXECUTE = 'execute';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductAttributeMediaGalleryEntryInterfaceFactory $entryFactory,
        private readonly ImageContentInterfaceFactory $imageContentFactory,
        private readonly ExceptionFormatter $exceptionFormatter,
        private readonly State $appState
    ) {
        parent::__construct('cosmotec:eccube:diagnose:gallery');
    }

    protected function configure(): void
    {
        $this->setDescription('Isolate which media-gallery mutation causes a Magento product save to fail.');
        $this->addOption(self::OPT_PRODUCT_ID, null, InputOption::VALUE_REQUIRED, 'Magento product entity id.');
        $this->addOption(self::OPT_FILE, null, InputOption::VALUE_REQUIRED, 'Absolute path to a source image on this server.');
        $this->addOption(self::OPT_STAGE, null, InputOption::VALUE_REQUIRED, '1 = image only, 2 = + roles, 3 = + label/position/disabled. Default 1.');
        $this->addOption(self::OPT_EXECUTE, null, InputOption::VALUE_NONE, 'Actually attempt the save. Without this the command only inspects and reports.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Throwable) {
            $this->appState->setAreaCode('adminhtml');
        }

        $productId = $input->getOption(self::OPT_PRODUCT_ID);
        $filePath = $input->getOption(self::OPT_FILE);

        if ($productId === null || $filePath === null) {
            $output->writeln('<error>Both --product-id and --file are required.</error>');

            return Command::FAILURE;
        }

        $productId = (int) $productId;
        $filePath = (string) $filePath;
        $stage = (int) ($input->getOption(self::OPT_STAGE) ?? 1);
        $execute = (bool) $input->getOption(self::OPT_EXECUTE);

        // STEP: source file facts
        $output->writeln('<comment>Source file</comment>');

        if (!is_file($filePath) || !is_readable($filePath)) {
            $output->writeln(sprintf('<error>  Not readable: %s</error>', $filePath));

            return Command::FAILURE;
        }

        $size = filesize($filePath);
        $imageInfo = @getimagesize($filePath);
        $output->writeln(sprintf('  path       : %s', $filePath));
        $output->writeln(sprintf('  size       : %s bytes', $size === false ? '?' : (string) $size));
        $output->writeln(sprintf('  mime       : %s', $imageInfo['mime'] ?? '(getimagesize failed - not a valid image?)'));
        $output->writeln(sprintf(
            '  dimensions : %s',
            $imageInfo === false ? '(unknown)' : sprintf('%dx%d', $imageInfo[0], $imageInfo[1])
        ));
        $output->writeln(sprintf('  perms      : %s', substr(sprintf('%o', @fileperms($filePath)), -4)));
        $output->writeln('');

        // STEP A: product state
        try {
            $product = $this->productRepository->getById($productId, true);
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not load product.</error>');
            $output->writeln($this->exceptionFormatter->format($e, ['operation' => 'product_load']));

            return Command::FAILURE;
        }

        $before = $product->getMediaGalleryEntries() ?? [];
        $output->writeln('<comment>STEP A - product before</comment>');
        $this->dumpProduct($output, $product, $before);

        // STEP B/C: build the entry for the requested stage
        $binary = file_get_contents($filePath);

        if ($binary === false) {
            $output->writeln('<error>PHP could not read the file contents.</error>');

            return Command::FAILURE;
        }

        $imageContent = $this->imageContentFactory->create();
        $imageContent->setBase64EncodedData(base64_encode($binary));
        $imageContent->setType($imageInfo['mime'] ?? 'image/jpeg');
        $imageContent->setName(basename($filePath));

        $entry = $this->entryFactory->create();
        $entry->setMediaType('image');
        $entry->setContent($imageContent);

        $applied = ['media_type', 'content'];

        if ($stage >= 2) {
            $entry->setTypes(['image', 'small_image', 'thumbnail']);
            $applied[] = 'types=image,small_image,thumbnail';
        } else {
            $entry->setTypes([]);
            $applied[] = 'types=[]';
        }

        if ($stage >= 3) {
            $entry->setLabel(basename($filePath));
            $entry->setPosition(0);
            $entry->setDisabled(false);
            $applied[] = 'label';
            $applied[] = 'position=0';
            $applied[] = 'disabled=false';
        }

        $output->writeln(sprintf('<comment>STEP B/C - stage %d entry</comment>', $stage));
        $output->writeln('  applied: ' . implode(', ', $applied));
        $output->writeln('');

        if (!$execute) {
            $output->writeln('<comment>Inspection only. Pass --execute to attempt the save.</comment>');

            return Command::SUCCESS;
        }

        // STEP D: the actual save, with the full exception tree on failure
        $entries = $before;
        $entries[] = $entry;
        $product->setMediaGalleryEntries($entries);

        $output->writeln('<comment>STEP D - ProductRepository::save()</comment>');

        try {
            $this->productRepository->save($product);
        } catch (\Throwable $e) {
            $output->writeln('<error>  SAVE FAILED</error>');
            $output->writeln('  ' . $this->exceptionFormatter->format($e, [
                'operation' => 'gallery_save_stage_' . $stage,
                'magento_product_id' => (string) $productId,
                'sku' => (string) $product->getSku(),
                'source_file' => $filePath,
                'entries_before' => (string) count($before),
            ]));

            return Command::FAILURE;
        }

        $reloaded = $this->productRepository->getById($productId, false, null, true);
        $after = $reloaded->getMediaGalleryEntries() ?? [];

        $output->writeln('<info>  SAVE OK</info>');
        $output->writeln('');
        $output->writeln('<comment>Product after</comment>');
        $this->dumpProduct($output, $reloaded, $after);

        if (count($after) <= count($before)) {
            $output->writeln(sprintf(
                '<error>WARNING: entry count did not increase (%d -> %d). '
                . 'The save succeeded but the gallery was not persisted - '
                . 'this is the "file copied, 0 entries" symptom.</error>',
                count($before),
                count($after)
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<int, \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface> $entries
     */
    private function dumpProduct(OutputInterface $output, $product, array $entries): void
    {
        $output->writeln(sprintf('  id=%s sku=%s type=%s', $product->getId(), $product->getSku(), $product->getTypeId()));
        $output->writeln(sprintf(
            '  attribute_set=%s store=%s websites=[%s]',
            $product->getAttributeSetId(),
            $product->getStoreId(),
            implode(',', $product->getWebsiteIds() ?: [])
        ));
        $output->writeln(sprintf(
            '  image=%s small_image=%s thumbnail=%s',
            (string) $product->getData('image'),
            (string) $product->getData('small_image'),
            (string) $product->getData('thumbnail')
        ));
        $output->writeln(sprintf('  gallery entries=%d', count($entries)));

        foreach ($entries as $i => $entry) {
            $output->writeln(sprintf(
                '    [%d] id=%s file=%s types=[%s] disabled=%s position=%s',
                $i,
                (string) $entry->getId(),
                (string) $entry->getFile(),
                implode(',', $entry->getTypes() ?? []),
                $entry->isDisabled() ? 'yes' : 'no',
                (string) $entry->getPosition()
            ));
        }

        $output->writeln('');
    }
}
