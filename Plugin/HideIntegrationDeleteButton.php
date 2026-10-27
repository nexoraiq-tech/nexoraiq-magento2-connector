<?php
declare(strict_types=1);

namespace NexorAIQ\Connector\Plugin;

use Magento\Framework\DataObject;
use Magento\Integration\Block\Adminhtml\Widget\Grid\Column\Renderer\Button\Delete;
use NexorAIQ\Connector\Setup\Patch\Data\CreateNexoraIQIntegration;

/**
 * Removes the Delete button from the integrations grid for the NexoraIQ integration.
 */
class HideIntegrationDeleteButton
{
    public function aroundRender(Delete $subject, callable $proceed, DataObject $row): string
    {
        if ($row->getData('name') === CreateNexoraIQIntegration::INTEGRATION_NAME
            || $row->getData('name') === CreateNexoraIQIntegration::LEGACY_INTEGRATION_NAME
        ) {
            return '<span title="' . __('Managed by NexorAIQ_Connector. Disable the module to remove.') . '" '
                . 'style="color:#999;font-size:11px;white-space:nowrap;">'
                . __('Managed by NexorAIQ')
                . '</span>';
        }

        return $proceed($row);
    }
}
