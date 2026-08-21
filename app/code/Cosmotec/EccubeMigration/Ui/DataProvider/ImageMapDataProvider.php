<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Ui\DataProvider;

use Cosmotec\EccubeMigration\Model\ResourceModel\ImageMap\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

class ImageMapDataProvider extends AbstractDataProvider
{
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    public function getData(): array
    {
        if (!$this->collection->isLoaded()) {
            $this->collection->load();
        }

        $items = [];
        foreach ($this->collection->getItems() as $item) {
            $items[] = $item->getData(); // Convert each item object to an associative array
        }

        $data = [
            'totalRecords' => $this->collection->getSize(),
            'items' => $items, // Ensure items is a flat indexed array
        ];

        return $data;
    }
}
