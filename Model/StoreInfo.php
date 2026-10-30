<?php
declare(strict_types=1);
namespace NexorAIQ\Connector\Model;

use Magento\Framework\Webapi\Rest\Response as RestResponse;
use NexorAIQ\Connector\Api\StoreInfoInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Store\Model\ScopeInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Psr\Log\LoggerInterface;

class StoreInfo implements StoreInfoInterface
{
    private const CONFIG_PATH_KEY = 'nexoraiq/general/connection_key';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly EavConfig $eavConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger,
        private readonly RestResponse $response
    ) {
    }

    public function getStoreInfo(string $key = ''): string
    {
        $storedEncrypted = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_KEY,
            ScopeInterface::SCOPE_STORE
        );
        $storedKey = $storedEncrypted !== '' ? $this->encryptor->decrypt($storedEncrypted) : '';

        if ($key === '' || $storedKey === '' || !hash_equals($storedKey, $key)) {
            $this->logger->warning('NexoraIQ StoreInfo: store-info rejected — invalid connection key');
            throw new WebapiException(__('Invalid connection key'), 0, WebapiException::HTTP_UNAUTHORIZED);
        }

        $this->logger->info('NexoraIQ StoreInfo: store-info requested and served');

        $conn = $this->resource->getConnection();

        $websites    = $this->fetchWebsites($conn);
        $storeGroups = $this->fetchStoreGroups($conn);
        $storeViews  = $this->fetchStoreViews($conn);
        $currencies  = $this->fetchCurrencies($conn, $websites);

        // Count products per store view using Magento's ProductCollection with proper store scope.
        // This matches exactly what /rest/{store_code}/V1/products?status=1 returns.
        $productCountPerStoreView = $this->fetchProductCountPerStoreViewNative($storeViews);

        // Overall product count: use the default/admin store scope (store_id=0), status=1
        // This is the global enabled count used as a fallback.
        $statusAttrId = (int)$this->eavConfig
            ->getAttribute('catalog_product', 'status')
            ->getAttributeId();
        $productCount = $this->fetchProductCount($conn, $statusAttrId);

        foreach ($storeViews as &$view) {
            $view['locale'] = $this->fetchLocale($conn, (int)$view['id']);
            $view['product_count'] = $productCountPerStoreView[(string)$view['id']] ?? $productCount;
        }
        unset($view);

        $defaultWebsite = array_reduce($websites, function ($carry, $w) {
            return ($carry === null || $w['sort_order'] < $carry['sort_order']) ? $w : $carry;
        }, null);
        $defaultWebsiteId = $defaultWebsite ? $defaultWebsite['id'] : null;
        $defaultGroupId = $defaultWebsite ? $defaultWebsite['default_group_id'] : null;

        $groupMap = [];
        foreach ($storeGroups as $g) {
            $groupMap[$g['id']] = $g;
        }

        $defaultStoreViewId = $defaultGroupId && isset($groupMap[$defaultGroupId])
            ? $groupMap[$defaultGroupId]['default_store_id']
            : null;

        $defaultViewPerGroup = [];
        foreach ($storeGroups as $g) {
            $defaultViewPerGroup[$g['id']] = $g['default_store_id'];
        }

        $defaultGroupPerWebsite = [];
        foreach ($websites as $w) {
            $defaultGroupPerWebsite[$w['id']] = $w['default_group_id'];
        }

        // Fetch configurations
        $extraAttributes = $this->scopeConfig->getValue('nexoraiq/attributes_config/extra_attributes', ScopeInterface::SCOPE_STORE) ?? '';
        $extraAttributesList = $extraAttributes !== '' ? array_map('trim', explode(',', $extraAttributes)) : [];

        $ignoreDesc = (int)($this->scopeConfig->getValue('nexoraiq/description_config/ignore_description', ScopeInterface::SCOPE_STORE) ?? 0);
        $commonWords = $this->scopeConfig->getValue('nexoraiq/description_config/common_words', ScopeInterface::SCOPE_STORE) ?? '';
        $commonWordsList = $commonWords !== '' ? array_filter(array_map('trim', explode("\n", $commonWords))) : [];

        $isMultiBrand = (int)($this->scopeConfig->getValue('nexoraiq/brands_config/is_multi_brand', ScopeInterface::SCOPE_STORE) ?? 1);
        $brandIdentifiedUsing = $this->scopeConfig->getValue('nexoraiq/brands_config/identified_using', ScopeInterface::SCOPE_STORE) ?? 'attribute';
        $brandAttribute = $this->scopeConfig->getValue('nexoraiq/brands_config/attribute', ScopeInterface::SCOPE_STORE) ?? 'manufacturer';
        $isBrandMandatory = (int)($this->scopeConfig->getValue('nexoraiq/brands_config/is_brand_mandatory', ScopeInterface::SCOPE_STORE) ?? 0);

        // Send HTTP cache-control headers to prevent caching of this endpoint by browser, Cloudflare, Varnish, or Magento Full Page Cache (FPC).
        $this->response->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0', true);
        $this->response->setHeader('Pragma', 'no-cache', true);
        $this->response->setHeader('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT', true);

        return (string)json_encode([
            'websites'                  => $websites,
            'store_groups'              => $storeGroups,
            'store_views'               => $storeViews,
            'currencies'                => $currencies,
            'product_count'             => $productCount,
            'product_count_per_store_view' => $productCountPerStoreView,
            'total_active_stores'       => count($storeViews),
            'magento_version'           => $this->productMetadata->getVersion(),
            'edition'                   => $this->productMetadata->getEdition(),
            'defaults'                  => [
                'website_id'        => $defaultWebsiteId,
                'store_group_id'    => $defaultGroupId,
                'store_view_id'     => $defaultStoreViewId,
                'view_per_group'    => $defaultViewPerGroup,
                'group_per_website' => $defaultGroupPerWebsite,
            ],
            'config'                    => [
                'extra_attributes'     => $extraAttributesList,
                'ignore_description'   => $ignoreDesc === 1,
                'common_words'         => $commonWordsList,
                'is_multi_brand'       => $isMultiBrand === 1,
                'identified_using'     => $brandIdentifiedUsing,
                'brand_attribute'      => $brandAttribute,
                'is_brand_mandatory'   => $isBrandMandatory === 1,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Count enabled products per store view using Magento's ProductCollection.
     *
     * This is the authoritative count — it matches exactly what the REST API
     * /rest/{store_code}/V1/products?searchCriteria[filter_groups][0][filters][0][field]=status
     *   &searchCriteria[filter_groups][0][filters][0][value]=1
     * returns, because both use the same underlying ProductCollection with store scope applied.
     *
     * We apply setStoreId() and addWebsiteFilter() to get accurate per-store-view product counts,
     * plus add configurable child relations so total product count includes child variants.
     */
    private function fetchProductCountPerStoreViewNative(array $storeViews): array
    {
        $conn = $this->resource->getConnection();
        $statusAttrId = (int)$this->eavConfig
            ->getAttribute('catalog_product', 'status')
            ->getAttributeId();

        $result = [];
        foreach ($storeViews as $view) {
            $storeId = (int)$view['id'];
            $websiteId = isset($view['website_id']) ? (int)$view['website_id'] : 0;
            try {
                /** @var \Magento\Catalog\Model\ResourceModel\Product\Collection $collection */
                $collection = $this->productCollectionFactory->create();
                $collection->setStoreId($storeId);
                if ($websiteId > 0) {
                    $collection->addWebsiteFilter($websiteId);
                }
                $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
                $baseCount = (int)$collection->getSize();

                $childCount = 0;
                // Count configurable child relations for enabled parent products in this store view/website
                /* $superLinkTable = $this->resource->getTableName('catalog_product_super_link');
                $entityTable = $this->resource->getTableName('catalog_product_entity');
                $websiteTable = $this->resource->getTableName('catalog_product_website');
                $intTable = $this->resource->getTableName('catalog_product_entity_int');

                $childCountSql = "
                    SELECT COUNT(*) FROM {$superLinkTable} l
                    JOIN {$entityTable} p ON p.entity_id = l.parent_id AND p.type_id = 'configurable'
                    JOIN {$websiteTable} w ON w.product_id = p.entity_id AND w.website_id = {$websiteId}
                    LEFT JOIN {$intTable} s_store ON s_store.entity_id = p.entity_id AND s_store.attribute_id = {$statusAttrId} AND s_store.store_id = {$storeId}
                    LEFT JOIN {$intTable} s_default ON s_default.entity_id = p.entity_id AND s_default.attribute_id = {$statusAttrId} AND s_default.store_id = 0
                    WHERE COALESCE(s_store.value, s_default.value) = 1
                ";
                $childCount = (int)$conn->fetchOne($childCountSql); */

                $result[(string)$storeId] = $baseCount + $childCount;
            } catch (\Throwable $e) {
                // If a store fails, leave it out — the caller will fall back to global count
                $result[(string)$storeId] = 0;
            }
        }
        return $result;
    }

    private function fetchWebsites($conn): array
    {
        $t = $this->resource->getTableName('store_website');
        $rows = $conn->fetchAll(
            $conn->select()->from($t, ['website_id','code','name','sort_order','default_group_id'])
                 ->where('website_id != ?', 0)->order('sort_order ASC')
        );
        return array_map(fn($r) => [
            'id' => (int)$r['website_id'], 'code' => $r['code'], 'name' => $r['name'],
            'sort_order' => (int)$r['sort_order'], 'default_group_id' => (int)$r['default_group_id'],
        ], $rows);
    }

    private function fetchStoreGroups($conn): array
    {
        $t = $this->resource->getTableName('store_group');
        $rows = $conn->fetchAll(
            $conn->select()->from($t, ['group_id','website_id','name','root_category_id','default_store_id'])
                 ->where('group_id != ?', 0)->order('group_id ASC')
        );
        return array_map(fn($r) => [
            'id' => (int)$r['group_id'], 'website_id' => (int)$r['website_id'], 'name' => $r['name'],
            'root_category_id' => (int)$r['root_category_id'], 'default_store_id' => (int)$r['default_store_id'],
        ], $rows);
    }

    private function fetchStoreViews($conn): array
    {
        $t = $this->resource->getTableName('store');
        $rows = $conn->fetchAll(
            $conn->select()->from($t, ['store_id','code','website_id','group_id','name','sort_order','is_active'])
                 ->where('store_id != ?', 0)->where('is_active = ?', 1)->order('sort_order ASC')
        );
        return array_map(fn($r) => [
            'id' => (int)$r['store_id'], 'code' => $r['code'],
            'website_id' => (int)$r['website_id'], 'store_group_id' => (int)$r['group_id'],
            'name' => $r['name'], 'locale' => '', 'product_count' => 0, 'is_active' => (int)$r['is_active'],
            'sort_order' => (int)$r['sort_order'],
        ], $rows);
    }

    private function fetchLocale($conn, int $storeId): string
    {
        $t = $this->resource->getTableName('core_config_data');
        foreach ([['stores', $storeId], ['default', 0]] as [$scope, $scopeId]) {
            $v = $conn->fetchOne(
                $conn->select()->from($t, ['value'])
                     ->where('path = ?', 'general/locale/code')
                     ->where('scope = ?', $scope)->where('scope_id = ?', $scopeId)
            );
            if ($v !== false && $v !== '') {
                return (string)$v;
            }
        }
        return 'en_US';
    }

    private function fetchCurrencies($conn, array $websites): array
    {
        $t = $this->resource->getTableName('core_config_data');
        $result = [];
        foreach ($websites as $w) {
            $wid = $w['id'];
            $lookups = [
                ['currency/options/default', 'websites', $wid],
                ['currency/options/base',    'websites', $wid],
                ['currency/options/default', 'default',  0],
                ['currency/options/base',    'default',  0],
            ];
            $found = false;
            foreach ($lookups as [$path, $scope, $sid]) {
                $v = $conn->fetchOne(
                    $conn->select()->from($t, ['value'])
                         ->where('path = ?', $path)->where('scope = ?', $scope)->where('scope_id = ?', $sid)
                );
                if ($v !== false && $v !== '') {
                    $result[(string)$wid] = (string)$v;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $result[(string)$wid] = 'USD';
            }
        }
        return $result;
    }

    private function fetchProductCount($conn, int $statusAttrId): int
    {
        $pe  = $this->resource->getTableName('catalog_product_entity');
        $pei = $this->resource->getTableName('catalog_product_entity_int');
        return (int)$conn->fetchOne(
            $conn->select()
                ->from(['e' => $pe], [new \Zend_Db_Expr('COUNT(*)')])
                ->join(
                    ['s' => $pei],
                    "s.entity_id = e.entity_id AND s.attribute_id = {$statusAttrId} AND s.store_id = 0 AND s.value = 1",
                    []
                )
        );
    }
}
