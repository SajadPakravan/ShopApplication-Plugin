# Application API v2.0.2

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

- If a variable product is marked on sale, its published sale variations are checked and the variation with the greatest exact discount ratio supplies the card's `price`, `regular_price`, `discount_percent`, `image`, and `stock_quantity`.
- If two sale variations have exactly the same discount ratio, the first one in WooCommerce variation order wins.
- If a variable product is not marked on sale, its configured default variation supplies the card's `price`, `regular_price`, `image`, and `stock_quantity`.
- If a store has an incomplete, deleted, or unpublished default variation, the first published variation is used as a defensive fallback.

### Stock contract and ordering

- `stock_quantity` is never `null`.
- `0` means unavailable/out of stock.
- A positive managed stock quantity is returned when WooCommerce tracks an exact quantity.
- `1` means available when WooCommerce exposes availability but does not expose a positive numeric quantity, such as products with stock management disabled or backorders.
- Available products are always sorted before unavailable products across the complete filtered result set. The requested `orderby` and `order` are then applied inside each availability group. This ordering occurs before pagination.

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

The detail response excludes `currency`, `slug`, `permalink`, `sale_price`, `price_range`, `stock_status`, and `image_id`.

For a variable product:

- the top-level `price`, `regular_price`, `discount_percent`, `on_sale`, `stock_quantity`, and `image` always represent the default variation;
- `default_variation` contains the default variation as a separate object;
- `variations` contains every published variation in WooCommerce order.

Each variation object contains exactly:

```json
{
  "id": 100,
  "name": "Product name - Black, 20",
  "sku": "SKU-100",
  "price": "4000000",
  "regular_price": "5000000",
  "discount_percent": 20,
  "on_sale": true,
  "stock_quantity": 3,
  "image": "https://example.com/image.webp"
}
```

For a non-variable product, `default_variation` is `null` and `variations` is an empty array.

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
