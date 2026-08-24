<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Console\Command;

use Cosmotec\EccubeMigration\Api\AttributeSetMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\CategoryMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\CouplingProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ImageMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ItemAdditionalContentMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ItemMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\MediaMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\ProductReferenceMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\RelatedProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SpecificationMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\Media\MediaRelationType;
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
        private readonly ImageMapRepositoryInterface $imageMapRepository,
        private readonly SpecificationMapRepositoryInterface $specificationMapRepository,
        private readonly AttributeSetMapRepositoryInterface $attributeSetMapRepository,
        private readonly RelatedProductMapRepositoryInterface $relatedProductMapRepository,
        private readonly CouplingProductMapRepositoryInterface $couplingProductMapRepository,
        private readonly ProductReferenceMapRepositoryInterface $productReferenceMapRepository,
        private readonly ItemAdditionalContentMapRepositoryInterface $additionalContentMapRepository,
        private readonly MediaMapRepositoryInterface $mediaMapRepository
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
        $table->addRow($this->row('Attributes', fn (string $status): int => $this->specificationMapRepository->countByStatus($status)));
        $table->addRow($this->row('Attribute Sets', fn (string $status): int => $this->attributeSetMapRepository->countByStatus($status)));
        $table->addRow($this->row('Related Products', fn (string $status): int => $this->relatedProductMapRepository->countByStatus($status)));
        $table->addRow($this->row('Connection Parts', fn (string $status): int => $this->couplingProductMapRepository->countByStatus($status)));
        $table->addRow($this->row('Product References', fn (string $status): int => $this->productReferenceMapRepository->countByStatus($status)));
        $table->addRow($this->row('Additional Content', fn (string $status): int => $this->additionalContentMapRepository->countByStatus($status)));
        $table->addRow($this->row('Media', fn (string $status): int => $this->countMediaByStatus($status)));
        $table->addRow($this->row('Images (legacy)', fn (string $status): int => $this->imageMapRepository->countByStatus($status)));

        $table->render();

        return Command::SUCCESS;
    }

    /**
     * Sums across all 7 MediaRelationType cases - MediaMapRepositoryInterface
     * counts per relation type, unlike every other map repository here.
     */
    private function countMediaByStatus(string $status): int
    {
        $total = 0;

        foreach (MediaRelationType::cases() as $relationType) {
            $total += $this->mediaMapRepository->countByStatus($relationType, $status);
        }

        return $total;
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
