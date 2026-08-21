<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\ProductSpecificationValueInterface;

final class ProductSpecificationValue implements ProductSpecificationValueInterface
{
    public function __construct(
        private readonly int $id,
        private readonly int $productId,
        private readonly int $specificationId,
        private readonly int $specificationClassId
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getSpecificationId(): int
    {
        return $this->specificationId;
    }

    public function getSpecificationClassId(): int
    {
        return $this->specificationClassId;
    }
}
