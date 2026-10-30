<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Integration\Api\AuthorizationServiceInterface;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Api\OauthServiceInterface;
use Magento\Integration\Model\Integration;

/**
 * Auto-creates the "NexorAIQ | AI Commerce Search" Magento integration on module install.
 *
 * Resources are catalog read-only only — no write access,
 * no customer data, no orders, no payment information.
 * Run: bin/magento setup:upgrade
 */
class CreateNexoraIQIntegration implements DataPatchInterface
{
    public const INTEGRATION_NAME = 'NexorAIQ | AI Commerce Search';
    public const LEGACY_INTEGRATION_NAME = 'NexoraIQ Search Cloud';

    private const REQUIRED_RESOURCES = [
        'Magento_Catalog::catalog',
        'Magento_Catalog::products',
        'Magento_Catalog::catalog_attributes',
        'Magento_Eav::eav_attribute',
        'Magento_Catalog::categories',
        'Magento_CatalogInventory::cataloginventory',
        'Magento_Store::store',
        'Magento_Store::store_settings',
        'Magento_Store::groups',
        'Magento_Store::websites',
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface      $moduleDataSetup,
        private readonly IntegrationServiceInterface   $integrationService,
        private readonly OauthServiceInterface         $oauthService,
        private readonly AuthorizationServiceInterface $authorizationService,
        private readonly WriterInterface               $configWriter,
    ) {
    }

    public function apply(): static
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        // Allow integration OAuth tokens to be used as REST API bearer tokens.
        // Required for NexorAIQ to call /V1/products with this integration's token.
        $this->configWriter->save('oauth/consumer/enable_integration_as_bearer', 1);

        $existing = $this->integrationService->findByName(self::INTEGRATION_NAME);
        if (!$existing->getId()) {
            $legacy = $this->integrationService->findByName(self::LEGACY_INTEGRATION_NAME);
            if ($legacy->getId()) {
                $this->moduleDataSetup->getConnection()->update(
                    $this->moduleDataSetup->getTable('integration'),
                    ['name' => self::INTEGRATION_NAME],
                    ['integration_id = ?' => (int)$legacy->getId()]
                );
                $existing = $this->integrationService->findByName(self::INTEGRATION_NAME);
            }
        }

        if ($existing->getId()) {
            // Integration already exists. Fix setup_type if wrong — TYPE_CONFIG causes
            // an admin edit error because Magento looks for an integration.xml that doesn't exist.
            // Do NOT overwrite permissions: the admin may have intentionally granted broader access,
            // and saveRel() replaces all rules, which can silently break the API token.
            if ((int)$existing->getSetupType() !== Integration::TYPE_MANUAL) {
                // Use direct DB update to avoid triggering Plugin\Integration::afterUpdate,
                // which calls _saveApiPermissions() and wipes existing permissions when no
                // 'resource' key is present on the integration data array.
                $this->moduleDataSetup->getConnection()->update(
                    $this->moduleDataSetup->getTable('integration'),
                    ['setup_type' => Integration::TYPE_MANUAL],
                    ['integration_id = ?' => (int)$existing->getId()]
                );
            }

            // Ensure a token exists and is activated.
            $token = $this->oauthService->getAccessToken((int)$existing->getConsumerId());
            if (!$token || !$token->getToken()) {
                $this->oauthService->createAccessToken((int)$existing->getConsumerId(), true);
            }

            $this->moduleDataSetup->getConnection()->endSetup();
            return $this;
        }

        // TYPE_MANUAL = integration created programmatically (not from XML config files)
        $integration = $this->integrationService->create([
            'name'       => self::INTEGRATION_NAME,
            'email'      => 'integrations@nexoraiq.tech',
            'status'     => Integration::STATUS_ACTIVE,
            'setup_type' => Integration::TYPE_MANUAL,
        ]);

        // Grant catalog-only read permissions
        $this->authorizationService->grantPermissions(
            (int)$integration->getId(),
            self::REQUIRED_RESOURCES
        );

        // Activate and generate access token
        $this->oauthService->createAccessToken((int)$integration->getConsumerId(), true);

        $this->moduleDataSetup->getConnection()->endSetup();
        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        // Same patch in the older single-theme connectors: skip it when one of them already applied it.
        return [
            'NexorAIQ\\HyvaConnector\\Setup\\Patch\\Data\\CreateNexoraIQIntegration',
            'NexorAIQ\\MagentoConnector\\Setup\\Patch\\Data\\CreateNexoraIQIntegration',
        ];
    }
}
