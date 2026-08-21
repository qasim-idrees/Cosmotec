<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Model\MediaMap;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;
use Magento\Framework\Exception\CouldNotSaveException;

interface MediaMapRepositoryInterface
{
    /**
     * Canonical lookup: (relation type, owner, upload file).
     */
    public function get(MediaRelationType $relationType, int $ownerId, int $uploadFileId): ?MediaMap;

    /**
     * @throws CouldNotSaveException
     */
    public function save(MediaMap $map): MediaMap;

    /**
     * Existing mappings for one owner, used to detect source relations
     * that have disappeared so they can be marked obsolete.
     *
     * @return MediaMap[]
     */
    public function getByOwner(MediaRelationType $relationType, int $ownerId): array;

    /**
     * Other active mappings that reference the same physical source file.
     * A Magento file must not be removed while another relation still
     * uses it.
     *
     * @return MediaMap[]
     */
    public function getOtherActiveUsages(int $uploadFileId, int $excludeEntityId): array;

    /**
     * @return MediaMap[]
     */
    public function getFailed(MediaRelationType $relationType, int $limit): array;

    public function countByStatus(MediaRelationType $relationType, string $status): int;
}
