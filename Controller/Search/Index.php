<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Controller\Search;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Search\Helper\Data as SearchHelper;
use Magento\Store\Model\StoreManagerInterface;
use NexorAIQ\Connector\Model\SearchPageConfig;

/**
 * NexorAIQ search results page. Reached through Controller\Router on the configured URL path;
 * the results themselves are loaded in the browser from the NexorAIQ GraphQL searchCatalog query.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageFactory $pageFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly SearchPageConfig $searchPageConfig,
        private readonly SearchHelper $searchHelper,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->searchPageConfig->isActive()) {
            return $this->forwardFactory->create()->forward('noroute');
        }

        /** @var HttpRequest $request */
        $request = $this->request;
        // Direct hits on /nexoraiq/search/index go to the configured URL so each search has one address.
        if (!$request->getAlias(UrlInterface::REWRITE_REQUEST_PATH_ALIAS)) {
            return $this->redirectFactory->create()
                ->setUrl($this->searchPageConfig->getUrl($request->getQueryValue() ?: []))
                ->setHttpResponseCode(301);
        }

        $page = $this->pageFactory->create();
        $query = trim((string)$this->searchHelper->getEscapedQueryText());
        $page->getConfig()->getTitle()->set(
            $query !== '' ? __("Search results for: '%1'", $query) : __('Search')
        );
        $page->getConfig()->setRobots('NOINDEX,FOLLOW');

        // Same breadcrumbs as Magento's catalogsearch result page.
        $breadcrumbs = $page->getLayout()->getBlock('breadcrumbs');
        if ($breadcrumbs) {
            $breadcrumbs->addCrumb('home', [
                'label' => __('Home'),
                'title' => __('Go to Home Page'),
                'link'  => $this->storeManager->getStore()->getBaseUrl(),
            ]);
            $breadcrumbs->addCrumb('search', [
                'label' => $query !== '' ? __("Search results for: '%1'", $query) : __('Search'),
            ]);
        }

        return $page;
    }
}
