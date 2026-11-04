<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */
declare(strict_types=1);

namespace NexorAIQ\Connector\Cron;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\SpecialPrice\ScheduledChangeFinder;
use NexorAIQ\Connector\Model\SyncQueueManager;
use Psr\Log\LoggerInterface;

/**
 * Queues products whose special price expired or started, per store view timezone.
 *
 * Magento keeps a special price valid through the whole To date and starts it at the
 * beginning of the From date. When a store view's local date moves from H to T, products
 * with special_to_date in [H, T-1] just lost their special price and products with
 * special_from_date in [H+1, T] just got one. The last handled local date per store view is
 * kept in a flag, so each change is queued once and days missed while cron was down are
 * caught up on the next run.
 */
class SpecialPriceQueue
{
    public const FLAG_CODE = 'nexoraiq_special_price_last_date';
    private const MAX_CATCH_UP_DAYS = 31;

    public function __construct(
        private readonly HelperData $helper,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone,
        private readonly FlagManager $flagManager,
        private readonly ScheduledChangeFinder $finder,
        private readonly SyncQueueManager $syncQueueManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->helper->isEnabled() || !$this->helper->isRealtimeEnabled()) {
            return;
        }

        $lastDates = $this->flagManager->getFlagData(self::FLAG_CODE);
        $lastDates = is_array($lastDates) ? $lastDates : [];
        $changed = false;
        $productIds = [];

        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->isActive()) {
                continue;
            }
            $storeId = (int)$store->getId();

            try {
                $today = $this->timezone->scopeDate($store, null, true);
                $todayStr = $today->format('Y-m-d');
                $last = isset($lastDates[$storeId]) ? (string)$lastDates[$storeId] : '';
                if ($last === $todayStr) {
                    continue;
                }

                $earliest = (clone $today)->modify('-' . self::MAX_CATCH_UP_DAYS . ' days')->format('Y-m-d');
                if ($last === '' || $last > $todayStr) {
                    // First run (or clock moved back): handle only the change at the start of today.
                    $last = (clone $today)->modify('-1 day')->format('Y-m-d');
                } elseif ($last < $earliest) {
                    $last = $earliest;
                }

                $lastDate = new \DateTime($last);
                $yesterday = (clone $today)->modify('-1 day')->format('Y-m-d');
                $dayAfterLast = (clone $lastDate)->modify('+1 day')->format('Y-m-d');

                $expired = $this->finder->findProductIds('special_to_date', $last, $yesterday, $storeId);
                $started = $this->finder->findProductIds('special_from_date', $dayAfterLast, $todayStr, $storeId);

                if ($expired || $started) {
                    $this->logger->info(sprintf(
                        'NexoraIQ SpecialPriceQueue: store %s (%s): %d expired (to %s..%s), %d started (from %s..%s)',
                        $store->getCode(),
                        $todayStr,
                        count($expired),
                        $last,
                        $yesterday,
                        count($started),
                        $dayAfterLast,
                        $todayStr
                    ));
                }
                foreach (array_merge($expired, $started) as $id) {
                    $productIds[$id] = $id;
                }

                $lastDates[$storeId] = $todayStr;
                $changed = true;
            } catch (\Throwable $e) {
                // Leave this store's date unchanged so the next run retries it.
                $this->logger->error(sprintf(
                    'NexoraIQ SpecialPriceQueue failed for store %s: %s',
                    $store->getCode(),
                    $e->getMessage()
                ));
            }
        }

        if ($productIds) {
            $this->syncQueueManager->enqueueBatch(array_values($productIds));
        }
        if ($changed) {
            $this->flagManager->saveFlag(self::FLAG_CODE, $lastDates);
        }
    }
}
