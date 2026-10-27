<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Integration\Api\IntegrationServiceInterface;

/**
 * Updates existing integration branding to "NexorAIQ | AI Commerce Search".
 */
class UpdateIntegrationBranding implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface    $moduleDataSetup,
        private readonly IntegrationServiceInterface $integrationService,
    ) {}

    public function apply(): static
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $target = $this->integrationService->findByName(CreateNexoraIQIntegration::INTEGRATION_NAME);
        if (!$target->getId()) {
            $target = $this->integrationService->findByName(CreateNexoraIQIntegration::LEGACY_INTEGRATION_NAME);
        }
        if ($target->getId()) {
            $this->moduleDataSetup->getConnection()->update(
                $this->moduleDataSetup->getTable('integration'),
                [
                    'name'  => CreateNexoraIQIntegration::INTEGRATION_NAME,
                    'email' => 'integrations@nexoraiq.tech',
                ],
                ['integration_id = ?' => (int)$target->getId()]
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();
        return $this;
    }

    public static function getDependencies(): array
    {
        return [
            CreateNexoraIQIntegration::class,
        ];
    }

    public function getAliases(): array
    {
        // Same patch in the older single-theme connectors: skip it when one of them already applied it.
        return [
            'NexorAIQ\\HyvaConnector\\Setup\\Patch\\Data\\UpdateIntegrationBranding',
            'NexorAIQ\\MagentoConnector\\Setup\\Patch\\Data\\UpdateIntegrationBranding',
        ];
    }
}
