<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Block\Adminhtml\Product;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\SyncStatusManager;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Psr\Log\LoggerInterface;

class SyncStatus extends Template
{
    protected $_template = 'product/sync_status.phtml';

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly HelperData $helper,
        private readonly SyncStatusManager $syncStatusManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly ImageHelper $imageHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getProduct()
    {
        return $this->registry->registry('current_product');
    }

    /**
     * Thumbnail URL of the current product, or '' when it has none.
     */
    public function getImageUrl(): string
    {
        $product = $this->getProduct();
        if (!$product) {
            return '';
        }
        try {
            return (string)$this->imageHelper->init($product, 'product_thumbnail_image')->getUrl();
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function getHelper(): HelperData
    {
        return $this->helper;
    }

    public function getStoreManager(): StoreManagerInterface
    {
        return $this->storeManager;
    }

    /**
     * Get Ajax URL for manual single product sync
     */
    public function getSyncUrl(): string
    {
        return $this->getUrl('nexoraiq/product/sync');
    }

    /**
     * Read store view indexing statuses directly from Magento Database (Zero API calls on page view!)
     * Uses nexoraiq_product_sync_status table populated by product sync process / indexer.
     */
    public function getStoreStatuses(): array
    {
        $product = $this->getProduct();
        if (!$product || !$this->helper->isEnabled()) {
            return [];
        }

        $productId = (int)$product->getId();
        $activeStoreViews = $this->helper->getActiveStoreViews();
        $storeViews = $this->storeManager->getStores();

        // 1. Primary lookup: Read from local Magento Database table (nexoraiq_product_sync_status)
        $dbStatuses = $this->syncStatusManager->getSyncStatusesFromDb($productId);

        $results = [];
        foreach ($storeViews as $store) {
            $storeCode = $store->getCode();
            if ($activeStoreViews !== null && !in_array($storeCode, $activeStoreViews)) {
                continue;
            }

            // Check if status is stored in local Magento DB
            if (isset($dbStatuses[$storeCode])) {
                $results[] = [
                    'store_name'     => $store->getName(),
                    'store_code'     => $storeCode,
                    'is_synced'      => $dbStatuses[$storeCode]['is_synced'],
                    'last_synced_at' => $dbStatuses[$storeCode]['last_synced_at'],
                    'fields'         => $dbStatuses[$storeCode]['fields'],
                ];
            } else {
                // Product is not yet in sync status table = not synced
                $results[] = [
                    'store_name'     => $store->getName(),
                    'store_code'     => $storeCode,
                    'is_synced'      => false,
                    'last_synced_at' => null,
                    'fields'         => [],
                ];
            }
        }

        return $results;
    }
}
