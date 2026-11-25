<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Sparkable\MagoProductFinder\Model\AttributeAccess;

class AttributeAccessLevel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => AttributeAccess::USE_DEFAULT, 'label' => __('Use Store Default')],
            ['value' => AttributeAccess::ALLOWED, 'label' => __('Yes')],
            ['value' => AttributeAccess::DENIED, 'label' => __('No')],
        ];
    }
}
