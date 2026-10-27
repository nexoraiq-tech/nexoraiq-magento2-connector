<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders a config field as read-only.
 *
 * The "readonly" attribute and "disabled" class stop Magento's <depends> JS
 * (mage/adminhtml/form.js) from enabling the input again when it shows it.
 */
class ReadonlyField extends Field
{
    protected function _getElementHtml(AbstractElement $element)
    {
        $element->setData('readonly', 'readonly');
        $element->setData('disabled', 'disabled');
        $element->addClass('disabled nexoraiq-readonly-field');
        return parent::_getElementHtml($element);
    }
}
