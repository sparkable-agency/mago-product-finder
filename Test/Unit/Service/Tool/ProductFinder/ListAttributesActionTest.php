<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Test\Unit\Service\Tool\ProductFinder;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sparkable\MagoProductFinder\Model\AttributeAccess;
use Sparkable\MagoProductFinder\Model\AttributeSuggester;
use Sparkable\MagoProductFinder\Service\Tool\ProductFinder\ListAttributesAction;

class ListAttributesActionTest extends TestCase
{
    private const int ADMIN_USER_ID = 7;

    private InternalApiClientInterface&MockObject $apiClient;
    private ListAttributesAction $action;

    protected function setUp(): void
    {
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($this->createMock(Attribute::class));

        $attributeSuggester = $this->createMock(AttributeSuggester::class);
        $attributeSuggester->method('findCodesByOptionLabels')->willReturn(['demo_color']);

        $attributeAccess = $this->createMock(AttributeAccess::class);
        $attributeAccess->method('maySetValues')->willReturn(true);
        $attributeAccess->method('mayAddOptions')->willReturn(false);

        $this->apiClient = $this->createMock(InternalApiClientInterface::class);
        $this->action = new ListAttributesAction(
            $this->apiClient,
            $eavConfig,
            $attributeSuggester,
            $attributeAccess,
        );
    }

    public function testReadsTheAttributesRouteWithTheSearchFilters(): void
    {
        $this->apiClient->expects($this->once())
            ->method('buildSearchCriteria')
            ->with(
                [
                    ['field' => 'attribute_code', 'value' => 'price,name', 'condition_type' => 'in'],
                    [
                        ['field' => 'attribute_code', 'value' => '%Red%', 'condition_type' => 'like'],
                        ['field' => 'frontend_label', 'value' => '%Red%', 'condition_type' => 'like'],
                        ['field' => 'attribute_code', 'value' => 'demo_color', 'condition_type' => 'in'],
                    ],
                    ['field' => 'frontend_input', 'value' => 'select'],
                ],
                25,
                1,
                [['field' => 'attribute_code']]
            )
            ->willReturn(['criteria']);
        $this->apiClient->expects($this->once())
            ->method('get')
            ->with('products/attributes', ['criteria'], self::ADMIN_USER_ID)
            ->willReturn([
                'total_count' => 1,
                'items' => [[
                    'attribute_code' => 'demo_color',
                    'default_frontend_label' => 'Color',
                    'frontend_input' => 'select',
                    'scope' => 'global',
                    'is_user_defined' => true,
                    'is_required' => false,
                ]],
            ]);

        $result = $this->action->execute(
            ['attribute_codes' => ['price', ' name '], 'search' => 'Red', 'input_type' => 'select'],
            self::ADMIN_USER_ID
        );

        $this->assertSame(
            [
                'total_count' => 1,
                'count' => 1,
                'attributes' => [[
                    'attribute_code' => 'demo_color',
                    'label' => 'Color',
                    'input_type' => 'select',
                    'scope' => 'global',
                    'is_user_defined' => true,
                    'is_required' => false,
                    'may_set_values' => true,
                    'may_add_options' => false,
                ]],
            ],
            $result
        );
    }

    public function testReturnsTheErrorOfTheRoute(): void
    {
        $this->apiClient->method('buildSearchCriteria')->willReturn([]);
        $this->apiClient->method('get')->willReturn(['error' => 'No access']);

        $this->assertSame(
            ['error' => 'Failed to list attributes: No access'],
            $this->action->execute([], self::ADMIN_USER_ID)
        );
    }
}
