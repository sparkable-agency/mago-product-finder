<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Model;

use InvalidArgumentException;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

class ProductSelection
{
    private const string PARENT_FIELD = 'parent_sku';
    private const array MAGENTO_FIELDS = [
        'category_id', 'entity_id', 'attribute_set_id', 'type_id', 'created_at', 'updated_at',
    ];
    private const array CONDITIONS = [
        'eq', 'neq', 'like', 'nlike', 'in', 'nin', 'gt', 'gteq', 'lt', 'lteq', 'null', 'notnull',
    ];
    private const array NO_VALUE_CONDITIONS = ['null', 'notnull'];
    private const array LIST_CONDITIONS = ['in', 'nin'];
    private const array OPTION_CONDITIONS = ['eq', 'neq', 'in', 'nin'];
    private const array OPTION_INPUTS = ['select', 'multiselect', 'boolean'];
    private const array NEGATIVE_CONDITIONS = ['neq', 'nin', 'nlike'];
    private const array CONDITION_WORDS = [
        'eq' => 'is',
        'neq' => 'is not',
        'like' => 'contains',
        'nlike' => 'does not contain',
        'in' => 'is one of',
        'nin' => 'is none of',
        'gt' => 'is more than',
        'gteq' => 'is at least',
        'lt' => 'is less than',
        'lteq' => 'is at most',
        'null' => 'is empty',
        'notnull' => 'is not empty',
    ];

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly EavConfig $eavConfig,
        private readonly AttributeOptions $attributeOptions,
        private readonly AttributeSuggester $attributeSuggester,
        private readonly ProductRelations $productRelations,
    ) {
    }

    public static function getParameterSchema(): array
    {
        return [
            'skus' => [
                'type' => 'string',
                'description' => 'Exact SKUs to select, comma-separated.',
            ],
            'filters' => [
                'type' => 'array',
                'description' => 'Search criteria filters that select products. All filters must match.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'field' => [
                            'type' => 'string',
                            'description' => 'Product attribute code, or one of: '
                                . implode(', ', self::MAGENTO_FIELDS) . ', or ' . self::PARENT_FIELD
                                . ' for the child products of a configurable, grouped or bundle product',
                        ],
                        'condition_type' => [
                            'type' => 'string',
                            'enum' => self::CONDITIONS,
                            'description' => 'Default: eq. neq, nin and nlike also match products without a value.',
                        ],
                        'value' => [
                            'type' => 'string',
                            'description' => 'Comma-separated for in and nin. Leave out for null and notnull. '
                                . 'Option label or option ID for dropdown attributes, 1 or 0 for yes/no attributes.',
                        ],
                    ],
                    'required' => ['field'],
                ],
            ],
            'store_id' => [
                'type' => 'integer',
                'description' => 'Store view ID. 0 (default) is the global scope.',
            ],
        ];
    }

    public function parse(array $params): Selection
    {
        $filters = $params['filters'] ?? [];
        if (!is_array($filters)) {
            throw new InvalidArgumentException('filters must be a list of search criteria filters');
        }

        $skus = $this->parseSkus($params['skus'] ?? []);
        $filterGroups = $skus ? [[['field' => 'sku', 'condition_type' => 'in', 'value' => $skus]]] : [];
        foreach ($filters as $filter) {
            array_push($filterGroups, ...$this->parseFilter($filter));
        }

        return new Selection($skus, $filterGroups);
    }

    public function describe(array $params): array
    {
        $skus = $this->parseSkus($params['skus'] ?? []);
        $phrases = $skus ? ['SKU is one of ' . implode(', ', $skus)] : [];
        foreach (is_array($params['filters'] ?? null) ? $params['filters'] : [] as $filter) {
            $condition = self::CONDITION_WORDS[$filter['condition_type'] ?? ''] ?? self::CONDITION_WORDS['eq'];
            $value = is_scalar($filter['value'] ?? null) ? ' ' . trim((string)$filter['value']) : '';
            $phrases[] = trim(($filter['field'] ?? '') . ' ' . $condition . $value);
        }

        return $phrases;
    }

    public function createCollection(Selection $selection, int $storeId): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $this->collectionProcessor->process(
            $this->searchCriteriaBuilder
                ->setFilterGroups(array_map($this->createFilterGroup(...), $selection->filterGroups))
                ->create(),
            $collection
        );

        return $collection;
    }

    private function createFilterGroup(array $filters): FilterGroup
    {
        return $this->filterGroupBuilder
            ->setFilters(array_map(
                fn (array $filter) => $this->filterBuilder
                    ->setField($filter['field'])
                    ->setConditionType($filter['condition_type'])
                    ->setValue($filter['value'])
                    ->create(),
                $filters
            ))
            ->create();
    }

    private function parseSkus(mixed $skus): array
    {
        if (is_string($skus)) {
            $skus = explode(',', $skus);
        }
        if (!is_array($skus)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $sku): string => is_scalar($sku) ? trim((string)$sku) : '', $skus),
            static fn (string $sku): bool => $sku !== ''
        )));
    }

    private function parseFilter(mixed $filter): array
    {
        if (!is_array($filter)) {
            throw new InvalidArgumentException('Each filter must be an object');
        }

        $field = is_string($filter['field'] ?? null) ? trim($filter['field']) : '';
        $condition = is_string($filter['condition_type'] ?? null) && $filter['condition_type'] !== ''
            ? $filter['condition_type']
            : 'eq';
        $value = match (true) {
            is_bool($filter['value'] ?? null) => $filter['value'] ? '1' : '0',
            is_scalar($filter['value'] ?? null) => trim((string)$filter['value']),
            default => '',
        };

        if ($field === '') {
            throw new InvalidArgumentException('Each filter needs a field');
        }
        if (!in_array($condition, self::CONDITIONS, true)) {
            throw new InvalidArgumentException(
                sprintf('Unknown condition_type "%s". Use one of: %s', $condition, implode(', ', self::CONDITIONS))
            );
        }
        if (in_array($condition, self::NO_VALUE_CONDITIONS, true)) {
            return [[['field' => $field, 'condition_type' => $condition, 'value' => true]]];
        }
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The filter on "%s" needs a value', $field));
        }

        $values = in_array($condition, self::LIST_CONDITIONS, true)
            ? array_map(trim(...), explode(',', $value))
            : [$value];

        if ($field === self::PARENT_FIELD) {
            return [[$this->createParentFilter($condition, $values)]];
        }
        if (in_array($field, self::MAGENTO_FIELDS, true)) {
            return [[['field' => $field, 'condition_type' => $condition, 'value' => $value]]];
        }

        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $field);
        if (!$attribute || !$attribute->getAttributeId()) {
            throw new InvalidArgumentException($this->attributeSuggester->describeUnknown($field, $value));
        }

        return $this->createAttributeFilterGroups($attribute, $condition, $values);
    }

    private function createParentFilter(string $condition, array $parentSkus): array
    {
        if (!in_array($condition, ['eq', 'in'], true)) {
            throw new InvalidArgumentException('A parent_sku filter supports only: eq, in');
        }

        $childIds = [];
        foreach ($parentSkus as $parentSku) {
            array_push($childIds, ...$this->productRelations->findChildIds($parentSku));
        }

        $childIds = array_values(array_unique($childIds));

        return ['field' => 'entity_id', 'condition_type' => 'in', 'value' => $childIds ?: [0]];
    }

    private function createAttributeFilterGroups(AbstractAttribute $attribute, string $condition, array $values): array
    {
        $field = $attribute->getAttributeCode();
        $input = $attribute->getFrontendInput();
        $isNegative = in_array($condition, self::NEGATIVE_CONDITIONS, true);
        $isOptionFilter = in_array($condition, self::OPTION_CONDITIONS, true)
            && in_array($input, self::OPTION_INPUTS, true);
        $empty = ['field' => $field, 'condition_type' => 'null', 'value' => true];

        if ($isOptionFilter) {
            $values = array_map(fn (string $value): string => $this->resolveOption($attribute, $value), $values);
        }
        if ($isOptionFilter && $input === 'multiselect') {
            $filters = array_map(
                static fn (string $optionId): array => [
                    'field' => $field,
                    'condition_type' => $isNegative ? 'nfinset' : 'finset',
                    'value' => $optionId,
                ],
                $values
            );

            return $isNegative
                ? array_map(static fn (array $filter): array => [$filter, $empty], $filters)
                : [$filters];
        }

        $filter = match (true) {
            $isOptionFilter => ['condition_type' => $isNegative ? 'nin' : 'in', 'value' => $values],
            in_array($condition, self::LIST_CONDITIONS, true) => ['condition_type' => $condition, 'value' => $values],
            in_array($condition, ['like', 'nlike'], true) && !str_contains($values[0], '%')
                => ['condition_type' => $condition, 'value' => '%' . $values[0] . '%'],
            default => ['condition_type' => $condition, 'value' => $values[0]],
        };
        $filter = ['field' => $field] + $filter;

        return [$isNegative ? [$filter, $empty] : [$filter]];
    }

    private function resolveOption(AbstractAttribute $attribute, string $value): string
    {
        return $this->attributeOptions->findOptionId($attribute, $value)
            ?? throw new InvalidArgumentException(sprintf(
                '"%s" is not an option of "%s". Options: %s',
                $value,
                $attribute->getAttributeCode(),
                $this->attributeOptions->describe($attribute)
            ));
    }
}
