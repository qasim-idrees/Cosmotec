<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Cron;

use Cosmotec\EccubeMigration\Api\EccubeConfigProviderInterface;
use Cosmotec\EccubeMigration\Logger\SyncLogger;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use Cosmotec\EccubeMigration\Model\Sync\CategorySync;
use Cosmotec\EccubeMigration\Model\Sync\MediaSync;
use Cosmotec\EccubeMigration\Model\Sync\InventorySync;
use Cosmotec\EccubeMigration\Model\Sync\ItemSync;
use Cosmotec\EccubeMigration\Model\Sync\ProductSync;

/**
 * Registered in etc/crontab.xml, default schedule: hourly.
 *
 * Runs every Milestone 8 Sync service. Unlike FullImportCron, order
 * between stages doesn't matter for correctness here — sync only ever
 * touches records that were already successfully imported at least once,
 * so there's no parent-before-child dependency to preserve — but the same
 * category -> group products -> simple products -> inventory -> images
 * order is kept anyway for log readability.
 */
class SyncCron
{
    public function __construct(
        private readonly EccubeConfigProviderInterface $config,
        private readonly CategorySync $categorySync,
        private readonly ItemSync $itemSync,
        private readonly ProductSync $productSync,
        private readonly InventorySync $inventorySync,
        private readonly MediaSync $mediaSync,
        private readonly SyncLogger $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            $this->logger->info('SyncCron: module disabled, skipping.');

            return;
        }

        if (!$this->config->isScheduledSyncEnabled()) {
            $this->logger->info('SyncCron: scheduled sync disabled, skipping.');

            return;
        }

        $runId = 'cron-sync-' . date('Ymd-His');
        $context = new ImportContext($runId, false, true, null);

        $this->logger->info(sprintf('SyncCron: starting run %s', $runId));

        $this->runStage('categories', fn (): ImportResult => $this->categorySync->import($context));
        $this->runStage('group products', fn (): ImportResult => $this->itemSync->import($context));
        $this->runStage('simple products', fn (): ImportResult => $this->productSync->import($context));
        $this->runStage('inventory', fn (): ImportResult => $this->inventorySync->import($context));

        if ($this->config->getImageFolder() !== null) {
            $this->runStage('media', fn (): ImportResult => $this->mediaSync->import($context));
        } else {
            $this->logger->info('SyncCron: image folder not configured, skipping images stage.');
        }

        $this->logger->info(sprintf('SyncCron: run %s complete', $runId));
    }

    /**
     * @param callable(): ImportResult $stage
     */
    private function runStage(string $label, callable $stage): void
    {
        try {
            $result = $stage();
            $this->logger->info(sprintf(
                'SyncCron: %s stage complete — imported=%d updated=%d skipped=%d errors=%d',
                $label,
                $result->getImported(),
                $result->getUpdated(),
                $result->getSkipped(),
                $result->getErrors()
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('SyncCron: %s stage failed: %s', $label, $e->getMessage()));
        }
    }
}
