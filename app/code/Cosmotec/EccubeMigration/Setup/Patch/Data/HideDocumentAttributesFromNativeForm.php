<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Setup\Patch\Data;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * QA FIX: CreateDocumentAttributes assigned eccube_dimension_image /
 * eccube_cad2d_file / eccube_cad3d_file / cad_unavailable to an EAV
 * attribute group named "EC-CUBE Documents" (DOCUMENTS_GROUP_NAME) on
 * every attribute set, with is_visible=true. Magento's own EAV form
 * modifier auto-renders every visible attribute group as its own
 * product-edit section - so a SECOND "EC-CUBE Documents" section
 * appeared natively, showing these four attributes as plain generic
 * inputs, alongside the real custom section (Block\Adminhtml\Product\
 * Documents / documents.phtml) that already handles them with proper
 * upload/checkbox UI and file storage under pub/media/cad/.
 *
 * Setting is_visible=false stops the native EAV form modifier from
 * rendering these attributes at all, leaving exactly one "EC-CUBE
 * Documents" section (the custom one). This does not affect the
 * attributes' own data: they remain real, saveable EAV attributes -
 * $product->getData()/setCustomAttribute() are unaffected by
 * is_visible, which only controls Magento's own generic form rendering.
 */
class HideDocumentAttributesFromNativeForm implements DataPatchInterface
{
    private const ATTRIBUTE_CODES = [
        CreateDocumentAttributes::ATTRIBUTE_CODE_DIMENSION_IMAGE,
        CreateDocumentAttributes::ATTRIBUTE_CODE_CAD2D_FILE,
        CreateDocumentAttributes::ATTRIBUTE_CODE_CAD3D_FILE,
        CreateDocumentAttributes::ATTRIBUTE_CODE_CAD_UNAVAILABLE,
    ];

    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributeRepository
    ) {
    }

    public static function getDependencies(): array
    {
        return [CreateDocumentAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): void
    {
        foreach (self::ATTRIBUTE_CODES as $code) {
            try {
                $attribute = $this->attributeRepository->get($code);
            } catch (\Throwable) {
                continue;
            }

            $attribute->setIsVisible(false);
            $this->attributeRepository->save($attribute);
        }
    }
}
