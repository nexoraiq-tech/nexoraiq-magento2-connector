<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Check Subscription" button on Stores > Configuration > General Settings — sends one real
 * product through the same sync path cron/indexer use; on success clears the sync block and
 * triggers a full reindex to resume real-time sync (see Controller\Adminhtml\System\
 * CheckSubscription).
 */
class CheckSubscriptionButton extends Field
{
    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $url = $this->getUrl('nexoraiq/system/checksubscription');

        return <<<HTML
<div class="control-value" style="padding-top:4px;">
    <button id="nexoraiq-check-subscription-btn" class="action-secondary" type="button" style="display:flex; align-items:center; gap:8px;">
        <span class="btn-text">Check Subscription</span>
        <span class="btn-spinner" style="display:none; width:12px; height:12px; border:2px solid rgba(0,0,0,0.2); border-radius:50%; border-top-color:#333; animation: nexoraiq-spin 0.8s linear infinite;"></span>
    </button>
    <div id="nexoraiq-check-subscription-result" style="margin-top:8px; font-size:12px;"></div>
</div>
<style>
    @keyframes nexoraiq-spin { to { transform: rotate(360deg); } }
</style>
<script>
    require(['jquery'], function (\$) {
        \$('#nexoraiq-check-subscription-btn').off('click').on('click', function () {
            var btn = \$(this);
            var text = btn.find('.btn-text');
            var spinner = btn.find('.btn-spinner');
            var result = \$('#nexoraiq-check-subscription-result');

            btn.prop('disabled', true);
            text.text('Checking...');
            spinner.show();
            result.text('').css('color', '');

            \$.ajax({
                url: '{$url}',
                type: 'POST',
                data: { form_key: window.FORM_KEY },
                dataType: 'json',
                success: function (response) {
                    btn.prop('disabled', false);
                    text.text('Check Subscription');
                    spinner.hide();
                    if (response && response.success) {
                        result.css('color', '#2e7d32').text(response.message);
                        setTimeout(function () { window.location.reload(); }, 1200);
                    } else {
                        result.css('color', '#c0392b').text((response && response.message) || 'Sync is still blocked.');
                    }
                },
                error: function (xhr, status, error) {
                    btn.prop('disabled', false);
                    text.text('Check Subscription');
                    spinner.hide();
                    result.css('color', '#c0392b').text('Request failed: ' + error);
                }
            });
        });
    });
</script>
HTML;
    }
}
