<?php
declare(strict_types=1);
namespace NexorAIQ\Connector\Api;

interface StoreInfoInterface
{
    /**
     * Returns store structure as a JSON string.
     *
     * @param string $key The NexoraIQ connection key (auth).
     * @return string
     */
    public function getStoreInfo(string $key = ''): string;
}
