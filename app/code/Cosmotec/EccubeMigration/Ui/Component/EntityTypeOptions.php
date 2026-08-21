<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Ui\Component;

use Magento\Framework\Data\OptionSourceInterface;

class EntityTypeOptions implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'category', 'label' => __('Category')],
            ['value' => 'item', 'label' => __('Item (Grouped Product)')],
            ['value' => 'product', 'label' => __('Product (Simple Product)')],
            ['value' => 'product_class', 'label' => __('Product Class')],
            ['value' => 'image', 'label' => __('Image')],
            ['value' => 'inventory', 'label' => __('Inventory')],
        ];
    }
}
