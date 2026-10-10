# Application API v2.5.0

## Product list

`GET /wp-json/app-api/v1/products`

Supported query parameters:

- `page`
- `per_page`
- `search`
- `category`
- `brand`
- `attributes`
- `min_price`
- `max_price`
- `on_sale`
- `orderby`: `date`, `price`, `rating`, `popularity`, `count_sales`, `id`, `title`
- `order`: `asc`, `desc`

Product cards use one shared contract in the product list, Home product sections, and related products:

```json
{
  "id": 2440,
  "name": "",
  "price": 5700000,
  "regular_price": 5700000,
  "discount_percent": 0,
  "stock_quantity": 1,
  "image": "",
  "variation_name": "",
  "total_sales": 0,
  "average_rating": "3.5",
  "categories": [
    {"id": 197, "name": "خنک‌کننده پردازنده", "image": ""}
  ],
  "brand": {"id": 364, "name": "", "image": ""},
  "colors": ["#000000", "#ffffff"]
}
```

## Product detail

`GET /wp-json/app-api/v1/products/{id}`

Variable attributes are grouped in `variations`. Each option represents an actual published WooCommerce variation and exposes its variation ID, option name, SKU, swatch color/image, price, stock, discount and product image. `default_variation` is only the numeric variation ID.

There is no `sections` wrapper in the public product-detail JSON. After `variations`, the optional keys are appended in this fixed order:

1. `reviews`
2. `related_products`

Both can be enabled/disabled and configured from **Application API → محصولات → جزئیات محصول**, but their public order is not configurable.

## Home

`GET /wp-json/app-api/v1/{configured-home-endpoint}`

Home product sections use the same product-card contract as the product list. Post entries include a Solar Hijri `date` and a `categories` list after it.

## Category page

`GET /wp-json/app-api/v1/categories/{id}`

The category response contains the current category metadata plus a configurable `sections` array. The Categories admin tab acts as a visual builder and supports these section types:

- `image`: one or more image/banner/menu-like items with actions.
- `product`: one explicitly selected published product, returned with the shared product-card contract.
- `category`: a category list.

A category-list section can use either:

- manually entered category IDs, preserving their configured order; or
- all top-level/parent product categories.

Each category item contains `id`, `name`, published-product `count`, and the original/full taxonomy image URL.
