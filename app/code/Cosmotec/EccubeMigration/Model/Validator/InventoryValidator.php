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
        $stockQuantity = $source->getStockQuantity();

        if ($stockQuantity !== null && $stockQuantity < 0) {
            $errors[] = sprintf(
                'Product id=%d has a negative stock_quantity (%d)',
                $source->getProductId(),
                $stockQuantity
            );
        }

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
