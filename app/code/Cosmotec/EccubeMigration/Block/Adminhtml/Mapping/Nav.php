<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Block\Adminhtml\Mapping;

use Magento\Backend\Block\Template;

class Nav extends Template
{
    /**
     * @return array<int, array{label: string, path: string, active: bool}>
     */
    public function getLinks(): array
    {
        $current = (string) $this->getData('current');

        $entries = [
            'categories' => ['label' => __('Categories'), 'path' => 'cosmotec_eccube/mapping/index'],
            'items' => ['label' => __('Items (Grouped Products)'), 'path' => 'cosmotec_eccube/mapping/items'],
            'products' => ['label' => __('Products (Simple Products)'), 'path' => 'cosmotec_eccube/mapping/products'],
            'images' => ['label' => __('Images'), 'path' => 'cosmotec_eccube/mapping/images'],
        ];

        $links = [];

        foreach ($entries as $key => $entry) {
            $links[] = [
                'label' => $entry['label'],
                'path' => $this->getUrl($entry['path']),
                'active' => $key === $current,
            ];
        }

        return $links;
    }
}
