<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\RelatedProductInterface;

final class RelatedProduct implements RelatedProductInterface
{
    public function __construct(
        private readonly int $id,
        private readonly int $productId,
        private readonly int $relatedProductId
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

    public function getRelatedProductId(): int
    {
        return $this->relatedProductId;
    }
}
