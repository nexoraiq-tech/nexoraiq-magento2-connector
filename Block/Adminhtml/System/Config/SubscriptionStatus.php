<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use NexorAIQ\Connector\Model\SyncBlockManager;

/**
 * Read-only notice on Stores > Configuration > General Settings showing the current sync
 * block state (if any) — error_type / error_message / http_code are rendered exactly as
 * NexorAIQ returned them, never hardcoded, so this stays accurate for any plan-restriction
 * reason the API reports (subscription expired, product/index limit reached, etc.).
 */
class SubscriptionStatus extends Field
{
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        private readonly SyncBlockManager $syncBlockManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $state = $this->syncBlockManager->getState();

        if (empty($state['is_blocked'])) {
            return '<div class="control-value" style="padding-top:7px; color:#2e7d32; font-weight:600;">'
                . '&#10003; ' . $this->escapeHtml(__('Sync Active')->render())
                . '</div>';
        }

        $errorType = $this->escapeHtml((string)($state['error_type'] ?? 'UNKNOWN'));
        $errorMessage = $this->escapeHtml((string)($state['error_message'] ?? ''));
        $httpCode = $this->escapeHtml((string)($state['http_code'] ?? ''));
        $blockedAt = $this->escapeHtml((string)($state['blocked_at'] ?? ''));

        $html = '<div class="control-value" style="padding-top:4px; max-width:650px;">';
        $html .= '<div style="background:#fdecea; border:1px solid #f5c2c0; border-radius:6px; padding:12px 14px; color:#611a15;">';
        $html .= '<div style="font-weight:700; font-size:13px; margin-bottom:4px;">&#9888; ' . $this->escapeHtml(__('Sync Paused')->render()) . '</div>';
        $html .= sprintf(
            '<div style="font-size:12px; margin-bottom:2px;"><strong>%s:</strong> %s</div>',
            $this->escapeHtml(__('Reason')->render()),
            $errorType
        );
        if ($errorMessage !== '') {
            $html .= sprintf('<div style="font-size:12px; margin-bottom:2px;">%s</div>', $errorMessage);
        }
        $html .= sprintf(
            '<div style="font-size:11px; opacity:0.75; margin-top:6px;">HTTP %s &mdash; %s %s</div>',
            $httpCode,
            $this->escapeHtml(__('since')->render()),
            $blockedAt
        );
        $html .= '<div style="font-size:12px; margin-top:8px;">'
            . $this->escapeHtml(__('Update or renew your plan on the NexorAIQ portal, then click "Check Subscription" below to resume sync.')->render())
            . '</div>';
        $html .= '</div></div>';

        return $html;
    }
}
