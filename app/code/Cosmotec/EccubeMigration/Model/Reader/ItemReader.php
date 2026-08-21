<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Reader;

use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Api\ItemRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;

class ItemReader extends AbstractReader
{
    public function __construct(
        EccubeConfigProviderInterface $config,
        ImportLogger $logger,
        private readonly ItemRepositoryInterface $itemRepository
    ) {
        parent::__construct($config, $logger);
    }

    public function count(): int
    {
        return $this->itemRepository->countAll();
    }

    protected function fetchBatch(int $offset, int $limit): array
    {
        return $this->itemRepository->getBatch($offset, $limit);
    }
}
