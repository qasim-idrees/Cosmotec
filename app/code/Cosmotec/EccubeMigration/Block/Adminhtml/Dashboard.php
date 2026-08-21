<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Block\Adminhtml;

use Cosmotec\EccubeMigration\Api\CategoryMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ImageMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ItemMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Dashboard extends Template
{
    private const STATUSES = ['pending', 'imported', 'updated', 'skipped', 'error'];
    private const RECENT_LIMIT = 25;

    public function __construct(
        Context $context,
        private readonly CategoryMapRepositoryInterface $categoryMapRepository,
        private readonly ItemMapRepositoryInterface $itemMapRepository,
        private readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly ImageMapRepositoryInterface $imageMapRepository,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array{label: string, counts: array<string, int>}>
     */
    public function getEntitySummaries(): array
    {
        return [
            $this->summarize('Categories', fn (string $status): int => $this->categoryMapRepository->countByStatus($status)),
            $this->summarize('Items (Grouped Products)', fn (string $status): int => $this->itemMapRepository->countByStatus($status)),
            $this->summarize('Products (Simple Products)', fn (string $status): int => $this->productMapRepository->countByStatus($status)),
            $this->summarize('Images', fn (string $status): int => $this->imageMapRepository->countByStatus($status)),
        ];
    }

    /**
     * @return string[]
     */
    public function getStatuses(): array
    {
        return self::STATUSES;
    }

    /**
     * @return SyncHistory[]
     */
    public function getRecentActivity(): array
    {
        return $this->syncHistoryRepository->getRecent(self::RECENT_LIMIT);
    }

    /**
     * @return array{label: string, counts: array<string, int>}
     */
    private function summarize(string $label, callable $countByStatus): array
    {
        $counts = [];

        foreach (self::STATUSES as $status) {
            $counts[$status] = $countByStatus($status);
        }

        return ['label' => $label, 'counts' => $counts];
    }
}
