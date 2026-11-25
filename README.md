# Mago Product Finder

A tool for the [Mago](https://packagist.org/packages/mago-assistant/magento2-mago) admin assistant. It finds products and product attributes. It writes nothing.

| Action | What it does |
| --- | --- |
| `find_products` | Counts and lists products by SKU, attribute filters, category or parent product, with the values of chosen attributes. |
| `parent_products` | Finds the configurable, grouped or bundle parents of a child SKU. |
| `list_attributes` | Finds product attributes by code, label or option label. |

Example questions:

- "Give me five red products."
- "Which products are siblings of SKU gear-44t-red?"

## Installation

The package is on Packagist: [sparkable/magento-mago-product-finder](https://packagist.org/packages/sparkable/magento-mago-product-finder).

```
composer require sparkable/magento-mago-product-finder
bin/magento module:enable Sparkable_MagoProductFinder
bin/magento setup:upgrade
```

## Attribute access

The module adds two settings that say what the assistant may change. Other Mago tools, such as `sparkable/magento-mago-bulk-update`, obey them.

- Store default: Stores > Configuration > Mago > Product Attribute Access.
- Per attribute: Stores > Attributes > Product > Advanced Attribute Properties.

## Filters

A filter has a `field`, a `condition_type` and a `value`, as in Magento search criteria. An option label works as a value, in the language of any store view. The conditions `neq`, `nin` and `nlike` also match products without a value.

## Permissions and privacy

- The admin user needs `Magento_Catalog::products`. `list_attributes` also needs `Magento_Catalog::attributes_attributes`.
- All returned fields are catalog data and cross as they are. Admin links cross as tokens.

## Tests

```
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist vendor/sparkable/magento-mago-product-finder/Test/Unit
```
