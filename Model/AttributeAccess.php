<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Model;

use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\Config\ScopeConfigInterface;

class AttributeAccess
{
    public const int USE_DEFAULT = 0;
    public const int ALLOWED = 1;
    public const int DENIED = 2;

    public const string FIELD_SET_VALUES = 'mago_set_values';
    public const string FIELD_ADD_OPTIONS = 'mago_add_options';
    public const string LABEL_SET_VALUES = 'Mago May Set Values on Products';
    public const string LABEL_ADD_OPTIONS = 'Mago May Add Options';

    private const string CONFIG_SET_VALUES = 'mago/attribute_access/set_values';
    private const string CONFIG_ADD_OPTIONS = 'mago/attribute_access/add_options';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function maySetValues(AbstractAttribute $attribute): bool
    {
        return $this->isAllowed($attribute, self::FIELD_SET_VALUES, self::CONFIG_SET_VALUES);
    }

    public function mayAddOptions(AbstractAttribute $attribute): bool
    {
        return $this->isAllowed($attribute, self::FIELD_ADD_OPTIONS, self::CONFIG_ADD_OPTIONS);
    }

    public function describeValuesDenial(AbstractAttribute $attribute): string
    {
        return $this->describeDenial($attribute, self::LABEL_SET_VALUES);
    }

    public function describeOptionsDenial(AbstractAttribute $attribute): string
    {
        return $this->describeDenial($attribute, self::LABEL_ADD_OPTIONS);
    }

    private function describeDenial(AbstractAttribute $attribute, string $fieldLabel): string
    {
        return sprintf(
            'The assistant is not allowed to change attribute "%s". A merchant can allow it: open the attribute '
            . 'under Stores > Attributes > Product, and set "%s" to Yes in Advanced Attribute Properties.',
            $attribute->getAttributeCode(),
            $fieldLabel
        );
    }

    private function isAllowed(AbstractAttribute $attribute, string $field, string $configPath): bool
    {
        return match ((int)$attribute->getData($field)) {
            self::ALLOWED => true,
            self::DENIED => false,
            default => $this->scopeConfig->isSetFlag($configPath),
        };
    }
}
