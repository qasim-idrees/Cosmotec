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

        return new MagentoSimpleProduct(
            $source->getId(),
            $source->getItemId(),
            $sku,
            $this->resolveName($source),
            $source->getProductStatusId() === 1,
            $source->getItemId() !== null ? Visibility::VISIBILITY_NOT_VISIBLE : Visibility::VISIBILITY_BOTH,
            $this->attributeSetProvider->getDefaultAttributeSetId(),
            $source->getPrice(),
            $stockQuantity,
            $stockQuantity > 0,
            $source->isCadUnavailable()
        );
    }

    private function resolveName(ProductInterface $source): string
    {
        $name = trim($source->getNameEn());

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

        if ($existing !== null && $existing->getEccubeProductId() !== $source->getId()) {
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
