<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Model;

use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory as OptionCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use WeakMap;

class AttributeOptions
{
    private const int DESCRIBE_LIMIT = 30;

    private WeakMap $optionsByAttribute;

    public function __construct(
        private readonly OptionCollectionFactory $optionCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
    ) {
        $this->optionsByAttribute = new WeakMap();
    }

    public function findOptionId(AbstractAttribute $attribute, string $value): ?string
    {
        $value = trim($value);
        $options = $this->getOptions($attribute);
        if (isset($options[$value])) {
            return $value;
        }

        $needle = mb_strtolower($value);
        foreach ($options as $optionId => $labels) {
            if (in_array($needle, array_map(mb_strtolower(...), $labels), true)) {
                return (string)$optionId;
            }
        }

        return null;
    }

    public function hasLabel(AbstractAttribute $attribute, string $label): bool
    {
        $needle = mb_strtolower(trim($label));
        foreach ($this->getOptions($attribute) as $labels) {
            if (in_array($needle, array_map(mb_strtolower(...), $labels), true)) {
                return true;
            }
        }

        return false;
    }

    public function count(AbstractAttribute $attribute): int
    {
        return count($this->getOptions($attribute));
    }

    public function getLabel(AbstractAttribute $attribute, string $optionId): string
    {
        return $this->getOptions($attribute)[$optionId][0] ?? $optionId;
    }

    public function describe(AbstractAttribute $attribute): string
    {
        $labels = array_column($this->getOptions($attribute), 0);
        $shown = array_slice($labels, 0, self::DESCRIBE_LIMIT);
        $rest = count($labels) - count($shown);

        return '"' . implode('", "', $shown) . '"' . ($rest > 0 ? sprintf(' and %d more', $rest) : '');
    }

    private function getOptions(AbstractAttribute $attribute): array
    {
        return $this->optionsByAttribute[$attribute] ??= $this->loadOptions($attribute);
    }

    private function loadOptions(AbstractAttribute $attribute): array
    {
        $source = $attribute->getSource();
        $lists = $source instanceof Table
            ? [$source->getAllOptions(false, true), $this->loadStoreLabels($attribute)]
            : [$source->getAllOptions(false)];

        $options = [];
        foreach ($lists as $list) {
            foreach ($list as $option) {
                $optionId = $option['value'] ?? '';
                if (!is_scalar($optionId) || (string)$optionId === '') {
                    continue;
                }
                $label = trim((string)($option['label'] ?? ''));
                if (!in_array($label, $options[(string)$optionId] ?? [], true)) {
                    $options[(string)$optionId][] = $label;
                }
            }
        }

        // A yes/no attribute that a module created without a source model has no option list.
        if (!$options && $attribute->getFrontendInput() === 'boolean') {
            return match ($attribute->getAttributeCode()) {
                'price_type', 'sku_type', 'weight_type' => ['1' => ['Fixed'], '0' => ['Dynamic']],
                default => ['1' => ['Yes'], '0' => ['No']],
            };
        }

        return $options;
    }

    private function loadStoreLabels(AbstractAttribute $attribute): array
    {
        $labels = [];
        foreach ($this->storeManager->getStores() as $store) {
            $collection = $this->optionCollectionFactory->create();
            $collection->setAttributeFilter($attribute->getAttributeId());
            $collection->setStoreFilter((int)$store->getId(), false);
            array_push($labels, ...$collection->toOptionArray());
        }

        return $labels;
    }
}
