<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Controller\Adminhtml\Product\AdditionalContent;

use Cosmotec\EccubeMigration\Api\ItemAdditionalContentMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\ItemAdditionalContentMap;
use Cosmotec\EccubeMigration\Model\ItemAdditionalContentMapFactory;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Admin "Add tab" / "Update tab" action for the EC-CUBE Additional Content
 * section (Task 1: full CRUD, replacing the previous read-only display).
 * Tabs created here have no EC-CUBE origin (eccube_additional_information_id
 * / eccube_item_id left NULL) so a later EC-CUBE import/sync can never
 * touch or obsolete them - see ItemAdditionalContentImporter::
 * markObsoleteForItem(), which filters strictly by eccube_item_id and so
 * never matches a NULL row.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Catalog::products';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ItemAdditionalContentMapRepositoryInterface $mapRepository,
        private readonly ItemAdditionalContentMapFactory $mapFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\Controller\Result\Json
    {
        $result = $this->resultJsonFactory->create();
        $request = $this->getRequest();

        $productId = (int) $request->getParam('product_id');
        $entityId = (int) $request->getParam('entity_id');
        $title = trim((string) $request->getParam('tab_title', ''));
        $content = (string) $request->getParam('tab_content', '');
        $sortNo = (int) $request->getParam('sort_no', 0);

        if ($productId <= 0) {
            return $result->setData(['success' => false, 'message' => __('Missing product id.')]);
        }

        if ($title === '') {
            return $result->setData(['success' => false, 'message' => __('Tab title is required.')]);
        }

        try {
            if ($entityId > 0) {
                $map = $this->mapRepository->getById($entityId);

                if ((int) $map->getMagentoProductId() !== $productId) {
                    return $result->setData(['success' => false, 'message' => __('Tab does not belong to this product.')]);
                }
            } else {
                /** @var ItemAdditionalContentMap $map */
                $map = $this->mapFactory->create();
                $map->setMagentoProductId($productId);
                $map->setStatus(ItemAdditionalContentMap::STATUS_IMPORTED);
            }

            $map->setTabNameEn($title);
            $map->setSortNo($sortNo);
            $map->setHtmlContent($content);
            $this->mapRepository->save($map);

            return $result->setData(['success' => true, 'entity_id' => $map->getId()]);
        } catch (NoSuchEntityException $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => __('Could not save tab: %1', $e->getMessage())]);
        }
    }
}
