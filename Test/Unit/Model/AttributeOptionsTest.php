<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Test\Unit\Model;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\Collection as OptionCollection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as OptionCollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sparkable\MagoProductFinder\Model\AttributeOptions;

class AttributeOptionsTest extends TestCase
{
    public function testDropdownMatchesLabelsOfEveryStoreView(): void
    {
        $source = $this->createMock(Table::class);
        $source->method('getAllOptions')->with(false, true)->willReturn([
            ['value' => '10', 'label' => 'Rood'],
            ['value' => '11', 'label' => 'Blauw'],
        ]);
        $attribute = $this->createAttribute('color', 'select', $source);
        $attributeOptions = $this->createAttributeOptions([
            ['value' => '10', 'label' => 'Red'],
            ['value' => '11', 'label' => 'Blue'],
        ]);

        self::assertSame('10', $attributeOptions->findOptionId($attribute, 'red'));
        self::assertSame('10', $attributeOptions->findOptionId($attribute, ' Rood '));
        self::assertSame('11', $attributeOptions->findOptionId($attribute, '11'));
        self::assertNull($attributeOptions->findOptionId($attribute, 'Green'));
        self::assertTrue($attributeOptions->hasLabel($attribute, 'BLUE'));
        self::assertFalse($attributeOptions->hasLabel($attribute, '11'));
        self::assertSame('Rood', $attributeOptions->getLabel($attribute, '10'));
        self::assertSame('99', $attributeOptions->getLabel($attribute, '99'));
        self::assertSame(2, $attributeOptions->count($attribute));
        self::assertSame('"Rood", "Blauw"', $attributeOptions->describe($attribute));
    }

    public function testOptionsAreLoadedOncePerAttribute(): void
    {
        $source = $this->createMock(AbstractSource::class);
        $source->expects(self::once())->method('getAllOptions')->willReturn([['value' => 1, 'label' => 'One']]);
        $attribute = $this->createAttribute('custom', 'select', $source);
        $attributeOptions = $this->createAttributeOptions([]);

        $attributeOptions->findOptionId($attribute, 'One');
        $attributeOptions->hasLabel($attribute, 'One');
        $attributeOptions->count($attribute);
    }

    public function testDescribeShortensALongOptionList(): void
    {
        $source = $this->createMock(AbstractSource::class);
        $source->method('getAllOptions')->willReturn(array_map(
            static fn (int $id): array => ['value' => $id, 'label' => 'Option ' . $id],
            range(1, 32)
        ));
        $attribute = $this->createAttribute('custom', 'select', $source);

        $description = $this->createAttributeOptions([])->describe($attribute);

        self::assertStringContainsString('"Option 30"', $description);
        self::assertStringNotContainsString('"Option 31"', $description);
        self::assertStringEndsWith(' and 2 more', $description);
    }

    #[DataProvider('yesNoWithoutOptionsProvider')]
    public function testYesNoAttributeWithoutOptionList(string $code, string $value, ?string $id, string $label): void
    {
        $source = $this->createMock(AbstractSource::class);
        $source->method('getAllOptions')->willReturn([]);
        $attribute = $this->createAttribute($code, 'boolean', $source);
        $attributeOptions = $this->createAttributeOptions([]);

        self::assertSame($id, $attributeOptions->findOptionId($attribute, $value));
        self::assertSame($label, $attributeOptions->getLabel($attribute, (string)$id));
    }

    public static function yesNoWithoutOptionsProvider(): array
    {
        return [
            'yes by id' => ['no_index', '1', '1', 'Yes'],
            'no by label' => ['no_index', 'no', '0', 'No'],
            'bundle type by id' => ['price_type', '1', '1', 'Fixed'],
            'bundle type by label' => ['weight_type', 'Dynamic', '0', 'Dynamic'],
            'bundle type is not yes or no' => ['sku_type', 'Yes', null, ''],
        ];
    }

    private function createAttributeOptions(array $storeViewOptions): AttributeOptions
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);
        $collection = $this->createMock(OptionCollection::class);
        $collection->method('setStoreFilter')->with(2, false)->willReturnSelf();
        $collection->method('toOptionArray')->willReturn($storeViewOptions);
        $factory = $this->createMock(OptionCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new AttributeOptions($factory, $storeManager);
    }

    private function createAttribute(string $code, string $input, AbstractSource $source): Attribute
    {
        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getAttributeId')->willReturn(1);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getSource')->willReturn($source);

        return $attribute;
    }
}
