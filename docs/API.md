# Application API v2.0.5

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

`schema_version`, `currency`, and `generated_at` are not returned.

The `sections` array order is the exact Flutter render order. A section object has no public `id` and no `layout` object. Flutter selects its renderer from `type`:

```json
{
  "type": "products",
  "position": 2,
  "title": "پیشنهاد شگفت‌انگیز",
  "data": []
}
```

Internal IDs may still appear in the server-side settings JSON so the plugin can migrate presets, but they are never exposed by `/home`.

Supported home section types:

- `banner_slider`
- `promo_banners`
- `action_menu`
- `menu`
- `products`
- `categories`
- `brands`
- `custom`

The home preset does not include FAQ. FAQ/help content should be opened from a separate application menu and endpoint later.

### Banner sections

Banner items return `id`, full-quality `image`, and `action`. Banner `title` and `subtitle` are intentionally omitted.

### Product sections

Product home sections never expose pagination or query metadata. They always query page 1 and always sort newest first (`date desc`). Arbitrary product search, type, brand, tag, price, and ordering filters in legacy home JSON are ignored.

The only general product-section setting is:

```json
{
  "per_page": 10
}
```

If omitted, `per_page` defaults to 10. It is capped at 30 for a single home section.

Fixed sources:

- `source: "on_sale"`: all sale products, newest first;
- `source: "latest"`: all published products, newest first;
- `source: "category"`: products belonging to the configured category IDs, newest first.

The amazing-offers section uses `source: "on_sale"`, defaults to 10 products, and contains a separate `view_all` object after `data`. The app should render this object as the final horizontal slide rather than mixing a non-product object into the product array:

```json
{
  "type": "products",
  "title": "پیشنهاد شگفت‌انگیز",
  "data": ["10 product cards"],
  "view_all": {
    "title": "مشاهده همه",
    "action": {
      "type": "products",
      "on_sale": true
    }
  }
}
```

### Selected category and brand sections

For `categories` and `brands`, configure exact term IDs in `config.include`:

```json
{
  "type": "categories",
  "title": "دسته‌بندی‌های ویژه",
  "config": {
    "include": [55, 166, 167],
    "hide_empty": false
  }
}
```

```json
{
  "type": "brands",
  "title": "محبوب‌ترین برندها",
  "config": {
    "include": [313, 362, 367],
    "hide_empty": false
  }
}
```

When `include` contains IDs, only those IDs are returned, in the same order, and fallback names/slugs are ignored. Name/slug lookup remains only as a backward-compatible fallback when `include` is empty.

For Yademan System, version 2.0.5 ships a preset in the current public-homepage order:

1. hero banners;
2. quick-action menu;
3. amazing offers;
4. special categories;
5. latest products;
6. computer and accessories products;
7. computer/hardware promotional banners;
8. laptop and accessories products;
9. speaker products;
10. shop by category;
11. popular brands;
12. smartwatch banner.

The quick-action preset follows the current site items: application, sale, purchase consulting, goods order, assembly order, repair order, return request, store payment, survey, and more.

Manage the JSON configuration from **WooCommerce > Application API**.

## Extension hooks

- `app_api_brand_taxonomy`
- `app_api_home_sections`
- `app_api_home_banners`
- `app_api_home_custom_section`
- `app_api_home_unknown_section`
