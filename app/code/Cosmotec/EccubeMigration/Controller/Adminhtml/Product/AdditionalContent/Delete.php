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
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Admin "Remove tab" action - a real hard delete (Task 1 explicitly asks
 * for removal, not obsolete-marking; obsolete-marking is reserved for
 * EC-CUBE-driven sync, see ItemAdditionalContentImporter).
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Catalog::products';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ItemAdditionalContentMapRepositoryInterface $mapRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\Controller\Result\Json
    {
        $result = $this->resultJsonFactory->create();
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        if ($entityId <= 0) {
            return $result->setData(['success' => false, 'message' => __('Missing tab id.')]);
        }

        try {
            $map = $this->mapRepository->getById($entityId);
            $this->mapRepository->delete($map);

            return $result->setData(['success' => true]);
        } catch (NoSuchEntityException $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => __('Could not delete tab: %1', $e->getMessage())]);
        }
    }
}
