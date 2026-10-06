# Application API v2.2.1

Base namespace:

`/wp-json/app-api/v1`

## Product list and product cards

`GET /products`

Supported request parameters remain:

- `page`, `per_page`
- `search`: partial product title, parent SKU, or published variation SKU
- `type`: one product type slug such as `simple` or `variable`
- `on_sale`: `true` or `false`
- `category`, `brand`, `tag`: one ID, comma-separated IDs, or an array
- `min_price`, `max_price`
- `orderby`: `price`, `date`, `rating`, `id`, `title`, `popularity`
- `order`: `asc`, `desc`

`type` and `on_sale` are still accepted as internal query/filter inputs, but they are not repeated in the response JSON.

Every product card now has the compact contract below:

```json
{
  "id": 2143,
  "name": "کیبورد گیمینگ اچ‌پی مدل HP K10G-98L",
  "price": 4598213,
  "regular_price": 5000000,
  "discount_percent": 8,
  "stock_quantity": 3,
  "image": "https://example.com/original-product-image.webp",
  "variation_name": "مشکی"
}
```

For a simple product, or when no variation label is available:

```json
{
  "variation_name": ""
}
```

Product cards no longer include:

- `type`
- `sku`
- `on_sale`
- `average_rating`
- `rating_count`
- `review_count`
- `total_sales`
- `categories`
- `brands`
- `tags`

`discount_percent > 0` is the public indication that the selected product presentation is discounted.

### Monetary contract

All public product monetary fields are JSON integers, never strings:

- `price`
- `regular_price`
- the same fields inside `default_variation`
- the same fields inside every member of `variations`

Missing, empty, or invalid monetary values are returned as `0`, never `null`.

WooCommerce decimal values are retained internally while selecting sale variations and calculating discount percentages. Integer conversion occurs only when creating the public JSON payload, so the existing greatest-discount variation logic is not changed.

### Variable-product card rule

- For a variable product marked on sale, published sale variations are checked and the variation with the greatest exact discount ratio supplies the card values.
- An exact discount-ratio tie keeps the first variation in WooCommerce order.
- For a variable product not marked on sale, its configured default variation supplies the card values.
- If the default is unavailable, the first published variation is used as a defensive fallback.

### Stock contract and ordering

- `stock_quantity` is never `null`.
- `0` means unavailable.
- A positive managed quantity is returned when available.
- `1` means available when WooCommerce has no exact positive numeric quantity.
- Available products remain before unavailable products across the entire filtered result set and before pagination.

## Product detail

`GET /products/{id}`

The detail response does not include the product `type` or `on_sale` fields. It retains the product `sku` and complete detail-only information such as description, gallery, dimensions, shipping class, ratings, sales, taxonomies, attributes, default variation, and all published variations.

For a variable product, the top-level `price`, `regular_price`, `discount_percent`, `stock_quantity`, `image`, and `variation_name` represent the configured default variation.

Each variation contains:

```json
{
  "id": 100,
  "name": "Product name - Black, 20",
  "sku": "SKU-100",
  "price": 4000000,
  "regular_price": 5000000,
  "discount_percent": 20,
  "stock_quantity": 3,
  "image": "https://example.com/original-image.webp"
}
```

`on_sale` is omitted from both `default_variation` and `variations`. A positive `discount_percent` indicates a discounted variation.

## Home

`GET /home`

The endpoint remains configured visually under:

`WooCommerce > Application API > Home`

Product sections use the same compact product-card formatter as `GET /products`. This keeps home-page lists and future related-product lists consistent and lightweight.

The home builder continues to support dynamic `banner`, `products`, `categories`, and `brands` sections. Section-level `type` is still required because it identifies the kind of home section; it is unrelated to the removed WooCommerce product-type field.

The `on_sale` checkbox in the WordPress settings and the internal WooCommerce query remain functional for building discounted product sections. The `on_sale` value itself is not emitted in product objects, response filters, or the home `view_all` action.
