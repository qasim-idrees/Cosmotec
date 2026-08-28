<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Setup\Patch\Data;

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
 * Creates the Simple Product attributes backing the new "EC-CUBE
 * Documents" admin section (Task 5): a dedicated Dimension Image field
 * (deliberately NOT another product-gallery entry - see
 * MediaImporter/MediaRelationType, whose DIMENSION relation currently adds
 * to the gallery, disabled, purely so it never becomes a storefront
 * primary image; this attribute is the real fix, the dedicated field the
 * task requires), CAD 2D / CAD 3D file uploads (stored under
 * pub/media/cad/, replacing MediaImporter's previous
 * pub/media/cosmotec/eccube/cad2d|cad3d/ path for these two relation
 * types), and a real `cad_unavailable` checkbox.
 *
 * `cad_unavailable` is included here because it turned out to never
 * actually exist as a Magento attribute - ProductImporter::persist() has
 * called setCustomAttribute('cad_unavailable', ...) since Round 41, but
 * that call silently no-ops when the attribute is missing (by design, so
 * one missing attribute never fails a product import), and no patch ever
 * created it. Live-confirmed via eav_attribute: 0 rows. This patch is the
 * first time it is actually created.
 *
 * All four attributes are plain varchar/int EAV attributes with no native
 * UI-component form binding - the admin section that reads/writes them is
 * a fully custom block + AJAX controller (Block/Adminhtml/Product/
 * Documents.php), the same pattern already used for Additional Content
 * and (previously) Connection Parts, because none of Magento's stock
 * frontend_input types accept arbitrary file uploads (media_image is
 * image-only) or resolve to a non-catalog/product media path.
 */
class CreateDocumentAttributes implements DataPatchInterface
{
    public const ATTRIBUTE_CODE_DIMENSION_IMAGE = 'eccube_dimension_image';
    public const ATTRIBUTE_CODE_CAD2D_FILE = 'eccube_cad2d_file';
    public const ATTRIBUTE_CODE_CAD3D_FILE = 'eccube_cad3d_file';
    public const ATTRIBUTE_CODE_CAD_UNAVAILABLE = 'cad_unavailable';
    public const DOCUMENTS_GROUP_NAME = 'EC-CUBE Documents';

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
        return [CreateProductInfoAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): void
    {
        $entityTypeId = (int) $this->eavConfig->getEntityType(MagentoProduct::ENTITY)->getId();

        $this->createOrGetTextAttribute(self::ATTRIBUTE_CODE_DIMENSION_IMAGE, 'Dimension Image', $entityTypeId);
        $this->createOrGetTextAttribute(self::ATTRIBUTE_CODE_CAD2D_FILE, 'CAD 2D File', $entityTypeId);
        $this->createOrGetTextAttribute(self::ATTRIBUTE_CODE_CAD3D_FILE, 'CAD 3D File', $entityTypeId);
        $this->createOrGetBooleanAttribute(self::ATTRIBUTE_CODE_CAD_UNAVAILABLE, 'CAD is not available', $entityTypeId);

        $attributeSetCollection = $this->attributeSetCollectionFactory->create();
        $attributeSetCollection->setEntityTypeFilter($entityTypeId);

        foreach ($attributeSetCollection->getItems() as $attributeSet) {
            $attributeSetId = (int) $attributeSet->getId();
            $groupId = $this->resolveDocumentsGroupId($attributeSetId);

            $this->attributeManagement->assign(MagentoProduct::ENTITY, (string) $attributeSetId, (string) $groupId, self::ATTRIBUTE_CODE_DIMENSION_IMAGE, 600);
            $this->attributeManagement->assign(MagentoProduct::ENTITY, (string) $attributeSetId, (string) $groupId, self::ATTRIBUTE_CODE_CAD2D_FILE, 601);
            $this->attributeManagement->assign(MagentoProduct::ENTITY, (string) $attributeSetId, (string) $groupId, self::ATTRIBUTE_CODE_CAD3D_FILE, 602);
            $this->attributeManagement->assign(MagentoProduct::ENTITY, (string) $attributeSetId, (string) $groupId, self::ATTRIBUTE_CODE_CAD_UNAVAILABLE, 603);
        }
    }

    private function createOrGetTextAttribute(string $code, string $label, int $entityTypeId): void
    {
        if ($this->attributeExists($code)) {
            return;
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
        $attribute->setIsVisibleOnFront(false);
        $attribute->setUsedInProductListing(false);
        $attribute->setIsFilterable(false);
        $attribute->setIsFilterableInSearch(false);
        $attribute->setIsSearchable(false);

        $this->attributeRepository->save($attribute);
    }

    private function createOrGetBooleanAttribute(string $code, string $label, int $entityTypeId): void
    {
        if ($this->attributeExists($code)) {
            return;
        }

        $attribute = $this->attributeFactory->create();
        $attribute->setAttributeCode($code);
        $attribute->setEntityTypeId($entityTypeId);
        $attribute->setFrontendInput('boolean');
        $attribute->setBackendType('int');
        $attribute->setSourceModel(\Magento\Eav\Model\Entity\Attribute\Source\Boolean::class);
        $attribute->setIsUserDefined(true);
        $attribute->setIsGlobal(1);
        $attribute->setDefaultFrontendLabel($label);
        $attribute->setIsRequired(false);
        $attribute->setIsVisible(true);
        $attribute->setIsVisibleOnFront(false);
        $attribute->setUsedInProductListing(false);
        $attribute->setIsFilterable(false);
        $attribute->setIsFilterableInSearch(false);
        $attribute->setIsSearchable(false);

        $this->attributeRepository->save($attribute);
    }

    private function attributeExists(string $code): bool
    {
        try {
            $this->attributeRepository->get($code);

            return true;
        } catch (NoSuchEntityException) {
            return false;
        }
    }

    /**
     * Same lookup-or-create pattern as CreateProductInfoAttributes::
     * resolveSpecificationGroupId(), targeting a new, separate group so
     * Documents fields never mix into the "EC-CUBE Specification" group.
     */
    private function resolveDocumentsGroupId(int $attributeSetId): int
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('attribute_set_id', $attributeSetId)
            ->create();

        foreach ($this->attributeGroupRepository->getList($criteria)->getItems() as $group) {
            if ($group->getAttributeGroupName() === self::DOCUMENTS_GROUP_NAME) {
                return (int) $group->getAttributeGroupId();
            }
        }

        $group = $this->attributeGroupFactory->create();
        $group->setAttributeGroupName(self::DOCUMENTS_GROUP_NAME);
        $group->setAttributeSetId($attributeSetId);
        $saved = $this->attributeGroupRepository->save($group);

        return (int) $saved->getAttributeGroupId();
    }
}
