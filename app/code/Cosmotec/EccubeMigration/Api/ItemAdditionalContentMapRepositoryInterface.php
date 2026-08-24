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
use Magento\Framework\Exception\CouldNotSaveException;

interface ItemAdditionalContentMapRepositoryInterface
{
    public function getBySourceRowId(int $eccubeAdditionalInformationId): ?ItemAdditionalContentMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(ItemAdditionalContentMap $map): ItemAdditionalContentMap;

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
