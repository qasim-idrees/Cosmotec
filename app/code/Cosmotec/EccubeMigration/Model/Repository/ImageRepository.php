<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Repository;

use Cosmotec\EccubeMigration\Api\Data\ImageInterface;
use Cosmotec\EccubeMigration\Api\ImageRepositoryInterface;
use Cosmotec\EccubeMigration\Model\DTO\Image;

/**
 * @deprecated LEGACY - NOT USED BY THE PRODUCTION PIPELINE.
 *
 * This class reads dtb_product_image, which contains ZERO rows in the
 * Cosmotec production database. Real media lives in dtb_upload_file
 * joined through seven catalog relation tables.
 *
 * The production path is now:
 *   MediaReader -> MediaValidator -> MediaImporter -> eccube_media_map
 *   (commands: import:images, sync:images)
 *
 * Retained only so existing eccube_image_map rows remain readable. Do not
 * extend, and do not write new data through it.
 */
class ImageRepository extends AbstractEccubeRepository implements ImageRepositoryInterface
{
    private const TABLE = 'dtb_product_image';

    public function getByProductId(int $productId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . self::TABLE . ' WHERE product_id = :product_id ORDER BY sort_no ASC, id ASC',
            ['product_id' => $productId]
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function getBatch(int $offset, int $limit): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT * FROM ' . self::TABLE . ' ORDER BY id ASC LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset)
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function countAll(): int
    {
        return (int) $this->connection->fetchScalar('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ImageInterface
    {
        return new Image(
            (int) $row['id'],
            $this->toNullableInt($row, 'product_id'),
            (string) $row['file_name'],
            (int) $row['sort_no'],
            $this->toDateTimeImmutable($row, 'create_date')
        );
    }
}
