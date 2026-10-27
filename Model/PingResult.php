<?php

/**
 * Copyright © 2025 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use NexorAIQ\Connector\Api\Data\PingResultInterface;

class PingResult implements PingResultInterface
{
    public function __construct(
        private readonly string  $status,
        private readonly string  $extension,
        private readonly string  $version,
        private readonly string  $platform,
        private readonly string  $magentoVersion,
        private readonly string  $edition,
        private readonly bool    $connectionKeyConfigured,
        private readonly bool    $connectionKeyValid,
        private readonly bool    $catalogAccessible,
        private readonly int     $productCount,
        private readonly int     $storeCount,
        private readonly ?string $accessToken = null,
        private readonly ?string $cronDiagnostics = null,
    ) {}

    public function getStatus(): string                { return $this->status; }
    public function getExtension(): string             { return $this->extension; }
    public function getVersion(): string               { return $this->version; }
    public function getPlatform(): string              { return $this->platform; }
    public function getMagentoVersion(): string        { return $this->magentoVersion; }
    public function getEdition(): string               { return $this->edition; }
    public function getConnectionKeyConfigured(): bool { return $this->connectionKeyConfigured; }
    public function getConnectionKeyValid(): bool      { return $this->connectionKeyValid; }
    public function getCatalogAccessible(): bool       { return $this->catalogAccessible; }
    public function getProductCount(): int             { return $this->productCount; }
    public function getStoreCount(): int               { return $this->storeCount; }
    public function getAccessToken(): ?string          { return $this->accessToken; }
    public function getCronDiagnostics(): ?string      { return $this->cronDiagnostics; }
}
