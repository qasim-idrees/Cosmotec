<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\Data\ImageInterface as EccubeImageInterface;
use Cosmotec\EccubeMigration\Api\Data\ProductInterface as EccubeProductInterface;
use Cosmotec\EccubeMigration\Api\ImageMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ImageRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\DTO\MagentoImage;
use Cosmotec\EccubeMigration\Model\ImageMap;
use Cosmotec\EccubeMigration\Model\ImageMapFactory;
use Cosmotec\EccubeMigration\Model\Mapper\ImageMapper;
use Cosmotec\EccubeMigration\Model\ProductMap;
use Cosmotec\EccubeMigration\Model\Reader\ProductReader;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Cosmotec\EccubeMigration\Model\Validator\ImageValidator;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Milestone 6 (Images). Iterates already-imported Simple Products (via
 * ProductReader + ProductMapRepository, not the global ImageReader from
 * Milestone 2) so that role assignment — which image gets the base/
 * small_image/thumbnail roles versus gallery-only — can be decided per
 * product. Images belonging to a product that hasn't been imported yet are
 * counted as skipped, not errored: a later run picks them up once the
 * product exists.
 */
/**
 * @deprecated LEGACY - NOT USED BY THE PRODUCTION PIPELINE.
 *
 * This class reads dtb_product_image, which contains ZERO rows in the
 * Cosmotec production database. Real media lives in dtb_upload_file
 * joined through seven catalog relation tables.
 *
 * The production path is now:
 *   MediaReader -> MediaValidator -> MediaImporter -> eccube_media_map
 *   (commands: import:images, sync:images)
 *
 * Retained only so existing eccube_image_map rows remain readable. Do not
 * extend, and do not write new data through it.
 */
class ImageImporter implements ImporterInterface
{
    public function __construct(
        private readonly ProductReader $productReader,
        private readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly ImageRepositoryInterface $eccubeImageRepository,
        private readonly ImageValidator $validator,
        private readonly ImageMapper $mapper,
        private readonly ImageMapRepositoryInterface $imageMapRepository,
        private readonly ImageMapFactory $imageMapFactory,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly ProductAttributeMediaGalleryManagementInterface $mediaGalleryManagement,
        private readonly ProductAttributeMediaGalleryEntryInterfaceFactory $mediaGalleryEntryFactory,
        private readonly ImageContentInterfaceFactory $imageContentFactory,
        private readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();

        foreach ($this->productReader->read(0, $context->getBatchSize()) as $product) {
            /** @var EccubeProductInterface $product */
            $this->importProductImages($product, $context, $result);
        }

        $this->logger->info(sprintf(
            'ImageImporter run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    private function importProductImages(EccubeProductInterface $product, ImportContext $context, ImportResult $result): void
    {
        $images = $this->eccubeImageRepository->getByProductId($product->getId());

        if ($images === []) {
            return;
        }

        $productMap = $this->productMapRepository->getByEccubeProductId($product->getId());

        if ($productMap === null || $productMap->getMagentoProductId() === null || $productMap->getSku() === null) {
            $result->incrementSkipped(count($images));
            $this->logger->info(sprintf(
                'Product id=%d not yet imported into Magento; skipping its %d image(s) for now.',
                $product->getId(),
                count($images)
            ));

            return;
        }

        foreach ($images as $image) {
            /** @var EccubeImageInterface $image */
            $this->importOne($image, $productMap, $context, $result);
        }
    }

    private function importOne(EccubeImageInterface $image, ProductMap $productMap, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $validation = $this->validator->validate($image);

            if (!$validation->isValid()) {
                $this->handleError($image, $context, $result, $validation->getErrorsAsString(), $startTime, $startMemory);

                return;
            }

            $mapped = $this->mapper->map($image);
            $existingMap = $this->imageMapRepository->getByEccubeImageId($image->getId());

            if ($this->isUnchanged($existingMap, $mapped)) {
                $result->incrementSkipped();
                $this->recordHistory($context, $image->getId(), (int) $productMap->getMagentoProductId(), SyncHistory::STATUS_SKIPPED, 'Unchanged', $startTime, $startMemory);

                return;
            }

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info(sprintf(
                    '[DRY RUN] Would %s image "%s" for product sku=%s (%s)',
                    $existingMap?->getGalleryValueId() !== null ? 'replace' : 'upload',
                    $mapped->getFileName(),
                    $productMap->getSku(),
                    $mapped->isMain() ? 'main' : 'gallery'
                ));

                return;
            }

            $this->persist($mapped, $productMap, $existingMap, $context, $result, $startTime, $startMemory);
        } catch (LocalizedException | \Throwable $e) {
            $this->handleError($image, $context, $result, $e->getMessage(), $startTime, $startMemory);
        }
    }

    private function isUnchanged(?ImageMap $existingMap, MagentoImage $mapped): bool
    {
        if ($existingMap === null || $existingMap->getGalleryValueId() === null) {
            return false;
        }

        if (!in_array($existingMap->getStatus(), [ImageMap::STATUS_IMPORTED, ImageMap::STATUS_UPDATED], true)) {
            return false;
        }

        return $existingMap->getContentHash() === $mapped->getContentHash();
    }

    private function persist(
        MagentoImage $mapped,
        ProductMap $productMap,
        ?ImageMap $existingMap,
        ImportContext $context,
        ImportResult $result,
        float $startTime,
        int $startMemory
    ): void {
        $sku = (string) $productMap->getSku();
        $isReplace = $existingMap !== null && $existingMap->getGalleryValueId() !== null;

        if ($isReplace) {
            // Changed-image detection: the old binary content can't be
            // updated in place through this API, so remove the stale entry
            // and create a fresh one.
            $this->mediaGalleryManagement->remove($sku, (int) $existingMap->getGalleryValueId());
        }

        $binaryContent = file_get_contents($mapped->getAbsolutePath());

        if ($binaryContent === false) {
            throw new \RuntimeException(sprintf('Could not read file "%s"', $mapped->getAbsolutePath()));
        }

        $imageContent = $this->imageContentFactory->create();
        $imageContent->setBase64EncodedData(base64_encode($binaryContent));
        $imageContent->setType($this->detectMimeType($mapped->getAbsolutePath()));
        $imageContent->setName($mapped->getFileName());

        $entry = $this->mediaGalleryEntryFactory->create();
        $entry->setMediaType('image');
        $entry->setLabel($mapped->getFileName());
        $entry->setPosition($mapped->getPosition());
        $entry->setDisabled(false);
        $entry->setTypes($mapped->isMain() ? ['image', 'small_image', 'thumbnail'] : []);
        $entry->setContent($imageContent);

        $galleryValueId = $this->mediaGalleryManagement->create($sku, $entry);

        /** @var ImageMap $map */
        $map = $existingMap ?? $this->imageMapFactory->create();
        $map->setEccubeImageId($mapped->getEccubeImageId());
        $map->setEccubeProductId($mapped->getEccubeProductId());
        $map->setMagentoProductId($productMap->getMagentoProductId());
        $map->setGalleryValueId((int) $galleryValueId);
        $map->setFileName($mapped->getFileName());
        $map->setContentHash($mapped->getContentHash());
        $map->setIsMain($mapped->isMain() ? 1 : 0);
        $map->setStatus($isReplace ? ImageMap::STATUS_UPDATED : ImageMap::STATUS_IMPORTED);
        $map->setErrorMessage(null);
        $map->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->imageMapRepository->save($map);

        if ($isReplace) {
            $result->incrementUpdated();
        } else {
            $result->incrementImported();
        }

        $this->logger->info(sprintf(
            'Image id=%d %s for product sku=%s (%s, gallery value id=%d)',
            $mapped->getEccubeImageId(),
            $isReplace ? 'replaced' : 'uploaded',
            $sku,
            $mapped->isMain() ? 'main' : 'gallery',
            $galleryValueId
        ));

        $this->recordHistory(
            $context,
            $mapped->getEccubeImageId(),
            $productMap->getMagentoProductId(),
            $isReplace ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
            null,
            $startTime,
            $startMemory
        );
    }

    private function detectMimeType(string $absolutePath): string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo !== false) {
            $mime = finfo_file($finfo, $absolutePath);
            finfo_close($finfo);

            if (is_string($mime) && str_starts_with($mime, 'image/')) {
                return $mime;
            }
        }

        return match (strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            default => 'application/octet-stream',
        };
    }

    private function handleError(
        EccubeImageInterface $image,
        ImportContext $context,
        ImportResult $result,
        string $message,
        float $startTime,
        int $startMemory
    ): void {
        $result->incrementErrors();
        $this->logger->error(sprintf('Image id=%d failed: %s', $image->getId(), $message));

        if (!$context->isDryRun()) {
            $existingMap = $this->imageMapRepository->getByEccubeImageId($image->getId());
            /** @var ImageMap $map */
            $map = $existingMap ?? $this->imageMapFactory->create();
            $map->setEccubeImageId($image->getId());
            $map->setEccubeProductId((int) $image->getProductId());
            $map->setStatus(ImageMap::STATUS_ERROR);
            $map->setErrorMessage($message);
            $this->imageMapRepository->save($map);
        }

        $this->recordHistory($context, $image->getId(), null, SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);
    }

    private function recordHistory(
        ImportContext $context,
        int $sourceId,
        ?int $targetId,
        string $status,
        ?string $message,
        float $startTime,
        int $startMemory
    ): void {
        if ($context->isDryRun()) {
            return;
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $memoryBytes = max(0, memory_get_usage(true) - $startMemory);

        $this->syncHistoryRepository->record(
            $context->getRunId(),
            SyncHistory::ENTITY_TYPE_IMAGE,
            SyncHistory::OPERATION_IMPORT,
            $sourceId,
            $targetId,
            $status,
            $message,
            $durationMs,
            $memoryBytes
        );
    }
}
