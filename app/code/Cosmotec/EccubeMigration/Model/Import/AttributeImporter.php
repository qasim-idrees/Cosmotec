<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Import;

use Cosmotec\EccubeMigration\Api\Data\SpecificationInterface;
use Cosmotec\EccubeMigration\Api\SpecificationMapRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SpecificationRepositoryInterface;
use Cosmotec\EccubeMigration\Api\SyncHistoryRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\SpecificationMap;
use Cosmotec\EccubeMigration\Model\SpecificationMapFactory;
use Cosmotec\EccubeMigration\Model\Specification\MultiValueSpecificationRegistry;
use Cosmotec\EccubeMigration\Model\SpecificationOptionMap;
use Cosmotec\EccubeMigration\Model\SpecificationOptionMapFactory;
use Cosmotec\EccubeMigration\Model\SyncHistory;
use Magento\Catalog\Api\Data\ProductAttributeInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Phase 4 — creates Magento product EAV attributes and their options from
 * EC-CUBE specifications.
 *
 * Source-confirmed rules applied here (see docs/):
 *  - Only specifications classified CREATE become attributes. Triggers
 *    (SetSpecificationRule keys), unused and invalid specifications are
 *    recorded with an explicit reason and skipped — never silently
 *    dropped (§38).
 *  - Attribute code is deterministic: eccube_spec_{id}, independent of
 *    labels, so relabelling never orphans an attribute (§27).
 *  - Labels are English-first; Japanese is retained in the mapping table
 *    for reference but never invented or translated (§4).
 *  - Option order follows dtb_specification_class.sort_no, never
 *    alphabetical (§30).
 *  - is_filterable uses the UNION rule (filterable if ANY item marks the
 *    specification selectable) while the raw per-item selectable count is
 *    preserved in the mapping table for future exact-fidelity filtering
 *    (§29).
 *  - Idempotent: an existing Magento attribute with the same code is
 *    adopted into the mapping rather than duplicated (§27).
 *
 * Scope note: attributes are created here as global product attributes.
 * Assigning them to the correct attribute set(s) — including the 80
 * specifications used at BOTH Item and Product scope — is Phase 5, not
 * this class's responsibility.
 */
class AttributeImporter implements ImporterInterface
{
    public function __construct(
        private readonly SpecificationRepositoryInterface $specificationRepository,
        private readonly SpecificationMapRepositoryInterface $mapRepository,
        private readonly SpecificationMapFactory $mapFactory,
        private readonly SpecificationOptionMapFactory $optionMapFactory,
        private readonly SyncHistoryRepositoryInterface $syncHistoryRepository,
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly ProductAttributeInterfaceFactory $attributeFactory,
        private readonly AttributeOptionInterfaceFactory $optionFactory,
        private readonly AttributeOptionLabelInterfaceFactory $optionLabelFactory,
        private readonly EavConfig $eavConfig,
        private readonly MultiValueSpecificationRegistry $multiValueRegistry,
        private readonly ImportLogger $logger
    ) {
    }

    public function import(ImportContext $context): ImportResult
    {
        return $this->importFiltered($context, null);
    }

    /**
     * @param int[]|null $specificationIds restrict to exactly these
     *                                     dtb_specification ids - for a
     *                                     small controlled test before a
     *                                     full run. Null processes every
     *                                     specification (the normal path).
     */
    public function importFiltered(ImportContext $context, ?array $specificationIds): ImportResult
    {
        $result = new ImportResult();

        foreach ($this->specificationRepository->getAllWithUsage() as $specification) {
            if ($specificationIds !== null && !in_array($specification->getId(), $specificationIds, true)) {
                continue;
            }

            $this->importOne($specification, $context, $result);
        }

        $this->logger->info(sprintf(
            'AttributeImporter run %s complete: imported=%d updated=%d skipped=%d errors=%d',
            $context->getRunId(),
            $result->getImported(),
            $result->getUpdated(),
            $result->getSkipped(),
            $result->getErrors()
        ));

        return $result;
    }

    private function importOne(SpecificationInterface $specification, ImportContext $context, ImportResult $result): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            if ($specification->getClassification() !== SpecificationInterface::CLASSIFICATION_CREATE) {
                $this->recordSkip($specification, $context, $result, $startTime, $startMemory);

                return;
            }

            // Checked once here rather than only inside persist(), so a dry
            // run can accurately predict CREATE vs UPDATE the same way the
            // real execute path does - previously this check only happened
            // after the dry-run branch had already returned, so every dry
            // run reported "Created" even for specifications already
            // imported in an earlier run.
            $attributeExists = $this->findExistingAttribute($specification->getMagentoAttributeCode()) !== null;

            if ($context->isDryRun()) {
                if ($attributeExists) {
                    $result->incrementUpdated();
                    $this->logger->info(sprintf(
                        '[DRY RUN] Attribute %s ("%s") already exists - would verify/update, %d option(s), filterable=%s',
                        $specification->getMagentoAttributeCode(),
                        $specification->getLabel(),
                        $specification->getOptionCount(),
                        $specification->getSelectableCount() > 0 ? 'yes' : 'no'
                    ));
                } else {
                    $result->incrementImported();
                    $this->logger->info(sprintf(
                        '[DRY RUN] Would create attribute %s ("%s") with %d option(s), filterable=%s',
                        $specification->getMagentoAttributeCode(),
                        $specification->getLabel(),
                        $specification->getOptionCount(),
                        $specification->getSelectableCount() > 0 ? 'yes' : 'no'
                    ));
                }

                return;
            }

            $this->persist($specification, $context, $result, $startTime, $startMemory);
        } catch (LocalizedException | \Throwable $e) {
            $this->handleError($specification, $context, $result, $e->getMessage(), $startTime, $startMemory);
        }
    }

    private function persist(
        SpecificationInterface $specification,
        ImportContext $context,
        ImportResult $result,
        float $startTime,
        int $startMemory
    ): void {
        $code = $specification->getMagentoAttributeCode();
        $existingMap = $this->mapRepository->getBySpecificationId($specification->getId());
        $attribute = $this->findExistingAttribute($code);
        $isUpdate = $attribute !== null;

        // PENDING BUSINESS DECISION (see MultiValueSpecificationRegistry and
        // BUILD_STATUS.md): 5 specifications (ICF, NW/KF, VF, VG, D) have
        // real source-confirmed multi-value assignments (329 pairs, 0
        // identical - adapters/reducers, not duplicate data). Current
        // standing design is multiselect for these 5, plain select for
        // every other specification. Reversing this decision means
        // changing only MultiValueSpecificationRegistry, not this class.
        $isMultiValue = $this->multiValueRegistry->isMultiValue($specification->getId());

        if ($attribute === null) {
            $attribute = $this->attributeFactory->create();
            $attribute->setAttributeCode($code);
            $attribute->setEntityTypeId($this->getProductEntityTypeId());
            $attribute->setFrontendInput($isMultiValue ? 'multiselect' : 'select');
            $attribute->setBackendType($isMultiValue ? 'varchar' : 'int');
            $attribute->setIsUserDefined(true);
            $attribute->setIsGlobal(1);
        }

        // Label is English-first and may legitimately change between runs;
        // the attribute code never does.
        $attribute->setDefaultFrontendLabel($specification->getLabel());
        $attribute->setIsRequired(false);
        $attribute->setIsVisible(true);
        $attribute->setIsVisibleOnFront(true);
        $attribute->setUsedInProductListing(true);
        $attribute->setIsFilterable($specification->getSelectableCount() > 0 ? 1 : 0);
        $attribute->setIsFilterableInSearch($specification->getSelectableCount() > 0);
        $attribute->setIsSearchable(false);
        $attribute->setPosition($specification->getSortNo());

        $saved = $this->attributeRepository->save($attribute);

        /** @var SpecificationMap $map */
        $map = $existingMap ?? $this->mapFactory->create();
        $map->setEccubeSpecificationId($specification->getId());
        $map->setAttributeCode($code);
        $map->setMagentoAttributeId((int) $saved->getAttributeId());
        $map->setLabelEn($specification->getNameEn());
        $map->setLabelJa($specification->getName());
        $map->setUsedAtItemScope($specification->isUsedAtItemScope() ? 1 : 0);
        $map->setUsedAtProductScope($specification->isUsedAtProductScope() ? 1 : 0);
        $map->setSelectableCount($specification->getSelectableCount());
        $map->setIsFilterable($specification->getSelectableCount() > 0 ? 1 : 0);
        $map->setOptionCount($specification->getOptionCount());
        $map->setSortNo($specification->getSortNo());
        $map->setClassification($specification->getClassification());
        $map->setClassificationReason($specification->getClassificationReason());
        $map->setStatus($isUpdate ? SpecificationMap::STATUS_UPDATED : SpecificationMap::STATUS_IMPORTED);
        $map->setErrorMessage(null);
        $map->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->mapRepository->save($map);

        $optionsCreated = $this->importOptions($specification, $code);

        if ($isUpdate) {
            $result->incrementUpdated();
        } else {
            $result->incrementImported();
        }

        $this->logger->info(sprintf(
            'Specification %d "%s" %s as attribute %s (id=%d), %d new option(s)',
            $specification->getId(),
            $specification->getLabel(),
            $isUpdate ? 'updated' : 'created',
            $code,
            (int) $saved->getAttributeId(),
            $optionsCreated
        ));

        $this->recordHistory(
            $context,
            $specification->getId(),
            (int) $saved->getAttributeId(),
            $isUpdate ? SyncHistory::STATUS_UPDATED : SyncHistory::STATUS_IMPORTED,
            null,
            $startTime,
            $startMemory
        );
    }

    /**
     * Creates any option that has no mapping yet. Existing options are
     * left untouched, which keeps repeated runs idempotent and avoids
     * disturbing option ids already referenced by product values.
     */
    private function importOptions(SpecificationInterface $specification, string $attributeCode): int
    {
        $options = $this->specificationRepository->getOptionsBySpecificationId($specification->getId());
        $created = 0;

        foreach ($options as $option) {
            if ($this->mapRepository->getOptionByClassId($option->getId()) !== null) {
                continue;
            }

            $optionLabel = $this->optionLabelFactory->create();
            $optionLabel->setStoreId(0);
            $optionLabel->setLabel($option->getLabel());

            $magentoOption = $this->optionFactory->create();
            $magentoOption->setLabel($option->getLabel());
            $magentoOption->setStoreLabels([$optionLabel]);
            // Source ordering is authoritative (see §30) - never alphabetical.
            $magentoOption->setSortOrder($option->getSortNo());
            $magentoOption->setIsDefault(false);

            $this->addOption($attributeCode, $magentoOption);

            /** @var SpecificationOptionMap $optionMap */
            $optionMap = $this->optionMapFactory->create();
            $optionMap->setEccubeSpecificationClassId($option->getId());
            $optionMap->setEccubeSpecificationId($specification->getId());
            $optionMap->setAttributeCode($attributeCode);
            $optionMap->setMagentoOptionId($this->resolveOptionId($attributeCode, $option->getLabel()));
            $optionMap->setLabelEn($option->getNameEn());
            $optionMap->setLabelJa($option->getName());
            $optionMap->setSortNo($option->getSortNo());
            $optionMap->setStatus(SpecificationOptionMap::STATUS_IMPORTED);
            $optionMap->setErrorMessage(null);
            $optionMap->setLastSyncedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
            $this->mapRepository->saveOption($optionMap);

            $created++;
        }

        return $created;
    }

    private function addOption(string $attributeCode, \Magento\Eav\Api\Data\AttributeOptionInterface $option): void
    {
        $attribute = $this->eavConfig->getAttribute(MagentoProduct::ENTITY, $attributeCode);
        $options = $attribute->getOptions() ?? [];
        $existingLabels = array_map(static fn ($o): string => (string) $o->getLabel(), $options);

        if (in_array((string) $option->getLabel(), $existingLabels, true)) {
            return;
        }

        $attribute->setOption([
            'value' => ['option_0' => [(string) $option->getLabel()]],
            'order' => ['option_0' => $option->getSortOrder()],
        ]);
        $this->attributeRepository->save($attribute);
        $this->eavConfig->clear();
    }

    private function resolveOptionId(string $attributeCode, string $label): ?int
    {
        $this->eavConfig->clear();
        $attribute = $this->eavConfig->getAttribute(MagentoProduct::ENTITY, $attributeCode);

        foreach ($attribute->getOptions() ?? [] as $option) {
            if ((string) $option->getLabel() === $label && $option->getValue() !== '') {
                return (int) $option->getValue();
            }
        }

        return null;
    }

    private function findExistingAttribute(string $code): ?\Magento\Catalog\Api\Data\ProductAttributeInterface
    {
        try {
            return $this->attributeRepository->get($code);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    private function getProductEntityTypeId(): int
    {
        return (int) $this->eavConfig->getEntityType(MagentoProduct::ENTITY)->getId();
    }

    /**
     * Skips are always recorded with their reason so that no source row
     * disappears without an explanation (§38).
     */
    private function recordSkip(
        SpecificationInterface $specification,
        ImportContext $context,
        ImportResult $result,
        float $startTime,
        int $startMemory
    ): void {
        $result->incrementSkipped();

        if (!$context->isDryRun()) {
            $existingMap = $this->mapRepository->getBySpecificationId($specification->getId());
            /** @var SpecificationMap $map */
            $map = $existingMap ?? $this->mapFactory->create();
            $map->setEccubeSpecificationId($specification->getId());
            $map->setAttributeCode($specification->getMagentoAttributeCode());
            $map->setLabelEn($specification->getNameEn());
            $map->setLabelJa($specification->getName());
            $map->setUsedAtItemScope($specification->isUsedAtItemScope() ? 1 : 0);
            $map->setUsedAtProductScope($specification->isUsedAtProductScope() ? 1 : 0);
            $map->setSelectableCount($specification->getSelectableCount());
            $map->setOptionCount($specification->getOptionCount());
            $map->setSortNo($specification->getSortNo());
            $map->setClassification($specification->getClassification());
            $map->setClassificationReason($specification->getClassificationReason());
            $map->setStatus(SpecificationMap::STATUS_SKIPPED);
            $this->mapRepository->save($map);
        }

        $this->recordHistory(
            $context,
            $specification->getId(),
            null,
            SyncHistory::STATUS_SKIPPED,
            $specification->getClassification() . ': ' . $specification->getClassificationReason(),
            $startTime,
            $startMemory
        );
    }

    private function handleError(
        SpecificationInterface $specification,
        ImportContext $context,
        ImportResult $result,
        string $message,
        float $startTime,
        int $startMemory
    ): void {
        $result->incrementErrors();
        $this->logger->error(sprintf('Specification %d failed: %s', $specification->getId(), $message));

        if (!$context->isDryRun()) {
            $existingMap = $this->mapRepository->getBySpecificationId($specification->getId());
            /** @var SpecificationMap $map */
            $map = $existingMap ?? $this->mapFactory->create();
            $map->setEccubeSpecificationId($specification->getId());
            $map->setAttributeCode($specification->getMagentoAttributeCode());
            $map->setClassification($specification->getClassification());
            $map->setStatus(SpecificationMap::STATUS_ERROR);
            $map->setErrorMessage($message);
            $this->mapRepository->save($map);
        }

        $this->recordHistory($context, $specification->getId(), null, SyncHistory::STATUS_ERROR, $message, $startTime, $startMemory);
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
            SyncHistory::ENTITY_TYPE_ATTRIBUTE,
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
