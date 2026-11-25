<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Test\Unit\Model;

use InvalidArgumentException;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as OptionCollectionFactory;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sparkable\MagoProductFinder\Model\AttributeOptions;
use Sparkable\MagoProductFinder\Model\AttributeSuggester;
use Sparkable\MagoProductFinder\Model\ProductRelations;
use Sparkable\MagoProductFinder\Model\ProductSelection;

class ProductSelectionTest extends TestCase
{
    private ProductSelection $productSelection;
    private Collection&MockObject $collection;
    private CollectionProcessorInterface&MockObject $collectionProcessor;
    private SearchCriteria&MockObject $searchCriteria;
    private int $builtGroupCount = 0;

    protected function setUp(): void
    {
        $attributes = [
            'visibility' => $this->createAttribute('visibility', 'select', [2 => 'Catalog', 4 => 'Catalog, Search']),
            'color' => $this->createAttribute('color', 'select', [10 => 'Red', 11 => 'Blue']),
            'tags' => $this->createAttribute('tags', 'multiselect', [1 => 'New', 2 => 'Sale']),
            'name' => $this->createAttribute('name', 'text'),
            'price' => $this->createAttribute('price', 'price'),
            'missing' => $this->createAttribute('missing', 'text', id: 0),
        ];

        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(
            static fn (string $entityType, string $code): Attribute => $attributes[$code] ?? $attributes['missing']
        );
        $suggester = $this->createMock(AttributeSuggester::class);
        $suggester->method('describeUnknown')->willReturnCallback(
            static fn (string $code, string $value): string => sprintf('Unknown "%s" with "%s"', $code, $value)
        );
        $relations = $this->createMock(ProductRelations::class);
        $relations->method('findChildIds')->willReturnCallback(
            static fn (string $sku): array => match ($sku) {
                'gear' => [5, 6],
                'kit' => [6, 7],
                'lonely' => [],
                default => throw new InvalidArgumentException(sprintf('No product has the SKU "%s"', $sku)),
            }
        );
        $this->collection = $this->createMock(Collection::class);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($this->collection);

        $this->collectionProcessor = $this->createMock(CollectionProcessorInterface::class);
        $this->searchCriteria = $this->createMock(SearchCriteria::class);
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('setFilterGroups')->willReturnCallback(
            function (array $filterGroups) use ($searchCriteriaBuilder): SearchCriteriaBuilder {
                $this->builtGroupCount = count($filterGroups);

                return $searchCriteriaBuilder;
            }
        );
        $searchCriteriaBuilder->method('create')->willReturn($this->searchCriteria);
        $filterGroupBuilder = $this->createMock(FilterGroupBuilder::class);
        $filterGroupBuilder->method('setFilters')->willReturnSelf();
        $filterGroupBuilder->method('create')->willReturn($this->createMock(FilterGroup::class));
        $filterBuilder = $this->createMock(FilterBuilder::class);
        $filterBuilder->method('setField')->willReturnSelf();
        $filterBuilder->method('setConditionType')->willReturnSelf();
        $filterBuilder->method('setValue')->willReturnSelf();
        $filterBuilder->method('create')->willReturn($this->createMock(Filter::class));

        $this->productSelection = new ProductSelection(
            $collectionFactory,
            $this->collectionProcessor,
            $searchCriteriaBuilder,
            $filterGroupBuilder,
            $filterBuilder,
            $eavConfig,
            new AttributeOptions(
                $this->createMock(OptionCollectionFactory::class),
                $this->createMock(StoreManagerInterface::class)
            ),
            $suggester,
            $relations
        );
    }

    public function testParseCleansSkus(): void
    {
        $selection = $this->productSelection->parse(['skus' => [' A-1 ', 'A-1', '', 'B-2', ['x']]]);

        self::assertSame(['A-1', 'B-2'], $selection->skus);
        self::assertSame(
            [[['field' => 'sku', 'condition_type' => 'in', 'value' => ['A-1', 'B-2']]]],
            $selection->filterGroups
        );
        self::assertFalse($selection->isEmpty());
        self::assertTrue($this->productSelection->parse([])->isEmpty());
    }

    public function testParseReadsACommaSeparatedSkuList(): void
    {
        self::assertSame(['A-1', 'B-2'], $this->productSelection->parse(['skus' => ' A-1, B-2 ,A-1,'])->skus);
    }

    public function testDescribeNamesTheSelectionInWords(): void
    {
        self::assertSame(
            ['SKU is one of A-1, B-2', 'color is Red', 'price is at least 10', 'name is empty'],
            $this->productSelection->describe([
                'skus' => 'A-1,B-2',
                'filters' => [
                    ['field' => 'color', 'value' => 'Red'],
                    ['field' => 'price', 'condition_type' => 'gteq', 'value' => '10'],
                    ['field' => 'name', 'condition_type' => 'null'],
                ],
            ])
        );
    }

    #[DataProvider('validFiltersProvider')]
    public function testParseBuildsFilterGroups(array $filter, array $expectedGroups): void
    {
        self::assertSame($expectedGroups, $this->productSelection->parse(['filters' => [$filter]])->filterGroups);
    }

    public static function validFiltersProvider(): array
    {
        $filter = static fn (string $field, string $condition, mixed $value): array => [
            'field' => $field,
            'condition_type' => $condition,
            'value' => $value,
        ];
        $empty = static fn (string $field): array => $filter($field, 'null', true);

        return [
            'option label with a comma stays whole for eq' => [
                ['field' => 'visibility', 'value' => 'Catalog, Search'],
                [[$filter('visibility', 'in', ['4'])]],
            ],
            'option labels and IDs in a list' => [
                ['field' => 'color', 'condition_type' => 'in', 'value' => 'red, 11'],
                [[$filter('color', 'in', ['10', '11'])]],
            ],
            'not equal also matches products without a value' => [
                ['field' => 'color', 'condition_type' => 'neq', 'value' => 'Red'],
                [[$filter('color', 'nin', ['10']), $empty('color')]],
            ],
            'not like also matches products without a value' => [
                ['field' => 'name', 'condition_type' => 'nlike', 'value' => 'Gear'],
                [[$filter('name', 'nlike', '%Gear%'), $empty('name')]],
            ],
            'multiselect has one of the options' => [
                ['field' => 'tags', 'condition_type' => 'in', 'value' => 'New,Sale'],
                [[$filter('tags', 'finset', '1'), $filter('tags', 'finset', '2')]],
            ],
            'multiselect has none of the options' => [
                ['field' => 'tags', 'condition_type' => 'nin', 'value' => 'New,Sale'],
                [
                    [$filter('tags', 'nfinset', '1'), $empty('tags')],
                    [$filter('tags', 'nfinset', '2'), $empty('tags')],
                ],
            ],
            'like on a dropdown stays plain text' => [
                ['field' => 'color', 'condition_type' => 'like', 'value' => 'Re'],
                [[$filter('color', 'like', '%Re%')]],
            ],
            'number value' => [
                ['field' => 'price', 'condition_type' => 'gt', 'value' => 10],
                [[$filter('price', 'gt', '10')]],
            ],
            'plain list' => [
                ['field' => 'price', 'condition_type' => 'in', 'value' => '10,20'],
                [[$filter('price', 'in', ['10', '20'])]],
            ],
            'children of one parent' => [
                ['field' => 'parent_sku', 'value' => 'gear'],
                [[$filter('entity_id', 'in', [5, 6])]],
            ],
            'children of two parents' => [
                ['field' => 'parent_sku', 'condition_type' => 'in', 'value' => 'gear,kit'],
                [[$filter('entity_id', 'in', [5, 6, 7])]],
            ],
            'parent without children matches nothing' => [
                ['field' => 'parent_sku', 'value' => 'lonely'],
                [[$filter('entity_id', 'in', [0])]],
            ],
            'null needs no value' => [
                ['field' => 'name', 'condition_type' => 'null'],
                [[$empty('name')]],
            ],
            'Magento handles a product column' => [
                ['field' => 'type_id', 'value' => 'simple'],
                [[$filter('type_id', 'eq', 'simple')]],
            ],
            'Magento handles categories' => [
                ['field' => 'category_id', 'condition_type' => 'nin', 'value' => '3,4'],
                [[$filter('category_id', 'nin', '3,4')]],
            ],
        ];
    }

    #[DataProvider('invalidFiltersProvider')]
    public function testParseRejectsInvalidFilter(mixed $filter, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->productSelection->parse(['filters' => [$filter]]);
    }

    public static function invalidFiltersProvider(): array
    {
        return [
            'not an object' => ['color', 'must be an object'],
            'no field' => [['value' => 'x'], 'needs a field'],
            'unknown condition' => [
                ['field' => 'name', 'condition_type' => 'contains', 'value' => 'x'],
                'Unknown condition_type',
            ],
            'no value' => [['field' => 'name'], 'needs a value'],
            'unknown parent' => [['field' => 'parent_sku', 'value' => 'nope'], 'No product has the SKU "nope"'],
            'parent condition' => [
                ['field' => 'parent_sku', 'condition_type' => 'neq', 'value' => 'gear'],
                'supports only',
            ],
            'unknown attribute' => [['field' => 'colour', 'value' => 'Red'], 'Unknown "colour" with "Red"'],
            'unknown option' => [['field' => 'color', 'value' => 'Green'], '"Green" is not an option of "color"'],
            'option label with a comma in a list' => [
                ['field' => 'visibility', 'condition_type' => 'in', 'value' => 'Catalog, Search'],
                '"Search" is not an option',
            ],
        ];
    }

    public function testFiltersMustBeAList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->productSelection->parse(['filters' => 'color=Red']);
    }

    public function testCreateCollectionLetsMagentoApplyTheFilterGroups(): void
    {
        $this->collection->expects(self::once())->method('setStoreId')->with(3);
        $this->collectionProcessor->expects(self::once())
            ->method('process')
            ->with($this->searchCriteria, $this->collection);

        $selection = $this->productSelection->parse([
            'skus' => ['A-1'],
            'filters' => [['field' => 'tags', 'condition_type' => 'nin', 'value' => 'New,Sale']],
        ]);

        self::assertSame($this->collection, $this->productSelection->createCollection($selection, 3));
        self::assertSame(3, $this->builtGroupCount);
    }

    private function createAttribute(string $code, string $input, array $options = [], int $id = 1): Attribute
    {
        $source = $this->createMock(AbstractSource::class);
        $source->method('getAllOptions')->willReturn(array_map(
            static fn (int $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys($options),
            $options
        ));

        $attribute = $this->createMock(Attribute::class);
        $attribute->method('getAttributeId')->willReturn($id);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getSource')->willReturn($source);

        return $attribute;
    }
}
