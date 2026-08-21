<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Sync;

use Cosmotec\EccubeMigration\Api\ItemMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ItemRepositoryInterface as EccubeItemRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use Cosmotec\EccubeMigration\Model\Import\ItemImporter;
use Cosmotec\EccubeMigration\Model\ItemMapFactory;
use Cosmotec\EccubeMigration\Model\Mapper\ItemMapper;
use Cosmotec\EccubeMigration\Model\Reader\ItemReader;
use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeyResolver;
use Cosmotec\EccubeMigration\Model\Validator\ItemValidator;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory as MagentoProductFactory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Milestone 8 (Synchronization). Extends ItemImporter — see CategorySync
 * for the reasoning; identical pattern here, scanning dtb_item via a
 * MAX(dtb_product.update_date) join (ItemRepository::getModifiedSince(),
 * built in Milestone 2 for exactly this) instead of a full table scan.
 */
class ItemSync extends ItemImporter
{
    public function __construct(
        ItemReader $reader,
        ItemValidator $validator,
        ItemMapper $mapper,
        ItemMapRepositoryInterface $itemMapRepository,
        ItemMapFactory $itemMapFactory,
        SyncHistoryRepositoryInterface $syncHistoryRepository,
        MagentoProductRepositoryInterface $magentoProductRepository,
        MagentoProductFactory $magentoProductFactory,
        CategoryLinkManagementInterface $categoryLinkManagement,
        StoreManagerInterface $storeManager,
        UrlKeyResolver $urlKeyResolver,
        ImportLogger $logger,
        private readonly EccubeItemRepositoryInterface $eccubeItemRepository
    ) {
        parent::__construct(
            $reader,
            $validator,
            $mapper,
            $itemMapRepository,
            $itemMapFactory,
            $syncHistoryRepository,
            $magentoProductRepository,
            $magentoProductFactory,
            $categoryLinkManagement,
            $storeManager,
            $urlKeyResolver,
            $logger
        );
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();
        $since = $this->itemMapRepository->getMaxLastSyncedAt() ?? new \DateTimeImmutable('@0');
        $batchSize = $context->getBatchSize() ?? 100;
        $offset = 0;

        $this->logger->info(sprintf('ItemSync run %s: scanning items modified since %s', $context->getRunId(), $since->format('Y-m-d H:i:s')));

        while (true) {
            $page = $this->eccubeItemRepository->getModifiedSince($since, $offset, $batchSize);

            if ($page === []) {
                break;
            }

            foreach ($page as $source) {
                $this->importOne($source, $context, $result);
            }

            $offset += $batchSize;

            if (count($page) < $batchSize) {
                break;
            }
        }

        $this->logger->info(sprintf(
            'ItemSync run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }
}
