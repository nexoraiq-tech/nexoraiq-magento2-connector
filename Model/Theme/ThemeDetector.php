<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */
declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Theme;

use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Design\ThemeInterface;
use Magento\Framework\View\DesignInterface;

/**
 * Tells whether the storefront theme assigned in the admin (Content > Design > Configuration)
 * is a Hyvä theme. Decides which storefront UI the connector renders:
 * Hyvä (Alpine.js templates) or Luma / Blank / any other theme (RequireJS + jQuery widgets).
 */
class ThemeDetector
{
    private const HYVA_THEMES_SERVICE = 'Hyva\Theme\Service\HyvaThemes';

    /** @var array<string, bool> */
    private array $cache = [];

    /**
     * Hyvä is optional, so its HyvaThemes service cannot be a constructor dependency; the object
     * manager resolves it only after class_exists() confirms Hyvä is installed.
     *
     * @param DesignInterface $design
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        private readonly DesignInterface $design,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    public function isHyva(?ThemeInterface $theme = null): bool
    {
        $theme = $theme ?? $this->design->getDesignTheme();
        $key = (string)($theme->getId() ?: $theme->getCode());
        if (!isset($this->cache[$key])) {
            $this->cache[$key] = $this->detect($theme);
        }
        return $this->cache[$key];
    }

    private function detect(ThemeInterface $theme): bool
    {
        // Hyvä's own check also covers child themes registered as Hyvä base themes.
        if (class_exists(self::HYVA_THEMES_SERVICE)) {
            try {
                return (bool)$this->objectManager->get(self::HYVA_THEMES_SERVICE)->isHyvaTheme($theme);
            } catch (\Throwable) {
                // fall through to the theme hierarchy check
            }
        }
        // Without the Hyvä module: a theme is Hyvä when it, or one of its parents, is a Hyva/* theme.
        for ($current = $theme, $depth = 0; $current && $depth < 20; $current = $current->getParentTheme(), $depth++) {
            if (str_starts_with((string)$current->getCode(), 'Hyva/')) {
                return true;
            }
        }
        return false;
    }
}
