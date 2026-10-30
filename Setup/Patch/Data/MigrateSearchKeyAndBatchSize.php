<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Setup\Patch\Data;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use NexorAIQ\Connector\Model\Config\Source\BatchSize;

/**
 * Encrypts Public Search API Key values saved as plain text and moves
 * Bulk Sync Batch Size values that are no longer offered to the default (100).
 */
class MigrateSearchKeyAndBatchSize implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function apply(): static
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $rows = $connection->fetchPairs(
            $connection->select()->from($table, ['config_id', 'value'])
                ->where('path = ?', 'nexoraiq/storefront_ui/search_key')
        );
        foreach ($rows as $id => $value) {
            if (is_string($value) && str_starts_with($value, 'key_search_')) {
                $connection->update($table, ['value' => $this->encryptor->encrypt($value)], ['config_id = ?' => (int)$id]);
            }
        }

        $connection->update(
            $table,
            ['value' => (string)BatchSize::DEFAULT_SIZE],
            [
                'path = ?' => 'nexoraiq/general/batch_size',
                'value NOT IN (?)' => array_map('strval', BatchSize::ALLOWED_SIZES),
            ]
        );

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
