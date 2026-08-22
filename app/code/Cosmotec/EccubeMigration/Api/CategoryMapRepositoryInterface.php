<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\CategoryMap;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Persistence for eccube_category_map. Distinct from
 * Api\CategoryRepositoryInterface, which reads EC-CUBE source data — this
 * repository reads/writes the Magento-side mapping table that links an
 * EC-CUBE category to the Magento catalog category created from it.
 */
interface CategoryMapRepositoryInterface
{
    public function getByEccubeCategoryId(int $eccubeCategoryId): ?CategoryMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(CategoryMap $categoryMap): CategoryMap;

    /**
     * Rows not yet successfully imported (pending or error), for --resume.
     *
     * @return CategoryMap[]
     */
    public function getUnfinished(int $limit): array;

    public function countByStatus(string $status): int;

    /**
     * Timestamp of the most recent successful import/sync, used by
     * CategorySync as the watermark for "what's changed since we last
     * looked". Null if nothing has ever been imported yet.
     */
    public function getMaxLastSyncedAt(): ?\DateTimeImmutable;

    /**
     * All rows currently believed to correspond to a live Magento category
     * (status IMPORTED or UPDATED) - used by CategorySync to detect
     * categories deleted at the EC-CUBE source, which has no del_flg/status
     * column of its own and therefore cannot be detected any other way.
     *
     * @return CategoryMap[]
     */
    public function getAllSuccessful(): array;
}
