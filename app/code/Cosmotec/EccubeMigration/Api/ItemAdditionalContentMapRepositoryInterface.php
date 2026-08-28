<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\ItemAdditionalContentMap;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

interface ItemAdditionalContentMapRepositoryInterface
{
    public function getBySourceRowId(int $eccubeAdditionalInformationId): ?ItemAdditionalContentMap;

    /**
     * Direct entity_id lookup, used by the admin Save/Delete controllers
     * (which address a specific tab row, EC-CUBE-imported or
     * admin-created, rather than an EC-CUBE source row).
     *
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): ItemAdditionalContentMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(ItemAdditionalContentMap $map): ItemAdditionalContentMap;

    /**
     * Hard delete - the admin "Remove tab" action. Unlike EC-CUBE-driven
     * obsolete-marking (which must preserve history for re-import),
     * admin-initiated deletion is a genuine removal.
     *
     * @throws CouldNotDeleteException
     */
    public function delete(ItemAdditionalContentMap $map): void;

    /**
     * All mappings for an item, so tabs removed at source can be marked
     * obsolete without touching the ones that remain.
     *
     * @return ItemAdditionalContentMap[]
     */
    public function getByItemId(int $eccubeItemId): array;

    /**
     * All non-obsolete tabs for a Magento Grouped Product, ordered for
     * display - used by the admin UI section and the extension attribute
     * plugin.
     *
     * @return ItemAdditionalContentMap[]
     */
    public function getByMagentoProductId(int $magentoProductId): array;

    public function countByStatus(string $status): int;
}
