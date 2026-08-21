<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Api;

use Cosmotec\EccubeMigration\Api\Data\MediaFileInterface;
use Cosmotec\EccubeMigration\Model\Connection\EccubeConnectionException;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;

/**
 * Read-only access to EC-CUBE media through its relation tables.
 *
 * Replaces the dtb_product_image based ImageRepository: that table has
 * zero rows in production, and real media lives in dtb_upload_file joined
 * through seven catalog relation tables.
 */
interface MediaRepositoryInterface
{
    /**
     * @return MediaFileInterface[]
     * @throws EccubeConnectionException
     */
    public function getBatch(MediaRelationType $relationType, int $offset, int $limit): array;

    /**
     * @throws EccubeConnectionException
     */
    public function countByRelation(MediaRelationType $relationType): int;

    /**
     * @return MediaFileInterface[]
     * @throws EccubeConnectionException
     */
    public function getByOwner(MediaRelationType $relationType, int $ownerId): array;

    /**
     * One specific dtb_upload_file row within a relation. Filtered in SQL,
     * not in PHP: product media alone is ~30,876 rows.
     *
     * @throws EccubeConnectionException
     */
    public function getByUploadFileId(MediaRelationType $relationType, int $uploadFileId): ?MediaFileInterface;

    /**
     * The upload file id that should carry the primary image roles for an
     * owner within a given relation: the first row by the source's own
     * ordering (sort_no ASC, then id ASC as a deterministic tie-break).
     *
     * Deliberately scoped to a single relation so that a dimension, CAD or
     * item file can never influence which product_upload_file row becomes
     * the Simple Product's main image.
     *
     * @throws EccubeConnectionException
     */
    public function getPrimaryUploadFileId(MediaRelationType $relationType, int $ownerId): ?int;

    /**
     * Distinct owner ids for a relation, paged. Lets synchronization walk
     * owner by owner instead of materialising the whole relation.
     *
     * @return int[]
     * @throws EccubeConnectionException
     */
    public function getOwnerIds(MediaRelationType $relationType, int $offset, int $limit): array;

    /**
     * Extension breakdown per relation, for the analyze:media report.
     *
     * @return array<string, int>
     * @throws EccubeConnectionException
     */
    public function getExtensionBreakdown(MediaRelationType $relationType): array;
}
