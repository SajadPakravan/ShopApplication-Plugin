# Application API v2

Base namespace:

`/wp-json/app-api/v1`

## Product cards

`GET /products`

Supported query parameters:

- `page`, `per_page`
- `search`: partial product title, parent SKU, or variation SKU
- `type`: one slug or comma-separated slugs (`simple,variable`)
- `on_sale`: `true` or `false`
- `categories`, `brands`, `tags`: one ID or comma-separated IDs
- `min_price`, `max_price`
- `orderby`: `price`, `date`, `rating`, `id`, `title`, `popularity`
- `order`: `asc`, `desc`

Examples:

`/products?search=K10G&categories=350,352&on_sale=true&page=1&per_page=20`

`/products?brands=313&min_price=1000000&max_price=10000000&orderby=price&order=asc`

Multiple IDs inside the same taxonomy use OR logic. Different filters use AND logic.
For variable products, the response selects one variation for the card and returns its price, regular price, sale price, image, stock, SKU, attributes, and variation ID. A separate variation request is not required.

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

A product section can use the same filters as `/products`, for example:

```json
{
  "id": "laptops",
  "type": "products",
  "enabled": true,
  "title": "لپ‌تاپ‌ها",
  "query": {
    "categories": [123],
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
