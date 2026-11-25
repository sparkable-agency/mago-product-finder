<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Service\Tool\ProductFinder;

use InvalidArgumentException;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use Sparkable\MagoProductFinder\Model\ProductRelations;

class ParentProductsAction implements ActionInterface
{
    public function __construct(
        private readonly ProductRelations $productRelations,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly ErrorReporter $errorReporter,
    ) {
    }

    public function getName(): string
    {
        return 'parent_products';
    }

    public function getDescription(): string
    {
        return 'Find the configurable, grouped or bundle parent products of a child SKU';
    }

    public function getParameterSchema(): array
    {
        return [
            'sku' => [
                'type' => 'string',
                'description' => 'SKU of the child product',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'sku' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'type' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'option_attributes' => [PiiClass::PUBLIC],
            'parent_count' => [PiiClass::PUBLIC],
            'admin_url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $sku = is_scalar($params['sku'] ?? null) ? trim((string)$params['sku']) : '';
        if ($sku === '') {
            return ['error' => 'sku is required'];
        }

        try {
            $parents = array_map($this->describe(...), $this->productRelations->findParents($sku));
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Exception $e) {
            return ['error' => 'Failed to find parent products: '
                . $this->errorReporter->reportToolFailure('sparkable_product_finder parent_products', $e)];
        }

        return ['sku' => $sku, 'parent_count' => count($parents), 'parents' => $parents];
    }

    private function describe(Product $parent): array
    {
        return [
            'sku' => (string)$parent->getSku(),
            'name' => (string)$parent->getName(),
            'type' => (string)$parent->getTypeId(),
            'status' => (int)$parent->getStatus() === Status::STATUS_ENABLED ? 'enabled' : 'disabled',
            'option_attributes' => $this->productRelations->getOptionAttributeCodes($parent),
            'admin_url' => $this->secureAdminUrl->getUrl('catalog/product/edit', ['id' => (int)$parent->getId()]),
        ];
    }
}
