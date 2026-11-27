<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Model;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as OptionCollectionFactory;
use Magento\Framework\DB\Select;

class AttributeSuggester
{
    private const int HINT_LIMIT = 5;
    private const int PARTIAL_MATCH_MIN_LENGTH = 3;
    private const int MATCHED_LABEL_LIMIT = 10;
    private const int MATCHED_ROW_LIMIT = 500;

    public function __construct(
        private readonly AttributeCollectionFactory $attributeCollectionFactory,
        private readonly OptionCollectionFactory $optionCollectionFactory,
    ) {
    }

    public function describeUnknown(string $code, string $value = ''): string
    {
        $message = sprintf('Unknown product attribute "%s".', $code);
        $byName = $this->findByName($code);
        $byOption = $this->findByOptionLabels($this->splitWords($value));

        if ($byName) {
            $message .= ' Attributes with a similar code or label: ' . $this->format($byName) . '.';
        }
        if ($byOption) {
            $message .= sprintf(' Attributes with the option "%s": %s.', $value, $this->format($byOption));
        }
        if (!$byName && !$byOption) {
            $message .= ' Use list_attributes of sparkable_product_finder to find the attribute code.';
        }

        return $message;
    }

    public function findOptionMatches(array $terms): array
    {
        $terms = array_values(array_unique(array_filter(array_map(trim(...), $terms), strlen(...))));
        if (!$terms) {
            return [];
        }

        $options = $this->optionCollectionFactory->create();
        $connection = $options->getConnection();
        $conditions = [$connection->quoteInto('label.value IN (?)', $terms)];
        foreach ($terms as $term) {
            if (mb_strlen($term) >= self::PARTIAL_MATCH_MIN_LENGTH) {
                $conditions[] = $connection->quoteInto('label.value LIKE ?', '%' . addcslashes($term, '%_\\') . '%');
            }
        }

        $select = $options->getSelect()
            ->reset(Select::COLUMNS)
            ->join(
                ['label' => $options->getTable('eav_attribute_option_value')],
                'label.option_id = main_table.option_id',
                []
            )
            ->join(
                ['attribute' => $options->getTable('eav_attribute')],
                'attribute.attribute_id = main_table.attribute_id',
                ['attribute_code']
            )
            ->join(
                ['catalog_attribute' => $options->getTable('catalog_eav_attribute')],
                'catalog_attribute.attribute_id = main_table.attribute_id',
                []
            )
            ->where(implode(' OR ', $conditions));

        $codes = $connection->fetchCol((clone $select)->distinct()->order('attribute.attribute_code ASC'));
        $matches = array_fill_keys($codes, []);
        $labelSelect = $select
            ->columns(['label' => 'label.value'])
            ->group(['attribute.attribute_code', 'label.value'])
            ->order(['attribute.attribute_code ASC', 'MIN(label.store_id) ASC', 'MIN(main_table.sort_order) ASC'])
            ->limit(self::MATCHED_ROW_LIMIT);
        foreach ($connection->fetchAll($labelSelect) as $row) {
            if (count($matches[$row['attribute_code']] ?? []) < self::MATCHED_LABEL_LIMIT) {
                $matches[$row['attribute_code']][] = $row['label'];
            }
        }

        return $matches;
    }

    private function findByName(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $collection = $this->attributeCollectionFactory->create();
        $collection->addFieldToFilter(
            ['main_table.attribute_code', 'main_table.frontend_label'],
            [['like' => '%' . $text . '%'], ['like' => '%' . $text . '%']]
        );
        $collection->setOrder('attribute_code', 'ASC');

        return $this->toMap($collection->getItems());
    }

    private function findByOptionLabels(array $labels): array
    {
        $labels = array_values(array_filter(array_map(trim(...), $labels), strlen(...)));
        if (!$labels) {
            return [];
        }

        $options = $this->optionCollectionFactory->create();
        $options->getSelect()
            ->join(
                ['label' => $options->getTable('eav_attribute_option_value')],
                'label.option_id = main_table.option_id',
                []
            )
            ->where('label.value IN (?)', $labels)
            ->group('main_table.option_id');
        $attributeIds = array_unique($options->getColumnValues('attribute_id'));
        if (!$attributeIds) {
            return [];
        }

        $collection = $this->attributeCollectionFactory->create();
        $collection->addFieldToFilter('main_table.attribute_id', ['in' => $attributeIds]);
        $collection->setOrder('attribute_code', 'ASC');

        return $this->toMap($collection->getItems());
    }

    private function splitWords(string $value): array
    {
        return array_map(trim(...), explode(',', $value));
    }

    private function toMap(array $attributes): array
    {
        $map = [];
        foreach ($attributes as $attribute) {
            $map[(string)$attribute->getAttributeCode()] = (string)$attribute->getData('frontend_label');
        }

        return $map;
    }

    private function format(array $map): string
    {
        $shown = array_slice($map, 0, self::HINT_LIMIT, true);
        $rest = count($map) - count($shown);

        return implode(', ', array_map(
            static fn (string $code, string $label): string => sprintf('%s (%s)', $code, $label),
            array_keys($shown),
            $shown
        )) . ($rest > 0 ? sprintf(' and %d more', $rest) : '');
    }
}
