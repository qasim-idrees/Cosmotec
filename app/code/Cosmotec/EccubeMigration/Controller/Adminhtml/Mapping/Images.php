<?php
/**
 * Cosmotec_EccubeMigration
 *
 * @copyright Copyright (c) Cosmotec
 * @license   Proprietary
 */

declare(strict_types=1);

namespace Cosmotec\EccubeMigration\Controller\Adminhtml\Mapping;

use Magento\Backend\App\Action;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;

class Images extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Cosmotec_EccubeMigration::mapping';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Cosmotec_EccubeMigration::mapping');
        $resultPage->getConfig()->getTitle()->prepend(__('Entity Mapping - Images'));
        $resultPage->addBreadcrumb(__('EC-CUBE Migration'), __('Entity Mapping'));

        return $resultPage;
    }
}
