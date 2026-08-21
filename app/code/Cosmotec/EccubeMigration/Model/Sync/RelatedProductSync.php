<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Sync;

use Cosmotec\EccubeMigration\Api\RelatedProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\SyncLogger;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImporterInterface;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use Cosmotec\EccubeMigration\Model\Import\RelatedProductImporter;

/**
 * RelatedProductImporter already handles CREATE/SKIP idempotently (a
 * relation already linked is a static id-only fact with nothing to
 * update). Sync adds the one thing import cannot infer: relations that
 * have DISAPPEARED at source - marked obsolete via
 * RelatedProductImporter::markObsoleteForProduct(), same pattern as
 * MediaSync.
 */
class RelatedProductSync implements ImporterInterface
{
    private const PRODUCT_PAGE_SIZE = 500;

    public function __construct(
        private readonly RelatedProductImporter $relatedProductImporter,
        private readonly RelatedProductMapRepositoryInterface $mapRepository,
        private readonly SyncLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = $this->relatedProductImporter->import($context);
        $this->markObsolete($context);

        $this->logger->info(sprintf(
            'RelatedProductSync run %s complete: linked=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    private function markObsolete(ImportContext $context): void
    {
        if ($context->isDryRun()) {
            return;
        }

        $offset = 0;

        while (true) {
            $productIds = $this->mapRepository->getDistinctProductIds($offset, self::PRODUCT_PAGE_SIZE);

            if ($productIds === []) {
                break;
            }

            foreach ($productIds as $productId) {
                $count = $this->relatedProductImporter->markObsoleteForProduct($productId);

                if ($count > 0) {
                    $this->logger->info(sprintf('RelatedProductSync: marked %d relation(s) obsolete for product id=%d', $count, $productId));
                }
            }

            if (count($productIds) < self::PRODUCT_PAGE_SIZE) {
                break;
            }

            $offset += self::PRODUCT_PAGE_SIZE;
        }
    }
}
