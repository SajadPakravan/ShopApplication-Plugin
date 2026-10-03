# Application API v2.0.4

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

### Product images

Every product and variation `image` URL points to the original uploaded attachment when WordPress still has it. Generated thumbnail sizes such as `150x150` and `woocommerce_thumbnail` are not used. If original-image metadata is unavailable, the API falls back to the attachment/full-size URL.

The detail response also contains `gallery`, which is a JSON array of full-quality image URLs. Its order is:

1. the initially selected image (the default variation image for variable products);
2. the parent product featured image, when different;
3. the remaining WooCommerce product-gallery images.

Duplicate images are removed.

### Additional detail fields

The detail response includes:

- `description`: sanitized rendered HTML from the full WooCommerce product description;
- `dimensions`: an object containing `length`, `width`, and `height`;
- `shipping_class`: the WooCommerce shipping-class slug, or an empty string;
- `average_rating`, `rating_count`, and `total_sales`;
- `attributes`: every product attribute in WooCommerce order.

Each attribute contains:

```json
{
  "name": "Color",
  "visible": true,
  "options": ["Black", "White"]
}
```

The repeated `visible` entry in the requested field list is represented once because a JSON object cannot contain the same key twice reliably.

## Home

`GET /home`

The top-level response is intentionally compact:

```json
{
  "success": true,
  "sections": []
}
```

The removed top-level fields are `schema_version`, `currency`, and `generated_at`.

The response uses a `sections` array. Array order is intentional and is the exact Flutter render order. Reordering the section configuration reorders the application home page without an application update.

Supported section types:

- `banner_slider`
- `promo_banners`
- `action_menu`
- `menu`
- `products`
- `categories`
- `brands`
- `faq`
- `custom`

For Yademan System, version 2.0.4 includes a preset matching the current public homepage order:

1. hero banner slider;
2. quick-action icon menu;
3. amazing offers;
4. special categories;
5. latest products;
6. computer and accessories products;
7. computer and hardware promotional banners;
8. laptop and accessories products;
9. speaker products;
10. shop by category;
11. popular brands;
12. smartwatch promotional banner;
13. frequently asked questions.

The Yademan preset resolves category and brand references by their names at runtime rather than hard-coding term IDs. Other WooCommerce stores receive the generic preset and can use the same section engine.

Product sections accept the same filters as `/products`. They may also resolve terms by `category_names`, `category_slugs`, `brand_names`, `brand_slugs`, `tag_names`, or `tag_slugs` inside the server-side section configuration.

Category and brand items contain `id`, `name`, `parent`, `count`, the original/full-quality `image`, and a normalized app `action`. Common WooCommerce brand-image metadata formats are detected automatically.

The `faq` section can read accordion items from the WordPress front page. It supports common Woodmart/WPBakery FAQ shortcodes and HTML `<details>` elements, with configured fallback questions when the page builder content cannot be parsed.

Manage the JSON configuration from **WooCommerce > Application API**.

## Extension hooks

- `app_api_brand_taxonomy`
- `app_api_home_sections`
- `app_api_home_banners`
- `app_api_home_custom_section`
- `app_api_home_unknown_section`
