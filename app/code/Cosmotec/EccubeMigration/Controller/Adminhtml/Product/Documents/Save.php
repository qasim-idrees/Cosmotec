<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Controller\Adminhtml\Product\Documents;

use Cosmotec\EccubeMigration\Api\ProductReferenceMapRepositoryInterface;
use Cosmotec\EccubeMigration\Model\Media\DocumentUploader;
use Cosmotec\EccubeMigration\Model\ProductReferenceMap;
use Cosmotec\EccubeMigration\Model\ProductReferenceMapFactory;
use Magento\Backend\App\Action;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Admin save action for the whole "EC-CUBE Documents" section (Task 5):
 * Dimension Image, CAD 2D/3D uploads, CAD-unavailable checkbox, and the
 * Document Name/Reference Link (1)/(2) convenience fields, all submitted
 * together as one multipart form (mirrors the product edit page's own
 * "save everything in the section at once" UX).
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Catalog::products';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly DocumentUploader $documentUploader,
        private readonly ProductReferenceMapRepositoryInterface $referenceMapRepository,
        private readonly ProductReferenceMapFactory $referenceMapFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\Controller\Result\Json
    {
        $result = $this->resultJsonFactory->create();
        $request = $this->getRequest();
        $productId = (int) $request->getParam('product_id');

        if ($productId <= 0) {
            return $result->setData(['success' => false, 'message' => __('Missing product id.')]);
        }

        try {
            $product = $this->productRepository->getById($productId);
            // QA FIX: Magento's own SaveHandler (fired unconditionally on
            // every product save) deletes a product's existing
            // Related/Up-Sell/Cross-Sell/Connection Part links first, then
            // re-inserts from Product::getProductLinks(). Live-confirmed
            // this round: merely calling getProductLinks() first is NOT
            // enough - the save still silently drops every link type this
            // controller doesn't explicitly deal with. Explicitly calling
            // setProductLinks() with that same loaded array does not drop
            // anything (setProductLinks() also clears the internal
            // 'ignore_links_flag' data key that getProductLinks() alone
            // leaves untouched, which is what the rest of the save
            // pipeline actually keys off). See
            // Model\Import\ItemImporter::persist() for the full mechanism
            // (found live: a bulk import run wiped all 862 Connection Part
            // links this exact way; a plain getProductLinks() fix here
            // was verified NOT sufficient before landing on this one).
            $product->setProductLinks($product->getProductLinks());

            $this->applyFileField($product, $request, 'dimension_image', DocumentUploader::SUBDIR_DIMENSION, 'eccube_dimension_image', ['jpg', 'jpeg', 'png', 'gif', 'bmp']);
            $this->applyFileField($product, $request, 'cad2d_file', DocumentUploader::SUBDIR_CAD2D, 'eccube_cad2d_file', ['zip']);
            $this->applyFileField($product, $request, 'cad3d_file', DocumentUploader::SUBDIR_CAD3D, 'eccube_cad3d_file', ['zip']);

            $product->setCustomAttribute('cad_unavailable', $request->getParam('cad_unavailable') ? 1 : 0);

            $this->productRepository->save($product);

            $this->saveReferenceSlot(
                $productId,
                (int) $request->getParam('ref_entity_id_1'),
                trim((string) $request->getParam('document_name_1', '')),
                trim((string) $request->getParam('reference_link_1', ''))
            );
            $this->saveReferenceSlot(
                $productId,
                (int) $request->getParam('ref_entity_id_2'),
                trim((string) $request->getParam('document_name_2', '')),
                trim((string) $request->getParam('reference_link_2', ''))
            );

            return $result->setData(['success' => true]);
        } catch (LocalizedException $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $result->setData(['success' => false, 'message' => __('Could not save documents: %1', $e->getMessage())]);
        }
    }

    /**
     * @param string[] $allowedExtensions
     */
    private function applyFileField(
        \Magento\Catalog\Api\Data\ProductInterface $product,
        \Magento\Framework\App\RequestInterface $request,
        string $inputName,
        string $subDir,
        string $attributeCode,
        array $allowedExtensions
    ): void {
        if ($request->getParam('remove_' . $inputName)) {
            $this->documentUploader->remove((string) $product->getData($attributeCode));
            $product->setCustomAttribute($attributeCode, null);

            return;
        }

        $files = $request->getFiles($inputName);

        if (!is_array($files) || ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            // No new file submitted - leave the existing value untouched.
            return;
        }

        $relativePath = $this->documentUploader->upload($inputName, $subDir, $allowedExtensions);
        $product->setCustomAttribute($attributeCode, $relativePath);
    }

    private function saveReferenceSlot(int $productId, int $entityId, string $name, string $link): void
    {
        if ($entityId > 0) {
            $map = $this->referenceMapRepository->getById($entityId);

            if ((int) $map->getMagentoProductId() !== $productId) {
                return;
            }
        } elseif ($name === '' && $link === '') {
            // Nothing to create for an empty, previously-nonexistent slot.
            return;
        } else {
            /** @var ProductReferenceMap $map */
            $map = $this->referenceMapFactory->create();
            $map->setMagentoProductId($productId);
            $map->setSortNo(0);
            $map->setStatus(ProductReferenceMap::STATUS_IMPORTED);
        }

        $map->setReferenceName($name !== '' ? $name : null);
        $map->setReferenceLink($link !== '' ? $link : null);
        $this->referenceMapRepository->save($map);
    }
}
