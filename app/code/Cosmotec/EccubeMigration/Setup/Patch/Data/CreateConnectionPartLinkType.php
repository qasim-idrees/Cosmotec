<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Setup\Patch\Data;

use Cosmotec\EccubeMigration\Model\ProductLink\ConnectionPartLinkType;
use Magento\Catalog\Api\Data\ProductLinkExtensionFactory;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;

/**
 * Task 2 QA rework: registers "connection_part" as a genuine Magento
 * product-link type (mirrors the seed InstallDefaultCategories.php uses
 * for related/up_sell/cross_sell - see Model\ProductLink\
 * ConnectionPartLinkType for the full architecture explanation), then
 * backfills the 862 already-imported eccube_coupling_product_map rows as
 * real catalog_product_link rows so the new native admin UI (Ui\
 * DataProvider\Product\Form\Modifier\ConnectionParts) shows existing
 * data immediately, without requiring a re-run of
 * import:connection-parts. Idempotent both ways: re-running this patch
 * (or ConnectionPartImporter after this patch) never duplicates a link
 * type row or a product link.
 *
 * Implements NonTransactionableInterface: PatchApplier wraps every
 * transactionable patch's apply() in its own outer DB transaction, which
 * conflicts with ProductRepository::save() (called per-parent during the
 * backfill) starting its own nested transaction on the same connection -
 * live-confirmed this round as an "Asymmetric transaction rollback" error
 * that silently rolled back the catalog_product_link_type/
 * catalog_product_link_attribute inserts while still recording the patch
 * as applied. Opting out of the outer transaction here fixes that; each
 * product save still gets its own correctly-scoped transaction.
 */
class CreateConnectionPartLinkType implements DataPatchInterface, NonTransactionableInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductLinkInterfaceFactory $productLinkFactory,
        private readonly ProductLinkExtensionFactory $productLinkExtensionFactory,
        private readonly \Magento\Framework\App\State $appState
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): void
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
            // Already set by an earlier patch/bootstrap step in this run.
        }

        $connection = $this->moduleDataSetup->getConnection();

        // insertForce() is NOT an upsert (it is a plain insert() with a
        // SQL_MODE tweak, verified against the adapter source after this
        // ran into a Duplicate entry error on a second run) - explicit
        // existence check needed for idempotency, same as the
        // catalog_product_link_attribute insert below.
        $existingLinkType = $connection->fetchOne(
            $connection->select()
                ->from($this->moduleDataSetup->getTable('catalog_product_link_type'), 'link_type_id')
                ->where('link_type_id = ?', ConnectionPartLinkType::LINK_TYPE_CONNECTION_PART)
        );

        if (!$existingLinkType) {
            $connection->insertForce(
                $this->moduleDataSetup->getTable('catalog_product_link_type'),
                ['link_type_id' => ConnectionPartLinkType::LINK_TYPE_CONNECTION_PART, 'code' => ConnectionPartLinkType::LINK_TYPE_CODE]
            );
        }

        $existingAttribute = $connection->fetchOne(
            $connection->select()
                ->from($this->moduleDataSetup->getTable('catalog_product_link_attribute'), 'product_link_attribute_id')
                ->where('link_type_id = ?', ConnectionPartLinkType::LINK_TYPE_CONNECTION_PART)
                ->where('product_link_attribute_code = ?', 'position')
        );

        if (!$existingAttribute) {
            $connection->insert(
                $this->moduleDataSetup->getTable('catalog_product_link_attribute'),
                [
                    'link_type_id' => ConnectionPartLinkType::LINK_TYPE_CONNECTION_PART,
                    'product_link_attribute_code' => 'position',
                    'data_type' => 'int',
                ]
            );
        }

        $this->backfillExistingRelationships($connection);
    }

    private function backfillExistingRelationships(\Magento\Framework\DB\Adapter\AdapterInterface $connection): void
    {
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->moduleDataSetup->getTable('eccube_coupling_product_map'),
                    ['magento_parent_product_id', 'magento_connected_product_id', 'sort_no']
                )
                ->where('magento_parent_product_id IS NOT NULL')
                ->where('magento_connected_product_id IS NOT NULL')
                ->where('status != ?', 'obsolete')
                ->order('magento_parent_product_id ASC')
                ->order('sort_no ASC')
        );

        $linksByParent = [];

        foreach ($rows as $row) {
            $linksByParent[(int) $row['magento_parent_product_id']][] = (int) $row['magento_connected_product_id'];
        }

        foreach ($linksByParent as $parentId => $connectedIds) {
            $this->syncParentLinks($parentId, $connectedIds);
        }
    }

    /**
     * @param int[] $connectedIds
     */
    private function syncParentLinks(int $parentId, array $connectedIds): void
    {
        try {
            $parentProduct = $this->productRepository->getById($parentId);
        } catch (LocalizedException) {
            return;
        }

        $existingLinks = $parentProduct->getProductLinks() ?? [];
        $existingConnectionPartSkus = array_map(
            static fn ($link) => $link->getLinkedProductSku(),
            array_filter($existingLinks, static fn ($link): bool => $link->getLinkType() === ConnectionPartLinkType::LINK_TYPE_CODE)
        );

        $newLinks = $existingLinks;
        $position = count($existingConnectionPartSkus);
        $changed = false;

        foreach ($connectedIds as $connectedId) {
            try {
                $connectedProduct = $this->productRepository->getById($connectedId);
            } catch (LocalizedException) {
                continue;
            }

            if (in_array($connectedProduct->getSku(), $existingConnectionPartSkus, true)) {
                continue;
            }

            $position++;
            $link = $this->productLinkFactory->create();
            $link->setSku($parentProduct->getSku());
            $link->setLinkedProductSku($connectedProduct->getSku());
            $link->setLinkType(ConnectionPartLinkType::LINK_TYPE_CODE);
            $link->setPosition($position);
            $link->setExtensionAttributes($this->productLinkExtensionFactory->create());

            $newLinks[] = $link;
            $existingConnectionPartSkus[] = $connectedProduct->getSku();
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $parentProduct->setProductLinks($newLinks);

        try {
            $this->productRepository->save($parentProduct);
        } catch (LocalizedException) {
            // A pre-existing product-save failure (e.g. needs_review
            // pricing) must not abort the whole backfill - the remaining
            // connection parts still get their new native links.
        }
    }
}
