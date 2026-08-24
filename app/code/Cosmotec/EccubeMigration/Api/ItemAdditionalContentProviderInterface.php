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

interface ItemAdditionalContentProviderInterface
{
    /**
     * @return ItemAdditionalContentMap[]
     */
    public function getByMagentoProductId(int $magentoProductId): array;
}
