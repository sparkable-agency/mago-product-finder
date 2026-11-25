<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Test\Unit\Model;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sparkable\MagoProductFinder\Model\AttributeAccess;

class AttributeAccessTest extends TestCase
{
    #[DataProvider('accessProvider')]
    public function testAccess(
        string $code,
        int $setValues,
        int $addOptions,
        bool $defaultSetValues,
        bool $defaultAddOptions,
        bool $expectedSetValues,
        bool $expectedAddOptions,
    ): void {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnMap([
            ['mago/attribute_access/set_values', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null, $defaultSetValues],
            ['mago/attribute_access/add_options', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null, $defaultAddOptions],
        ]);
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getData')->willReturnMap([
            [AttributeAccess::FIELD_SET_VALUES, null, $setValues],
            [AttributeAccess::FIELD_ADD_OPTIONS, null, $addOptions],
        ]);
        $attributeAccess = new AttributeAccess($scopeConfig);

        self::assertSame($expectedSetValues, $attributeAccess->maySetValues($attribute));
        self::assertSame($expectedAddOptions, $attributeAccess->mayAddOptions($attribute));
    }

    public static function accessProvider(): array
    {
        $default = AttributeAccess::USE_DEFAULT;
        $yes = AttributeAccess::ALLOWED;
        $no = AttributeAccess::DENIED;

        return [
            'store default allows both' => ['color', $default, $default, true, true, true, true],
            'store default allows nothing' => ['color', $default, $default, false, false, false, false],
            'store default differs per permission' => ['color', $default, $default, false, true, false, true],
            'attribute allows against the store default' => ['color', $yes, $yes, false, false, true, true],
            'attribute denies against the store default' => ['color', $no, $no, true, true, false, false],
            'options allowed but values denied' => ['color', $no, $yes, true, true, false, true],
        ];
    }

    public function testDenialNamesTheAttributeAndTheSetting(): void
    {
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn('color');
        $attributeAccess = new AttributeAccess($this->createMock(ScopeConfigInterface::class));

        self::assertStringContainsString('"color"', $attributeAccess->describeValuesDenial($attribute));
        self::assertStringContainsString(
            '"Mago May Set Values on Products"',
            $attributeAccess->describeValuesDenial($attribute)
        );
        self::assertStringContainsString('"Mago May Add Options"', $attributeAccess->describeOptionsDenial($attribute));
    }
}
