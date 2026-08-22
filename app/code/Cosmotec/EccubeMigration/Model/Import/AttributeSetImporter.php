<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\AttributeSetMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\Data\SpecificationInterface;
use Cosmotec\EccubeMigration\Api\SpecificationMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SpecificationRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\AttributeSetMap;
use Cosmotec\EccubeMigration\Model\AttributeSetMapFactory;
use Cosmotec\EccubeMigration\Model\Config\DefaultAttributeSetProvider;
use Cosmotec\EccubeMigration\Model\Mapper\AttributeSetResolver;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Eav\Api\AttributeGroupRepositoryInterface;
use Magento\Eav\Api\AttributeManagementInterface;
use Magento\Eav\Api\AttributeSetManagementInterface;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Api\Data\AttributeSetInterfaceFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;

/**
 * Creates/updates the 8 Magento Attribute Sets derived from EC-CUBE's
 * top-level categories (docs/ATTRIBUTE_MIGRATION_PLAN.md §7,
 * docs/SPECIFICATION_MAGENTO_DATA_MODEL.md ADDENDUM D) and assigns every
 * CREATE-classified specification used anywhere in that category tree.
 *
 * Approved and executed (see BUILD_STATUS.md).
 *
 * Design notes:
 *  - EC-CUBE has no attribute-set concept - this is a Magento design
 *    decision, stated explicitly in every set's mapping row and echoed by
 *    AnalyzeAttributeSetsCommand's own output.
 *  - Sets are PERMISSIVE unions (docs ADDENDUM D: the admin "Product
 *    Specifications" whitelist hypothesis was tested against the full
 *    production database and rejected - only 5.69% conformance). A
 *    specification not populated on every product in its set is normal
 *    Magento behaviour, not a data-quality problem.
 *  - Requires AttributeImporter to have already run: a specification
 *    without a Magento attribute yet is skipped from the assignment (not
 *    an error) and picked up automatically on the next run.
 *  - Idempotent: an existing attribute set with the matching name is
 *    adopted (never duplicated), matching AttributeImporter's own pattern.
 *  - Deliberately does NOT touch the pre-existing "Coaxial" attribute set
 *    (id 9, 76 attributes including a manually-built ct_* family, 84 live
 *    products) discovered this session - see BUILD_STATUS.md. That
 *    reconciliation is an explicit pending business decision, not
 *    something this importer may resolve on its own.
 */
class AttributeSetImporter implements ImporterInterface
{
    private const SKELETON_GROUP_CODE_FALLBACK_SORT = 0;

    public function __construct(
        private readonly SpecificationRepositoryInterface $specificationRepository,
        private readonly SpecificationMapRepositoryInterface $specificationMapRepository,
        private readonly AttributeSetMapRepositoryInterface $attributeSetMapRepository,
        private readonly AttributeSetMapFactory $attributeSetMapFactory,
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly AttributeSetManagementInterface $attributeSetManagement,
        private readonly AttributeSetInterfaceFactory $attributeSetFactory,
        private readonly AttributeGroupRepositoryInterface $attributeGroupRepository,
        private readonly AttributeManagementInterface $attributeManagement,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly DefaultAttributeSetProvider $defaultAttributeSetProvider,
        private readonly EavConfig $eavConfig,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        $result = new ImportResult();

        foreach ($this->specificationRepository->getTopLevelCategories() as $topLevel) {
            $this->importOne($topLevel, $context, $result);
        }

        // The 9th set: a fallback for items with specification values but
        // zero dtb_category_item rows (Round 32/45, database-confirmed),
        // which can never resolve through the category-based path above.
        // Not a real EC-CUBE category - see AttributeSetResolver::UNCATEGORIZED_TOP_LEVEL_ID.
        $this->importOne(
            [
                'id' => AttributeSetResolver::UNCATEGORIZED_TOP_LEVEL_ID,
                'name' => 'Uncategorized',
                'name_en' => 'Uncategorized',
                'sort_no' => PHP_INT_MAX,
            ],
            $context,
            $result
        );

        $this->logger->info(sprintf(
            'AttributeSetImporter run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    /**
     * @param array{id: int, name: string, name_en: string, sort_no: int} $topLevel
     */
    private function importOne(array $topLevel, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $setName = $topLevel['name_en'] !== '' ? $topLevel['name_en'] : $topLevel['name'];

        try {
            if ($topLevel['id'] === AttributeSetResolver::UNCATEGORIZED_TOP_LEVEL_ID) {
                $usage = $this->specificationRepository->getSpecificationUsageForItems(
                    $this->specificationRepository->getUncategorizedItemIds()
                );
                $specIds = $usage['item_scope_specification_ids'];
            } else {
                $descendants = $this->specificationRepository->getDescendantCategoryIds($topLevel['id']);
                $usage = $this->specificationRepository->getSpecificationUsageForCategories($descendants);
                $specIds = array_values(array_unique(array_merge(
                    $usage['item_scope_specification_ids'],
                    $usage['product_scope_specification_ids']
                )));
            }

            sort($specIds);

            // Only specifications that actually became Magento attributes
            // can be assigned. A spec not yet imported is skipped here and
            // picked up automatically the next time this command runs
            // after AttributeImporter creates it - never an error.
            $attributeCodes = [];
            $pendingCount = 0;

            foreach ($specIds as $specId) {
                $specMap = $this->specificationMapRepository->getBySpecificationId($specId);

                if ($specMap === null
                    || $specMap->getClassification() !== SpecificationInterface::CLASSIFICATION_CREATE
                    || $specMap->getMagentoAttributeId() === null) {
                    $pendingCount++;

                    continue;
                }

                $attributeCodes[] = $specMap->getAttributeCode();
            }

            $hash = hash('sha256', implode(',', $attributeCodes));
            $existingMap = $this->attributeSetMapRepository->getByTopLevelCategoryId($topLevel['id']);

            if ($existingMap !== null && $existingMap->getContentHash() === $hash) {
                $result->incrementSkipped();

                return;
            }

            if ($context->isDryRun()) {
                $result->incrementImported();
                $this->logger->info(sprintf(
                    '[DRY RUN] Would create/verify attribute set "%s" (top-level category id=%d) with %d attribute(s)%s',
                    $setName,
                    $topLevel['id'],
                    count($attributeCodes),
                    $pendingCount > 0 ? sprintf(', %d specification(s) pending (attribute not yet created)', $pendingCount) : ''
                ));

                return;
            }

            $this->persist($topLevel, $setName, $attributeCodes, $hash, count($specIds), $existingMap, $context, $result, $startTime, $startMemory);
        } catch (LocalizedException | \Throwable $e) {
            $result->incrementErrors();
            $this->logger->error(sprintf('Attribute set for top-level category %d ("%s") failed: %s', $topLevel['id'], $setName, $e->getMessage()));

            if (!$context->isDryRun()) {
                $existingMap = $this->attributeSetMapRepository->getByTopLevelCategoryId($topLevel['id']);
                /** @var AttributeSetMap $map */
                $map = $existingMap ?? $this->attributeSetMapFactory->create();
                $map->setEccubeTopLevelCategoryId($topLevel['id']);
                $map->setSetName($setName);
                $map->setStatus(AttributeSetMap::STATUS_ERROR);
                $map->setErrorMessage($e->getMessage());
                $this->attributeSetMapRepository->save($map);
            }

            $this->recordHistory($context, $topLevel['id'], null, SyncHistory::STATUS_ERROR, $e->getMessage(), $startTime, $startMemory);
        }
    }

    /**
     * @param array{id: int, name: string, name_en: string, sort_no: int} $topLevel
     * @param string[] $attributeCodes
     */
    private function persist(
        array $topLevel,
        string $setName,
        array $attributeCodes,
        string $hash,
        int $specificationCount,
        ?AttributeSetMap $existingMap,
        ImportContext $context,
        ImportResult $result,
        float $startTime,
        int $startMemory
    ): void {
        $attributeSetId = $existingMap?->getMagentoAttributeSetId() !== null
            ? (int) $existingMap->getMagentoAttributeSetId()
            : $this->findExistingSetIdByName($setName);
        $isUpdate = $attributeSetId !== null;

        if ($attributeSetId === null) {
            $attributeSetId = $this->createAttributeSet($setName);
        }

        $groupId = $this->resolveDefaultGroupId($attributeSetId);

        foreach ($attributeCodes as $index => $code) {
            // assign() is itself idempotent - re-assigning an attribute
            // already in the set is a harmless no-op, which is what makes
            // repeated runs safe as the union grows over time.
            $this->attributeManagement->assign(
                MagentoProduct::ENTITY,
                (string) $attributeSetId,
                (string) $groupId,
                $code,
                $index
            );
        }

        /** @var AttributeSetMap $map */
        $map = $existingMap ?? $this->attributeSetMapFactory->create();
        $map->setEccubeTopLevelCategoryId($topLevel['id']);
        $map->setSetName($setName);
        $map->setMagentoAttributeSetId($attributeSetId);
        $map->setSpecificationCount($specificationCount);
        $map->setContentHash($hash);
        $map->setStatus($isUpdate ? AttributeSetMap::STATUS_UPDATED : AttributeSetMap::STATUS_IMPORTED);
        $map->setErrorMessage(null);
        $map->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->attributeSetMapRepository->save($map);

        if ($isUpdate) {
            $result->incrementUpdated();
        } else {
            $result->incrementImported();
        }

        $this->logger->info(sprintf(
            'Attribute set "%s" (top-level category id=%d) %s as Magento attribute_set_id=%d, %d attribute(s) assigned',
            $setName,
            $topLevel['id'],
            $isUpdate ? 'updated' : 'created',
            $attributeSetId,
            count($attributeCodes)
        ));

        $this->recordHistory(
            $context,
            $topLevel['id'],
            $attributeSetId,
            $isUpdate ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
            null,
            $startTime,
            $startMemory
        );
    }

    private function findExistingSetIdByName(string $setName): ?int
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('attribute_set_name', $setName)
            ->addFilter('entity_type_id', $this->getProductEntityTypeId())
            ->create();

        $sets = $this->attributeSetRepository->getList($criteria)->getItems();

        foreach ($sets as $set) {
            return (int) $set->getAttributeSetId();
        }

        return null;
    }

    /**
     * New sets are cloned from the module's own configured default set
     * (Stores > Configuration, same DefaultAttributeSetProvider every
     * other mapper already uses) as the skeleton, so the new set inherits
     * a normal Magento attribute-group structure (General/Design/etc.)
     * rather than being empty.
     */
    private function createAttributeSet(string $setName): int
    {
        $attributeSet = $this->attributeSetFactory->create();
        $attributeSet->setAttributeSetName($setName);
        $attributeSet->setEntityTypeId((string) $this->getProductEntityTypeId());

        $created = $this->attributeSetManagement->create(
            MagentoProduct::ENTITY,
            $attributeSet,
            $this->defaultAttributeSetProvider->getDefaultAttributeSetId()
        );

        return (int) $created->getAttributeSetId();
    }

    private function resolveDefaultGroupId(int $attributeSetId): int
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('attribute_set_id', $attributeSetId)
            ->create();

        $groups = $this->attributeGroupRepository->getList($criteria)->getItems();
        $sorted = [];

        foreach ($groups as $group) {
            $sorted[(int) $group->getSortOrder()] = (int) $group->getAttributeGroupId();
        }

        if ($sorted === []) {
            throw new \RuntimeException(sprintf('Attribute set %d has no attribute groups - cannot assign attributes.', $attributeSetId));
        }

        ksort($sorted);

        return array_values($sorted)[self::SKELETON_GROUP_CODE_FALLBACK_SORT] ?? array_values($sorted)[0];
    }

    private function getProductEntityTypeId(): int
    {
        return (int) $this->eavConfig->getEntityType(MagentoProduct::ENTITY)->getId();
    }

    private function recordHistory(
        ImportContext $context,
        int $sourceId,
        ?int $targetId,
        string $status,
        ?string $message,
        float $startTime,
        int $startMemory
    ): void {
        if ($context->isDryRun()) {
            return;
        }

        $this->syncHistoryRepository->record(
            $context->getRunId(),
            SyncHistory::ENTITY_TYPE_ATTRIBUTE_SET,
            SyncHistory::OPERATION_IMPORT,
            $sourceId,
            $targetId,
            $status,
            $message,
            (int) round((microtime(true) - $startTime) * 1000),
            max(0, memory_get_usage(true) - $startMemory)
        );
    }
}
