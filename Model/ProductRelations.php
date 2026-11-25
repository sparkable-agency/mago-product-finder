<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Model;

use InvalidArgumentException;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\NoSuchEntityException;

class ProductRelations
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly array $parentTypes = [],
    ) {
    }

    public function findParents(string $sku): array
    {
        $childId = (int)$this->getProduct($sku)->getId();
        $parentIds = [];
        foreach ($this->parentTypes as $type) {
            if ($type instanceof AbstractType) {
                array_push($parentIds, ...array_map(intval(...), $type->getParentIdsByChild($childId)));
            }
        }
        if (!$parentIds) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->setStoreId(0);
        $collection->addAttributeToSelect(['name', 'status']);
        $collection->addIdFilter(array_unique($parentIds));
        $collection->setOrder('entity_id', 'ASC');

        return array_values($collection->getItems());
    }

    public function findChildIds(string $parentSku): array
    {
        $parent = $this->getProduct($parentSku);
        $type = $this->parentTypes[$parent->getTypeId()] ?? null;
        if (!$type instanceof AbstractType) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is a %s product and has no child products',
                $parentSku,
                $parent->getTypeId()
            ));
        }

        $childIds = [];
        foreach ($type->getChildrenIds((int)$parent->getId(), false) as $group) {
            array_push($childIds, ...array_map(intval(...), (array)$group));
        }

        return array_values(array_unique($childIds));
    }

    public function getOptionAttributeCodes(Product $parent): array
    {
        $type = $this->parentTypes[$parent->getTypeId()] ?? null;
        if (!$type instanceof Configurable) {
            return [];
        }

        return array_values(array_map(
            static fn ($attribute): string => (string)$attribute->getAttributeCode(),
            $type->getUsedProductAttributes($parent)
        ));
    }

    private function getProduct(string $sku): ProductInterface
    {
        try {
            return $this->productRepository->get($sku);
        } catch (NoSuchEntityException $e) {
            throw new InvalidArgumentException($e->getMessage(), previous: $e);
        }
    }
}
