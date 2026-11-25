<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Model;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as OptionCollectionFactory;

class AttributeSuggester
{
    private const int HINT_LIMIT = 5;

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

    public function findCodesByOptionLabels(array $labels): array
    {
        return array_keys($this->findByOptionLabels($labels));
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
