<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */
declare(strict_types=1);

namespace NexorAIQ\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\Layout;
use NexorAIQ\Connector\Model\Theme\ThemeDetector;

/**
 * Counterpart of Hyvä's hyva_* layout handles: when the current theme is NOT a Hyvä theme,
 * every layout handle gets a "nexoraiq_luma_" copy (e.g. nexoraiq_luma_default), so the
 * Luma storefront UI is only loaded on Luma / Blank / other themes and the Hyvä UI
 * (hyva_default.xml, ...) only on Hyvä themes.
 *
 * @event layout_load_before
 */
class AddLumaLayoutHandles implements ObserverInterface
{
    public const HANDLE_PREFIX = 'nexoraiq_luma_';

    public function __construct(
        private readonly ThemeDetector $themeDetector
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var Layout $layout */
        $layout = $observer->getData('layout');
        if (!$layout || $this->themeDetector->isHyva()) {
            return;
        }
        $update = $layout->getUpdate();
        foreach ($update->getHandles() as $handle) {
            if (!str_starts_with($handle, self::HANDLE_PREFIX) && !str_starts_with($handle, 'hyva_')) {
                $update->addHandle(self::HANDLE_PREFIX . $handle);
            }
        }
    }
}
