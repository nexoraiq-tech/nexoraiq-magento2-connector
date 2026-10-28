<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Controller\Adminhtml\System;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use NexorAIQ\Connector\Helper\Data as HelperData;

/**
 * Feeds the "Current Plan" card on Stores > Configuration > General Settings. Returns the plan
 * and a fresh auto-login URL (opened by the "Upgrade Plan" button) from NexorAIQ's
 * connection-login endpoint. Called on every click because the login token is short-lived.
 */
class PlanInfo extends Action implements HttpPostActionInterface
{
    const ADMIN_RESOURCE = 'NexorAIQ_Connector::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly HelperData $helper
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $data = $this->helper->getConnectionLogin();

        if (empty($data['success'])) {
            return $result->setData([
                'success' => false,
                'message' => (string)($data['message'] ?? __('Could not load your plan.')->render()),
            ]);
        }

        // Only hand an https dashboard link to the browser.
        $loginUrl = (string)($data['autoLoginUrl'] ?? '');
        if (!preg_match('#^https://#i', $loginUrl)) {
            $loginUrl = '';
        }

        return $result->setData([
            'success'      => true,
            'autoLoginUrl' => $loginUrl,
            'store'        => is_array($data['store'] ?? null) ? $data['store'] : [],
            'plan'         => is_array($data['plan'] ?? null) ? $data['plan'] : [],
            'usage'        => is_array($data['usage'] ?? null) ? $data['usage'] : [],
        ]);
    }
}
