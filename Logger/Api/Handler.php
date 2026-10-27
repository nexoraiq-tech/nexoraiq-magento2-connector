<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Logger\Api;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    /**
     * @var int
     */
    protected $loggerType = Logger::INFO;

    /**
     * @var string
     */
    protected $fileName = '/var/log/NexoraIQ/api.log';
}
