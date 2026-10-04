# Application API v2.2.0

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

The endpoint itself remains parameter-free. Administrators build its content visually under:

`WooCommerce > Application API > Home`

```json
{
  "success": true,
  "sections": []
}
```

The `sections` array follows the saved active order. Disabled or deleted sections are omitted.

### Dynamic section builder

Administrators can add as many independent sections as needed. New sections can currently be one of:

- `banner`
- `products`
- `categories`
- `brands`

The existing quick-actions menu is preserved as the `menu` type. Each section has an editable API `id`, type, title, subtitle, Component, direction, optional rows, and optional columns. Section IDs are made unique when settings are saved.

Every returned section has this base contract:

```json
{
  "id": "latest_products",
  "type": "products",
  "position": 3,
  "title": "جدیدترین محصولات",
  "subtitle": "تازه‌های فروشگاه",
  "layout": {
    "component": "product_carousel",
    "direction": "horizontal",
    "rows": 2
  },
  "data": []
}
```

Empty `rows` or `columns` are omitted from JSON. This allows one banner to be rendered without forcing a grid while multi-item sections can explicitly declare their grid.

### Banner sections

A `banner` section can contain one or many ordered items. It replaces the former separate slider, promotional-banner, and single-banner types. Its Component decides how Flutter renders it, for example `banner_slider`, `banner_grid`, or `banner`.

```json
{
  "id": "hardware_banners",
  "type": "banner",
  "layout": {
    "component": "banner_grid",
    "direction": "horizontal",
    "rows": 2,
    "columns": 2
  },
  "data": [
    {
      "id": "hardware_1",
      "title": "",
      "subtitle": "",
      "image": "https://example.com/original.webp",
      "action": { "type": "category", "id": 350 }
    }
  ]
}
```

Banner items can be added, removed, reordered by drag-and-drop, and edited through the WordPress media library.

### Product sections

A `products` section supports:

- one or more category IDs;
- one or more brand IDs;
- an `on_sale` switch;
- `per_page`, defaulting to 10 when empty;
- an optional row count for multi-row horizontal rendering.

If both category and brand filters are empty, the newest products from the entire store are returned. Multiple IDs inside one taxonomy use OR logic; category and brand filters are combined with AND logic. Results are always page 1, ordered by date descending.

Every product section returns a final-slide instruction containing the exact filters used:

```json
{
  "view_all": {
    "title": "مشاهده همه",
    "action": {
      "type": "products",
      "category": [166, 350],
      "brand": [313],
      "on_sale": true,
      "orderby": "date",
      "order": "desc"
    }
  }
}
```

Flutter should render `view_all` after all objects in `data`.

### Category and brand sections

The administrator enters exact term IDs as a comma-separated ordered list. Only those terms are returned, including empty terms, and their entered order is preserved. There is no hide-empty setting.

### Visual management

The Home tab supports:

- adding and deleting whole sections;
- enabling or disabling each section;
- drag-and-drop section ordering and up/down buttons;
- custom API ID, title, subtitle, type, and Component;
- optional direction, row, and column hints;
- exact category and brand ID lists;
- product filters and per-section product counts;
- media-library image selection;
- item-level ordering for banners and the existing quick-actions menu.

No JSON editing is required.
