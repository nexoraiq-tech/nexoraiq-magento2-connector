<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Module\PackageInfo;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Api\OauthServiceInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use NexorAIQ\Connector\Api\ConnectorInterface;
use NexorAIQ\Connector\Api\Data\PingResultInterface;
use NexorAIQ\Connector\Setup\Patch\Data\CreateNexoraIQIntegration;
use Psr\Log\LoggerInterface;

class Connector implements ConnectorInterface
{
    private const CONFIG_PATH_KEY = 'nexoraiq/general/connection_key';
    private const MODULE_NAME     = 'NexorAIQ_Connector';

    public function __construct(
        private readonly ScopeConfigInterface      $scopeConfig,
        private readonly ProductMetadataInterface  $productMetadata,
        private readonly StoreManagerInterface     $storeManager,
        private readonly ResourceConnection        $resourceConnection,
        private readonly EncryptorInterface        $encryptor,
        private readonly LoggerInterface           $logger,
        private readonly PingResultFactory         $pingResultFactory,
        private readonly IntegrationServiceInterface $integrationService,
        private readonly OauthServiceInterface       $oauthService,
        private readonly PackageInfo                 $packageInfo,
    ) {
    }

    public function ping(?string $key = null): PingResultInterface
    {
        $storedEncrypted = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_KEY,
            ScopeInterface::SCOPE_STORE
        );
        $storedKey    = $storedEncrypted !== '' ? $this->encryptor->decrypt($storedEncrypted) : '';
        $keyConfigured = $storedKey !== '';

        // Only a request carrying the configured connection key is trusted (the NexorAIQ platform always sends it).
        $keyValid = $keyConfigured && $key !== null && $key !== '' && hash_equals($storedKey, $key);

        $this->logger->info(sprintf('NexorAIQ_Connector: ping requested (keyConfigured=%s, keyValid=%s)', $keyConfigured ? 'true' : 'false', $keyValid ? 'true' : 'false'));

        if (!$keyValid) {
            // Anonymous / wrong key: confirm the connector is installed, nothing about the store or its catalog.
            return $this->pingResultFactory->create([
                'status'                  => 'ok',
                'extension'               => self::MODULE_NAME,
                'version'                 => $this->moduleVersion(),
                'platform'                => 'magento2',
                'magentoVersion'          => '',
                'edition'                 => '',
                'connectionKeyConfigured' => $keyConfigured,
                'connectionKeyValid'      => false,
                'catalogAccessible'       => false,
                'productCount'            => 0,
                'storeCount'              => 0,
            ]);
        }

        $productCount     = 0;
        $catalogAccessible = false;
        try {
            $conn             = $this->resourceConnection->getConnection();
            $tbl              = $this->resourceConnection->getTableName('catalog_product_entity');
            $statusAttrId = (int)$this->scopeConfig->getValue('catalog/product/status') ?: 97; // fallback or query if possible
            try {
                $statusAttrId = (int)$this->resourceConnection->getConnection()->fetchOne(
                    $this->resourceConnection->getConnection()->select()
                        ->from($this->resourceConnection->getTableName('eav_attribute'), ['attribute_id'])
                        ->where('attribute_code = ?', 'status')
                        ->where('entity_type_id = ?', 4)
                );
            } catch (\Throwable $e) {

            }
            $pei = $this->resourceConnection->getTableName('catalog_product_entity_int');
            $productCount = (int)$conn->fetchOne(
                $conn->select()
                    ->from(['e' => $tbl], [new \Zend_Db_Expr('COUNT(*)')])
                    ->join(
                        ['s' => $pei],
                        "s.entity_id = e.entity_id AND s.attribute_id = " . $statusAttrId . " AND s.store_id = 0 AND s.value = 1",
                        []
                    )
            );
            $catalogAccessible = true;
        } catch (\Throwable $e) {
            $this->logger->error('NexorAIQ_Connector: ' . $e->getMessage());
        }

        $storeCount = 0;
        try {
            $storeCount = count($this->storeManager->getStores());
        } catch (\Throwable $e) {
            $this->logger->error('NexorAIQ_Connector: ' . $e->getMessage());
        }

        // Return the NexorAIQ | AI Commerce Search OAuth access token only when the connection key is verified.
        // NexorAIQ stores this as magentoAccessToken (separate from connectionKey).
        $accessToken = null;
        if ($keyValid) {
            try {
                $integration = $this->integrationService->findByName(CreateNexoraIQIntegration::INTEGRATION_NAME);
                if (!$integration->getId()) {
                    $integration = $this->integrationService->findByName(CreateNexoraIQIntegration::LEGACY_INTEGRATION_NAME);
                }
                if ($integration->getId()) {
                    $token = $this->oauthService->getAccessToken((int) $integration->getConsumerId());
                    $accessToken = $token?->getToken() ?: null;
                }
            } catch (\Throwable $e) {
                $this->logger->error('NexorAIQ_Connector: could not retrieve access token: ' . $e->getMessage());
            }
        }

        // Live Cron Diagnostics Check
        $cronDiagnostics = null;
        try {
            $conn = $this->resourceConnection->getConnection();
            $cronTable = $this->resourceConnection->getTableName('cron_schedule');
            $jobs = ['nexoraiq_index_queue', 'nexoraiq_catalog_sync', 'nexoraiq_webhook_consumer'];
            $cronInfo = [];
            foreach ($jobs as $job) {
                // Fetch the latest executed row first so future 'pending' scheduled jobs don't mask recent successful runs
                $select = $conn->select()
                    ->from($cronTable, ['status', 'executed_at', 'finished_at'])
                    ->where('job_code = ?', $job)
                    ->where('executed_at IS NOT NULL')
                    ->order('executed_at DESC')
                    ->limit(1);
                $row = $conn->fetchRow($select);
                if (!$row) {
                    // Fallback to latest scheduled row if never executed
                    $select = $conn->select()
                        ->from($cronTable, ['status', 'executed_at', 'finished_at'])
                        ->where('job_code = ?', $job)
                        ->order('scheduled_at DESC')
                        ->limit(1);
                    $row = $conn->fetchRow($select);
                }
                if ($row) {
                    $cronInfo[$job] = [
                        'status' => $row['status'],
                        'executed_at' => $row['executed_at'],
                        'finished_at' => $row['finished_at']
                    ];
                } else {
                    $cronInfo[$job] = null;
                }
            }
            $cronDiagnostics = json_encode($cronInfo);
        } catch (\Throwable $e) {
            $this->logger->error('NexorAIQ_Connector cron diagnostics check failed: ' . $e->getMessage());
        }

        return $this->pingResultFactory->create([
            'status'                 => 'ok',
            'extension'              => self::MODULE_NAME,
            'version'                => $this->moduleVersion(),
            'platform'               => 'magento2',
            'magentoVersion'         => $this->productMetadata->getVersion(),
            'edition'                => $this->productMetadata->getEdition(),
            'connectionKeyConfigured' => $keyConfigured,
            'connectionKeyValid'      => $keyValid,
            'catalogAccessible'       => $catalogAccessible,
            'productCount'            => $productCount,
            'storeCount'              => $storeCount,
            'accessToken'             => $accessToken,
            'cronDiagnostics'         => $cronDiagnostics,
        ]);
    }

    /**
     * Connector version from its composer.json.
     */
    private function moduleVersion(): string
    {
        return (string)$this->packageInfo->getVersion(self::MODULE_NAME);
    }
}
