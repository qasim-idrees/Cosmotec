<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Reader;

use Cosmotec\EccubeMigration\Api\CategoryRepositoryInterface;
use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;

class CategoryReader extends AbstractReader
{
    public function __construct(
        EccubeConfigProviderInterface $config,
        ImportLogger $logger,
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {
        parent::__construct($config, $logger);
    }

    public function count(): int
    {
        return $this->categoryRepository->countAll();
    }

    protected function fetchBatch(int $offset, int $limit): array
    {
        return $this->categoryRepository->getBatch($offset, $limit);
    }
}
