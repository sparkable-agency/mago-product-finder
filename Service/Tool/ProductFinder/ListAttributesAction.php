<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Service\Tool\ProductFinder;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use MagoAssistant\Mago\Api\InternalApiClientInterface;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;
use MagoAssistant\Mago\Service\Skills\ResultLimit;
use Sparkable\MagoProductFinder\Model\AttributeAccess;
use Sparkable\MagoProductFinder\Model\AttributeSuggester;

class ListAttributesAction implements ActionInterface
{
    private const int DEFAULT_LIMIT = 25;
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly InternalApiClientInterface $apiClient,
        private readonly EavConfig $eavConfig,
        private readonly AttributeSuggester $attributeSuggester,
        private readonly AttributeAccess $attributeAccess,
    ) {
    }

    public function getName(): string
    {
        return 'list_attributes';
    }

    public function getDescription(): string
    {
        return 'Find product attributes by code, label or option label';
    }

    public function getParameterSchema(): array
    {
        return [
            'attribute_codes' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Attribute codes to read',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Words to find. A word matches an attribute code, an attribute label or an '
                    . 'exact option label, e.g. "color" or "Red"',
            ],
            'input_type' => [
                'type' => 'string',
                'description' => 'Frontend input type, e.g. select, multiselect, text, price, boolean',
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
        return array_fill_keys(
            [
                'total_count',
                'count',
                'attribute_code',
                'label',
                'input_type',
                'scope',
                'is_user_defined',
                'is_required',
                'may_set_values',
                'may_add_options',
            ],
            [PiiClass::PUBLIC]
        );
    }

    public function getInstructions(): string
    {
        return '';
    }

    public function execute(array $params, int $adminUserId): array
    {
        if (!$adminUserId) {
            return ['error' => 'Admin user context is required'];
        }

        $codes = array_values(array_filter(
            is_array($params['attribute_codes'] ?? null) ? $params['attribute_codes'] : [],
            static fn (mixed $code): bool => is_string($code) && trim($code) !== ''
        ));
        $search = is_string($params['search'] ?? null) ? trim($params['search']) : '';
        $inputType = is_string($params['input_type'] ?? null) ? trim($params['input_type']) : '';

        $filters = [];
        if ($codes) {
            $filters[] = $this->createListFilter(array_map(trim(...), $codes));
        }
        if ($search !== '') {
            $words = preg_split('/[\s,]+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            $group = [];
            foreach ($words as $word) {
                $group[] = ['field' => 'attribute_code', 'value' => '%' . $word . '%', 'condition_type' => 'like'];
                $group[] = ['field' => 'frontend_label', 'value' => '%' . $word . '%', 'condition_type' => 'like'];
            }
            $optionCodes = $this->attributeSuggester->findCodesByOptionLabels([$search, ...$words]);
            if ($optionCodes) {
                $group[] = $this->createListFilter($optionCodes);
            }
            $filters[] = $group;
        }
        if ($inputType !== '') {
            $filters[] = ['field' => 'frontend_input', 'value' => $inputType];
        }

        $result = $this->apiClient->get(
            'products/attributes',
            $this->apiClient->buildSearchCriteria(
                $filters,
                ResultLimit::fromParams($params, self::DEFAULT_LIMIT, self::MAX_LIMIT),
                1,
                [['field' => 'attribute_code']]
            ),
            $adminUserId
        );
        if (isset($result['error'])) {
            return ['error' => 'Failed to list attributes: ' . $result['error']];
        }

        $attributes = array_map($this->describe(...), array_values($result['items'] ?? []));

        return [
            'total_count' => (int)($result['total_count'] ?? 0),
            'count' => count($attributes),
            'attributes' => $attributes,
        ];
    }

    private function createListFilter(array $codes): array
    {
        return ['field' => 'attribute_code', 'value' => implode(',', $codes), 'condition_type' => 'in'];
    }

    private function describe(array $row): array
    {
        $code = (string)($row['attribute_code'] ?? '');
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);

        return [
            'attribute_code' => $code,
            'label' => (string)($row['default_frontend_label'] ?? ''),
            'input_type' => (string)($row['frontend_input'] ?? ''),
            'scope' => (string)($row['scope'] ?? ''),
            'is_user_defined' => !empty($row['is_user_defined']),
            'is_required' => !empty($row['is_required']),
            'may_set_values' => $this->attributeAccess->maySetValues($attribute),
            'may_add_options' => $this->attributeAccess->mayAddOptions($attribute),
        ];
    }
}
