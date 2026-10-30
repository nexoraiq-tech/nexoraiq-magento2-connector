<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Inventory;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Api\Data\WebsiteInterface;

/**
 * Salable quantity from Multi-Source Inventory (MSI), when MSI is installed.
 *
 * MSI is optional (composer "suggest"): stores can remove it, so its interfaces cannot be
 * constructor dependencies of this module, and a class that type-hints them would break
 * setup:di:compile on those stores. This adapter is the single place that resolves the MSI
 * services, and only after checking that the MSI modules are enabled.
 */
class SalableQtyProvider
{
    private const MSI_MODULE = 'Magento_InventorySalesApi';
    private const GET_SALABLE_QTY = 'Magento\InventorySalesApi\Api\GetProductSalableQtyInterface';
    private const STOCK_RESOLVER = 'Magento\InventorySalesApi\Api\StockResolverInterface';
    private const SALES_CHANNEL_WEBSITE = 'website';

    /**
     * @var array<string, int> Stock ID per website code
     */
    private array $stockIds = [];

    /**
     * @param ObjectManagerInterface $objectManager
     * @param ModuleManager $moduleManager
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ModuleManager $moduleManager
    ) {
    }

    /**
     * Whether MSI is installed and enabled.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->moduleManager->isEnabled(self::MSI_MODULE)
            && interface_exists(self::GET_SALABLE_QTY);
    }

    /**
     * Salable quantity of a SKU on the stock assigned to a website, or null when MSI is not available.
     *
     * @param string $sku
     * @param WebsiteInterface|string $website Website or website code
     * @return float|null
     * @throws \Throwable When MSI cannot resolve the SKU (e.g. composite products)
     */
    public function get(string $sku, $website): ?float
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stockId = $this->getStockId(is_string($website) ? $website : (string)$website->getCode());
        return (float)$this->objectManager->get(self::GET_SALABLE_QTY)->execute($sku, $stockId);
    }

    /**
     * Stock ID assigned to a website.
     *
     * @param string $websiteCode
     * @return int
     */
    private function getStockId(string $websiteCode): int
    {
        if (!isset($this->stockIds[$websiteCode])) {
            $stock = $this->objectManager->get(self::STOCK_RESOLVER)
                ->execute(self::SALES_CHANNEL_WEBSITE, $websiteCode);
            $this->stockIds[$websiteCode] = (int)$stock->getStockId();
        }
        return $this->stockIds[$websiteCode];
    }
}
