<?php
/**
 * Copyright © 2026 NexorAIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Controller;

use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Framework\UrlInterface;
use NexorAIQ\Connector\Model\SearchPageConfig;

/**
 * Serves the search results page on the URL path configured per store (default "/search").
 */
class Router implements RouterInterface
{
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly SearchPageConfig $searchPageConfig
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        /** @var HttpRequest $request */
        if ($request->getModuleName() === 'nexoraiq') {
            return null;
        }

        $path = strtolower(trim((string)$request->getPathInfo(), '/'));
        if ($path === '' || !$this->searchPageConfig->isActive() || $path !== $this->searchPageConfig->getRoute()) {
            return null;
        }

        $request->setAlias(UrlInterface::REWRITE_REQUEST_PATH_ALIAS, $path);
        $request->setModuleName('nexoraiq')->setControllerName('search')->setActionName('index');
        $request->setPathInfo('/nexoraiq/search/index');

        return $this->actionFactory->create(Forward::class);
    }
}
