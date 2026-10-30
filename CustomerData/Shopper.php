<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use NexorAIQ\Connector\Model\Api\RequestContext;

/**
 * Private content section "nexoraiq": the "x-user-id" value the browser sends with its direct calls
 * to the NexorAIQ API ("user_" + base64(email), or "guest_1"). Pages are full-page cached, so it
 * can't be rendered into the HTML.
 */
class Shopper implements SectionSourceInterface
{
    public function __construct(
        private readonly RequestContext $requestContext
    ) {
    }

    public function getSectionData(): array
    {
        return ['userId' => $this->requestContext->getUserId()];
    }
}
