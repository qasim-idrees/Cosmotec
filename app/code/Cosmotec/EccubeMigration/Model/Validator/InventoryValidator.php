<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Model\Validator;

use Cosmotec\EccubeMigration\Api\Data\InventoryRecordInterface;

class InventoryValidator implements ValidatorInterface
{
    public function validate(object $source): ValidationResult
    {
        if (!$source instanceof InventoryRecordInterface) {
            return ValidationResult::failure([
                sprintf('Expected %s, got %s', InventoryRecordInterface::class, get_debug_type($source)),
            ]);
        }

        $errors = [];

        // Negative stock_quantity is a legitimate EC-CUBE
        // oversold/backorder state, not bad data (same 109 products
        // confirmed live in Round 48's ProductValidator fix - Magento has
        // no concept of negative available stock). InventoryMapper::map()
        // already clamps this to max(0, ...) and marks out of stock -
        // rejecting it here made that clamp unreachable, the identical bug
        // class fixed in ProductValidator this round.

        foreach ($source->getProductClasses() as $productClass) {
            if ($productClass->getStock() !== null && !is_numeric($productClass->getStock())) {
                $errors[] = sprintf(
                    'Product id=%d has a non-numeric dtb_product_class.stock value "%s" (class id=%d)',
                    $source->getProductId(),
                    $productClass->getStock(),
                    $productClass->getId()
                );
            }
        }

        return $errors === [] ? ValidationResult::success() : ValidationResult::failure($errors);
    }
}
