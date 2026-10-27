<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Plugin;

use Magento\CatalogSearch\Controller\Result\Index;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use NexorAIQ\Connector\Model\SearchPageConfig;

/**
 * Sends catalogsearch/result/?q=... to the NexorAIQ search results page while it is enabled.
 */
class CatalogSearchResultRedirect
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly SearchPageConfig $searchPageConfig
    ) {
    }

    public function aroundExecute(Index $subject, callable $proceed)
    {
        if (!$this->searchPageConfig->isActive()) {
            return $proceed();
        }

        /** @var HttpRequest $request */
        $request = $this->request;
        return $this->redirectFactory->create()
            ->setUrl($this->searchPageConfig->getUrl($request->getQueryValue() ?: []))
            ->setHttpResponseCode(302);
    }
}
