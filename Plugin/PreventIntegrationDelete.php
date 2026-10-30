<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Plugin;

use Magento\Framework\Exception\LocalizedException;
use Magento\Integration\Api\IntegrationServiceInterface;
use NexorAIQ\Connector\Setup\Patch\Data\CreateNexoraIQIntegration;

/**
 * Blocks deletion of the NexorAIQ | AI Commerce Search integration from Magento admin.
 *
 * If deleted, NexorAIQ loses catalog sync access and search will break.
 * To remove: uninstall the NexorAIQ_Connector module first.
 */
class PreventIntegrationDelete
{
    public function __construct(
        private readonly IntegrationServiceInterface $integrationService,
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function beforeDelete(IntegrationServiceInterface $subject, int $integrationId): array
    {
        $integration = $this->integrationService->get($integrationId);

        if ($integration->getName() === CreateNexoraIQIntegration::INTEGRATION_NAME
            || $integration->getName() === CreateNexoraIQIntegration::LEGACY_INTEGRATION_NAME
        ) {
            throw new LocalizedException(__(
                '"%1" is managed by NexorAIQ and cannot be deleted from here. '
                . 'To remove it, uninstall the NexorAIQ_Connector module: '
                . 'bin/magento module:disable NexorAIQ_Connector && bin/magento setup:upgrade',
                $integration->getName()
            ));
        }

        return [$integrationId];
    }
}
