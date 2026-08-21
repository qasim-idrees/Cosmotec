<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\ProductMap;
use Magento\Framework\Exception\CouldNotSaveException;

interface ProductMapRepositoryInterface
{
    public function getByEccubeProductId(int $eccubeProductId): ?ProductMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(ProductMap $productMap): ProductMap;

    /**
     * @return ProductMap[]
     */
    public function getUnfinished(int $limit): array;

    public function countByStatus(string $status): int;

    /**
     * Successfully-imported products belonging to a given EC-CUBE item that
     * have not yet been linked to their parent Grouped Product.
     *
     * @return ProductMap[]
     */
    public function getUnlinkedByItemId(int $eccubeItemId): array;

    /**
     * Used to detect two different EC-CUBE products resolving to the same
     * SKU before a save fails on Magento's unique SKU constraint.
     */
    public function getBySku(string $sku): ?ProductMap;

    /**
     * Timestamp of the most recent successful import/sync, used by
     * ProductSync as the watermark for "what's changed since we last
     * looked".
     */
    public function getMaxLastSyncedAt(): ?\DateTimeImmutable;
}
