<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\DTO;

use Cosmotec\EccubeMigration\Api\Data\CouplingProductInterface;

final class CouplingProduct implements CouplingProductInterface
{
    public function __construct(
        private readonly int $id,
        private readonly int $itemId,
        private readonly int $productId
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }
}
