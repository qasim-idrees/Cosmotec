<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Setup\Patch\Data;

use Cosmotec\EccubeMigration\Model\Import\AttributeSetImporter;
use Magento\Catalog\Api\Data\ProductAttributeInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Eav\Api\AttributeGroupRepositoryInterface;
use Magento\Eav\Api\AttributeManagementInterface;
use Magento\Eav\Api\Data\AttributeGroupInterfaceFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as AttributeSetCollectionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates two plain-text product attributes for dtb_product fields that
 * have no Magento destination but carry real, source-confirmed data:
 * `model` (100% populated, 27,590/27,590) and `maker_part_number`
 * (2.7% populated, 745/27,590) - see ProductImporter::persist() for
 * where they are written.
 *
 * Deliberately NOT prefixed `ecs_` (that prefix means "EC-CUBE
 * Specification" - see SpecificationAttributeCodeResolver::PREFIX - and
 * ProductAttributeValueImporter/ItemAttributeValueImporter's codesToClear
 * logic wipes any ecs_*-prefixed attribute on a product's set that isn't
 * part of the resolved specification set on every sync. These two fields
 * are not specifications and must never be touched by that logic).
 *
 * Assigned to every attribute set that exists at the time this patch
 * runs (all 9 real EC-CUBE-derived sets are already created by this point
 * in the project), into the same "EC-CUBE Specification" group
 * (AttributeSetImporter::SPECIFICATION_GROUP_NAME) rather than a new
 * group, to avoid a third admin section for two fields. This avoids the
 * same silent-EAV-drop failure mode already fixed elsewhere in this
 * module for attributes outside a product's current attribute set - a
 * set created after this patch runs would not automatically receive
 * these two attributes (same limitation the existing cad_unavailable_check
 * field already has), which is an acceptable, minimal-scope tradeoff.
 */
class CreateProductInfoAttributes implements DataPatchInterface
{
    public const ATTRIBUTE_CODE_MODEL = 'eccube_product_model';
    public const ATTRIBUTE_CODE_MAKER_PART_NUMBER = 'eccube_product_maker_part_number';

    public function __construct(
        private readonly ProductAttributeInterfaceFactory $attributeFactory,
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly EavConfig $eavConfig,
        private readonly AttributeManagementInterface $attributeManagement,
        private readonly AttributeGroupRepositoryInterface $attributeGroupRepository,
        private readonly AttributeGroupInterfaceFactory $attributeGroupFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly AttributeSetCollectionFactory $attributeSetCollectionFactory
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): void
    {
        $entityTypeId = (int) $this->eavConfig->getEntityType(MagentoProduct::ENTITY)->getId();

        $this->createOrGetAttribute(self::ATTRIBUTE_CODE_MODEL, 'Model', $entityTypeId);
        $this->createOrGetAttribute(self::ATTRIBUTE_CODE_MAKER_PART_NUMBER, 'Manufacturer Part Number', $entityTypeId);

        $attributeSetCollection = $this->attributeSetCollectionFactory->create();
        $attributeSetCollection->setEntityTypeFilter($entityTypeId);

        foreach ($attributeSetCollection->getItems() as $attributeSet) {
            $attributeSetId = (int) $attributeSet->getId();
            $groupId = $this->resolveSpecificationGroupId($attributeSetId);

            // assign() is idempotent - re-assigning an attribute already
            // in the set is a harmless no-op, same as AttributeSetImporter.
            $this->attributeManagement->assign(MagentoProduct::ENTITY, (string) $attributeSetId, (string) $groupId, self::ATTRIBUTE_CODE_MODEL, 500);
            $this->attributeManagement->assign(MagentoProduct::ENTITY, (string) $attributeSetId, (string) $groupId, self::ATTRIBUTE_CODE_MAKER_PART_NUMBER, 501);
        }
    }

    private function createOrGetAttribute(string $code, string $label, int $entityTypeId): void
    {
        try {
            $this->attributeRepository->get($code);

            return;
        } catch (NoSuchEntityException) {
            // Does not exist yet - create it below.
        }

        $attribute = $this->attributeFactory->create();
        $attribute->setAttributeCode($code);
        $attribute->setEntityTypeId($entityTypeId);
        $attribute->setFrontendInput('text');
        $attribute->setBackendType('varchar');
        $attribute->setIsUserDefined(true);
        $attribute->setIsGlobal(1);
        $attribute->setDefaultFrontendLabel($label);
        $attribute->setIsRequired(false);
        $attribute->setIsVisible(true);
        $attribute->setIsVisibleOnFront(true);
        $attribute->setUsedInProductListing(true);
        $attribute->setIsFilterable(false);
        $attribute->setIsFilterableInSearch(false);
        $attribute->setIsSearchable(true);

        $this->attributeRepository->save($attribute);
    }

    /**
     * Same lookup-or-create logic as
     * AttributeSetImporter::resolveSpecificationGroupId() (private there,
     * and that class carries EC-CUBE-repository dependencies unsuited to
     * a setup-time Data Patch, so this is a small, self-contained
     * re-implementation rather than a shared dependency) - finds the
     * set's own "EC-CUBE Specification" group by name, creating it once
     * if this is the set's first assignment. Idempotent.
     */
    private function resolveSpecificationGroupId(int $attributeSetId): int
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('attribute_set_id', $attributeSetId)
            ->create();

        foreach ($this->attributeGroupRepository->getList($criteria)->getItems() as $group) {
            if ($group->getAttributeGroupName() === AttributeSetImporter::SPECIFICATION_GROUP_NAME) {
                return (int) $group->getAttributeGroupId();
            }
        }

        $group = $this->attributeGroupFactory->create();
        $group->setAttributeGroupName(AttributeSetImporter::SPECIFICATION_GROUP_NAME);
        $group->setAttributeSetId($attributeSetId);
        $saved = $this->attributeGroupRepository->save($group);

        return (int) $saved->getAttributeGroupId();
    }
}
