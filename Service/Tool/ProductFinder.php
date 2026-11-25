<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Service\Tool;

use MagoAssistant\Mago\Api\Tool\PresentableToolInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class ProductFinder extends AbstractSkill implements PresentableToolInterface
{
    public function getName(): string
    {
        return 'sparkable_product_finder';
    }

    public function getDisplayName(): string
    {
        return 'Product finder';
    }

    public function getStatusMessage(string $action, array $input): ?string
    {
        return match ($action) {
            'find_products' => 'Finding products...',
            'parent_products' => 'Looking up parent products...',
            'list_attributes' => 'Reading attributes...',
            default => null,
        };
    }

    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Catalog::products';
    }

    protected function getBaseDescription(): string
    {
        return 'Find products by SKU, attribute filters or parent product, find the configurable, grouped or '
            . 'bundle parents of a child SKU, and find product attributes by code, label or option. Use this '
            . 'for parent and child relations, not a name search.';
    }

    protected function getBaseInstructions(): string
    {
        return <<<'TEXT'
- A filter needs the real attribute code. Codes and labels differ per store and can be in another language.
  When a filter fails on an unknown attribute, use the attribute that the error suggests, or call
  "list_attributes" with the word or the option label.
- total_count is the number of matching products. count is the number of rows shown.
- Children of a parent: "find_products" with a filter on parent_sku.
- Siblings of a SKU: call "parent_products", then "find_products" with a filter on parent_sku for each parent.
  The list includes the given SKU itself. Pass option_attributes as attribute_codes to show the variant values.
- Never derive parent, child or sibling relations from product names.
TEXT;
    }
}
