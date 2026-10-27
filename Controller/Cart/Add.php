<?php
/**
 * Copyright © 2026 NexoraIQ. All rights reserved.
 */

declare(strict_types=1);

namespace NexorAIQ\Connector\Controller\Cart;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use NexorAIQ\Connector\Model\AddToCartConfig;

/**
 * POST /nexoraiq/cart/add (sku, qty, form_key)
 *
 * Search results carry the SKU, not the Magento product ID: resolve it and hand the request to
 * Magento's own checkout/cart/add so stock checks, cart rules and messages work as usual.
 */
class Add implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ForwardFactory $forwardFactory,
        private readonly JsonFactory $jsonFactory,
        private readonly ProductResource $productResource,
        private readonly AddToCartConfig $addToCartConfig
    ) {
    }

    public function execute(): ResultInterface
    {
        $sku = trim((string)$this->request->getParam('sku'));
        $productId = $sku !== '' && ($this->addToCartConfig->isOverlayEnabled() || $this->addToCartConfig->isSearchPageEnabled())
            ? (int)$this->productResource->getIdBySku($sku)
            : 0;

        if (!$productId) {
            return $this->jsonFactory->create()
                ->setHttpResponseCode(404)
                ->setData(['error' => (string)__('This product is not available.')]);
        }

        $this->request->setParam('product', $productId);
        $this->request->setParam('qty', max(1, (int)$this->request->getParam('qty', 1)));

        return $this->forwardFactory->create()
            ->setModule('checkout')
            ->setController('cart')
            ->forward('add');
    }
}
