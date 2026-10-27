<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Api;

/**
 * Exposes the auto-created NexoraIQ integration credentials via REST.
 *
 * GET /V1/nexoraiq/connection  – requires admin Bearer token
 * GET /V1/nexoraiq/status      – public (anonymous)
 */
interface IntegrationInterface
{
    /**
     * Returns the NexoraIQ integration access token and metadata.
     * Call with Authorization: Bearer <admin-token>
     *
     * @return string JSON
     */
    public function getCredentials(): string;

    /**
     * Public health check — confirms the extension is installed.
     *
     * @return string JSON
     */
    public function getStatus(): string;
}
