<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\PackageInfo;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Api\OauthServiceInterface;
use NexorAIQ\Connector\Api\IntegrationInterface;
use NexorAIQ\Connector\Setup\Patch\Data\CreateNexoraIQIntegration;

class IntegrationManager implements IntegrationInterface
{
    public function __construct(
        private readonly IntegrationServiceInterface $integrationService,
        private readonly OauthServiceInterface       $oauthService,
        private readonly PackageInfo                 $packageInfo,
    ) {
    }

    public function getCredentials(): string
    {
        $integration = $this->integrationService->findByName(
            CreateNexoraIQIntegration::INTEGRATION_NAME
        );
        if (!$integration->getId()) {
            $integration = $this->integrationService->findByName(
                CreateNexoraIQIntegration::LEGACY_INTEGRATION_NAME
            );
        }

        if (!$integration->getId()) {
            throw new LocalizedException(__(
                'NexorAIQ integration not found. Run "bin/magento setup:upgrade" to create it.'
            ));
        }

        $token = $this->oauthService->getAccessToken((int)$integration->getConsumerId());

        if (!$token || !$token->getToken()) {
            throw new LocalizedException(__(
                'NexorAIQ integration has no active token. '
                . 'Go to System → Integrations → NexorAIQ | AI Commerce Search → Reactivate.'
            ));
        }

        return (string)json_encode([
            'integration_name' => $integration->getName(),
            'access_token'     => $token->getToken(),
            'status'           => 'active',
        ]);
    }

    public function getStatus(): string
    {
        $integration = $this->integrationService->findByName(
            CreateNexoraIQIntegration::INTEGRATION_NAME
        );
        if (!$integration->getId()) {
            $integration = $this->integrationService->findByName(
                CreateNexoraIQIntegration::LEGACY_INTEGRATION_NAME
            );
        }

        return (string)json_encode([
            'extension'      => 'NexorAIQ_Connector',
            'version'        => (string)$this->packageInfo->getVersion('NexorAIQ_Connector'),
            'integration_ok' => (bool)$integration->getId(),
        ]);
    }
}
