<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\Data\ItemAdditionalContentInterface;
use Cosmotec\EccubeMigration\Api\ItemAdditionalContentMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ItemAdditionalContentRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ItemMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\ItemAdditionalContentMap;
use Cosmotec\EccubeMigration\Model\ItemAdditionalContentMapFactory;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Imports dtb_item_additional_information (database-driven, per-item HTML
 * tabs - Catalog, Assembly method, Pressing tools, etc. - 89 distinct tab
 * names, source-confirmed) as a true 1:N relation into a dedicated
 * mapping table, mirroring ProductReferenceImporter's shape: every source
 * row is preserved and independently mapped, HTML is stored verbatim
 * (never converted to plain text, never translated), and a Magento admin
 * section reads through the mapping layer rather than one EAV attribute
 * per tab name.
 *
 * Iterates via getItemIdsWithContent() rather than the full ItemReader,
 * since only a minority of items carry any content (377/1092,
 * source-confirmed) - same efficiency pattern as
 * ProductAttributeValueImporter::importLimited().
 */
class ItemAdditionalContentImporter implements ImporterInterface
{
    public function __construct(
        private readonly ItemAdditionalContentRepositoryInterface $contentRepository,
        private readonly ItemAdditionalContentMapRepositoryInterface $mapRepository,
        private readonly ItemAdditionalContentMapFactory $mapFactory,
        private readonly ItemMapRepositoryInterface $itemMapRepository,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        return $this->importLimited($context, null);
    }

    public function importLimited(ImportContext $context, ?int $limit): ImportResult
    {
        $result = new ImportResult();
        $batchSize = $context->getBatchSize() ?? 100;
        $offset = 0;
        $processed = 0;

        while (true) {
            $itemIds = $this->contentRepository->getItemIdsWithContent($offset, $batchSize);

            if ($itemIds === []) {
                break;
            }

            foreach ($itemIds as $eccubeItemId) {
                $this->importForItem($eccubeItemId, $context, $result);
                $processed++;

                if ($limit !== null && $processed >= $limit) {
                    break 2;
                }
            }

            if (count($itemIds) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }

        $this->logger->info(sprintf(
            'ItemAdditionalContentImporter run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    private function importForItem(int $eccubeItemId, ImportContext $context, ImportResult $result): void
    {
        $itemMap = $this->itemMapRepository->getByEccubeItemId($eccubeItemId);
        $rawProductId = $itemMap?->getMagentoProductId();
        $magentoProductId = $rawProductId !== null && $rawProductId !== '' ? (int) $rawProductId : null;

        foreach ($this->contentRepository->getByItemId($eccubeItemId) as $content) {
            $this->importOne($content, $magentoProductId, $context, $result);
        }

        if (!$context->isDryRun()) {
            $this->markObsoleteForItem($eccubeItemId);
        }
    }

    private function importOne(
        ItemAdditionalContentInterface $content,
        ?int $magentoProductId,
        ImportContext $context,
        ImportResult $result
    ): void {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            if ($magentoProductId === null) {
                $result->incrementSkipped();

                if (!$context->isDryRun()) {
                    $this->saveMap($content, null, ItemAdditionalContentMap::STATUS_PENDING, 'Item not imported yet');
                }

                return;
            }

            $existing = $this->mapRepository->getBySourceRowId($content->getId());

            if ($existing !== null
                && $existing->getContentHash() === $content->getContentHash()
                && in_array($existing->getStatus(), [ItemAdditionalContentMap::STATUS_IMPORTED, ItemAdditionalContentMap::STATUS_UPDATED], true)) {
                $result->incrementSkipped();

                return;
            }

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info(sprintf(
                    '[DRY RUN] Would map additional-content tab %d ("%s") to Magento product %d',
                    $content->getId(),
                    (string) ($content->getTabNameEn() ?: $content->getTabNameJa()),
                    $magentoProductId
                ));

                return;
            }

            $isUpdate = $existing !== null && $existing->getMagentoProductId() !== null;
            $this->saveMap(
                $content,
                $magentoProductId,
                $isUpdate ? ItemAdditionalContentMap::STATUS_UPDATED : ItemAdditionalContentMap::STATUS_IMPORTED,
                null
            );

            if ($isUpdate) {
                $result->incrementUpdated();
            } else {
                $result->incrementImported();
            }

            $this->recordHistory(
                $context,
                $content->getId(),
                $magentoProductId,
                $isUpdate ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
                null,
                $startTime,
                $startMemory
            );
        } catch (LocalizedException | \Throwable $e) {
            $result->incrementErrors();
            $this->logger->error(sprintf('Item additional-content row %d failed: %s', $content->getId(), $e->getMessage()));

            if (!$context->isDryRun()) {
                $this->saveMap($content, null, ItemAdditionalContentMap::STATUS_ERROR, $e->getMessage());
            }

            $this->recordHistory($context, $content->getId(), null, SyncHistory::STATUS_ERROR, $e->getMessage(), $startTime, $startMemory);
        }
    }

    private function saveMap(
        ItemAdditionalContentInterface $content,
        ?int $magentoProductId,
        string $status,
        ?string $error
    ): void {
        $existing = $this->mapRepository->getBySourceRowId($content->getId());
        /** @var ItemAdditionalContentMap $map */
        $map = $existing ?? $this->mapFactory->create();
        $map->setEccubeAdditionalInformationId($content->getId());
        $map->setEccubeItemId($content->getItemId());
        $map->setMagentoProductId($magentoProductId);
        $map->setTabNameEn($content->getTabNameEn());
        $map->setTabNameJa($content->getTabNameJa());
        $map->setHtmlContent($content->getHtmlContent());
        // ORDERING FALLBACK (verified against the production schema):
        // dtb_item_additional_information has no sort_no or equivalent
        // ordering column - source id order is used as a deterministic,
        // stable fallback, same pattern as ProductReferenceImporter.
        $map->setSortNo($content->getId());
        $map->setContentHash($content->getContentHash());
        $map->setStatus($status);
        $map->setErrorMessage($error);

        if ($error === null) {
            $map->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        }

        $this->mapRepository->save($map);
    }

    /**
     * Marks mappings obsolete when their source tab no longer exists.
     * Each tab is independent: removing one must never disturb the
     * others belonging to the same item.
     */
    public function markObsoleteForItem(int $eccubeItemId): int
    {
        $sourceIds = array_map(
            static fn (ItemAdditionalContentInterface $c): int => $c->getId(),
            $this->contentRepository->getByItemId($eccubeItemId)
        );

        $obsolete = 0;

        foreach ($this->mapRepository->getByItemId($eccubeItemId) as $map) {
            if (in_array((int) $map->getEccubeAdditionalInformationId(), $sourceIds, true)) {
                continue;
            }

            if ($map->getStatus() === ItemAdditionalContentMap::STATUS_OBSOLETE) {
                continue;
            }

            $map->setStatus(ItemAdditionalContentMap::STATUS_OBSOLETE);
            $map->setErrorMessage('Source tab no longer exists in dtb_item_additional_information');
            $this->mapRepository->save($map);
            $obsolete++;
        }

        return $obsolete;
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
            SyncHistory::ENTITY_TYPE_ITEM_ADDITIONAL_CONTENT,
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
