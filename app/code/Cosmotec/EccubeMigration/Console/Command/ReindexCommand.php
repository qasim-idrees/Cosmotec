<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Console\Command;

use Magento\Framework\Indexer\IndexerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Import Order step 11 ("Reindex"). Reindexes the catalog indexers most
 * affected by this module's writes — category, product, and inventory.
 * Deliberately does not touch unrelated indexers (e.g. customer_grid) that
 * this module has no reason to invalidate.
 */
class ReindexCommand extends Command
{
    private const INDEXER_IDS = [
        'catalog_category_product',
        'catalog_product_category',
        'catalog_product_attribute',
        'catalog_product_price',
        'cataloginventory_stock',
        'inventory',
    ];

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry
    ) {
        parent::__construct('cosmotec:eccube:reindex');
    }

    protected function configure(): void
    {
        $this->setDescription('Reindex the catalog/inventory indexers affected by this module (category, product, price, stock).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failures = 0;

        foreach (self::INDEXER_IDS as $indexerId) {
            try {
                $indexer = $this->indexerRegistry->get($indexerId);
                $indexer->reindexAll();
                $output->writeln(sprintf('<info>Reindexed %s</info>', $indexerId));
            } catch (\Throwable $e) {
                // Some indexer IDs above may not exist depending on which
                // Magento modules/editions are installed (e.g. "inventory"
                // is only present with certain MSI configurations) — that's
                // expected, not a hard failure, so it's logged and skipped.
                $output->writeln(sprintf('<comment>Skipped %s: %s</comment>', $indexerId, $e->getMessage()));
                $failures++;
            }
        }

        if ($failures === count(self::INDEXER_IDS)) {
            $output->writeln('<error>No indexers could be reindexed.</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
