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
use Cosmotec\EccubeMigration\Api\ImageRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;

class ImageReader extends AbstractReader
{
    public function __construct(
        EccubeConfigProviderInterface $config,
        ImportLogger $logger,
        private readonly ImageRepositoryInterface $imageRepository
    ) {
        parent::__construct($config, $logger);
    }

    public function count(): int
    {
        return $this->imageRepository->countAll();
    }

    protected function fetchBatch(int $offset, int $limit): array
    {
        return $this->imageRepository->getBatch($offset, $limit);
    }
}
