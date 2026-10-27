<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\ViewModel;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Search\Helper\Data as SearchHelper;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Swatches\Helper\Data as SwatchHelper;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Magento\Swatches\Model\Swatch;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\AddToCartConfig;
use NexorAIQ\Connector\Model\Config\Source\SearchPagePerPage;
use NexorAIQ\Connector\Model\SearchPageConfig;

/**
 * Supplies the search results page settings to its Alpine store (search/page.phtml).
 */
class SearchPage implements ArgumentInterface
{
    public function __construct(
        private readonly HelperData $helper,
        private readonly SearchPageConfig $searchPageConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly SearchHelper $searchHelper,
        private readonly Json $json,
        private readonly AddToCartConfig $addToCartConfig,
        private readonly SearchPagePerPage $perPageSource,
        private readonly AttributeCollectionFactory $attributeCollectionFactory,
        private readonly SwatchHelper $swatchHelper,
        private readonly SwatchMedia $swatchMedia
    ) {
    }

    public function getApiOrigin(): string
    {
        $parts = parse_url($this->helper->getApiUrl($this->getStoreId()));
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public function isFiltersEnabled(): bool
    {
        return $this->searchPageConfig->isFiltersEnabled();
    }

    public function isAddToCartEnabled(): bool
    {
        return $this->addToCartConfig->isSearchPageEnabled();
    }

    /**
     * "Show N per page" options: the admin choices plus the configured default.
     *
     * @return int[]
     */
    public function getLimits(): array
    {
        $limits = array_map(static fn (array $option) => (int)$option['value'], $this->perPageSource->toOptionArray());
        $limits[] = $this->searchPageConfig->getPerPage();
        $limits = array_unique($limits);
        sort($limits);
        return array_values($limits);
    }

    /**
     * Settings consumed by the Alpine store. The searchCatalog query is called from the browser with
     * the public, read-only search key; the store code follows the current store view.
     */
    public function getJsConfig(): string
    {
        return $this->json->serialize($this->getJsConfigData());
    }

    /**
     * Settings consumed by the search results page component (js/search-page.js).
     */
    public function getJsConfigData(): array
    {
        $store = $this->storeManager->getStore();
        $storeId = (int)$store->getId();

        return [
            'graphqlUrl'    => $this->helper->getApiUrl($storeId) . '/graphql',
            'searchKey'     => $this->helper->getSearchKey($storeId),
            'storeCode'     => $store->getCode(),
            'storeView'     => $store->getCode(),
            'searchType'    => $this->helper->getSearchType($storeId),
            'perPage'       => $this->searchPageConfig->getPerPage($storeId),
            'limits'        => $this->getLimits(),
            'hasFilter'     => $this->searchPageConfig->isFiltersEnabled($storeId),
            'queryParam'    => $this->searchHelper->getQueryParamName(),
            'filterable'    => $this->getFilterableAttributes(),
            'swatches'      => $this->getSwatches(),
            'trackEventUrl' => $this->urlBuilder->getUrl('', ['_direct' => 'rest/' . $store->getCode() . '/V1/nexoraiq/analytics/event']),
            'currencyCode'  => $store->getCurrentCurrencyCode(),
            'addToCart'     => $this->isAddToCartEnabled(),
            'cart'          => $this->addToCartConfig->getJsConfig(),
        ];
    }

    /**
     * Attributes shown as filters, as on the category page: those set to "Use in Layered Navigation" or
     * "Use in Search Results Layered Navigation", in layered navigation position order, keyed by code, with
     * their store view label, their option labels (lower-cased) in admin sort order and an option ID => label
     * map (some indexed products carry multi-select values as option IDs).
     *
     * @return array<string, array{label: string, options: string[], ids: array<string, string>}>
     */
    public function getFilterableAttributes(): array
    {
        $storeId = $this->getStoreId();
        $attributes = $this->attributeCollectionFactory->create()
            ->addFieldToFilter(
                ['additional_table.is_filterable', 'additional_table.is_filterable_in_search'],
                [['gt' => 0], ['gt' => 0]]
            )
            ->setOrder('additional_table.position', 'ASC');

        $result = [];
        /** @var Attribute $attribute */
        foreach ($attributes as $attribute) {
            if ($attribute->getAttributeCode() === 'price') {
                continue; // the price range filter comes from the searchCatalog aggregation
            }
            $label = $attribute->getStoreLabel($storeId) ?: $attribute->getFrontendLabel() ?: $attribute->getAttributeCode();
            $options = [];
            $ids = [];
            if ($attribute->usesSource()) {
                $attribute->setStoreId($storeId);
                foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                    $optionLabel = trim((string)$option['label']);
                    $options[] = mb_strtolower($optionLabel);
                    if (is_numeric($option['value'])) {
                        $ids[(string)$option['value']] = $optionLabel;
                    }
                }
            }
            $result[$attribute->getAttributeCode()] = [
                'label' => (string)$label,
                'options' => $options,
                'ids' => (object)$ids,
            ];
        }

        return $result;
    }

    /**
     * Swatches of the filterable swatch attributes (color, size, ...), keyed by attribute code and then by
     * lower-cased option label (store and admin label), so API facet values render like the category page.
     *
     * @return array<string, array<string, array{type: string, value: string}>>
     */
    public function getSwatches(): array
    {
        $storeId = $this->getStoreId();
        $attributes = $this->attributeCollectionFactory->create()
            ->addIsFilterableFilter()
            ->addFieldToFilter('additional_data', ['like' => '%swatch_input_type%']);

        $result = [];
        /** @var Attribute $attribute */
        foreach ($attributes as $attribute) {
            if (!$this->swatchHelper->isSwatchAttribute($attribute)) {
                continue;
            }
            $labels = [];
            foreach ([0, $storeId] as $labelStoreId) {
                $attribute->setStoreId($labelStoreId);
                foreach ($attribute->getSource()->getAllOptions(false, $labelStoreId === 0) as $option) {
                    if ($option['value'] !== '' && $option['value'] !== null) {
                        $labels[(int)$option['value']][] = mb_strtolower(trim((string)$option['label']));
                    }
                }
            }
            $swatches = $this->swatchHelper->getSwatchesByOptionsId(array_keys($labels));
            foreach ($labels as $optionId => $optionLabels) {
                if (!isset($swatches[$optionId])) {
                    continue;
                }
                $type = (int)$swatches[$optionId]['type'];
                $value = (string)$swatches[$optionId]['value'];
                $swatch = match ($type) {
                    Swatch::SWATCH_TYPE_VISUAL_COLOR => ['type' => 'color', 'value' => $value],
                    Swatch::SWATCH_TYPE_VISUAL_IMAGE => [
                        'type' => 'image',
                        'value' => $this->swatchMedia->getSwatchAttributeImage('swatch_image', $value),
                    ],
                    default => ['type' => 'text', 'value' => $value],
                };
                foreach (array_unique($optionLabels) as $label) {
                    $result[$attribute->getAttributeCode()][$label] = $swatch;
                }
            }
        }

        return $result;
    }

    private function getStoreId(): int
    {
        return (int)$this->storeManager->getStore()->getId();
    }
}
