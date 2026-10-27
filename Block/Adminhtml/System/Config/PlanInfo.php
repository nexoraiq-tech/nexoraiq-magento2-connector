<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Current Plan" card on Stores > Configuration > General Settings. The card loads over AJAX
 * (see Controller\Adminhtml\System\PlanInfo) so a slow API never delays the config page.
 */
class PlanInfo extends Field
{
    protected $_template = 'NexorAIQ_Connector::system/config/plan_info.phtml';

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    public function getPlanInfoUrl(): string
    {
        return $this->getUrl('nexoraiq/system/planinfo');
    }
}
