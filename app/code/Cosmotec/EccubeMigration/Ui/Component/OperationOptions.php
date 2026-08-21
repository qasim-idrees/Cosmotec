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

class OperationOptions implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'import', 'label' => __('Import')],
            ['value' => 'sync', 'label' => __('Sync')],
        ];
    }
}
