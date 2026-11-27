<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Test\Unit\Service\Tool\ProductFinder;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sparkable\MagoProductFinder\Model\AttributeAccess;
use Sparkable\MagoProductFinder\Model\AttributeSuggester;
use Sparkable\MagoProductFinder\Service\Tool\ProductFinder\ListAttributesAction;

class ListAttributesActionTest extends TestCase
{
    private const int ADMIN_USER_ID = 7;

    private EavConfig&Stub $eavConfig;
    private AttributeAccess&Stub $attributeAccess;

    protected function setUp(): void
    {
        $this->eavConfig = $this->createStub(EavConfig::class);
        $this->eavConfig->method('getAttribute')->willReturn($this->createStub(Attribute::class));

        $this->attributeAccess = $this->createStub(AttributeAccess::class);
        $this->attributeAccess->method('maySetValues')->willReturn(true);
        $this->attributeAccess->method('mayAddOptions')->willReturn(false);
    }

    public function testReadsTheAttributesRouteWithTheSearchFilters(): void
    {
        $suggester = $this->createMock(AttributeSuggester::class);
        $suggester->expects($this->once())
            ->method('findOptionMatches')
            ->with(['Red', 'Red'])
            ->willReturn(['demo_color' => ['Dark Red', 'Red']]);

        $apiClient = $this->createMock(InternalApiClientInterface::class);
        $apiClient->expects($this->once())
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
        $apiClient->expects($this->once())
            ->method('get')
            ->with('products/attributes', ['criteria'], self::ADMIN_USER_ID)
            ->willReturn($this->createResponse('demo_color', 'Color', 'select'));

        $result = $this->createAction($apiClient, $suggester)->execute(
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
                    'matching_options' => ['Dark Red', 'Red'],
                ]],
            ],
            $result
        );
    }

    public function testListsNoMatchingOptionsForAnAttributeFoundByName(): void
    {
        $suggester = $this->createStub(AttributeSuggester::class);
        $suggester->method('findOptionMatches')->willReturn(['demo_color' => ['Red']]);
        $apiClient = $this->createStub(InternalApiClientInterface::class);
        $apiClient->method('buildSearchCriteria')->willReturn(['criteria']);
        $apiClient->method('get')->willReturn($this->createResponse('red_label', 'Red label', 'text'));

        $result = $this->createAction($apiClient, $suggester)->execute(['search' => 'Red'], self::ADMIN_USER_ID);

        $this->assertArrayNotHasKey('matching_options', $result['attributes'][0]);
    }

    public function testSearchesNoOptionsWithoutASearch(): void
    {
        $suggester = $this->createMock(AttributeSuggester::class);
        $suggester->expects($this->never())->method('findOptionMatches');
        $apiClient = $this->createStub(InternalApiClientInterface::class);
        $apiClient->method('buildSearchCriteria')->willReturn([]);
        $apiClient->method('get')->willReturn(['total_count' => 0, 'items' => []]);

        $this->assertSame(
            ['total_count' => 0, 'count' => 0, 'attributes' => []],
            $this->createAction($apiClient, $suggester)->execute(['input_type' => 'select'], self::ADMIN_USER_ID)
        );
    }

    public function testReturnsTheErrorOfTheRoute(): void
    {
        $apiClient = $this->createStub(InternalApiClientInterface::class);
        $apiClient->method('buildSearchCriteria')->willReturn([]);
        $apiClient->method('get')->willReturn(['error' => 'No access']);

        $this->assertSame(
            ['error' => 'Failed to list attributes: No access'],
            $this->createAction($apiClient, $this->createStub(AttributeSuggester::class))
                ->execute([], self::ADMIN_USER_ID)
        );
    }

    private function createAction(
        InternalApiClientInterface $apiClient,
        AttributeSuggester $suggester
    ): ListAttributesAction {
        return new ListAttributesAction($apiClient, $this->eavConfig, $suggester, $this->attributeAccess);
    }

    private function createResponse(string $code, string $label, string $input): array
    {
        return [
            'total_count' => 1,
            'items' => [[
                'attribute_code' => $code,
                'default_frontend_label' => $label,
                'frontend_input' => $input,
                'scope' => $input === 'select' ? 'global' : 'store',
                'is_user_defined' => true,
                'is_required' => false,
            ]],
        ];
    }
}
