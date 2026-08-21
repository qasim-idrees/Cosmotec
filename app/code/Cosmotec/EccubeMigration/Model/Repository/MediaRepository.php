<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\Data\MediaFileInterface;
use Cosmotec\EccubeMigration\Api\MediaRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\MediaFile;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;

/**
 * Read-only. All SQL for EC-CUBE media lives here.
 *
 * Every join table has the same shape - (owner_id, upload_file_id) - so a
 * single parameterised query serves all seven relations. The table name
 * comes from the enum, never from user input, so interpolating it is safe.
 */
class MediaRepository extends AbstractEccubeRepository implements MediaRepositoryInterface
{
    public function getBatch(MediaRelationType $relationType, int $offset, int $limit): array
    {
        $join = $relationType->joinTable();
        $ownerColumn = $this->ownerColumn($relationType);

        $rows = $this->connection->fetchAll(
            'SELECT r.' . $ownerColumn . ' AS owner_id, uf.id AS upload_file_id, uf.file_name, uf.sort_no
             FROM ' . $join . ' r
             INNER JOIN dtb_upload_file uf ON uf.id = r.upload_file_id
             ORDER BY r.' . $ownerColumn . ' ASC, uf.sort_no ASC, uf.id ASC
             LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset)
        );

        return array_map(
            static fn (array $row): MediaFileInterface => new MediaFile(
                (int) $row['upload_file_id'],
                $relationType,
                (int) $row['owner_id'],
                (string) $row['file_name'],
                (int) $row['sort_no']
            ),
            $rows
        );
    }

    public function countByRelation(MediaRelationType $relationType): int
    {
        return (int) $this->connection->fetchScalar(
            'SELECT COUNT(*) FROM ' . $relationType->joinTable() . ' r
             INNER JOIN dtb_upload_file uf ON uf.id = r.upload_file_id'
        );
    }

    public function getByOwner(MediaRelationType $relationType, int $ownerId): array
    {
        $ownerColumn = $this->ownerColumn($relationType);

        $rows = $this->connection->fetchAll(
            'SELECT r.' . $ownerColumn . ' AS owner_id, uf.id AS upload_file_id, uf.file_name, uf.sort_no
             FROM ' . $relationType->joinTable() . ' r
             INNER JOIN dtb_upload_file uf ON uf.id = r.upload_file_id
             WHERE r.' . $ownerColumn . ' = :owner_id
             ORDER BY uf.sort_no ASC, uf.id ASC',
            ['owner_id' => $ownerId]
        );

        return array_map(
            static fn (array $row): MediaFileInterface => new MediaFile(
                (int) $row['upload_file_id'],
                $relationType,
                (int) $row['owner_id'],
                (string) $row['file_name'],
                (int) $row['sort_no']
            ),
            $rows
        );
    }

    public function getByUploadFileId(MediaRelationType $relationType, int $uploadFileId): ?MediaFileInterface
    {
        $ownerColumn = $this->ownerColumn($relationType);

        $rows = $this->connection->fetchAll(
            'SELECT r.' . $ownerColumn . ' AS owner_id, uf.id AS upload_file_id, uf.file_name, uf.sort_no
             FROM ' . $relationType->joinTable() . ' r
             INNER JOIN dtb_upload_file uf ON uf.id = r.upload_file_id
             WHERE uf.id = :upload_file_id
             LIMIT 1',
            ['upload_file_id' => $uploadFileId]
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return new MediaFile(
            (int) $row['upload_file_id'],
            $relationType,
            (int) $row['owner_id'],
            (string) $row['file_name'],
            (int) $row['sort_no']
        );
    }

    public function getPrimaryUploadFileId(MediaRelationType $relationType, int $ownerId): ?int
    {
        $ownerColumn = $this->ownerColumn($relationType);

        $value = $this->connection->fetchScalar(
            'SELECT uf.id
             FROM ' . $relationType->joinTable() . ' r
             INNER JOIN dtb_upload_file uf ON uf.id = r.upload_file_id
             WHERE r.' . $ownerColumn . ' = :owner_id
             ORDER BY uf.sort_no ASC, uf.id ASC
             LIMIT 1',
            ['owner_id' => $ownerId]
        );

        return $value === null ? null : (int) $value;
    }

    public function getOwnerIds(MediaRelationType $relationType, int $offset, int $limit): array
    {
        $ownerColumn = $this->ownerColumn($relationType);

        $rows = $this->connection->fetchAll(
            'SELECT DISTINCT r.' . $ownerColumn . ' AS owner_id
             FROM ' . $relationType->joinTable() . ' r
             INNER JOIN dtb_upload_file uf ON uf.id = r.upload_file_id
             ORDER BY r.' . $ownerColumn . ' ASC
             LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset)
        );

        return array_map(static fn (array $row): int => (int) $row['owner_id'], $rows);
    }

    public function getExtensionBreakdown(MediaRelationType $relationType): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT LOWER(SUBSTRING_INDEX(uf.file_name, ".", -1)) AS ext, COUNT(*) AS total
             FROM ' . $relationType->joinTable() . ' r
             INNER JOIN dtb_upload_file uf ON uf.id = r.upload_file_id
             GROUP BY ext
             ORDER BY total DESC'
        );

        $breakdown = [];

        foreach ($rows as $row) {
            $breakdown[(string) $row['ext']] = (int) $row['total'];
        }

        return $breakdown;
    }

    /**
     * The owning FK column name in each join table. Doctrine names these
     * after the owning entity, so they are not uniformly "owner_id".
     */
    private function ownerColumn(MediaRelationType $relationType): string
    {
        return match ($relationType) {
            MediaRelationType::PRODUCT,
            MediaRelationType::DIMENSION,
            MediaRelationType::CAD2D,
            MediaRelationType::CAD3D => 'product_id',
            MediaRelationType::ITEM,
            MediaRelationType::CATALOG => 'item_id',
            MediaRelationType::CATEGORY => 'category_id',
        };
    }
}
