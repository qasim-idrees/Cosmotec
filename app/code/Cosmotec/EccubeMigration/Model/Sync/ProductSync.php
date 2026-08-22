<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Sync;

use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductRepositoryInterface as EccubeProductRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use Cosmotec\EccubeMigration\Model\Import\ProductImporter;
use Cosmotec\EccubeMigration\Model\Mapper\ProductMapper;
use Cosmotec\EccubeMigration\Model\ProductMapFactory;
use Cosmotec\EccubeMigration\Model\Reader\ProductReader;
use Cosmotec\EccubeMigration\Model\UrlKey\UrlKeyFallbackGenerator;
use Cosmotec\EccubeMigration\Model\Validator\ProductValidator;
use Magento\Catalog\Api\Data\ProductInterfaceFactory as MagentoProductFactory;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Milestone 8 (Synchronization). Extends ProductImporter — see CategorySync
 * for the reasoning; identical pattern, scanning dtb_product via
 * ProductRepository::getModifiedSince() (Milestone 2) instead of a full
 * table scan. Does not touch Group Relations or Inventory — those remain
 * the concern of ProductRelationImporter and InventorySync respectively.
 */
class ProductSync extends ProductImporter
{
    public function __construct(
        ProductReader $reader,
        ProductValidator $validator,
        ProductMapper $mapper,
        ProductMapRepositoryInterface $productMapRepository,
        ProductMapFactory $productMapFactory,
        SyncHistoryRepositoryInterface $syncHistoryRepository,
        MagentoProductRepositoryInterface $magentoProductRepository,
        MagentoProductFactory $magentoProductFactory,
        StoreManagerInterface $storeManager,
        UrlKeyFallbackGenerator $urlKeyFallbackGenerator,
        ImportLogger $logger,
        private readonly EccubeProductRepositoryInterface $eccubeProductRepository
    ) {
        parent::__construct(
            $reader,
            $validator,
            $mapper,
            $productMapRepository,
            $productMapFactory,
            $syncHistoryRepository,
            $magentoProductRepository,
            $magentoProductFactory,
            $storeManager,
            $urlKeyFallbackGenerator,
            $logger
        );
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();
        $since = $this->productMapRepository->getMaxLastSyncedAt() ?? new \DateTimeImmutable('@0');
        $batchSize = $context->getBatchSize() ?? 100;
        $offset = 0;

        $this->logger->info(sprintf('ProductSync run %s: scanning products modified since %s', $context->getRunId(), $since->format('Y-m-d H:i:s')));

        while (true) {
            $page = $this->eccubeProductRepository->getModifiedSince($since, $offset, $batchSize);

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
            'ProductSync run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }
}
