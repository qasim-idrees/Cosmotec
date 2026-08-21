<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Validator;

use Cosmotec\EccubeMigration\Api\Data\ProductInterface;
use Cosmotec\EccubeMigration\Api\ItemRepositoryInterface;

class ProductValidator implements ValidatorInterface
{
    public function __construct(
        private readonly ItemRepositoryInterface $eccubeItemRepository
    ) {
    }

    public function validate(object $source): ValidationResult
    {
        if (!$source instanceof ProductInterface) {
            return ValidationResult::failure([
                sprintf('Expected %s, got %s', ProductInterface::class, get_debug_type($source)),
            ]);
        }

        $errors = [];

        if (trim($source->getNameEn()) === '') {
            $errors[] = sprintf('Product id=%d has an empty name_en', $source->getId());
        }

        // product_code is the closest thing to a SKU on dtb_product; it can
        // legitimately be empty in EC-CUBE (some installs SKU only at the
        // product_class level), so an empty one is not itself an error —
        // ProductMapper falls back to a synthesized SKU. It IS an error if
        // it's present but only whitespace/control characters, since that
        // usually indicates bad source data rather than "intentionally unset".
        $productCode = $source->getProductCode();

        if ($productCode !== null && trim($productCode) === '' && $productCode !== '') {
            $errors[] = sprintf('Product id=%d has a whitespace-only product_code', $source->getId());
        }

        $price = $source->getPrice();

        if ($price !== null && !is_numeric($price)) {
            $errors[] = sprintf('Product id=%d has a non-numeric price value "%s"', $source->getId(), $price);
        } elseif ($price !== null && (float) $price < 0) {
            $errors[] = sprintf('Product id=%d has a negative price (%s)', $source->getId(), $price);
        }

        $stockQuantity = $source->getStockQuantity();

        if ($stockQuantity !== null && $stockQuantity < 0) {
            $errors[] = sprintf('Product id=%d has a negative stock_quantity (%d)', $source->getId(), $stockQuantity);
        }

        $itemId = $source->getItemId();

        if ($itemId !== null && $this->eccubeItemRepository->getById($itemId) === null) {
            $errors[] = sprintf(
                'Product id=%d references item_id=%d which does not exist in dtb_item',
                $source->getId(),
                $itemId
            );
        }

        return $errors === [] ? ValidationResult::success() : ValidationResult::failure($errors);
    }
}
