<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\ImageInterface;

final class Image implements ImageInterface
{
    public function __construct(
        private readonly int $id,
        private readonly ?int $productId,
        private readonly string $fileName,
        private readonly int $sortNo,
        private readonly \DateTimeImmutable $createDate
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getSortNo(): int
    {
        return $this->sortNo;
    }

    public function getCreateDate(): \DateTimeImmutable
    {
        return $this->createDate;
    }
}
