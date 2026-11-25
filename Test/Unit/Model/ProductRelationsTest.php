<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Test\Unit\Model;

use InvalidArgumentException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sparkable\MagoProductFinder\Model\ProductRelations;

class ProductRelationsTest extends TestCase
{
    private Collection&MockObject $collection;
    private ProductRepositoryInterface&MockObject $productRepository;
    private Configurable&MockObject $configurableType;
    private AbstractType&MockObject $groupedType;
    private ProductRelations $productRelations;

    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($this->collection);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->configurableType = $this->createMock(Configurable::class);
        $this->groupedType = $this->createMock(AbstractType::class);

        $this->productRelations = new ProductRelations(
            $collectionFactory,
            $this->productRepository,
            ['configurable' => $this->configurableType, 'grouped' => $this->groupedType]
        );
    }

    public function testFindParentsCollectsParentsOfEveryType(): void
    {
        $parent = $this->createProduct(20, 'configurable');
        $this->productRepository->method('get')->willReturn($this->createProduct(7, 'simple'));
        $this->configurableType->method('getParentIdsByChild')->with(7)->willReturn(['20']);
        $this->groupedType->method('getParentIdsByChild')->with(7)->willReturn(['30', '20']);
        $this->collection->expects(self::once())->method('addIdFilter')->with([0 => 20, 1 => 30]);
        $this->collection->method('getItems')->willReturn([20 => $parent]);

        self::assertSame([$parent], $this->productRelations->findParents('child'));
    }

    public function testFindParentsReturnsNothingForAStandaloneProduct(): void
    {
        $this->productRepository->method('get')->willReturn($this->createProduct(7, 'simple'));
        $this->configurableType->method('getParentIdsByChild')->willReturn([]);
        $this->groupedType->method('getParentIdsByChild')->willReturn([]);
        $this->collection->expects(self::never())->method('addIdFilter');

        self::assertSame([], $this->productRelations->findParents('child'));
    }

    public function testFindParentsRejectsAnUnknownSku(): void
    {
        $this->productRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('The product does not exist.')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The product does not exist.');

        $this->productRelations->findParents('nope');
    }

    public function testFindChildIdsFlattensTheChildGroups(): void
    {
        $this->productRepository->method('get')->willReturn($this->createProduct(20, 'grouped'));
        $this->groupedType->method('getChildrenIds')->with(20, false)->willReturn([3 => ['5', '6'], 4 => ['6', '8']]);

        self::assertSame([5, 6, 8], $this->productRelations->findChildIds('set'));
    }

    public function testFindChildIdsRejectsAProductThatIsNoParent(): void
    {
        $this->productRepository->method('get')->willReturn($this->createProduct(7, 'simple'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"child" is a simple product and has no child products');

        $this->productRelations->findChildIds('child');
    }

    public function testOptionAttributeCodesExistOnlyForAConfigurableParent(): void
    {
        $color = $this->createMock(Attribute::class);
        $color->method('getAttributeCode')->willReturn('color');
        $size = $this->createMock(Attribute::class);
        $size->method('getAttributeCode')->willReturn('size');
        $this->configurableType->method('getUsedProductAttributes')->willReturn([93 => $color, 144 => $size]);

        self::assertSame(
            ['color', 'size'],
            $this->productRelations->getOptionAttributeCodes($this->createProduct(20, 'configurable'))
        );
        self::assertSame([], $this->productRelations->getOptionAttributeCodes($this->createProduct(30, 'grouped')));
    }

    private function createProduct(int $id, string $typeId): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getTypeId')->willReturn($typeId);

        return $product;
    }
}
