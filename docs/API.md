# Application API v2.0.1

Base namespace:

`/wp-json/app-api/v1`

## Product cards

`GET /products`

Canonical query parameters:

- `page`, `per_page`
- `search`: partial product title, parent SKU, or published variation SKU
- `type`: exactly one product type slug such as `simple`, `variable`, `grouped`, or `external`
- `on_sale`: `true` or `false`
- `category`, `brand`, `tag`: one ID, a comma-separated list, or an array of IDs
- `min_price`, `max_price`
- `orderby`: `price`, `date`, `rating`, `id`, `title`, `popularity`
- `order`: `asc`, `desc`

If `type` is omitted, no product-type filter is applied and all published parent products are eligible.

Examples:

`/products?search=K10G&category=350,352&on_sale=true&page=1&per_page=20`

`/products?brand=313&min_price=1000000&max_price=10000000&orderby=price&order=asc`

Multiple IDs inside the same taxonomy use OR logic. Different filters use AND logic.

The former plural query aliases `categories`, `brands`, and `tags` remain accepted temporarily for migration, but new clients should use the singular names.

### Variable-product card rule

- A variable product not marked on sale does not load or iterate through its variations. Its synchronized minimum current price is read from WooCommerce's product lookup row. The parent image and parent stock information are used.
- A variable product marked on sale loads its published variations.
- Only active sale variations with `price < regular_price` are candidates.
- The variation with the greatest exact discount ratio is selected.
- When two or more candidates have the same discount ratio, the first variation in WooCommerce child order is selected.
- The card's `price`, `regular_price`, `discount_percent`, `image`, `stock_quantity`, and `variation_name` represent the selected variation.

### Compact card fields

The list response intentionally excludes:

- `count`
- `currency`
- `slug`, `permalink`
- `sale_price`, `price_range`
- `variation_id`, `variation_sku`, `variation_attributes`
- `stock_status`, `image_id`

`price` is the current payable price. `regular_price` is the original price. Therefore the application can render the crossed-out original price without a separate `sale_price` field.

## Product detail

`GET /products/{id}`

The detail response also excludes `currency`, `slug`, `permalink`, `sale_price`, and `price_range`. Variation identifiers and attributes are currently retained on this endpoint so its final detail-page contract can be designed separately.

## Home

`GET /home`

The response uses a `sections` array. Array order is intentional and is the Flutter render order. JSON object key order should not be used as a layout contract.

Supported section types:

- `banner_slider`
- `menu`
- `products`
- `categories`
- `custom`

Manage the JSON configuration from **WooCommerce > Application API**. Reordering the array reorders the app home page without an app update.

A product section can use the same canonical filters as `/products`, for example:

```json
{
  "id": "laptops",
  "type": "products",
  "enabled": true,
  "title": "لپ‌تاپ‌ها",
  "query": {
    "category": [123],
    "orderby": "popularity",
    "order": "desc",
    "per_page": 10
  },
  "layout": {
    "component": "product_carousel",
    "direction": "horizontal"
  }
}
```

The menu section reads the WordPress menu location named **Application API home menu**.

## Extension hooks

- `app_api_brand_taxonomy`
- `app_api_home_sections`
- `app_api_home_banners`
- `app_api_home_custom_section`
- `app_api_home_unknown_section`
