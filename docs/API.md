# Application API v2.1.0

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

The home endpoint remains parameter-free. Its content is configured in WordPress under:

`WooCommerce > Application API > Home`

The top-level response is:

```json
{
  "success": true,
  "sections": []
}
```

The order of `sections` exactly matches the active order saved in the visual editor. Disabled sections are omitted completely.

Every section includes stable identity and rendering information:

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
    "columns": 1
  },
  "data": []
}
```

- `id` identifies the exact section instance.
- `type` identifies the data family.
- `layout` gives Flutter optional rendering hints.
- `position` is generated from the saved active order.
- `title` and `subtitle` always exist, even when empty.

### Visual WordPress editor

The home tab provides:

- enable/disable switches for every section;
- drag-and-drop ordering plus up/down buttons;
- section title and subtitle fields;
- layout component, direction, and column fields;
- per-section product count (`per_page`);
- category and brand ID selectors entered as comma-separated IDs;
- media-library selectors for slider banners, promotional banners, and menu icons;
- editable title, subtitle, image/icon, and action for every banner/menu item;
- responsive tabs reserved for future Shop, Categories, Product, and Account API settings.

No JSON editing is required.

### Product sections

Home product sections always use page 1 and date descending. The home editor controls `per_page`; when omitted it defaults to 10 and is capped at 30.

Fixed sources:

- `on_sale`: newest sale products;
- `latest`: newest published products;
- `category`: newest products from the configured category IDs.

The amazing-offers section also returns a separate `view_all` object. Flutter should render it after the product cards as the final slide.

### Category and brand sections

Enter IDs in the visual setting field, for example:

`55,166,350`

Only the selected IDs are returned and their entered order is preserved. This is used independently for special categories, shop-by-category, and popular brands.

### Banners and action menu

Each configured item returns:

```json
{
  "id": "banner_slider-1",
  "title": "",
  "subtitle": "",
  "image": "https://example.com/original-image.webp",
  "action": {
    "type": "category",
    "id": 350
  }
}
```

Media-library attachment IDs are preferred so the API returns the original uploaded image rather than a generated thumbnail.

