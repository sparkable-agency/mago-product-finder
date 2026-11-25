<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Test\Unit\Service\Tool\ProductFinder;

use InvalidArgumentException;
use Magento\Catalog\Model\Product;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sparkable\MagoProductFinder\Model\ProductRelations;
use Sparkable\MagoProductFinder\Service\Tool\ProductFinder\ParentProductsAction;

class ParentProductsActionTest extends TestCase
{
    private ProductRelations&MockObject $productRelations;
    private ParentProductsAction $action;

    protected function setUp(): void
    {
        $this->productRelations = $this->createMock(ProductRelations::class);
        $secureAdminUrl = $this->createMock(SecureAdminUrl::class);
        $secureAdminUrl->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string => $route . '/' . $params['id']
        );

        $this->action = new ParentProductsAction(
            $this->productRelations,
            $secureAdminUrl,
            $this->createMock(ErrorReporter::class)
        );
    }

    public function testExecuteDescribesEveryParent(): void
    {
        $parent = $this->createMock(Product::class);
        $parent->method('getId')->willReturn(20);
        $parent->method('getSku')->willReturn('gear');
        $parent->method('getName')->willReturn('Gear');
        $parent->method('getTypeId')->willReturn('configurable');
        $parent->method('getStatus')->willReturn(1);
        $this->productRelations->method('findParents')->with('gear-red')->willReturn([$parent]);
        $this->productRelations->method('getOptionAttributeCodes')->willReturn(['color']);

        self::assertSame(
            [
                'sku' => 'gear-red',
                'parent_count' => 1,
                'parents' => [[
                    'sku' => 'gear',
                    'name' => 'Gear',
                    'type' => 'configurable',
                    'status' => 'enabled',
                    'option_attributes' => ['color'],
                    'admin_url' => 'catalog/product/edit/20',
                ]],
            ],
            $this->action->execute(['sku' => ' gear-red '], 1)
        );
    }

    public function testExecuteReportsAProductWithoutParents(): void
    {
        $this->productRelations->method('findParents')->willReturn([]);

        self::assertSame(
            ['sku' => 'solo', 'parent_count' => 0, 'parents' => []],
            $this->action->execute(['sku' => 'solo'], 1)
        );
    }

    public function testExecuteReturnsTheLookupError(): void
    {
        $this->productRelations->method('findParents')
            ->willThrowException(new InvalidArgumentException('No product has the SKU "nope"'));

        self::assertSame(['error' => 'No product has the SKU "nope"'], $this->action->execute(['sku' => 'nope'], 1));
        self::assertSame(['error' => 'sku is required'], $this->action->execute([], 1));
    }

    public function testEveryResultFieldIsClassified(): void
    {
        $classified = array_keys($this->action->getFieldClassification());

        self::assertEqualsCanonicalizing(
            ['sku', 'name', 'type', 'status', 'option_attributes', 'parent_count', 'admin_url'],
            $classified
        );
        self::assertTrue($this->action->isReadOnly());
    }
}
