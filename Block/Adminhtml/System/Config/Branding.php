<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Renderer\RendererInterface;
use Magento\Framework\Filesystem\Driver\File;
use NexorAIQ\Connector\Helper\Data as HelperData;

/**
 * Branding header shown above "General Settings" on Stores > Configuration > NexorAIQ > General.
 * Uses local data only (config + sync tables), so it never slows the page down with API calls.
 */
class Branding extends Template implements RendererInterface
{
    public const WEBSITE_URL = 'https://nexoraiq.tech';

    protected $_template = 'NexorAIQ_Connector::system/config/branding.phtml';

    private ?array $syncStats = null;

    public function __construct(
        Context $context,
        private readonly HelperData $helper,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly File $file,
        private readonly ResourceConnection $resource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        return $this->toHtml();
    }

    public function getLogoUrl(): string
    {
        return $this->getViewFileUrl('NexorAIQ_Connector::images/nexoraiq-logo-animated-transparent.svg');
    }

    public function getModuleVersion(): string
    {
        try {
            $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'NexorAIQ_Connector');
            $composer = json_decode((string)$this->file->fileGetContents($path . '/composer.json'), true);
            return (string)($composer['version'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function getMagentoVersion(): string
    {
        return trim($this->productMetadata->getName() . ' ' . $this->productMetadata->getVersion());
    }

    public function isExtensionEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag('nexoraiq/general/enabled');
    }

    public function isConnected(): bool
    {
        return $this->helper->getConnectionKey() !== '';
    }

    public function isRealtimeEnabled(): bool
    {
        return $this->helper->isRealtimeEnabled();
    }

    /**
     * Names of the store views activated from the NexorAIQ dashboard (read from saved config only).
     */
    public function getActiveStoreViewNames(): array
    {
        $raw = (string)$this->_scopeConfig->getValue('nexoraiq/general/active_store_views');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $names = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $names[] = $item;
            } elseif (is_array($item)) {
                $names[] = (string)($item['name'] ?? $item['code'] ?? '');
            }
        }
        return array_values(array_filter($names));
    }

    /**
     * @return array{products:int, last:?string, queued:int}
     */
    public function getSyncStats(): array
    {
        if ($this->syncStats !== null) {
            return $this->syncStats;
        }
        $stats = ['products' => 0, 'last' => null, 'queued' => 0];
        try {
            $connection = $this->resource->getConnection();
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->resource->getTableName('nexoraiq_product_sync_status'), [
                        'products' => new \Zend_Db_Expr('COUNT(DISTINCT product_id)'),
                        'last'     => new \Zend_Db_Expr('MAX(last_synced_at)'),
                    ])
                    ->where('is_synced = ?', 1)
            );
            $stats['products'] = (int)($row['products'] ?? 0);
            $stats['last'] = $row['last'] ?? null;
            $stats['queued'] = (int)$connection->fetchOne(
                $connection->select()->from($this->resource->getTableName('nexoraiq_sync_queue'), 'COUNT(*)')
            );
        } catch (\Throwable $e) {
            // Tables missing (module not upgraded yet): show zeros.
        }
        return $this->syncStats = $stats;
    }

    public function formatLastSync(?string $value): string
    {
        if (!$value) {
            return (string)__('Never');
        }
        return $this->_localeDate->formatDateTime(
            new \DateTime($value),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }
}
