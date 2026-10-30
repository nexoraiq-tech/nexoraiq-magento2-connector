<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Api;

/**
 * Receives the active store views list pushed by NexoraIQ whenever
 * a customer activates or deactivates a store view in their dashboard,
 * or when a payment completes during onboarding.
 *
 * POST /V1/nexoraiq/active-store-views
 * Body: { "key": "<connectionKey>", "activeStoreViews": [{"code":"en","name":"English"}, ...] }
 */
interface ActiveStoreViewsInterface
{
    /**
     * Receives and persists the active store views list from NexoraIQ.
     *
     * @param string $key             The NexoraIQ connection key (auth).
     * @param mixed $activeStoreViews  Array of {code, name} objects or store view codes.
     * @return string JSON response.
     * @throws \Magento\Framework\Webapi\Exception When the connection key is wrong (HTTP 401)
     */
    public function update(string $key, $activeStoreViews): string;
}
