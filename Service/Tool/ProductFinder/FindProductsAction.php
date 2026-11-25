<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Service\Tool\ProductFinder;

use InvalidArgumentException;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Eav\Model\Config as EavConfig;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Error\ErrorReporter;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\ResultLimit;
use MagoAssistant\Mago\Service\Store\StoreScopeContext;
use MagoAssistant\Mago\Service\Url\SecureAdminUrl;
use Sparkable\MagoProductFinder\Model\AttributeSuggester;
use Sparkable\MagoProductFinder\Model\ProductSelection;

class FindProductsAction implements ActionInterface
{
    private const int DEFAULT_LIMIT = 10;
    private const int MAX_LIMIT = 50;
    private const int MAX_ATTRIBUTE_CODES = 8;

    public function __construct(
        private readonly ProductSelection $productSelection,
        private readonly StoreScopeContext $storeScopeContext,
        private readonly EavConfig $eavConfig,
        private readonly AttributeSuggester $attributeSuggester,
        private readonly SecureAdminUrl $secureAdminUrl,
        private readonly ErrorReporter $errorReporter,
    ) {
    }

    public function getName(): string
    {
        return 'find_products';
    }

    public function getDescription(): string
    {
        return 'Count and list the products that match SKUs or attribute filters, with the current values '
            . 'of chosen attributes';
    }

    public function getParameterSchema(): array
    {
        return ProductSelection::getParameterSchema() + [
            'attribute_codes' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Attribute codes to read',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Number of rows (default: ' . self::DEFAULT_LIMIT . ', max: ' . self::MAX_LIMIT . ')',
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
            'total_count' => [PiiClass::PUBLIC],
            'count' => [PiiClass::PUBLIC],
            'scope' => [PiiClass::PUBLIC],
            'skus_not_found' => [PiiClass::PUBLIC],
            'sku' => [PiiClass::PUBLIC],
            'name' => [PiiClass::PUBLIC],
            'type' => [PiiClass::PUBLIC],
            'status' => [PiiClass::PUBLIC],
            'price' => [PiiClass::PUBLIC],
            'attribute_code' => [PiiClass::PUBLIC],
            'value' => [PiiClass::PUBLIC],
            'display_value' => [PiiClass::PUBLIC],
            'admin_url' => [PiiClass::TOKENISE, 'url'],
        ];
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $storeId = (int)($params['store_id'] ?? 0);
        if ($storeId !== 0 && !$this->storeScopeContext->hasStoreView($storeId)) {
            return ['error' => $this->storeScopeContext->getUnknownStoreViewError($storeId)];
        }
        try {
            $selection = $this->productSelection->parse($params);
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        $codes = $this->getAttributeCodes($params);
        foreach ($codes as $code) {
            if (!$this->eavConfig->getAttribute(Product::ENTITY, $code)->getAttributeId()) {
                return ['error' => $this->attributeSuggester->describeUnknown($code)];
            }
        }

        $limit = ResultLimit::fromParams($params, self::DEFAULT_LIMIT, self::MAX_LIMIT);

        try {
            $collection = $this->productSelection->createCollection($selection, $storeId);
            $collection->addAttributeToSelect(array_merge(['name', 'price', 'status'], $codes));
            $collection->setOrder('entity_id', 'ASC');
            $collection->setPageSize($limit);
            $products = $this->describeProducts($collection, $codes);

            return [
                'total_count' => (int)$collection->getSize(),
                'count' => count($products),
                'scope' => $this->storeScopeContext->describeStoreTarget($storeId),
                'skus_not_found' => $this->findMissingSkus($selection->skus, $storeId),
                'products' => $products,
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to find products: '
                . $this->errorReporter->reportToolFailure('sparkable_product_finder find_products', $e)];
        }
    }

    private function describeProducts(Collection $collection, array $codes): array
    {
        $products = [];
        foreach ($collection as $product) {
            $row = [
                'sku' => (string)$product->getSku(),
                'name' => (string)$product->getName(),
                'type' => (string)$product->getTypeId(),
                'status' => (int)$product->getStatus() === Status::STATUS_ENABLED ? 'enabled' : 'disabled',
                'price' => (float)$product->getPrice(),
                'admin_url' => $this->secureAdminUrl->getUrl('catalog/product/edit', ['id' => (int)$product->getId()]),
            ];
            if ($codes) {
                $row['values'] = array_map(
                    fn (string $code): array => $this->describeValue($product, $code),
                    $codes
                );
            }
            $products[] = $row;
        }

        return $products;
    }

    private function getAttributeCodes(array $params): array
    {
        $codes = is_array($params['attribute_codes'] ?? null) ? $params['attribute_codes'] : [];
        $codes = array_filter(array_map(
            static fn (mixed $code): string => is_string($code) ? trim($code) : '',
            $codes
        ));

        return array_slice(array_values(array_unique($codes)), 0, self::MAX_ATTRIBUTE_CODES);
    }

    private function describeValue(Product $product, string $code): array
    {
        $value = $product->getData($code);
        $display = $value === null || $value === ''
            ? ''
            : $product->getResource()->getAttribute($code)->getFrontend()->getValue($product);

        return [
            'attribute_code' => $code,
            'value' => $this->toText($value),
            'display_value' => $this->toText($display),
        ];
    }

    private function toText(mixed $value): string
    {
        return match (true) {
            is_array($value) => implode(', ', array_map($this->toText(...), $value)),
            is_scalar($value), $value instanceof \Stringable => (string)$value,
            default => '',
        };
    }

    private function findMissingSkus(array $skus, int $storeId): array
    {
        if (!$skus) {
            return [];
        }

        $selection = $this->productSelection->parse(['skus' => $skus]);
        $collection = $this->productSelection->createCollection($selection, $storeId);
        $found = array_map(mb_strtolower(...), $collection->getColumnValues('sku'));

        return array_values(array_filter(
            $skus,
            static fn (string $sku): bool => !in_array(mb_strtolower($sku), $found, true)
        ));
    }
}
