<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * Raised when a NexorAIQ API call fails: transport error, non-2xx status or a non-JSON body.
 */
class ApiException extends LocalizedException
{
}
