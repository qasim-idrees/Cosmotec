<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\CategoryMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\Data\MediaFileInterface;
use Cosmotec\EccubeMigration\Api\ItemMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\MediaMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\MediaRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\Diagnostics\ExceptionFormatter;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;
use Cosmotec\EccubeMigration\Model\Media\MediaSourceResolution;
use Cosmotec\EccubeMigration\Model\Media\RemoteMediaResolver;
use Cosmotec\EccubeMigration\Model\MediaMap;
use Cosmotec\EccubeMigration\Model\MediaMapFactory;
use Cosmotec\EccubeMigration\Model\Reader\MediaReader;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Cosmotec\EccubeMigration\Model\Validator\MediaValidator;
use Magento\Catalog\Api\CategoryRepositoryInterface as MagentoCategoryRepositoryInterface;
use Magento\Catalog\Model\CategoryFactory as MagentoCategoryFactory;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;

/**
 * Relation-aware media/document writer.
 *
 * Replaces the previous dtb_product_image based pipeline entirely: that
 * table has zero rows in production. Real media is dtb_upload_file joined
 * through seven catalog relation tables, each with its own owner entity,
 * Magento target and role.
 *
 * Storage strategy (deliberate):
 *  - Gallery images (product, item) and category images go through
 *    Magento's media directory and the catalog gallery/category image
 *    attribute.
 *  - Dimension drawings are images but must never claim the main image
 *    roles, so they are stored in the media directory and tracked by
 *    eccube_media_map under the dimension_drawing role.
 *  - CAD 2D/3D and catalog documents are NOT images (production files are
 *    ZIP archives). They are copied into a dedicated media subdirectory
 *    and referenced only through eccube_media_map. No binary ever goes
 *    into an EAV attribute.
 *
 * Files are copied once per physical source file; a second relation
 * pointing at the same dtb_upload_file reuses the copy.
 */
class MediaImporter implements ImporterInterface
{
    private const DOCUMENT_SUBDIR = 'cosmotec/eccube';

    /** @var array<string, string> uploadFileId => magento relative path */
    private array $copiedFiles = [];

    /** @var array<string, int|null> "relation:ownerId" => primary upload file id */
    private array $primaryCache = [];

    public function __construct(
        private readonly MediaReader $reader,
        private readonly MediaValidator $validator,
        private readonly MediaMapRepositoryInterface $mediaMapRepository,
        private readonly MediaRepositoryInterface $mediaRepository,
        private readonly MediaMapFactory $mediaMapFactory,
        private readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly ItemMapRepositoryInterface $itemMapRepository,
        private readonly CategoryMapRepositoryInterface $categoryMapRepository,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly MagentoProductRepositoryInterface $magentoProductRepository,
        private readonly MagentoCategoryRepositoryInterface $magentoCategoryRepository,
        private readonly MagentoCategoryFactory $magentoCategoryFactory,
        private readonly ProductAttributeMediaGalleryEntryInterfaceFactory $mediaGalleryEntryFactory,
        private readonly ImageContentInterfaceFactory $imageContentFactory,
        private readonly Filesystem $filesystem,
        private readonly ExceptionFormatter $exceptionFormatter,
        private readonly \Cosmotec\EccubeMigration\Model\Media\DocumentUploader $documentUploader,
        private readonly ImportLogger $logger,
        private readonly RemoteMediaResolver $remoteMediaResolver
    ) {
    }

    /**
     * Imports every relation. Use importRelation() to target one.
     */
    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();

        foreach (MediaRelationType::all() as $relationType) {
            $this->importRelation($relationType, $context, $result);
        }

        return $result;
    }

    /**
     * Imports one relation, optionally narrowed.
     *
     * Filtering is pushed into SQL rather than scanning the relation in
     * PHP: product media alone is ~30,876 rows and the full catalog media
     * inventory is ~71,072, so a targeted test must not read all of it.
     *
     * @param int|null $ownerId      EC-CUBE owner (product/item/category) id
     * @param int|null $uploadFileId dtb_upload_file.id - one exact file
     */
    public function importRelation(
        MediaRelationType $relationType,
        ImportContext $context,
        ?ImportResult $result = null,
        ?int $limit = null,
        ?int $ownerId = null,
        ?int $uploadFileId = null
    ): ImportResult {
        $result ??= new ImportResult();

        $this->logger->info(sprintf(
            'MediaImporter[%s] starting (run %s)%s%s',
            $relationType->value,
            $context->getRunId(),
            $context->isDryRun() ? ' [DRY RUN]' : '',
            $this->describeFilter($ownerId, $uploadFileId)
        ));

        if ($uploadFileId !== null) {
            // Exactly one dtb_upload_file row, fetched directly.
            $file = $this->mediaRepository->getByUploadFileId($relationType, $uploadFileId);

            if ($file === null) {
                $this->logger->info(sprintf(
                    'MediaImporter[%s]: no %s relation found for upload_file_id=%d',
                    $relationType->value,
                    $relationType->joinTable(),
                    $uploadFileId
                ));
            } else {
                $this->importOne($file, $context, $result);
            }
        } elseif ($ownerId !== null) {
            // All media for one owner, fetched with WHERE owner = ?
            foreach ($this->mediaRepository->getByOwner($relationType, $ownerId) as $file) {
                $this->importOne($file, $context, $result);
            }
        } else {
            $processed = 0;

            foreach ($this->reader->read($relationType, 0, $context->getBatchSize()) as $file) {
                $this->importOne($file, $context, $result);
                $processed++;

                if ($limit !== null && $processed >= $limit) {
                    break;
                }
            }
        }

        $this->logger->info(sprintf(
            'MediaImporter[%s] complete: imported=%d updated=%d skipped=%d needs_review=%d errors=%d',
            $relationType->value,
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getNeedsReview(),
            $result->getErrors()
        ));

        return $result;
    }

    private function describeFilter(?int $ownerId, ?int $uploadFileId): string
    {
        if ($uploadFileId !== null) {
            return sprintf(' [upload_file_id=%d]', $uploadFileId);
        }

        if ($ownerId !== null) {
            return sprintf(' [owner_id=%d]', $ownerId);
        }

        return '';
    }

    private function importOne(MediaFileInterface $file, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        // Declared before the try so the catch block can always reference
        // it, even if resolution itself is what failed.
        $target = null;
        $resolution = null;

        try {
            // Resolution order: local filesystem, then (only if enabled
            // and configured) EC-CUBE's remote S3/CloudFront storage, then
            // genuinely not found. See RemoteMediaResolver - local-only
            // behavior is completely unchanged when remote fallback is
            // off (the default).
            $resolution = $this->remoteMediaResolver->resolve($file);

            if (!$resolution->isFound()) {
                $message = (string) $resolution->getErrorMessage();
                // A genuinely missing/unreadable source file (local AND
                // remote) is a data condition needing human follow-up, not
                // a code failure. A remote check/download that could not
                // even be completed (REMOTE_FETCH_FAILED) is distinct from
                // a confirmed absence - both still land in needs_review
                // (matching the existing SOURCE_FILE_NOT_FOUND /
                // SOURCE_FILE_NOT_READABLE precedent of one review bucket
                // for several distinguishable messages) but must never be
                // silently treated as a code error.
                $status = str_contains($message, 'SOURCE_FILE_NOT_FOUND')
                    || str_contains($message, 'SOURCE_FILE_NOT_READABLE')
                    || str_contains($message, 'REMOTE_FETCH_FAILED')
                        ? MediaMap::STATUS_NEEDS_REVIEW
                        : MediaMap::STATUS_ERROR;

                $this->recordFailure($file, $context, $result, $message, $status, $startTime, $startMemory, true, MediaMap::SOURCE_TYPE_NOT_FOUND);

                return;
            }

            $resolvedPath = (string) $resolution->getAbsolutePath();
            $validation = $this->validator->validate($file, $resolvedPath);

            if (!$validation->isValid()) {
                $message = $validation->getErrorsAsString();
                $status = str_contains($message, 'SOURCE_FILE_NOT_FOUND')
                    || str_contains($message, 'SOURCE_FILE_NOT_READABLE')
                        ? MediaMap::STATUS_NEEDS_REVIEW
                        : MediaMap::STATUS_ERROR;

                $this->recordFailure($file, $context, $result, $message, $status, $startTime, $startMemory, true, $resolution->getSourceType());

                return;
            }

            // Entity resolution: never create orphan Magento media. A
            // not-yet-imported owner is a skip, retried on the next run.
            $target = $this->resolveTarget($file);

            if ($target === null) {
                $result->incrementSkipped();
                $this->recordFailure(
                    $file,
                    $context,
                    $result,
                    sprintf('Owner %s %d not imported into Magento yet', $file->getRelationType()->ownerEntity(), $file->getOwnerId()),
                    MediaMap::STATUS_PENDING,
                    $startTime,
                    $startMemory,
                    false,
                    $resolution->getSourceType()
                );

                return;
            }

            $existing = $this->mediaMapRepository->get($file->getRelationType(), $file->getOwnerId(), $file->getUploadFileId());
            $hash = $this->computeHash($resolvedPath, $file, $resolution->getSourceType() === MediaMap::SOURCE_TYPE_REMOTE);
            $willSkip = $existing !== null
                && $existing->getContentHash() === $hash
                && in_array($existing->getStatus(), [MediaMap::STATUS_IMPORTED, MediaMap::STATUS_UPDATED], true);

            // Diagnostic only - added while investigating an observed
            // updated-instead-of-skipped result on a re-run. Never affects
            // the decision itself, only makes it observable in the log.
            $this->logger->info(sprintf(
                'Media %s/%d (owner %d) skip-check: existing_hash=%s computed_hash=%s existing_status=%s -> %s',
                $file->getRelationType()->value,
                $file->getUploadFileId(),
                $file->getOwnerId(),
                $existing?->getContentHash() ?? 'null',
                $hash,
                $existing?->getStatus() ?? 'null',
                $willSkip ? 'SKIP' : 'PROCEED'
            ));

            if ($willSkip) {
                $result->incrementSkipped();

                return;
            }

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info(sprintf(
                    '[DRY RUN] %s: would attach %s to %s %d as %s (source=%s)',
                    $file->getRelationType()->value,
                    $file->getFileName(),
                    $file->getRelationType()->magentoEntityType(),
                    $target,
                    $file->getRelationType()->magentoRole(true) ?? 'gallery',
                    $resolution->getSourceType()
                ));

                return;
            }

            $this->persist($file, $resolvedPath, $target, $existing, $hash, $resolution->getSourceType(), $context, $result, $startTime, $startMemory);
        } catch (LocalizedException | \Throwable $e) {
            // Full chain, not just the outer message: Magento's top-level
            // text ("The product can't be saved.") rarely names the cause.
            $diagnostic = $this->exceptionFormatter->format($e, [
                'operation' => 'media_gallery_write',
                'relation' => $file->getRelationType()->value,
                'eccube_upload_file_id' => $file->getUploadFileId(),
                'eccube_owner_id' => $file->getOwnerId(),
                'magento_entity_type' => $file->getRelationType()->magentoEntityType(),
                'magento_entity_id' => $target,
                'sku' => $this->describeSku($target),
                'source_file' => $file->getFileName(),
            ]);

            $this->recordFailure($file, $context, $result, $diagnostic, MediaMap::STATUS_ERROR, $startTime, $startMemory, true, $resolution?->getSourceType());
        } finally {
            // Only ever deletes a temp download this run created (a
            // no-op for LOCAL/NOT_FOUND resolutions) - never touches
            // EC-CUBE source files or Magento media.
            if ($resolution !== null) {
                $this->remoteMediaResolver->cleanup($resolution);
            }
        }
    }

    /**
     * SKU for diagnostics only; never fails the import if unavailable.
     */
    private function describeSku(?int $magentoProductId): ?string
    {
        if ($magentoProductId === null) {
            return null;
        }

        try {
            return (string) $this->magentoProductRepository->getById($magentoProductId)->getSku();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolves the Magento entity id for the file's owner, honouring
     * ownership isolation: item media never resolves to a Simple Product
     * and vice versa.
     */
    private function resolveTarget(MediaFileInterface $file): ?int
    {
        // Magento's generic AbstractModel data access returns column values
        // as strings, so these getters yield e.g. "123" rather than 123.
        // Cast explicitly rather than widening the return type.
        $id = match ($file->getRelationType()->ownerEntity()) {
            'product' => $this->productMapRepository->getByEccubeProductId($file->getOwnerId())?->getMagentoProductId(),
            'item' => $this->itemMapRepository->getByEccubeItemId($file->getOwnerId())?->getMagentoProductId(),
            'category' => $this->categoryMapRepository->getByEccubeCategoryId($file->getOwnerId())?->getMagentoCategoryId(),
            default => null,
        };

        if ($id === null || $id === '') {
            return null;
        }

        return (int) $id;
    }

    /**
     * Performs every Magento-side mutation for one file: copying the
     * binary, creating the gallery entry / category image, and writing
     * eccube_media_map.
     *
     * Defense in depth: callers already return before reaching this in
     * dry-run mode, but the guard below makes it impossible for a future
     * refactor to write to Magento during a dry run.
     */
    private function persist(
        MediaFileInterface $file,
        string $absoluteSourcePath,
        int $magentoEntityId,
        ?MediaMap $existing,
        string $hash,
        string $sourceType,
        ImportContext $context,
        ImportResult $result,
        float $startTime,
        int $startMemory
    ): void {
        if ($context->isDryRun()) {
            throw new \LogicException(
                'MediaImporter::persist() must never be reached during a dry run.'
            );
        }

        $relationType = $file->getRelationType();

        // Images MUST go through Magento's own media gallery API so the
        // file lands under pub/media/catalog/product/<x>/<y>/ with the
        // hashed sub-path Magento expects. Writing to an arbitrary media
        // sub-directory and inserting a gallery row pointing at it does
        // NOT work: Magento resolves gallery file values relative to
        // pub/media/catalog/product, so admin gallery, image roles,
        // frontend URLs and the resize cache would all break.
        //
        // Non-image documents (CAD ZIP, catalog files) are not catalog
        // images at all, so they are stored in a module-owned media
        // sub-directory and referenced only through eccube_media_map.
        $magentoPath = null;

        // Gallery-style relations attach through the catalog entity so the
        // storefront can render them natively. Dimension drawings, CAD and
        // catalog documents are tracked solely through eccube_media_map:
        // Magento's gallery API is image-only and would reject or mislabel
        // them.
        if ($relationType === MediaRelationType::CATEGORY) {
            $magentoPath = $this->copyCategoryImage($file, $absoluteSourcePath);
            $this->attachCategoryImage($magentoEntityId, $magentoPath);
        } elseif ($relationType === MediaRelationType::DIMENSION) {
            // QA FIX (Task 5.1): dimension drawings previously went through
            // the gallery API (disabled, no roles) purely so they could
            // never become a storefront primary image. That satisfied the
            // "never primary" rule but still left them as gallery entries,
            // which the task now explicitly forbids - a dedicated field
            // (eccube_dimension_image) is required instead. Only the
            // routing for NEW/changed imports is affected; files already
            // in the gallery from earlier runs are not retroactively
            // migrated here (that would be a full media re-sync, out of
            // scope for this change - see BUILD_STATUS.md).
            $magentoPath = $this->attachDedicatedFileField(
                $file,
                $absoluteSourcePath,
                $magentoEntityId,
                \Cosmotec\EccubeMigration\Model\Media\DocumentUploader::SUBDIR_DIMENSION,
                'eccube_dimension_image'
            );
        } elseif ($relationType === MediaRelationType::CAD2D || $relationType === MediaRelationType::CAD3D) {
            // QA FIX (Task 5.2/5.3): CAD files must live under
            // pub/media/cad/ and be exposed through a dedicated admin
            // field with view/replace/remove, not just tracked in
            // eccube_media_map with no product-visible field at all
            // (the previous behavior). Existing files already copied to
            // the old cosmotec/eccube/cad2d|cad3d/ path are not moved
            // retroactively - same scope note as DIMENSION above.
            $magentoPath = $this->attachDedicatedFileField(
                $file,
                $absoluteSourcePath,
                $magentoEntityId,
                $relationType === MediaRelationType::CAD2D
                    ? \Cosmotec\EccubeMigration\Model\Media\DocumentUploader::SUBDIR_CAD2D
                    : \Cosmotec\EccubeMigration\Model\Media\DocumentUploader::SUBDIR_CAD3D,
                $relationType === MediaRelationType::CAD2D ? 'eccube_cad2d_file' : 'eccube_cad3d_file'
            );
        } elseif ($relationType->requiresImage()) {
            // product, item - real gallery images
            $magentoPath = $this->attachGalleryImageViaApi($file, $absoluteSourcePath, $magentoEntityId);
        } else {
            // catalog - documents, not catalog images
            $magentoPath = $this->copyToModuleMedia($file, $absoluteSourcePath);
        }

        $isUpdate = $existing !== null && $existing->getMagentoFilePath() !== null;

        /** @var MediaMap $map */
        $map = $existing ?? $this->mediaMapFactory->create();
        $map->setEccubeUploadFileId($file->getUploadFileId());
        $map->setRelationType($relationType->value);
        $map->setEccubeOwnerId($file->getOwnerId());
        $map->setSortNo($file->getSortNo());
        $map->setSourceFileName($file->getFileName());
        $map->setFileExtension($file->getExtension());
        $map->setMediaClass($file->getMediaClass()->value);
        $map->setMagentoEntityType($relationType->magentoEntityType());
        $map->setMagentoEntityId($magentoEntityId);
        $map->setMagentoFilePath($magentoPath);
        $map->setMagentoRole($relationType->magentoRole($this->isPrimaryImage($file)));
        $map->setContentHash($hash);
        $map->setStatus($isUpdate ? MediaMap::STATUS_UPDATED : MediaMap::STATUS_IMPORTED);
        $map->setErrorMessage(null);
        $map->setSourceType($sourceType);
        $map->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->mediaMapRepository->save($map);

        if ($isUpdate) {
            $result->incrementUpdated();
        } else {
            $result->incrementImported();
        }

        $this->recordHistory(
            $context,
            $file->getUploadFileId(),
            $magentoEntityId,
            $isUpdate ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
            null,
            $startTime,
            $startMemory
        );
    }

    /**
     * Adds an image to a Magento product using Magento's own media
     * gallery API. The API copies the binary into
     * pub/media/catalog/product/<x>/<y>/ and returns the gallery entry id,
     * which is the only way the admin gallery, image roles, frontend URLs
     * and the resize cache all resolve correctly.
     *
     * Idempotent: if an entry with the same base filename already exists
     * on the product, the existing file path is returned and no second
     * entry is created.
     *
     * Roles: only the first image of a PRODUCT or ITEM gallery claims
     * image/small_image/thumbnail. Dimension drawings never do - they are
     * added to the gallery with no roles and are identified through
     * eccube_media_map's dimension_drawing role instead.
     */
    private function attachGalleryImageViaApi(MediaFileInterface $file, string $absoluteSourcePath, int $magentoProductId): string
    {
        $product = $this->magentoProductRepository->getById($magentoProductId, true);
        // QA FIX: see Model\Import\ItemImporter::persist() for the full
        // mechanism - round-tripping through setProductLinks(getProductLinks())
        // stops Magento's SaveHandler from silently wiping this product's
        // Related/Up-Sell/Cross-Sell/Connection Part links.
        $product->setProductLinks($product->getProductLinks());
        $baseName = $this->sanitizeGalleryFilename(basename($file->getFileName()));
        $existingEntries = $product->getMediaGalleryEntries() ?? [];

        // Idempotency: an entry for this source file already present means
        // nothing to do, so a repeated run cannot duplicate the gallery.
        foreach ($existingEntries as $existing) {
            if (basename((string) $existing->getFile()) === $baseName
                || $existing->getLabel() === $file->getFileName()) {
                return (string) $existing->getFile();
            }
        }

        $absoluteSource = $absoluteSourcePath;
        $binary = file_get_contents($absoluteSource);

        if ($binary === false) {
            throw new \RuntimeException(sprintf('Could not read source file "%s"', $absoluteSource));
        }

        $isPrimary = $this->isPrimaryImage($file);
        $isDimension = $file->getRelationType() === MediaRelationType::DIMENSION;

        $imageContent = $this->imageContentFactory->create();
        $imageContent->setBase64EncodedData(base64_encode($binary));
        $imageContent->setType($this->detectMimeType($absoluteSource));
        $imageContent->setName($baseName);

        $entry = $this->mediaGalleryEntryFactory->create();
        $entry->setMediaType('image');
        $entry->setLabel($file->getFileName());
        $entry->setPosition($file->getSortNo());
        $entry->setDisabled($isDimension);
        $entry->setTypes($isPrimary ? ['image', 'small_image', 'thumbnail'] : []);
        $entry->setContent($imageContent);

        // Append to the EXISTING entries and set the whole collection back.
        //
        // This must go through the media_gallery_entries extension
        // attribute, not Processor::addImage(). ProductRepository::save()
        // reads media_gallery_entries from the product and hands them to
        // the media gallery processor, which treats that array as the
        // authoritative full set. Populating only the legacy media_gallery
        // data array (what Processor::addImage does) while the extension
        // attribute still holds the pre-existing - here empty - set causes
        // the save to write that empty set, discarding the new image. That
        // is exactly the observed symptom: the file lands in
        // pub/media/catalog/product but the product ends with 0 entries.
        $existingEntries[] = $entry;
        $product->setMediaGalleryEntries($existingEntries);

        // Saved here rather than via GalleryManagement::create(), which
        // catches every save exception and rethrows a bare
        // StateException("The product can't be saved.") without a previous
        // exception, destroying the real validation error.
        $this->magentoProductRepository->save($product);

        return $this->resolveStoredFile($magentoProductId, $baseName, $file->getFileName());
    }

    /**
     * Copies a source file (dimension image, CAD 2D/3D) into a dedicated
     * module media sub-directory and stamps its path onto a dedicated
     * product EAV attribute - see Setup\Patch\Data\CreateDocumentAttributes
     * and the "EC-CUBE Documents" admin section (Block\Adminhtml\Product\
     * Documents). setCustomAttribute() is a silent no-op if that
     * attribute doesn't exist yet, matching the existing cad_unavailable/
     * eccube_product_model pattern - one missing attribute must never
     * fail the whole media import. File type is already gated upstream by
     * MediaValidator before persist() is ever reached.
     */
    private function attachDedicatedFileField(
        MediaFileInterface $file,
        string $absoluteSourcePath,
        int $magentoProductId,
        string $subDir,
        string $attributeCode
    ): string {
        $relativePath = $this->documentUploader->copyFile($absoluteSourcePath, $subDir, basename($file->getFileName()));

        $product = $this->magentoProductRepository->getById($magentoProductId, true);
        // QA FIX: see attachGalleryImageViaApi() / ItemImporter::persist()
        // for the full mechanism.
        $product->setProductLinks($product->getProductLinks());
        $product->setCustomAttribute($attributeCode, $relativePath);
        $this->magentoProductRepository->save($product);

        return $relativePath;
    }

    /**
     * Reads back the path Magento assigned to the newly stored image.
     */
    private function resolveStoredFile(int $magentoProductId, string $baseName, string $label): string
    {
        $saved = $this->magentoProductRepository->getById($magentoProductId, false, null, true);

        foreach ($saved->getMediaGalleryEntries() ?? [] as $entry) {
            $storedBase = basename((string) $entry->getFile());

            // Magento appends a numeric suffix on filename collision, so
            // match the stored name defensively rather than exactly.
            if ($storedBase === $baseName
                || $entry->getLabel() === $label
                || str_starts_with($storedBase, pathinfo($baseName, PATHINFO_FILENAME))) {
                return (string) $entry->getFile();
            }
        }

        throw new \RuntimeException(sprintf(
            'Gallery entry for "%s" was not found on product %d after save',
            $baseName,
            $magentoProductId
        ));
    }

    /**
     * Decides whether this file should carry image/small_image/thumbnail.
     *
     * The winner is resolved from the file's OWN relation alone, using
     * EC-CUBE's ordering (sort_no ASC, id ASC) via
     * MediaRepository::getPrimaryUploadFileId(). Consequences of that
     * scoping, all deliberate:
     *
     *  - dimension / cad2d / cad3d / catalog / category can never be
     *    primary, because they are excluded before the lookup runs;
     *  - a dimension or CAD file imported first can never stop the real
     *    product image from becoming primary;
     *  - current Magento gallery state, gallery position and import order
     *    are all irrelevant, so repeated runs reach the same answer.
     *
     * ITEM has its own gallery on the Grouped Product and follows the same
     * rule within item_upload_file.
     */
    private function isPrimaryImage(MediaFileInterface $file): bool
    {
        $relationType = $file->getRelationType();

        if ($relationType !== MediaRelationType::PRODUCT && $relationType !== MediaRelationType::ITEM) {
            return false;
        }

        // Cached per (relation, owner): a product has several images and
        // this is consulted more than once per file, so an uncached lookup
        // would add tens of thousands of queries to a full run.
        $cacheKey = $relationType->value . ':' . $file->getOwnerId();

        if (!array_key_exists($cacheKey, $this->primaryCache)) {
            $this->primaryCache[$cacheKey] = $this->mediaRepository->getPrimaryUploadFileId(
                $relationType,
                $file->getOwnerId()
            );
        }

        $primaryId = $this->primaryCache[$cacheKey];

        return $primaryId !== null && $primaryId === $file->getUploadFileId();
    }

    /**
     * EC-CUBE source filenames are not guaranteed to be safe as a Magento
     * gallery filename - two independent Magento-side checks were found to
     * reject real source filenames during a live run: (1)
     * Magento\Framework\Api\ImageContentValidator::validate() rejects
     * `\/?*:";<>()|{}` outright ("Provided image name contains forbidden
     * characters"), and (2) Magento\Framework\Filesystem\File\Write::
     * assertValid() separately rejects any filename starting with `-` or
     * containing a space immediately followed by `-` (a shell-argument-
     * injection guard - this was the actual cause of one error whose
     * filename otherwise looked unremarkable: "No_image_12693 - コピー_...",
     * rejected for its " - ", not for containing Japanese text).
     *
     * This only changes the filename used for the Magento-side gallery
     * entry (setName()/setFile()) - eccube_media_map.source_file_name
     * (already unmodified elsewhere in this class) remains the literal
     * EC-CUBE filename for identity/audit purposes, and the actual source
     * file read from disk (getAbsolutePath()) is never touched. Pure
     * character substitution, no randomness - the same source filename
     * always sanitizes to the same result, keeping the idempotency check
     * in attachGalleryImageViaApi() (which compares against this same
     * sanitized value) stable across repeated runs.
     */
    private function sanitizeGalleryFilename(string $fileName): string
    {
        // Collapse all whitespace to "_" first - this alone eliminates
        // every "\s-" sequence Filesystem\Write::assertValid() rejects,
        // without needing a separate pass for that rule.
        $sanitized = preg_replace('/\s+/u', '_', $fileName) ?? $fileName;

        // Replace every character ImageContentValidator forbids
        // (\/?*:";<>()|{}) with "_", preserving position/readability
        // rather than collapsing them away.
        $sanitized = preg_replace('/[\\\\\/?*:";<>()|{}]/u', '_', $sanitized) ?? $sanitized;

        // A leading "-" alone (no preceding space) also matches
        // Filesystem\Write::assertValid()'s "^-" branch.
        $sanitized = ltrim($sanitized, '-');

        if ($sanitized === '') {
            // Every character was forbidden/whitespace (never observed in
            // practice, but a byte-for-byte guarantee against writing an
            // empty gallery filename) - fall back to a name derived from
            // the original, itself sanitized the same way.
            $sanitized = 'file_' . preg_replace('/[^A-Za-z0-9._-]/u', '_', $fileName);
        }

        return $sanitized;
    }

    private function detectMimeType(string $absolutePath): string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo !== false) {
            $mime = finfo_file($finfo, $absolutePath);
            finfo_close($finfo);

            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return match (strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /**
     * Orphan-file note.
     *
     * Catalog images are now handed to Magento as base64 ImageContent on a
     * media_gallery_entries save, so Magento itself writes the file into
     * pub/media/catalog/product as part of that save. The module never
     * creates a catalog media file separately, which means a failed save
     * cannot leave a module-created orphan behind - there is nothing for
     * this class to roll back.
     *
     * Module-owned documents (CAD ZIP, catalog files) are different: they
     * are written by copyToModuleMedia() into a module-owned directory and
     * are only ever referenced through eccube_media_map, so a failed run
     * leaves a harmless unreferenced file that a later run reuses rather
     * than duplicating.
     */

    /**
     * Stores a non-catalog-image asset (CAD ZIP, catalog document,
     * category image) in a module-owned media sub-directory. These are
     * never Magento gallery entries, so an arbitrary media path is
     * correct here - they are resolved through eccube_media_map.
     *
     * Copied once per physical source file.
     */
    private function copyToModuleMedia(MediaFileInterface $file, string $absoluteSourcePath): string
    {
        $cacheKey = (string) $file->getUploadFileId();

        if (isset($this->copiedFiles[$cacheKey])) {
            return $this->copiedFiles[$cacheKey];
        }

        $absoluteSource = $absoluteSourcePath;
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $relativePath = self::DOCUMENT_SUBDIR . '/' . $file->getRelationType()->value . '/' . basename($file->getFileName());

        if (!$mediaDirectory->isExist($relativePath)) {
            $contents = file_get_contents($absoluteSource);

            if ($contents === false) {
                throw new \RuntimeException(sprintf('Could not read source file "%s"', $absoluteSource));
            }

            $mediaDirectory->writeFile($relativePath, $contents);
        }

        $this->copiedFiles[$cacheKey] = $relativePath;

        return $relativePath;
    }

    /**
     * Category images must live under pub/media/catalog/category/, because
     * Magento resolves the category "image" attribute relative to that
     * directory. Returns the value to store on the attribute (the path
     * relative to catalog/category/).
     */
    private function copyCategoryImage(MediaFileInterface $file, string $absoluteSourcePath): string
    {
        $cacheKey = 'category:' . $file->getUploadFileId();

        if (isset($this->copiedFiles[$cacheKey])) {
            return $this->copiedFiles[$cacheKey];
        }

        $absoluteSource = $absoluteSourcePath;
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $fileName = basename($file->getFileName());
        $relativePath = 'catalog/category/' . $fileName;

        if (!$mediaDirectory->isExist($relativePath)) {
            $contents = file_get_contents($absoluteSource);

            if ($contents === false) {
                throw new \RuntimeException(sprintf('Could not read source file "%s"', $absoluteSource));
            }

            $mediaDirectory->writeFile($relativePath, $contents);
        }

        $this->copiedFiles[$cacheKey] = $fileName;

        return $fileName;
    }

    /**
     * The category `image` attribute is Store-view scoped in stock
     * Magento. Deliberately does NOT use
     * MagentoCategoryRepositoryInterface here - live-confirmed this round
     * that CategoryRepository::save() hard-codes
     * `$storeId = $this->storeManager->getStore()->getId()` (the current
     * ambient store, resolving to a real store view in CLI context, never
     * global scope) and completely ignores whatever store scope the
     * passed-in category object carries - `$category->setStoreId(0)`
     * before calling repository save() is silently overridden and the
     * write still lands on the ambient store. This is a genuine
     * CategoryRepositoryInterface API limitation (see
     * vendor/magento/module-catalog/Model/CategoryRepository.php::save()),
     * not something fixable by changing what's set on the category object
     * beforehand.
     *
     * The plain \Magento\Catalog\Model\Category model's own save() (the
     * legacy AbstractModel path, bypassing the repository entirely) does
     * not have this limitation - its resource model writes using
     * $category->getStoreId(), which correctly returns whatever was
     * explicitly set via setStoreId(). Confirmed live: a direct model
     * save with setStoreId(0) correctly persisted at store_id=0, where
     * the repository path did not, even with the identical setStoreId(0)
     * call on the object.
     */
    private function attachCategoryImage(int $categoryId, string $mediaRelativePath): void
    {
        $category = $this->magentoCategoryFactory->create();
        $category->setStoreId(0);
        $category->load($categoryId);

        if (!$category->getId()) {
            return;
        }

        $category->setData('image', $mediaRelativePath);
        $category->save();
    }

    /**
     * Metadata hash (filename + size + mtime) for large local files,
     * SHA-256 of the content for small ones. Deterministic, and never
     * loads a large LOCAL file into memory just to detect a change.
     *
     * A remote-recovered file is always content-hashed regardless of
     * size, never via the mtime fallback: $absoluteSourcePath there is a
     * freshly-downloaded temp file whose mtime is "now" on every single
     * run, which would make the metadata hash change on every re-run and
     * defeat idempotency for any remote file over the size gate. This
     * costs nothing extra - the full content is already in memory from
     * the download itself.
     */
    private function computeHash(string $absoluteSourcePath, MediaFileInterface $file, bool $isRemote = false): string
    {
        $size = @filesize($absoluteSourcePath);

        if ($isRemote || ($size !== false && $size <= 1048576)) {
            $content = @file_get_contents($absoluteSourcePath);

            if ($content !== false) {
                return 'sha256:' . hash('sha256', $content);
            }
        }

        $mtime = @filemtime($absoluteSourcePath);

        return 'meta:' . hash('sha256', sprintf(
            '%s|%s|%s',
            $file->getFileName(),
            $size === false ? '0' : $size,
            $mtime === false ? '0' : $mtime
        ));
    }

    private function recordFailure(
        MediaFileInterface $file,
        ImportContext $context,
        ImportResult $result,
        string $message,
        string $status,
        float $startTime,
        int $startMemory,
        bool $countAsFailure = true,
        ?string $sourceType = null
    ): void {
        if ($countAsFailure) {
            // NEEDS_REVIEW (e.g. a source file genuinely missing on disk)
            // is a data condition, not a code failure - counted and
            // reported separately so it never masks a real error.
            if ($status === MediaMap::STATUS_NEEDS_REVIEW) {
                $result->incrementNeedsReview();
            } else {
                $result->incrementErrors();
            }

            $this->logger->error(sprintf(
                'Media %s/%d (owner %d) %s: %s',
                $file->getRelationType()->value,
                $file->getUploadFileId(),
                $file->getOwnerId(),
                $status === MediaMap::STATUS_NEEDS_REVIEW ? 'needs review' : 'failed',
                $message
            ));
        }

        if (!$context->isDryRun()) {
            $existing = $this->mediaMapRepository->get($file->getRelationType(), $file->getOwnerId(), $file->getUploadFileId());
            /** @var MediaMap $map */
            $map = $existing ?? $this->mediaMapFactory->create();
            $map->setEccubeUploadFileId($file->getUploadFileId());
            $map->setRelationType($file->getRelationType()->value);
            $map->setEccubeOwnerId($file->getOwnerId());
            $map->setSortNo($file->getSortNo());
            $map->setSourceFileName($file->getFileName());
            $map->setFileExtension($file->getExtension());
            $map->setMediaClass($file->getMediaClass()->value);
            $map->setStatus($status);
            $map->setErrorMessage($message);
            $map->setSourceType($sourceType ?? MediaMap::SOURCE_TYPE_NOT_FOUND);
            $this->mediaMapRepository->save($map);
        }

        $this->recordHistory($context, $file->getUploadFileId(), null, SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);
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

        $this->syncHistoryRepository->record(
            $context->getRunId(),
            SyncHistory::ENTITY_TYPE_IMAGE,
            SyncHistory::OPERATION_IMPORT,
            $sourceId,
            $targetId,
            $status,
            $message,
            (int) round((microtime(true) - $startTime) * 1000),
            max(0, memory_get_usage(true) - $startMemory)
        );
    }
}
