<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Block\Adminhtml\Product;

use Cosmotec\EccubeMigration\Api\ProductReferenceProviderInterface;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;

/**
 * Renders the "EC-CUBE Documents" product-edit section (Task 5/6):
 * Dimension Image, CAD 2D/3D file uploads, the CAD-unavailable checkbox,
 * and the Document Name/Reference Link (1)/(2) convenience view over the
 * first two Product References. Same server-rendered-block + AJAX-save
 * pattern as Additional Content, deliberately not a native UI-component
 * form field since none of Magento's stock frontend_input types accept
 * arbitrary file uploads or a non-catalog/product media path.
 */
class Documents extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly ProductReferenceProviderInterface $referenceProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getProductId(): int
    {
        $product = $this->registry->registry('current_product');

        return $product !== null ? (int) $product->getId() : 0;
    }

    public function getDimensionImagePath(): ?string
    {
        return $this->getAttributeValue('eccube_dimension_image');
    }

    public function getDimensionImageUrl(): ?string
    {
        return $this->getMediaUrl($this->getDimensionImagePath());
    }

    public function getCad2dFilePath(): ?string
    {
        return $this->getAttributeValue('eccube_cad2d_file');
    }

    public function getCad2dFileUrl(): ?string
    {
        return $this->getMediaUrl($this->getCad2dFilePath());
    }

    public function getCad3dFilePath(): ?string
    {
        return $this->getAttributeValue('eccube_cad3d_file');
    }

    public function getCad3dFileUrl(): ?string
    {
        return $this->getMediaUrl($this->getCad3dFilePath());
    }

    public function isCadUnavailable(): bool
    {
        return (bool) $this->getAttributeValue('cad_unavailable');
    }

    /**
     * The first two Product References (ordered), backing the Document
     * Name/Reference Link (1)/(2) convenience fields. The full 1:N list
     * is untouched and continues to exist in eccube_product_reference_map
     * regardless of how many rows a product has - see
     * ProductReferenceProviderInterface, which remains the canonical
     * read path everywhere else (extension attribute, StatusCommand,
     * etc).
     *
     * @return array{0: ?\Cosmotec\EccubeMigration\Model\ProductReferenceMap, 1: ?\Cosmotec\EccubeMigration\Model\ProductReferenceMap}
     */
    public function getFirstTwoReferences(): array
    {
        $productId = $this->getProductId();

        if ($productId === 0) {
            return [null, null];
        }

        $references = $this->referenceProvider->getByMagentoProductId($productId);

        return [$references[0] ?? null, $references[1] ?? null];
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('cosmotec_eccube/product_documents/save');
    }

    private function getAttributeValue(string $code): mixed
    {
        $product = $this->registry->registry('current_product');

        return $product?->getData($code);
    }

    private function getMediaUrl(?string $relativePath): ?string
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return null;
        }

        return $this->_urlBuilder->getBaseUrl(['_type' => UrlInterface::URL_TYPE_MEDIA]) . $relativePath;
    }
}
