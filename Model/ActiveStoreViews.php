<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Store\Model\ScopeInterface;
use NexorAIQ\Connector\Api\ActiveStoreViewsInterface;
use Psr\Log\LoggerInterface;

class ActiveStoreViews implements ActiveStoreViewsInterface
{
    private const CONFIG_PATH_KEY = 'nexoraiq/general/connection_key';
    private const CONFIG_PATH_ACTIVE_STORE_VIEWS = 'nexoraiq/general/active_store_views';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $key
     * @param mixed $activeStoreViews
     * @return string
     * @throws WebapiException When the connection key is wrong
     */
    public function update(string $key, $activeStoreViews): string
    {
        $storedEncrypted = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_KEY,
            ScopeInterface::SCOPE_STORE
        );
        $storedKey = $storedEncrypted !== '' ? $this->encryptor->decrypt($storedEncrypted) : '';

        if ($key === '' || $storedKey === '' || !hash_equals($storedKey, $key)) {
            $this->logger->warning('NexorAIQ_Connector: active-store-views rejected — invalid connection key');
            throw new WebapiException(__('Invalid connection key'), 0, WebapiException::HTTP_UNAUTHORIZED);
        }

        try {
            $jsonValue = json_encode($activeStoreViews);
            $this->configWriter->save(
                self::CONFIG_PATH_ACTIVE_STORE_VIEWS,
                $jsonValue,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                0
            );

            // Re-initialize Magento config cache so the helper picks up changes immediately
            $this->reinitableConfig->reinit();

            $this->logger->info('NexorAIQ_Connector: Updated active store views pushed from NexoraIQ', [
                'count' => count($activeStoreViews)
            ]);

            return (string) json_encode([
                'status' => 'success',
                'message' => 'Active store views updated successfully',
                'count' => count($activeStoreViews)
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('NexorAIQ_Connector: Failed to save active store views: ' . $e->getMessage());
            return (string) json_encode([
                'status' => 'error',
                'message' => 'Failed to save active store views: ' . $e->getMessage()
            ]);
        }
    }
}
