<?php

/**
 * Copyright © 2025 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use NexorAIQ\Connector\Helper\Data as HelperData;
use NexorAIQ\Connector\Model\Analytics\EventLogger;
use Psr\Log\LoggerInterface;

class TrackTelemetry implements ResolverInterface
{
    /**
     * @param HelperData $helper
     * @param LoggerInterface $logger
     * @param EventLogger $eventLogger
     */
    public function __construct(
        private readonly HelperData $helper,
        private readonly LoggerInterface $logger,
        private readonly EventLogger $eventLogger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!$this->helper->isEnabled()) {
            return false;
        }

        try {
            // Store view of the request (Store header): every event is logged with its store code and id.
            $store = $context->getExtensionAttributes()->getStore();

            switch ($field->getName()) {
                case 'nexoraiqTrackSearch':
                    return $this->eventLogger->logSearch(
                        (string)($args['query'] ?? ''),
                        $args['sessionId'] ?? null,
                        (int)($args['resultsCount'] ?? 0),
                        (int)($args['tookMs'] ?? 0),
                        $args['userId'] ?? null,
                        $args['device'] ?? null,
                        $store
                    ) !== null;

                case 'nexoraiqTrackEvent':
                    // CLICK only: ADD_TO_CART and CONVERSION are logged server-side.
                    $eventType = strtoupper(trim((string)($args['eventType'] ?? '')));
                    if ($eventType !== EventLogger::CLICK) {
                        return false;
                    }
                    return $this->eventLogger->log($eventType, [
                        'sessionId' => $args['sessionId'] ?? null,
                        'query'     => $args['query'] ?? null,
                        'productId' => $this->eventLogger->normalizeProductId($args['productId'] ?? null),
                        'source'    => $args['source'] ?? null,
                    ], $store) !== null;

                default:
                    return false;
            }
        } catch (\Throwable $e) {
            $this->logger->error('NexoraIQ Telemetry Resolver error: ' . $e->getMessage());
            return false;
        }
    }
}
