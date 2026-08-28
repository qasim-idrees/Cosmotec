<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\Data\ItemInterface as EccubeItemInterface;
use Cosmotec\EccubeMigration\Api\ItemMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\Category\ChildCategoryInheritanceService;
use Cosmotec\EccubeMigration\Model\ProductMap;
use Cosmotec\EccubeMigration\Model\Reader\ItemReader;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Magento\Catalog\Api\Data\ProductLinkExtensionFactory;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedProductType;

/**
 * Milestone 5 (Simple Products) closes the loop with the spec's Import
 * Order step 6, "Group Relations": once a Grouped Product's children
 * actually exist as Magento Simple Products (previous step), attach them
 * as its associated products. This is deliberately its own class/command
 * (import:product-relations) rather than folded into ProductImporter, both
 * because the spec's CLI list treats it as distinct and because it can then
 * be safely re-run on its own to pick up any items whose children finished
 * importing on a later run.
 *
 * ImportResult field usage here differs slightly from the other importers:
 * "imported" = newly-linked child products, "skipped" = items with nothing
 * pending to link (no error), "errors" = children that failed to link.
 * "updated" is unused (relations have no partial-update concept).
 *
 * QA FIX (P0, category listing showed zero products for every category):
 * a Grouped Product's own cataloginventory_stock_item.is_in_stock is NOT
 * computed at query time from its children - it is a materialized flag
 * that Magento only updates REACTIVELY, via
 * Magento\GroupedProduct\Model\Inventory\ChangeParentStockStatus, which
 * is normally triggered by an observer on a CHILD's stock save. Since
 * this module links children in bulk (this class) rather than through
 * that per-child save flow, the reactive update never fired, and every
 * Grouped Product this module ever created was left at Magento's own
 * default is_in_stock=0/stock_status_changed_auto=0 - which ALSO blocks
 * ChangeParentStockStatus's own safety guard from ever auto-correcting it
 * later (it refuses to flip a product to in-stock unless the existing
 * flag was itself previously set automatically). refreshParentStockStatus()
 * below performs that first-time computation directly, immediately after
 * children are linked, using the same core Grouped::getChildrenIds() this
 * module already treats as canonical elsewhere.
 */
class ProductRelationImporter implements ImporterInterface
{
    private const LINK_TYPE_ASSOCIATED = 'associated';

    public function __construct(
        private readonly ItemReader $itemReader,
        private readonly ItemMapRepositoryInterface $itemMapRepository,
        private readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly MagentoProductRepositoryInterface $magentoProductRepository,
        private readonly ProductLinkInterfaceFactory $productLinkFactory,
        private readonly ProductLinkExtensionFactory $productLinkExtensionFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly GroupedProductType $groupedProductType,
        private readonly ImportLogger $logger,
        private readonly ChildCategoryInheritanceService $childCategoryInheritanceService
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();

        foreach ($this->itemReader->read(0, $context->getBatchSize()) as $item) {
            /** @var EccubeItemInterface $item */
            $this->linkItemChildren($item, $context, $result);
        }

        $this->logger->info(sprintf(
            'ProductRelationImporter run %s complete: linked=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    private function linkItemChildren(EccubeItemInterface $item, ImportContext $context, ImportResult $result): void
    {
        $itemMap = $this->itemMapRepository->getByEccubeItemId($item->getId());

        if ($itemMap === null || $itemMap->getMagentoProductId() === null) {
            // Parent Grouped Product not imported yet — nothing to do here;
            // a later run (after import:group-products has processed it)
            // will pick this item up again.
            $result->incrementSkipped();

            return;
        }

        $pendingChildren = $this->productMapRepository->getUnlinkedByItemId($item->getId());

        if ($pendingChildren === []) {
            $result->incrementSkipped();

            return;
        }

        $parentProductId = (int) $itemMap->getMagentoProductId();

        try {
            $parentProduct = $this->magentoProductRepository->getById($parentProductId);
        } catch (NoSuchEntityException $e) {
            $this->logger->error(sprintf(
                'Item id=%d: mapped Magento product id=%d no longer exists, cannot link %d children: %s',
                $item->getId(),
                $parentProductId,
                count($pendingChildren),
                $e->getMessage()
            ));
            $result->incrementErrors(count($pendingChildren));

            return;
        }

        $existingLinks = $parentProduct->getProductLinks() ?? [];
        $existingLinkedSkus = array_map(
            static fn (ProductLinkInterface $link): string => $link->getLinkedProductSku(),
            array_filter($existingLinks, static fn (ProductLinkInterface $link): bool => $link->getLinkType() === self::LINK_TYPE_ASSOCIATED)
        );

        $newLinks = $existingLinks;
        $position = count($existingLinkedSkus);

        foreach ($pendingChildren as $childMap) {
            /** @var ProductMap $childMap */
            $childSku = (string) $childMap->getSku();

            if (!in_array($childSku, $existingLinkedSkus, true)) {
                $position++;
                $newLinks[] = $this->buildLink($parentProduct->getSku(), $childSku, $position);
            }
        }

        if ($context->isDryRun()) {
            $result->incrementImported(count($pendingChildren));
            $this->logger->info(sprintf(
                '[DRY RUN] Would link %d product(s) to Grouped Product id=%d (EC-CUBE item id=%d)',
                count($pendingChildren),
                $parentProductId,
                $item->getId()
            ));
            $this->childCategoryInheritanceService->cascade(
                $item->getId(),
                $parentProductId,
                $parentProduct->getCategoryIds() ?? [],
                true
            );

            return;
        }

        try {
            $parentProduct->setProductLinks($newLinks);
            $this->magentoProductRepository->save($parentProduct);
        } catch (LocalizedException | \Throwable $e) {
            $this->logger->error(sprintf(
                'Item id=%d: failed to save %d product link(s) on Magento product id=%d: %s',
                $item->getId(),
                count($pendingChildren),
                $parentProductId,
                $e->getMessage()
            ));
            $result->incrementErrors(count($pendingChildren));

            return;
        }

        $this->refreshParentStockStatus($parentProductId, $parentProduct->getSku());

        // New children just got linked above - make sure they receive the
        // parent's current categories immediately, so a fresh install's
        // documented pipeline (...-> import:product-relations) ends with
        // children already categorized rather than waiting for a later
        // sync:group-products run. Ongoing category CHANGES on an
        // already-linked item are handled separately, by
        // ItemImporter::persist() (shared with ItemSync) - see
        // ChildCategoryInheritanceService's class docblock.
        $this->childCategoryInheritanceService->cascade(
            $item->getId(),
            $parentProductId,
            $parentProduct->getCategoryIds() ?? [],
            false
        );

        foreach ($pendingChildren as $childMap) {
            $childMap->setRelationLinked(1);
            $this->productMapRepository->save($childMap);
            $result->incrementImported();

            $this->syncHistoryRepository->record(
                $context->getRunId(),
                SyncHistory::ENTITY_TYPE_PRODUCT,
                SyncHistory::OPERATION_IMPORT,
                // AbstractModel::getData() returns a raw DB string, not an
                // int (despite the getter's phpdoc) - record()'s $sourceId
                // parameter is strictly typed int. Same recurring bug class
                // as the Round 37/42 fixes.
                (int) $childMap->getEccubeProductId(),
                $parentProductId,
                SyncHistory::STATUS_UPDATED,
                sprintf('Linked to parent Grouped Product id=%d', $parentProductId)
            );
        }

        $this->logger->info(sprintf(
            'Item id=%d: linked %d product(s) to Grouped Product id=%d',
            $item->getId(),
            count($pendingChildren),
            $parentProductId
        ));
    }

    /**
     * First-time computation of a Grouped Product's own is_in_stock flag
     * from its children - see the class docblock for why Magento's own
     * reactive ChangeParentStockStatus mechanism never runs for links
     * created this way. Deliberately not gated on "was this previously
     * auto-changed" (unlike that core class) since this IS the first
     * time it is ever being set for a product this importer created;
     * every subsequent EC-CUBE-triggered sync still lands here too, so
     * a later run correctly re-derives the flag as children's own stock
     * changes over time.
     */
    private function refreshParentStockStatus(int $parentProductId, string $parentSku): void
    {
        $childrenIds = $this->groupedProductType->getChildrenIds($parentProductId);

        if ($childrenIds === []) {
            return;
        }

        $anyChildInStock = false;

        foreach ($childrenIds as $childIdGroup) {
            foreach ((array) $childIdGroup as $childId) {
                try {
                    $childStockItem = $this->stockRegistry->getStockItem((int) $childId);
                } catch (LocalizedException | \Throwable) {
                    continue;
                }

                if ($childStockItem->getIsInStock()) {
                    $anyChildInStock = true;
                    break 2;
                }
            }
        }

        $parentStockItem = $this->stockRegistry->getStockItem($parentProductId);

        if ((bool) $parentStockItem->getIsInStock() === $anyChildInStock
            && (bool) $parentStockItem->getStockStatusChangedAuto()) {
            return;
        }

        $parentStockItem->setIsInStock($anyChildInStock);
        $parentStockItem->setStockStatusChangedAuto(1);
        $this->stockRegistry->updateStockItemBySku($parentSku, $parentStockItem);
    }

    private function buildLink(string $parentSku, string $childSku, int $position): ProductLinkInterface
    {
        $link = $this->productLinkFactory->create();
        $link->setSku($parentSku);
        $link->setLinkedProductSku($childSku);
        $link->setLinkType(self::LINK_TYPE_ASSOCIATED);
        $link->setPosition($position);

        $extensionAttributes = $link->getExtensionAttributes() ?? $this->productLinkExtensionFactory->create();
        $extensionAttributes->setQty(1.0);
        $link->setExtensionAttributes($extensionAttributes);

        return $link;
    }
}
