<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

/**
 * Tracks which of a Simple Product's Magento category assignments were
 * placed there by inheritance from its parent Grouped Product, so a later
 * sync can safely remove only those categories when the parent's own
 * category set changes, while never touching categories assigned to the
 * Simple Product independently of this migration.
 */
interface CategoryInheritanceMapRepositoryInterface
{
    /**
     * @return int[] Magento category ids currently tracked as inherited for this product
     */
    public function getCategoryIdsForProduct(int $magentoProductId): array;

    /**
     * @param int[] $categoryIds The parent Grouped Product's current full category id set
     */
    public function replaceForProduct(int $magentoProductId, int $eccubeItemId, array $categoryIds): void;
}
