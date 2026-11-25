<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Sparkable\MagoProductFinder\Model\AttributeAccess;
use Sparkable\MagoProductFinder\Model\Config\Source\AttributeAccessLevel;

class AddAttributeAccessFields implements ObserverInterface
{
    public function __construct(
        private readonly AttributeAccessLevel $accessLevel,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $fieldset = $observer->getData('form')?->getElement('advanced_fieldset');
        if ($fieldset === null) {
            return;
        }

        $note = __('The store default is under Stores > Configuration > Mago Assistant.');
        $fields = [
            AttributeAccess::FIELD_SET_VALUES => AttributeAccess::LABEL_SET_VALUES,
            AttributeAccess::FIELD_ADD_OPTIONS => AttributeAccess::LABEL_ADD_OPTIONS,
        ];
        foreach ($fields as $name => $label) {
            $fieldset->addField($name, 'select', [
                'name' => $name,
                'label' => __($label),
                'title' => __($label),
                'values' => $this->accessLevel->toOptionArray(),
                'note' => $note,
            ]);
        }
    }
}
