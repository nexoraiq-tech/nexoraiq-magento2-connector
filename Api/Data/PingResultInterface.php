<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Api\Data;

interface PingResultInterface
{
    /**
     * @return string
     */
    public function getStatus(): string;

    /**
     * @return string
     */
    public function getExtension(): string;

    /**
     * @return string
     */
    public function getVersion(): string;

    /**
     * @return string
     */
    public function getPlatform(): string;

    /**
     * @return string
     */
    public function getMagentoVersion(): string;

    /**
     * @return string
     */
    public function getEdition(): string;

    /**
     * @return bool
     */
    public function getConnectionKeyConfigured(): bool;

    /**
     * @return bool
     */
    public function getConnectionKeyValid(): bool;

    /**
     * @return bool
     */
    public function getCatalogAccessible(): bool;

    /**
     * @return int
     */
    public function getProductCount(): int;

    /**
     * @return int
     */
    public function getStoreCount(): int;

    /**
     * The NexorAIQ | AI Commerce Search OAuth access token.
     * Only populated when connection_key_valid is true — null otherwise.
     *
     * @return string|null
     */
    public function getAccessToken(): ?string;

    /**
     * @return string|null
     */
    public function getCronDiagnostics(): ?string;
}
