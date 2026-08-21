<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Console\Command;

use Cosmotec\EccubeMigration\Api\CategoryMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ImageMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ItemMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    private const STATUSES = ['pending', 'imported', 'updated', 'skipped', 'error'];

    public function __construct(
        private readonly CategoryMapRepositoryInterface $categoryMapRepository,
        private readonly ItemMapRepositoryInterface $itemMapRepository,
        private readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly ImageMapRepositoryInterface $imageMapRepository
    ) {
        parent::__construct('cosmotec:eccube:status');
    }

    protected function configure(): void
    {
        $this->setDescription('Show import/sync status counts across all entity mapping tables.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['Entity', 'Pending', 'Imported', 'Updated', 'Skipped', 'Error']);

        $table->addRow($this->row('Categories', fn (string $status): int => $this->categoryMapRepository->countByStatus($status)));
        $table->addRow($this->row('Items (Grouped)', fn (string $status): int => $this->itemMapRepository->countByStatus($status)));
        $table->addRow($this->row('Products (Simple)', fn (string $status): int => $this->productMapRepository->countByStatus($status)));
        $table->addRow($this->row('Images', fn (string $status): int => $this->imageMapRepository->countByStatus($status)));

        $table->render();

        return Command::SUCCESS;
    }

    /**
     * @return array{string, int, int, int, int, int}
     */
    private function row(string $label, callable $countByStatus): array
    {
        $counts = array_map($countByStatus, self::STATUSES);

        return [$label, ...$counts];
    }
}
