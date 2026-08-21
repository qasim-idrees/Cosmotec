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
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\Import\CategoryImporter;
use Cosmotec\EccubeMigration\Model\Import\MediaImporter;
use Cosmotec\EccubeMigration\Model\Import\ProductReferenceImporter;
use Cosmotec\EccubeMigration\Model\Import\ImportContext;
use Cosmotec\EccubeMigration\Model\Import\ImportResult;
use Cosmotec\EccubeMigration\Model\Import\InventoryImporter;
use Cosmotec\EccubeMigration\Model\Import\ItemImporter;
use Cosmotec\EccubeMigration\Model\Import\ProductImporter;
use Cosmotec\EccubeMigration\Model\Import\ProductRelationImporter;

/**
 * Registered in etc/crontab.xml, default schedule: daily at 02:00.
 *
 * Runs every Milestone 3-7 importer in the same dependency order as the
 * spec's Import Order (categories -> group products -> simple products ->
 * group relations -> images -> inventory). Every importer is already
 * idempotent (skips already-imported records) and continues past
 * individual record failures on its own, so running the full pipeline on
 * every scheduled execution is safe and correctly picks up anything new
 * added to EC-CUBE since the last run — this is intentionally the same
 * behavior as running all six `cosmotec:eccube:import:*` CLI commands back
 * to back, just automated.
 */
class FullImportCron
{
    public function __construct(
        private readonly EccubeConfigProviderInterface $config,
        private readonly CategoryImporter $categoryImporter,
        private readonly ItemImporter $itemImporter,
        private readonly ProductImporter $productImporter,
        private readonly ProductRelationImporter $productRelationImporter,
        private readonly MediaImporter $mediaImporter,
        private readonly ProductReferenceImporter $productReferenceImporter,
        private readonly InventoryImporter $inventoryImporter,
        private readonly ImportLogger $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            $this->logger->info('FullImportCron: module disabled, skipping.');

            return;
        }

        if (!$this->config->isScheduledImportEnabled()) {
            $this->logger->info('FullImportCron: scheduled full import disabled, skipping.');

            return;
        }

        $runId = 'cron-full-' . date('Ymd-His');
        $context = new ImportContext($runId, false, true, null);

        $this->logger->info(sprintf('FullImportCron: starting run %s', $runId));

        $this->runStage('categories', fn (): ImportResult => $this->categoryImporter->import($context));
        $this->runStage('group products', fn (): ImportResult => $this->itemImporter->import($context));
        $this->runStage('simple products', fn (): ImportResult => $this->productImporter->import($context));
        $this->runStage('group relations', fn (): ImportResult => $this->productRelationImporter->import($context));

        if ($this->config->getImageFolder() !== null) {
            $this->runStage('media', fn (): ImportResult => $this->mediaImporter->import($context));
        } else {
            $this->logger->info('FullImportCron: image folder not configured, skipping images stage.');
        }

        $this->runStage('product references', fn (): ImportResult => $this->productReferenceImporter->import($context));
        $this->runStage('inventory', fn (): ImportResult => $this->inventoryImporter->import($context));

        $this->logger->info(sprintf('FullImportCron: run %s complete', $runId));
    }

    /**
     * Each stage runs independently: if one stage throws unexpectedly
     * (individual record errors are already handled inside each importer,
     * so this only catches genuine infrastructure failures — e.g. the
     * EC-CUBE database becoming unreachable mid-run), it's logged and the
     * remaining stages still run rather than aborting the whole cron job.
     *
     * @param callable(): ImportResult $stage
     */
    private function runStage(string $label, callable $stage): void
    {
        try {
            $result = $stage();
            $this->logger->info(sprintf(
                'FullImportCron: %s stage complete — imported=%d updated=%d skipped=%d errors=%d',
                $label,
                $result->getImported(),
                $result->getUpdated(),
                $result->getSkipped(),
                $result->getErrors()
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('FullImportCron: %s stage failed: %s', $label, $e->getMessage()));
        }
    }
}
