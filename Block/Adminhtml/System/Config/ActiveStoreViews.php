<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class ActiveStoreViews extends Field
{
    private const CONFIG_PATH_ACTIVE_STORE_VIEWS = 'nexoraiq/general/active_store_views';

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Render active store views grouped by Website & Store as read-only elements.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $saved = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_ACTIVE_STORE_VIEWS,
            ScopeInterface::SCOPE_STORE
        );

        if ($saved === '') {
            return '<div class="control-value" style="padding-top:7px; color:#666; font-style:italic;">No active store views configured. (All active store views will be synced by default)</div>';
        }

        $decoded = json_decode($saved, true);
        if (!is_array($decoded) || empty($decoded)) {
            return '<div class="control-value" style="padding-top:7px; color:#666; font-style:italic;">No active store views configured. (All active store views will be synced by default)</div>';
        }

        // Map all store views by code with Website & Store Group info
        $allStoresByCode = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            if ((int) $store->getId() === 0) {
                continue;
            }
            $websiteName = (string) $store->getWebsite()->getName();
            $groupName = (string) $store->getGroup()->getName();

            $allStoresByCode[$store->getCode()] = [
                'code' => $store->getCode(),
                'name' => $store->getName(),
                'website' => $websiteName,
                'group' => $groupName,
            ];
        }

        // Group active store views by Website + Store Group
        $grouped = [];
        foreach ($decoded as $item) {
            $code = '';
            $customName = '';
            if (is_string($item)) {
                $code = trim($item);
            } elseif (is_array($item) && isset($item['code'])) {
                $code = trim((string) $item['code']);
                if (isset($item['name'])) {
                    $customName = trim((string) $item['name']);
                }
            }

            if ($code === '') {
                continue;
            }

            $storeInfo = $allStoresByCode[$code] ?? null;
            $websiteName = $storeInfo ? $storeInfo['website'] : 'Default Website';
            $groupName = $storeInfo ? $storeInfo['group'] : 'Default Store';
            $viewName = $customName !== '' ? $customName : ($storeInfo ? $storeInfo['name'] : $code);

            $groupKey = $websiteName . ' /// ' . $groupName;
            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'website' => $websiteName,
                    'group' => $groupName,
                    'views' => [],
                ];
            }

            $grouped[$groupKey]['views'][] = [
                'code' => $code,
                'name' => $viewName,
            ];
        }

        if (empty($grouped)) {
            return '<div class="control-value" style="padding-top:7px; color:#666; font-style:italic;">No active store views configured. (All active store views will be synced by default)</div>';
        }

        $html = '<div class="control-value" style="padding-top:4px; max-width:650px;">';
        $html .= '<div style="display:flex; flex-direction:column; gap:8px;">';

        foreach ($grouped as $group) {
            $websiteEscaped = $this->escapeHtml($group['website']);
            $groupEscaped = $this->escapeHtml($group['group']);

            $html .= '<div style="background:#f8f9fa; border:1px solid #e3e8ee; border-radius:6px; padding:8px 12px;">';
            $html .= sprintf(
                '<div style="font-weight:600; font-size:12px; color:#2d3748; margin-bottom:6px;">%s <span style="color:#a0aec0; margin:0 4px;">&rsaquo;</span> %s</div>',
                $websiteEscaped,
                $groupEscaped
            );
            $html .= '<div style="display:flex; flex-wrap:wrap; gap:6px;">';

            foreach ($group['views'] as $view) {
                $viewNameEscaped = $this->escapeHtml($view['name']);
                $codeEscaped = $this->escapeHtml($view['code']);

                $html .= sprintf(
                    '<span style="background-color:#e8f4fd; color:#006699; border:1px solid #bce8f1; padding:3px 8px; border-radius:4px; font-size:12px; font-weight:500;">%s <span style="opacity:0.75; font-size:11px;">(%s)</span></span>',
                    $viewNameEscaped,
                    $codeEscaped
                );
            }

            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div></div>';

        return $html;
    }
}
