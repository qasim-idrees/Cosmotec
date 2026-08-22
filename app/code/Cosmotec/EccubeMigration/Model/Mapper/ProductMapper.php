<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Mapper;

use Cosmotec\EccubeMigration\Api\Data\MagentoSimpleProductInterface;
use Cosmotec\EccubeMigration\Api\Data\ProductInterface;
use Cosmotec\EccubeMigration\Api\ProductMapRepositoryInterface;
use Cosmotec\EccubeMigration\Logger\ImportLogger;
use Cosmotec\EccubeMigration\Model\Config\DefaultAttributeSetProvider;
use Cosmotec\EccubeMigration\Model\DTO\MagentoSimpleProduct;
use Magento\Catalog\Model\Product\Visibility;

class ProductMapper implements MapperInterface
{
    private const SKU_PREFIX = 'ECCUBE-PRODUCT-';

    public function __construct(
        private readonly ProductMapRepositoryInterface $productMapRepository,
        private readonly DefaultAttributeSetProvider $attributeSetProvider,
        private readonly ImportLogger $logger
    ) {
    }

    /**
     * @param ProductInterface $source
     */
    public function map(object $source): MagentoSimpleProductInterface
    {
        if (!$source instanceof ProductInterface) {
            throw new \InvalidArgumentException(sprintf(
                'ProductMapper expects %s, got %s',
                ProductInterface::class,
                get_debug_type($source)
            ));
        }

        $sku = $this->resolveSku($source);
        $stockQuantity = max(0, $source->getStockQuantity() ?? 0);

        // A genuinely NULL source price (136 products, live-confirmed -
        // all already display_status_id=2/hidden at EC-CUBE source, some
        // literal "*****" placeholder draft records) fails Magento's own
        // required-attribute check on Price if left unset entirely
        // (ProductImporter only calls setPrice() when getPrice() !== null).
        // Substituting 0.00 is not fabricating a price - it honestly
        // represents "no price was ever set", matching the existing
        // negative-stock-quantity precedent of clamping to a safe default
        // rather than silently rejecting the product. priceNeedsReview
        // flags this so the product lands in Magento disabled (per its
        // existing product_status_id, which is NULL/not 1 for all 136) and
        // its map row is marked STATUS_NEEDS_REVIEW instead of the normal
        // imported/updated status, per the explicit business decision.
        $priceNeedsReview = $source->getPrice() === null;
        $price = $priceNeedsReview ? '0.00' : $source->getPrice();

        return new MagentoSimpleProduct(
            $source->getId(),
            $source->getItemId(),
            $sku,
            $this->resolveName($source),
            $source->getProductStatusId() === 1,
            $source->getItemId() !== null ? Visibility::VISIBILITY_NOT_VISIBLE : Visibility::VISIBILITY_BOTH,
            $this->attributeSetProvider->getDefaultAttributeSetId(),
            $price,
            $stockQuantity,
            $stockQuantity > 0,
            $source->isCadUnavailable(),
            $priceNeedsReview
        );
    }

    /**
     * English preferred; Japanese fallback when English is unavailable -
     * per project language policy (CLAUDE.md "Language"). The synthetic
     * "product-{id}" placeholder previously used here for an empty name_en
     * discarded real source data (0 products in this dataset lack BOTH
     * languages - a Japanese name_en is always available whenever name_en
     * is empty, live-confirmed). Never invents a translation - this is the
     * genuine EC-CUBE name, just in the other language.
     */
    private function resolveName(ProductInterface $source): string
    {
        $nameEn = trim($source->getNameEn());

        if ($nameEn !== '') {
            return $nameEn;
        }

        $name = trim($source->getName());

        return $name !== '' ? $name : sprintf('product-%d', $source->getId());
    }

    /**
     * Uses product_code when present; otherwise synthesizes one. Either way,
     * checks it isn't already claimed by a *different* EC-CUBE product
     * (product_code is not guaranteed unique in the source data) before
     * Magento's own unique-SKU constraint would reject the save.
     */
    private function resolveSku(ProductInterface $source): string
    {
        $candidate = $source->getProductCode() !== null && trim($source->getProductCode()) !== ''
            ? trim($source->getProductCode())
            : self::SKU_PREFIX . $source->getId();

        $existing = $this->productMapRepository->getBySku($candidate);

        // AbstractModel::getData() returns a raw DB string for
        // getEccubeProductId(), not an int (despite the getter's phpdoc),
        // so without this cast the strict !== always evaluated true - even
        // when $existing is this exact product's own prior map row - and
        // every re-run of an already-imported product would misreport a
        // SKU "collision" with itself. Same recurring bug class as Round
        // 37/42/46.
        if ($existing !== null && (int) $existing->getEccubeProductId() !== $source->getId()) {
            $disambiguated = $candidate . '-' . $source->getId();
            $this->logger->info(sprintf(
                'Product id=%d: SKU "%s" is already used by EC-CUBE product id=%d, using "%s" instead.',
                $source->getId(),
                $candidate,
                $existing->getEccubeProductId(),
                $disambiguated
            ));

            return $disambiguated;
        }

        return $candidate;
    }
}
