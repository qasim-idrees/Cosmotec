<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Media;

use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use Cosmotec\EccubeMigration\Model\MediaMap;
use Cosmotec\EccubeMigration\Model\ResourceModel\MediaMap\CollectionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * One-time correction pass for Dimension Image / CAD 2D / CAD 3D media
 * that was imported before the dedicated fields existed (see
 * Setup\Patch\Data\CreateDocumentAttributes and MediaImporter's
 * attachDedicatedFileField() routing, added this round). Every row this
 * class touches already has a successful eccube_media_map entry
 * (status=imported) from the OLD scheme (dimension: added to the product
 * gallery, disabled; cad2d/cad3d: copied to
 * pub/media/cosmotec/eccube/cad2d|cad3d/) - this class does NOT re-derive
 * anything from EC-CUBE's raw dimension_upload_file/cad2d_upload_file/
 * cad3d_upload_file join tables (relation_type on eccube_media_map is
 * already the authoritative classification, resolved once at original
 * import time), and does NOT touch that gallery entry or the old copied
 * file - it only ever ADDS the new dedicated-field value.
 *
 * Idempotent by construction: correctness is judged by comparing the
 * product's CURRENT dedicated-attribute value against the expected new
 * relative path, not by any separate tracking flag - a second run finds
 * the attribute already correct and changes nothing.
 */
class DimensionCadMediaCorrector
{
    public function __construct(
        private readonly CollectionFactory $mediaMapCollectionFactory,
        private readonly DocumentUploader $documentUploader,
        private readonly EccubeConfigProviderInterface $config,
        private readonly MagentoProductRepositoryInterface $magentoProductRepository,
        private readonly ImportLogger $logger
    ) {
    }

    private const MAX_ERROR_SAMPLE = 50;

    /**
     * Streams through eccube_media_map in pages (CLAUDE.md media
     * performance rule: never load the complete dataset into memory -
     * dimension alone is 17,069 rows). Aggregate outcome counts land on
     * $result; only a capped sample of genuine errors is kept in memory
     * for the CLI report, since needs_review at this scale (thousands,
     * all SOURCE_FILE_NOT_FOUND) is already a known, fully-explained
     * source-data condition that does not need per-row output.
     *
     * @return array<int, array{eccube_owner_id:int, sku:?string, error:string}>
     */
    public function correct(string $relationType, string $subDir, string $attributeCode, bool $dryRun, ImportResult $result, int $batchSize = 200, ?int $limit = null): array
    {
        $errorSample = [];
        $page = 1;
        $processed = 0;
        // A handful of products (9/17,069 for dimension, live-confirmed)
        // have more than one source row. The dedicated attribute holds
        // exactly one value per product, so only the first row per
        // product (sort_no ASC, entity_id ASC - same tie-break MediaRepository
        // already uses for primary-image selection) is ever written; every
        // later row for the same product is a no-op duplicate. Tracking
        // only entity ids here (not full row data) stays well within the
        // "never load the complete dataset into memory" rule. Without this,
        // two rows for the same product would keep overwriting each
        // other's value on every run - a genuine idempotency violation
        // live-confirmed this round (18 spurious "corrections" on a
        // second, otherwise-unchanged run) - fixed by this tracking.
        $handledProductIds = [];

        while (true) {
            $collection = $this->mediaMapCollectionFactory->create();
            $collection->addFieldToFilter('relation_type', $relationType);
            $collection->addFieldToFilter('status', MediaMap::STATUS_IMPORTED);
            $collection->setPageSize($batchSize);
            $collection->setCurPage($page);
            $collection->setOrder('sort_no', 'ASC');
            $collection->setOrder('entity_id', 'ASC');

            $items = $collection->getItems();

            if ($items === []) {
                break;
            }

            foreach ($items as $map) {
                /** @var MediaMap $map */
                $magentoEntityId = $map->getMagentoEntityId() !== null ? (int) $map->getMagentoEntityId() : null;

                if ($magentoEntityId !== null && isset($handledProductIds[$magentoEntityId])) {
                    $result->incrementSkipped();
                } else {
                    if ($magentoEntityId !== null) {
                        $handledProductIds[$magentoEntityId] = true;
                    }

                    $outcome = $this->correctOne($map, $subDir, $attributeCode, $dryRun, $result);

                    if ($outcome !== null && count($errorSample) < self::MAX_ERROR_SAMPLE) {
                        $errorSample[] = $outcome;
                    }
                }

                $processed++;

                if ($limit !== null && $processed >= $limit) {
                    return $errorSample;
                }
            }

            if (count($items) < $batchSize) {
                break;
            }

            $page++;
        }

        return $errorSample;
    }

    /**
     * @return array{eccube_owner_id:int, sku:?string, error:string}|null null unless this row is a genuine error
     */
    private function correctOne(MediaMap $map, string $subDir, string $attributeCode, bool $dryRun, ImportResult $result): ?array
    {
        $ownerId = (int) $map->getEccubeOwnerId();
        $magentoEntityId = $map->getMagentoEntityId() !== null ? (int) $map->getMagentoEntityId() : null;
        $sourceFileName = $map->getSourceFileName();
        $expectedNewPath = $subDir . '/' . basename($sourceFileName);

        if ($magentoEntityId === null) {
            $result->incrementErrors();

            return ['eccube_owner_id' => $ownerId, 'sku' => null, 'error' => 'No resolved Magento entity id on this map row'];
        }

        $absoluteSource = rtrim((string) $this->config->getImageFolder(), '/') . '/' . $sourceFileName;

        if (!is_readable($absoluteSource)) {
            // SOURCE_FILE_NOT_FOUND: same terminology as MediaImporter's
            // own needs_review classification - do not fabricate, do not
            // touch the product, do not touch eccube_media_map (that row
            // already correctly reflects a successful OLD-scheme import;
            // this correction pass has its own outcome, tracked only in
            // the aggregate counters, not persisted back onto that row).
            $result->incrementNeedsReview();

            return null;
        }

        try {
            $product = $this->magentoProductRepository->getById($magentoEntityId, false, 0);
        } catch (NoSuchEntityException $e) {
            $result->incrementErrors();

            return ['eccube_owner_id' => $ownerId, 'sku' => null, 'error' => 'Magento product no longer exists: ' . $e->getMessage()];
        }

        $currentAttributeValue = $product->getData($attributeCode);

        if ($currentAttributeValue === $expectedNewPath) {
            $result->incrementSkipped();

            return null;
        }

        if ($dryRun) {
            $result->incrementImported();

            return null;
        }

        try {
            $this->documentUploader->copyFile($absoluteSource, $subDir, basename($sourceFileName));
            // QA FIX: see Model\Import\ItemImporter::persist() for the
            // full mechanism - round-tripping through
            // setProductLinks(getProductLinks()) stops Magento's
            // SaveHandler from silently wiping this product's Related/
            // Up-Sell/Cross-Sell/Connection Part links (this is the fix
            // for exactly the bug that wiped all 862 Connection Part
            // links this round).
            $product->setProductLinks($product->getProductLinks());
            $product->setCustomAttribute($attributeCode, $expectedNewPath);
            $this->magentoProductRepository->save($product);

            $result->incrementImported();

            return null;
        } catch (LocalizedException | \Throwable $e) {
            $result->incrementErrors();
            $this->logger->error(sprintf(
                'DimensionCadMediaCorrector: failed to correct %s owner=%d magento_entity_id=%d: %s',
                $map->getRelationType(),
                $ownerId,
                $magentoEntityId,
                $e->getMessage()
            ));

            return ['eccube_owner_id' => $ownerId, 'sku' => $product->getSku(), 'error' => $e->getMessage()];
        }
    }
}
