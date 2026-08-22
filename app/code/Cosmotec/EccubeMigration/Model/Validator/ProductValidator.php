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

        // Per project language policy (CLAUDE.md "Language"): English
        // preferred, Japanese fallback when English is unavailable - so an
        // empty name_en is only a real error when the Japanese name is ALSO
        // empty (live-confirmed this never actually happens in this
        // dataset: 0 products lack both). ProductMapper::resolveName()
        // implements the actual fallback; this validator must not reject a
        // product that mapper can legitimately name.
        if (trim($source->getNameEn()) === '' && trim($source->getName()) === '') {
            $errors[] = sprintf('Product id=%d has no usable name in either language (name_en and name both empty)', $source->getId());
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

        // Negative stock_quantity (109 products, real prices/names -
        // confirmed via live investigation, not a placeholder/draft
        // pattern like the empty-price group) is a legitimate EC-CUBE
        // oversold/backorder state, not bad data - Magento has no concept
        // of negative available stock, so ProductMapper::map() already
        // clamps it to 0 and marks the product out of stock (was already
        // correct, just unreachable because this validator rejected the
        // product before the mapper ever ran). No fabrication: the
        // product's true current availability (none) is preserved exactly.

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
