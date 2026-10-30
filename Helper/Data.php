<?php
declare(strict_types=1);
namespace NexorAIQ\Connector\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class Data extends AbstractHelper
{
    private const COMPOSITE_TYPES = ['configurable', 'bundle', 'grouped'];
    private const CONFIG_KEY      = 'nexoraiq/general/connection_key';
    private const CONFIG_API_URL  = 'nexoraiq/general/api_url';
    private const CONFIG_ENABLED  = 'nexoraiq/general/enabled';
    private const CONFIG_REALTIME = 'nexoraiq/general/realtime_sync';
    private const CONFIG_BATCH_SIZE = 'nexoraiq/general/batch_size';
    private const CONFIG_FULL_REINDEX_SKIP_WINDOW = 'nexoraiq/general/full_reindex_skip_window';
    private const CONFIG_TAKEOVER = 'nexoraiq/storefront_ui/takeover_enabled';
    private const CONFIG_SEARCH_KEY = 'nexoraiq/storefront_ui/search_key';
    private const CONFIG_SEARCH_TYPE = 'nexoraiq/storefront_ui/search_type';

    private const CONFIG_IS_MULTI_BRAND = 'nexoraiq/brands_config/is_multi_brand';
    private const CONFIG_BRAND_ATTRIBUTE = 'nexoraiq/brands_config/attribute';
    private const CONFIG_IGNORE_DESCRIPTION = 'nexoraiq/description_config/ignore_description';
    private const CONFIG_EXTRA_ATTRIBUTES = 'nexoraiq/attributes_config/extra_attributes';

    private ?array $activeStoreViewsCache = null;

    public function __construct(
        Context $context,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        private readonly \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        private readonly \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        private readonly \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        private readonly \Magento\Framework\App\ProductMetadataInterface $productMetadata,
        private readonly \NexorAIQ\Connector\Model\SyncStatusManager $syncStatusManager,
        private readonly \NexorAIQ\Connector\Model\SyncQueueManager $syncQueueManager,
        private readonly \NexorAIQ\Connector\Model\SyncBlockManager $syncBlockManager,
        private readonly \Magento\Framework\HTTP\Client\CurlFactory $curlFactory,
        private readonly \NexorAIQ\Connector\Model\Inventory\SalableQtyProvider $salableQtyProvider
    ) {
        parent::__construct($context);
    }

    /**
     * Some Magento installs (pub/ not set as the web server document root) serve
     * media URLs with a "/pub/" segment that the actual public site doesn't have —
     * strip it so generated image URLs resolve on the live storefront.
     */
    private function stripPubSegment(string $url): string
    {
        return preg_replace('#(/pub)(/media/)#', '$2', $url, 1);
    }

    public function isEnabled($storeId = null): bool
    {
        $enabled = $this->scopeConfig->isSetFlag(self::CONFIG_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
        if (!$enabled) {
            return false;
        }

        $activeStoreViews = $this->getActiveStoreViews();
        if ($activeStoreViews !== null && empty($activeStoreViews)) {
            return false;
        }

        return true;
    }

    public function isRealtimeEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_REALTIME, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getBatchSize($storeId = null): int
    {
        $val = $this->scopeConfig->getValue(self::CONFIG_BATCH_SIZE, ScopeInterface::SCOPE_STORE, $storeId);
        return $val !== null ? (int)$val : 100;
    }

    /**
     * Minutes within which a product already successfully synced is skipped during a full
     * reindex, instead of being blindly resent. 0 disables skipping (always resync everything).
     */
    public function getFullReindexSkipWindowMinutes($storeId = null): int
    {
        $val = $this->scopeConfig->getValue(self::CONFIG_FULL_REINDEX_SKIP_WINDOW, ScopeInterface::SCOPE_STORE, $storeId);
        return $val !== null ? max(0, (int)$val) : 30;
    }

    public function getApiUrl($storeId = null): string
    {
        return rtrim(
            $this->scopeConfig->getValue(self::CONFIG_API_URL, ScopeInterface::SCOPE_STORE, $storeId) ?? 'https://api.nexoraiq.tech',
            '/'
        );
    }

    public function getConnectionKey($storeId = null): string
    {
        $encrypted = $this->scopeConfig->getValue(self::CONFIG_KEY, ScopeInterface::SCOPE_STORE, $storeId) ?? '';
        return $encrypted !== '' ? $this->encryptor->decrypt($encrypted) : '';
    }

    public function isTakeoverEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_TAKEOVER, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getSearchKey($storeId = null): string
    {
        $value = (string)($this->scopeConfig->getValue(self::CONFIG_SEARCH_KEY, ScopeInterface::SCOPE_STORE, $storeId) ?? '');
        if ($value === '' || str_starts_with($value, 'key_search_')) {
            // Empty, or a legacy plain-text value saved before the field was encrypted.
            return $value;
        }
        return (string)$this->encryptor->decrypt($value);
    }

    public function getSearchType($storeId = null): string
    {
        $val = $this->scopeConfig->getValue(self::CONFIG_SEARCH_TYPE, ScopeInterface::SCOPE_STORE, $storeId);
        return !empty($val) ? (string)$val : \NexorAIQ\Connector\Model\Config\Source\SearchType::TYPE_KEYWORD;
    }



    public function isMultiBrand($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_IS_MULTI_BRAND, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getBrandAttribute($storeId = null): ?string
    {
        return $this->scopeConfig->getValue(self::CONFIG_BRAND_ATTRIBUTE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isIgnoreDescription($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_IGNORE_DESCRIPTION, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getExtraAttributes($storeId = null): array
    {
        $value = $this->scopeConfig->getValue(self::CONFIG_EXTRA_ATTRIBUTES, ScopeInterface::SCOPE_STORE, $storeId) ?? '';
        return $value !== '' ? explode(',', $value) : [];
    }

    /**
     * Bounds how much of a failed API response gets written to system.log — enough to see
     * NexoraIQ's actual rejection reason without risking huge/binary bodies flooding the log.
     */
    private function truncateResponseBody($response, int $maxLength = 500): string
    {
        if (!is_string($response) || $response === '') {
            return '(empty response)';
        }
        return strlen($response) > $maxLength ? substr($response, 0, $maxLength) . '...(truncated)' : $response;
    }

    private function authHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getConnectionKey(),
        ];
    }

    /**
     * Sends a request to the NexorAIQ API through Magento's HTTP client.
     *
     * @param string $method GET or POST
     * @param string $url
     * @param array $headers Header name => value
     * @param string|null $body Raw request body (POST only)
     * @param int $connectTimeout Seconds
     * @param int $timeout Seconds
     * @return array{code: int, body: string, error: string}
     */
    private function apiRequest(
        string $method,
        string $url,
        array $headers,
        ?string $body = null,
        int $connectTimeout = 3,
        int $timeout = 5
    ): array {
        $verifySsl = $this->verifySsl();
        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeout);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, $verifySsl);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
        $curl->setOption(CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        $curl->setHeaders($headers);
        try {
            if ($method === 'POST') {
                $curl->post($url, (string)$body);
            } else {
                $curl->get($url);
            }
        } catch (\Throwable $e) {
            return ['code' => 0, 'body' => '', 'error' => $e->getMessage()];
        }
        return ['code' => (int)$curl->getStatus(), 'body' => (string)$curl->getBody(), 'error' => ''];
    }

    /**
     * Scheme, host and port of the NexorAIQ API URL (e.g. "https://api.nexoraiq.tech"), or "" when invalid.
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function getApiOrigin($storeId = null): string
    {
        try {
            $uri = new \Laminas\Uri\Http($this->getApiUrl($storeId));
        } catch (\Throwable $e) {
            return '';
        }
        $scheme = (string)$uri->getScheme();
        $host = (string)$uri->getHost();
        if ($scheme === '' || $host === '') {
            return '';
        }
        $port = (int)$uri->getPort();
        $defaultPort = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . $host . ($port && $port !== $defaultPort ? ':' . $port : '');
    }

    public function verifySsl($storeId = null): bool
    {
        $apiUrl = $this->getApiUrl($storeId);
        if (strpos($apiUrl, 'localhost') !== false || strpos($apiUrl, '127.0.0.1') !== false) {
            return false; // Local developer environments
        }
        
        $key = $this->getConnectionKey($storeId);
        if (strpos($key, 'nxk_dev') === 0) {
            return false; // Sandbox/Dev keys
        }
        
        return true; // Enforce SSL verification in production
    }

    public function getProductCategoryName($product, $storeId): string
    {
        $categoryIds = $product->getCategoryIds();
        if (empty($categoryIds)) {
            return 'General';
        }
        $categoryCollection = $this->categoryCollectionFactory->create();
        $categoryCollection->addAttributeToSelect('name')
            ->setStoreId($storeId)
            ->addAttributeToFilter('entity_id', ['in' => $categoryIds]);
        $first = $categoryCollection->getFirstItem();
        return $first ? (string)$first->getName() : 'General';
    }

    /**
     * Builds the universal category-tree shape (id/name/url/level/parentId/pathIds/image/
     * includeInMenu/isSearchable) shared with the WooCommerce connector and the API-side
     * CategoryEntry format, instead of the single flattened `category_name` string. Includes
     * every ancestor of each assigned category (not just the leaf) so a category's pathIds
     * always resolve to an entry in this same array.
     */
    public function getProductCategories($product, $storeId): array
    {
        $categoryIds = $product->getCategoryIds();
        if (empty($categoryIds)) {
            return [];
        }

        $effectiveStoreId = (int)$storeId;
        if ($effectiveStoreId === 0) {
            try {
                $defaultStore = $this->storeManager->getDefaultStoreView();
                if ($defaultStore) {
                    $effectiveStoreId = (int)$defaultStore->getId();
                }
            } catch (\Throwable $e) {
                $effectiveStoreId = 1;
            }
        }

        $directCollection = $this->categoryCollectionFactory->create();
        $directCollection->addAttributeToSelect(['path'])
            ->setStoreId($effectiveStoreId)
            ->addAttributeToFilter('entity_id', ['in' => $categoryIds]);

        $allIds = [];
        foreach ($directCollection as $cat) {
            foreach (explode('/', (string)$cat->getPath()) as $id) {
                if ((int)$id > 2) { // Skip Magento's internal root categories (ids 0/1/2)
                    $allIds[(int)$id] = true;
                }
            }
        }
        if (empty($allIds)) {
            return [];
        }

        $baseUrl = rtrim($this->storeManager->getStore($effectiveStoreId)->getBaseUrl(), '/');
        $mediaUrl = $this->storeManager->getStore($effectiveStoreId)->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
        $mediaUrl = $this->stripPubSegment($mediaUrl);

        $fullCollection = $this->categoryCollectionFactory->create();
        $fullCollection->addAttributeToSelect(['name', 'url_key', 'url_path', 'image', 'level', 'path', 'position', 'is_active', 'include_in_menu'])
            ->setStoreId($effectiveStoreId)
            ->addAttributeToFilter('entity_id', ['in' => array_keys($allIds)]);

        $entries = [];
        foreach ($fullCollection as $cat) {
            if ((int)$cat->getLevel() <= 1) {
                continue; // Skip root categories (Default Category / level 1)
            }
            $path = array_values(array_filter(explode('/', (string)$cat->getPath()), fn($id) => (int)$id > 2));
            $parentId = count($path) > 1 ? $path[count($path) - 2] : (string)$cat->getParentId();
            $urlKey = $cat->getUrlKey();
            $catUrl = $cat->getUrl();
            if (empty($catUrl) || str_contains($catUrl, 'catalog/category/view')) {
                $key = $urlKey ?: $cat->getUrlPath();
                if ($key) {
                    $catUrl = "{$baseUrl}/" . ltrim((string)$key, '/') . ".html";
                } else {
                    $catUrl = null;
                }
            }
            $relativeUrl = $catUrl ? ltrim((string)(new \Laminas\Uri\Http($catUrl))->getPath(), '/') : null;
            $image = $cat->getImage();
            $entries[] = [
                'id' => (string)$cat->getId(),
                'name' => (string)$cat->getName(),
                'url' => $catUrl,
                'url_key' => $relativeUrl,
                'position' => (int)($cat->getPosition() ?: 0),
                'level' => (int)$cat->getLevel(),
                'parentId' => $parentId !== null ? (string)$parentId : null,
                'image' => $image ? $mediaUrl . 'catalog/category/' . ltrim((string)$image, '/') : null,
                'pathIds' => array_map('strval', $path),
                'includeInMenu' => (bool)$cat->getIncludeInMenu(),
                'isSearchable' => (bool)$cat->getIsActive(),
            ];
        }

        return $entries;
    }

    /**
     * Full image gallery (not just the single base image) so the universal `images[]` /
     * mainImage / hoverImage shape can be built API-side the same way for every platform.
     */
    public function getProductImages($product, string $mediaUrl): array
    {
        $images = [];
        $gallery = $product->getMediaGalleryImages();
        if ($gallery) {
            foreach ($gallery as $image) {
                if (!empty($image['url'])) {
                    $images[] = $this->stripPubSegment($image['url']);
                }
            }
        }
        if (empty($images)) {
            $imagePath = $product->getImage();
            if ($imagePath && $imagePath !== 'no_selection') {
                $images[] = $this->stripPubSegment($mediaUrl . 'catalog/product' . $imagePath);
            }
        }
        return array_values(array_unique($images));
    }

    /**
     * [min, max] final price, from the children for configurable / bundle / grouped products.
     *
     * @return float[]
     */
    private function getPriceRange(\Magento\Catalog\Model\Product $product): array
    {
        try {
            $finalPrice = $product->getPriceInfo()->getPrice('final_price');
            $min = (float)$finalPrice->getMinimalPrice()->getValue();
            $max = (float)$finalPrice->getMaximalPrice()->getValue();
            if ($max > 0) {
                return [min($min, $max), max($min, $max)];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('NexoraIQ price range failed', ['sku' => $product->getSku(), 'error' => $e->getMessage()]);
        }
        $price = (float)$product->getPrice();
        return [$price, $price];
    }

    /**
     * Total stock of the in-stock children of a configurable / bundle / grouped product (at least 1 when salable).
     */
    private function getChildrenQty(\Magento\Catalog\Model\Product $product, int $websiteId): int
    {
        $total = 0;
        try {
            foreach ($product->getTypeInstance()->getChildrenIds((int)$product->getId(), false) as $group) {
                foreach ((array)$group as $childId) {
                    $item = $this->stockRegistry->getStockItem((int)$childId, $websiteId);
                    if ($item && $item->getIsInStock()) {
                        $total += max(0, (int)$item->getQty());
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('NexoraIQ children qty failed', ['sku' => $product->getSku(), 'error' => $e->getMessage()]);
        }
        return max(1, $total);
    }

    /**
     * Option labels of the configurable attributes (size, color, ...) across the salable children.
     *
     * @return array<string, string[]>
     */
    private function getVariantOptionValues(\Magento\Catalog\Model\Product $product): array
    {
        $values = [];
        try {
            $typeInstance = $product->getTypeInstance();
            $codes = [];
            foreach ($typeInstance->getConfigurableAttributes($product) as $configurableAttribute) {
                $attribute = $configurableAttribute->getProductAttribute();
                if ($attribute) {
                    $codes[] = $attribute->getAttributeCode();
                }
            }
            foreach ($typeInstance->getUsedProducts($product) as $child) {
                if (!$child->isSalable()) {
                    continue;
                }
                foreach ($codes as $code) {
                    $label = $child->getAttributeText($code);
                    if (is_string($label) && $label !== '') {
                        $values[$code][$label] = $label;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('NexoraIQ variant options failed', ['sku' => $product->getSku(), 'error' => $e->getMessage()]);
        }
        return array_map('array_values', $values);
    }

    private function hasVariants(\Magento\Catalog\Model\Product $product): bool
    {
        try {
            return count($product->getTypeInstance()->getUsedProductIds($product)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function buildProductPayload(\Magento\Catalog\Model\Product $product, $store): array
    {
        $productId = (int)$product->getId();
        $storeId = (int)$store->getId();
        $storeCode = $store->getCode();

        // Check stock
        $stockItem = $this->stockRegistry->getStockItem($productId, $store->getWebsiteId());
        $qty = $stockItem ? (int)$stockItem->getQty() : 0;
        $isInStock = $stockItem ? (bool)$stockItem->getIsInStock() : false;
        $salableQty = null;

        // Resolve salable qty from MSI when it is installed
        try {
            $msiQty = $this->salableQtyProvider->get((string)$product->getSku(), $store->getWebsite());
            if ($msiQty !== null) {
                $salableQty = (int)$msiQty;
            }
        } catch (\Throwable $msiError) {
            // Composite products have no salable qty: keep the stock item qty
            $salableQty = null;
        }

        if ($salableQty !== null) {
            if ($salableQty <= 0) {
                $isInStock = false;
            } else {
                $isInStock = true;
            }
        }

        // Configurable / bundle / grouped parents hold no stock or price of their own: they are in stock when
        // a child can be bought, and their price is the range of their children's final prices.
        $typeId = (string)$product->getTypeId();
        $isComposite = in_array($typeId, self::COMPOSITE_TYPES, true);
        if ($isComposite) {
            $isInStock = (bool)$product->isSalable();
            // The API derives availability from the quantities, so send what the children hold.
            $qty = $isInStock ? $this->getChildrenQty($product, (int)$store->getWebsiteId()) : 0;
            $salableQty = $qty;
        }
        [$priceMin, $priceMax] = $this->getPriceRange($product);
        $hasVariants = $typeId === 'configurable' && $this->hasVariants($product);

        $defaultProduct = null;
        $getDefaultProduct = function () use (&$defaultProduct, $productId) {
            if ($defaultProduct === null && $productId > 0) {
                try {
                    $defaultProduct = $this->productRepository->getById($productId, false, 0);
                } catch (\Throwable $e) {
                    $defaultProduct = false;
                }
            }
            return $defaultProduct ?: null;
        };

        // Name fallback
        $name = $product->getName();
        if ($name === null || $name === '') {
            $defP = $getDefaultProduct();
            if ($defP) {
                $name = $defP->getName();
            }
        }

        // Description fallback
        $description = $product->getDescription();
        if ($description === null || $description === '') {
            $defP = $getDefaultProduct();
            if ($defP) {
                $description = $defP->getDescription();
            }
        }

        // Short Description fallback
        $shortDescription = $product->getShortDescription();
        if ($shortDescription === null || $shortDescription === '') {
            $defP = $getDefaultProduct();
            if ($defP) {
                $shortDescription = $defP->getShortDescription();
            }
        }

        // Image fallback
        $mediaUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
        $mediaUrl = $this->stripPubSegment($mediaUrl);
        $imagePath = $product->getImage();
        if (!$imagePath || $imagePath === 'no_selection') {
            $defP = $getDefaultProduct();
            if ($defP && $defP->getImage() && $defP->getImage() !== 'no_selection') {
                $imagePath = $defP->getImage();
            }
        }
        $imageUrl = ($imagePath && $imagePath !== 'no_selection') ? $mediaUrl . 'catalog/product' . $imagePath : null;

        // Category Name fallback
        $categoryName = $this->getProductCategoryName($product, $storeId);
        if (($categoryName === null || $categoryName === '' || $categoryName === 'General') && $storeId !== 0) {
            $defP = $getDefaultProduct();
            if ($defP) {
                $defCat = $this->getProductCategoryName($defP, 0);
                if ($defCat && $defCat !== 'General') {
                    $categoryName = $defCat;
                }
            }
        }

        // Brand / Manufacturer fallback
        $brand = null;
        $brandAttrCode = $this->isMultiBrand($storeId) ? ($this->getBrandAttribute($storeId) ?: 'manufacturer') : 'manufacturer';
        $brandVal = $product->getAttributeText($brandAttrCode);
        if ($brandVal === false || $brandVal === null || $brandVal === '') {
            $brandVal = $product->getData($brandAttrCode);
        }
        if ($brandVal === false || $brandVal === null || $brandVal === '') {
            $defP = $getDefaultProduct();
            if ($defP) {
                $brandVal = $defP->getAttributeText($brandAttrCode);
                if ($brandVal === false || $brandVal === null || $brandVal === '') {
                    $brandVal = $defP->getData($brandAttrCode);
                }
            }
        }
        if ($brandVal !== null && $brandVal !== '' && $brandVal !== false) {
            $brand = is_array($brandVal) ? implode(', ', $brandVal) : (string)$brandVal;
        }

        $extraAttributes = $this->getExtraAttributes($storeId);
        $customAttributes = [];
        foreach ($extraAttributes as $attrCode) {
            $attrVal = $product->getAttributeText($attrCode);
            if ($attrVal === false || $attrVal === null || $attrVal === '') {
                $attrVal = $product->getData($attrCode);
            }
            if ($attrVal === false || $attrVal === null || $attrVal === '') {
                $defP = $getDefaultProduct();
                if ($defP) {
                    $attrVal = $defP->getAttributeText($attrCode);
                    if ($attrVal === false || $attrVal === null || $attrVal === '') {
                        $attrVal = $defP->getData($attrCode);
                    }
                }
            }
            if ($attrVal !== null && $attrVal !== '' && $attrVal !== false) {
                $customAttributes[$attrCode] = is_array($attrVal) ? implode(', ', $attrVal) : $attrVal;
            }
        }

        // A configurable parent has no size / color of its own: send the options its in-stock children
        // offer, so the storefront can filter it by them (same "A, B" format as the other custom values).
        if ($typeId === 'configurable') {
            foreach ($this->getVariantOptionValues($product) as $attrCode => $labels) {
                $customAttributes[$attrCode] = implode(', ', $labels);
            }
        }

        $ratingSummary = $product->getRatingSummary() ? (float)$product->getRatingSummary() : 0.0;
        $reviewsCount = $product->getReviewsCount() ? (int)$product->getReviewsCount() : 0;

        // Universal categories tree + image gallery (see getProductCategories/getProductImages
        // above) — sent alongside the legacy category_name/image fields so nothing depending
        // on those flat fields breaks; the API side prefers the rich fields when present.
        $categories = $this->getProductCategories($product, $storeId);
        $images = $this->getProductImages($product, $mediaUrl);
        if ($storeId !== 0) {
            $defP = $getDefaultProduct();
            if ($defP) {
                if (empty($categories)) {
                    $categories = $this->getProductCategories($defP, 0);
                }
                if (empty($images)) {
                    $images = $this->getProductImages($defP, $mediaUrl);
                }
            }
        }

        // URL Key & URL fallback
        $urlKey = $product->getUrlKey();
        if ($urlKey === null || $urlKey === '') {
            $urlKey = $product->getData('url_key');
        }
        if ($urlKey === null || $urlKey === '') {
            $defP = $getDefaultProduct();
            if ($defP) {
                $urlKey = $defP->getUrlKey() ?: $defP->getData('url_key');
            }
        }
        $urlKeyStr = $urlKey ? (string)$urlKey : null;
        $url = $urlKeyStr ? ($urlKeyStr . '.html') : null;

        // Visibility
        $visibility = (int)$product->getVisibility();
        if ($visibility === 0 && $storeId !== 0) {
            $defP = $getDefaultProduct();
            if ($defP) {
                $visibility = (int)$defP->getVisibility();
            }
        }

        // Weight
        $weight = $product->getWeight() ? (float)$product->getWeight() : null;

        return [
            'entity_id' => $productId,
            'type_id' => $typeId, // 'configurable' parents get variant-child expansion on the API side
            'status' => (string)$product->getStatus(), // 1 = enabled, 2 = disabled
            'updated_at' => $product->getUpdatedAt(),
            'name' => $name ?: 'Untitled',
            'description' => $description ?? '',
            'short_description' => $shortDescription ?? '',
            'sku' => $product->getSku(),
            // Composite parents have price 0 in Magento; show the lowest price a customer can pay instead.
            'price' => $isComposite && (float)$product->getPrice() <= 0 ? $priceMin : (float)$product->getPrice(),
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'has_variants' => $hasVariants,
            'special_price' => $product->getSpecialPrice() ? (float)$product->getSpecialPrice() : null,
            'qty' => $qty,
            'stock_qty' => $qty,
            'salable_qty' => $salableQty !== null ? $salableQty : $qty,
            'is_in_stock' => $isInStock,
            'in_stock' => $isInStock,
            'stock_status' => $isInStock ? 'in_stock' : 'out_of_stock',
            'url_key' => $urlKeyStr,
            'url' => $url,
            'visibility' => $visibility,
            'weight' => $weight,
            'image' => $imageUrl,
            'images' => $images,
            'category_name' => $categoryName,
            'categories' => $categories,
            'store_view_code' => $storeCode,
            'store_id' => $storeId,
            'manufacturer' => $brand,
            'attributes' => [
                'custom' => $customAttributes
            ],
            'rating_summary' => $ratingSummary,
            'reviews_count' => $reviewsCount
        ];
    }

    public function queueSync(int $productId, bool $deleted = false): bool
    {
        return $this->syncProduct($productId, $deleted);
    }

    public function syncProduct(int $productId, bool $deleted = false): bool
    {
        if (!$this->isEnabled() || !$this->isRealtimeEnabled()) {
            return false;
        }
        $key = $this->getConnectionKey();
        if ($key === '') {
            return false;
        }

        $activeStoreViews = $this->getActiveStoreViews();
        $storeViews = $this->storeManager->getStores();
        $success = true;

        foreach ($storeViews as $store) {
            $storeId = (int)$store->getId();
            if (!$store->isActive()) {
                continue;
            }
            $storeCode = $store->getCode();
            if ($activeStoreViews !== null && !in_array($storeCode, $activeStoreViews)) {
                continue;
            }

            if ($deleted) {
                $payload = [
                    'entity_id' => $productId,
                    'status' => 'deleted',
                    'store_view_code' => $storeCode,
                    'store_id' => $storeId
                ];
                $this->sendWebhookPayload($payload);
            } else {
                try {
                    $product = $this->productRepository->getById($productId, false, $storeId);
                    $payload = $this->buildProductPayload($product, $store);
                    $this->sendWebhookPayload($payload);
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    // Product deleted/not found for this store view
                    $payload = [
                        'entity_id' => $productId,
                        'status' => 'deleted',
                        'store_view_code' => $storeCode,
                        'store_id' => $storeId
                    ];
                    $this->sendWebhookPayload($payload);
                } catch (\Throwable $e) {
                    $this->logger->error('NexoraIQ Real-time product mapping failed', [
                        'product_id' => $productId,
                        'store_id' => $storeId,
                        'error' => $e->getMessage()
                    ]);
                    $success = false;
                }
            }
        }

        return $success;
    }


    private function sendWebhookPayload(array $payload): bool
    {
        $url = $this->getApiUrl() . '/integrations/magento/product-sync?storeViewId=' . urlencode($payload['store_view_code']);
        $response = $this->apiRequest(
            'POST',
            $url,
            $this->authHeaders(),
            (string)json_encode($payload),
            2,
            3
        );
        $res = $response['body'];
        $code = $response['code'];

        if ($code < 200 || $code >= 300) {
            $this->logger->warning('NexoraIQ sync webhook failed', [
                'entity_id' => $payload['entity_id'],
                'store' => $payload['store_view_code'],
                'code' => $code,
                'response' => $this->truncateResponseBody($res),
            ]);
            return false;
        }

        // Persist sync status to local Magento database
        $productId = (int)($payload['entity_id'] ?? 0);
        $storeCode = (string)($payload['store_view_code'] ?? '');
        $storeId   = (int)($payload['store_id'] ?? 0);
        $isDeletedOrDisabled = (isset($payload['deleted']) && $payload['deleted'])
            || (isset($payload['status']) && in_array($payload['status'], [2, '2', 0, '0', 'disabled', 'deleted'], true));
        if ($productId > 0) {
            try {
                $syncStatusManager = $this->syncStatusManager;
                if ($isDeletedOrDisabled) {
                    $syncStatusManager->saveSyncStatus($productId, $storeId, $storeCode, false, [], date('Y-m-d H:i:s'));
                } else {
                    $fields = array_keys(array_filter($payload, fn($val, $k) => !in_array($k, ['entity_id', 'store_view_code', 'store_id'], true) && $val !== null && $val !== '', ARRAY_FILTER_USE_BOTH));
                    $syncStatusManager->saveSyncStatus($productId, $storeId, $storeCode, true, $fields, date('Y-m-d H:i:s'));
                }
                $this->logger->info(sprintf(
                    'NexoraIQ webhook sync ok: product_id=%d store=%s synced=%s',
                    $productId,
                    $storeCode,
                    $isDeletedOrDisabled ? 'false' : 'true'
                ));

                // Dequeue from local sync queue so background cron job won't send duplicate bulk request
                try {
                    $syncQueueManager = $this->syncQueueManager;
                    $syncQueueManager->removeFromQueue([$productId]);
                } catch (\Throwable $e) {

                }
            } catch (\Throwable $e) {
                // Non-blocking fallback
                $this->logger->error(sprintf(
                    'NexoraIQ webhook sync: failed to persist sync status for product_id=%d store=%s: %s',
                    $productId,
                    $storeCode,
                    $e->getMessage()
                ));
            }
        }

        return true;
    }

    public function sendBulkPayload(array $payloads, string $storeCode): bool
    {
        $url = $this->getApiUrl() . '/integrations/magento/product-sync-bulk?storeViewId=' . urlencode($storeCode);
        $response = $this->apiRequest(
            'POST',
            $url,
            $this->authHeaders(),
            (string)json_encode(['products' => $payloads]),
            5,
            30
        );
        $res = $response['body'];
        $code = $response['code'];

        if ($code < 200 || $code >= 300) {
            $this->logger->warning('NexoraIQ bulk sync failed', [
                'store' => $storeCode,
                'code' => $code,
                'count' => count($payloads),
                'sample_entity_ids' => array_slice(array_column($payloads, 'entity_id'), 0, 5),
                'response' => $this->truncateResponseBody($res),
            ]);

            // 402 = plan-level block (subscription expired, product/index limit reached, etc.).
            // Record it verbatim from the API response — never hardcode the wording — so cron/
            // indexer/observers can stop calling an API that is guaranteed to keep failing until
            // the account issue is resolved on the NexoraIQ side, and so the admin notice always
            // shows exactly what NexoraIQ returned.
            if ($code === 402) {
                $decoded = is_string($res) ? json_decode($res, true) : null;
                // NexoraIQ's actual response shape is {"statusCode":402,"error":"SUBSCRIPTION_EXPIRED","message":"..."}
                // — check that first, falling back to error_type/error_message in case other
                // endpoints/versions use different field names, so this stays dynamic either way.
                $errorType = 'PLAN_RESTRICTED';
                $errorMessage = $this->truncateResponseBody($res);
                if (is_array($decoded)) {
                    foreach (['error', 'error_type', 'errorType', 'type'] as $key) {
                        if (!empty($decoded[$key])) {
                            $errorType = (string)$decoded[$key];
                            break;
                        }
                    }
                    foreach (['message', 'error_message', 'errorMessage'] as $key) {
                        if (!empty($decoded[$key])) {
                            $errorMessage = (string)$decoded[$key];
                            break;
                        }
                    }
                }

                try {
                    $this->syncBlockManager->setBlocked(402, $errorType, $errorMessage);
                } catch (\Throwable $e) {
                    $this->logger->error('NexoraIQ Helper: failed to persist sync block state: ' . $e->getMessage());
                }
            }

            return false;
        }

        // Defensive: if a call ever succeeds while a stale block flag is still set (e.g. the
        // account was fixed on the NexoraIQ side without going through Check Subscription),
        // clear it here too rather than leaving the admin notice stuck.
        try {
            $syncBlockManager = $this->syncBlockManager;
            if ($syncBlockManager->isBlocked()) {
                $syncBlockManager->clearBlock();
            }
        } catch (\Throwable $e) {

        }

        // Persist sync status to local Magento database for all payloads in batch
        try {
            $syncStatusManager = $this->syncStatusManager;
            $batchItems = [];
            foreach ($payloads as $p) {
                $productId = (int)($p['entity_id'] ?? 0);
                $storeId   = (int)($p['store_id'] ?? 0);
                $pStoreCode = (string)($p['store_view_code'] ?? $storeCode);
                $isDeletedOrDisabled = (isset($p['deleted']) && $p['deleted'])
                    || (isset($p['status']) && in_array($p['status'], [2, '2', 0, '0', 'disabled', 'deleted'], true));
                if ($productId > 0) {
                    $fields = $isDeletedOrDisabled ? [] : array_keys(array_filter($p, fn($val, $k) => !in_array($k, ['entity_id', 'store_view_code', 'store_id'], true) && $val !== null && $val !== '', ARRAY_FILTER_USE_BOTH));
                    $batchItems[] = [
                        'product_id'     => $productId,
                        'store_id'       => $storeId,
                        'store_code'     => $pStoreCode,
                        'is_synced'      => !$isDeletedOrDisabled,
                        'fields'         => $fields,
                        'last_synced_at' => date('Y-m-d H:i:s'),
                    ];
                }
            }
            if (!empty($batchItems)) {
                $syncStatusManager->saveBatchSyncStatus($batchItems);
            }
            $this->logger->info(sprintf(
                'NexoraIQ bulk sync ok: store=%s count=%d',
                $storeCode,
                count($payloads)
            ));
        } catch (\Throwable $e) {
            // Non-blocking fallback
            $this->logger->error(sprintf(
                'NexoraIQ bulk sync: failed to persist sync statuses for store=%s: %s',
                $storeCode,
                $e->getMessage()
            ));
        }

        return true;
    }

    /**
     * Used by Controller\Adminhtml\System\CheckSubscription — sends exactly one product to
     * the same /integrations/magento/product-sync-bulk endpoint used by real syncs, so a 200
     * response is real proof the account is usable again (not just that the API is reachable).
     * Deliberately does not check SyncBlockManager::isBlocked() first — that's the whole point
     * of this explicit, admin-triggered action while cron/indexer stay paused.
     *
     * @return array{success: bool, message: string}
     */
    public function testProductSync(): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => __('NexorAIQ integration is disabled.')->render()];
        }

        try {
            $store = $this->storeManager->getDefaultStoreView() ?? $this->storeManager->getStore();
            $storeCode = $store->getCode();

            $productCollection = $this->productCollectionFactory->create();
            $productCollection->addAttributeToSelect('entity_id')
                ->addAttributeToFilter('status', ['eq' => \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED])
                ->setPageSize(1);
            $productId = (int)$productCollection->getFirstItem()->getId();

            if ($productId <= 0) {
                return ['success' => false, 'message' => __('No enabled product found to test sync with.')->render()];
            }

            $product = $this->productRepository->getById($productId, false, (int)$store->getId());
            $payload = $this->buildProductPayload($product, $store);

            $sent = $this->sendBulkPayload([$payload], $storeCode);
            if ($sent) {
                return ['success' => true, 'message' => __('Test sync succeeded — NexorAIQ accepted the product. Sync has resumed.')->render()];
            }

            // sendBulkPayload() already persisted the specific error into SyncBlockManager
            // (if it was a 402) — surface that exact message here.
            $blockState = $this->syncBlockManager->getState();

            if (!empty($blockState['is_blocked'])) {
                return [
                    'success' => false,
                    'message' => sprintf('%s: %s', $blockState['error_type'], $blockState['error_message']),
                ];
            }

            return ['success' => false, 'message' => __('Test sync failed — see NexorAIQ Indexer log for details.')->render()];
        } catch (\Throwable $e) {
            $this->logger->error('NexorAIQ testProductSync failed: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function startImport(string $integrationId, string $mode = 'background'): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $url = $this->getApiUrl() . '/import/start';
        $response = $this->apiRequest(
            'POST',
            $url,
            $this->authHeaders(),
            (string)json_encode(['integrationId' => $integrationId, 'mode' => $mode]),
            3,
            10
        );
        $res = $response['body'];
        $code = $response['code'];
        return ($code >= 200 && $code < 300 && $res) ? json_decode($res, true) : null;
    }

    public function getImportStatus(string $jobId): ?array
    {
        $url = $this->getApiUrl() . '/import/jobs/' . $jobId;
        $response = $this->apiRequest(
            'GET',
            $url,
            $this->authHeaders(),
            null,
            3,
            5
        );
        $res = $response['body'];
        $code = $response['code'];
        return ($code >= 200 && $code < 300 && $res) ? json_decode($res, true) : null;
    }

    public function getActiveStoreViews(): ?array
    {
        if ($this->activeStoreViewsCache !== null) {
            return $this->activeStoreViewsCache;
        }

        // 1. Check pushed config first (instant local lookup without network call)
        $saved = $this->scopeConfig->getValue('nexoraiq/general/active_store_views', ScopeInterface::SCOPE_STORE);
        if ($saved !== null && $saved !== '') {
            $decoded = json_decode((string)$saved, true);
            if (is_array($decoded)) {
                $codes = [];
                foreach ($decoded as $item) {
                    if (is_string($item)) {
                        $codes[] = $item;
                    } elseif (is_array($item) && isset($item['code'])) {
                        $codes[] = (string)$item['code'];
                    }
                }
                $this->activeStoreViewsCache = $codes;
                return $this->activeStoreViewsCache;
            }
        }

        // 2. Fallback to API query if no pushed config exists yet
        $url = $this->getApiUrl() . '/integrations/magento/active-store-views?forCron=1';
        $response = $this->apiRequest(
            'GET',
            $url,
            $this->authHeaders(),
            null,
            3,
            5
        );
        $res = $response['body'];
        $code = $response['code'];

        if ($code < 200 || $code >= 300 || !$res) {
            // API failed or not reachable — fallback: allow all active store views
            // to avoid blocking all syncs if NexoraIQ is temporarily down.
            return null;
        }

        $activeCodes = [];
        $data = json_decode($res, true);
        if (isset($data['activeStoreViews']) && is_array($data['activeStoreViews'])) {
            foreach ($data['activeStoreViews'] as $sv) {
                if (isset($sv['code'])) {
                    $activeCodes[] = $sv['code'];
                }
            }
        }
        
        // If API returns an empty array, fallback to null (allow all) to be safe.
        // This prevents blocking all cron syncs if there's a config issue.
        $this->activeStoreViewsCache = !empty($activeCodes) ? $activeCodes : null;
        return $this->activeStoreViewsCache;
    }

    public function notifyExtensionStatusToNexoraIQ(bool $isEnabled): bool
    {
        $connectionKey = $this->getConnectionKey();
        if (empty($connectionKey)) {
            return false;
        }

        $apiUrl = rtrim($this->getApiUrl(), '/');
        $endpointUrl = $apiUrl . '/integrations/extension-status';

        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $productMetadata = $this->productMetadata;

        $payload = (string)json_encode([
            'connectionKey' => $connectionKey,
            'enabled'       => $isEnabled,
            'storeUrl'      => $baseUrl,
            'magentoVersion'=> $productMetadata->getVersion()
        ]);

        $response = $this->apiRequest(
            'POST',
            $endpointUrl,
            ['Content-Type' => 'application/json', 'X-Connection-Key' => $connectionKey],
            $payload,
            5,
            10
        );
        $res = $response['body'];
        $code = $response['code'];

        return ($code >= 200 && $code < 300);
    }

    /**
     * Calls NexorAIQ's connection-login endpoint with the stored connection key. Returns the
     * decoded response (success, autoLoginUrl, store, plan) or ['success' => false, 'message' => ...].
     * The autoLoginUrl carries a short-lived session token, so callers must not cache it.
     */
    public function getConnectionLogin(): array
    {
        $key = $this->getConnectionKey();
        if ($key === '') {
            return ['success' => false, 'message' => __('Add your Connection Key and save the configuration first.')->render()];
        }

        // Production routes have no /api prefix; fall back to it in case the API is mounted there.
        foreach (['/auth/connection-login', '/api/auth/connection-login'] as $path) {
            $response = $this->apiRequest(
                'POST',
                $this->getApiUrl() . $path,
                ['Content-Type' => 'application/json', 'X-Connection-Key' => $key],
                (string)json_encode(['connectionKey' => $key]),
                5,
                15
            );
            $res = $response['body'];
            $code = $response['code'];
            $error = $response['error'];

            if ($code === 404) {
                continue;
            }

            $data = is_string($res) ? json_decode($res, true) : null;
            if ($code >= 200 && $code < 300 && is_array($data) && !empty($data['success'])) {
                return $data;
            }

            $message = is_array($data) && !empty($data['message'])
                ? (is_array($data['message']) ? implode(' ', $data['message']) : (string)$data['message'])
                : ($error !== '' ? $error : __('NexorAIQ returned HTTP %1.', $code)->render());
            $this->logger->warning('NexorAIQ connection-login failed (HTTP ' . $code . '): ' . $message);

            return ['success' => false, 'message' => $message];
        }

        return ['success' => false, 'message' => __('The plan service is not available on the NexorAIQ API yet.')->render()];
    }
}
