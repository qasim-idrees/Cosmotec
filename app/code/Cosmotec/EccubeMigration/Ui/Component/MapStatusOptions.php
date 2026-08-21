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

class MapStatusOptions implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'pending', 'label' => __('Pending')],
            ['value' => 'imported', 'label' => __('Imported')],
            ['value' => 'updated', 'label' => __('Updated')],
            ['value' => 'skipped', 'label' => __('Skipped')],
            ['value' => 'error', 'label' => __('Error')],
        ];
    }
}
