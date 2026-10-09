# Application API v2.3.1

Base namespace:

`/wp-json/app-api/v1`

## Endpoints

- Home: `/home` by default; the Home endpoint can be changed from the Home settings tab.
- Product list: `/products` (fixed).
- Product detail: `/products/{id}` (fixed).

## Product list query parameters

`GET /products` supports:

- `page`
- `per_page`
- `search`
- `category` — one ID or comma-separated IDs
- `brand` — one ID or comma-separated IDs
- `min_price`
- `max_price`
- `on_sale` — `true` / `false`; omitted means no sale-status filter
- `orderby`
- `order`

The response contains:

- `pagination`
- `filters`
  - `category`: hierarchical product-category tree with nested `children`
  - `brand`: alphabetical brand list with count and image when a brand image is stored by WooCommerce/the active brand plugin
  - `attribute`: public/global WooCommerce attributes; each attribute contains only `id`, `name`, and `options`, while each option contains only `id` and `name`
- `filter_by`: values applied to the current request
- `data`: lightweight product cards

`filter_by` has this stable shape:

```json
{
  "search": null,
  "category": [],
  "brand": [],
  "min_price": null,
  "max_price": null,
  "on_sale": null,
  "orderby": "date",
  "order": "desc"
}
```

## Home section types

The visual Home builder supports:

- `image`
- `products`
- `category`
- `brand`
- `posts`

Every section has an administrator-defined section ID, title/subtitle and layout:

```json
{
  "direction": "horizontal",
  "rows": 1,
  "columns": 1
}
```

### Category section source

A category section supports two source modes:

1. Custom IDs: explicitly entered category IDs are returned in the entered order.
2. Parent category: the administrator selects one top-level product category and the section returns all direct child categories of that parent in the store-defined order.

### Posts section

A `posts` section can select one or more WordPress post-category IDs and a `per_page` value. If category IDs are empty, posts from all categories are considered. Posts are returned newest first.

```json
{
  "id": 123,
  "title": "Article title",
  "excerpt": "...",
  "image": "https://example.com/original-image.webp",
  "date": "2026-08-08"
}
```

## View all

`products`, `category`, `brand`, and `posts` sections have a View All toggle.

When View All is disabled, the `view_all` key is completely omitted from that section.

When enabled:

- Products: destination types are `all`, `category`, `brand`.
- Category: destination type is fixed to `all` (all product categories).
- Brand: destination type is fixed to `all` (all brands).
- Posts: destination types are `all`, `category`.

For category/brand destinations, an empty `destination_id` means all categories/brands of that destination type. For post-category destinations, an empty `destination_id` means all post categories.

## Action contract

Actions appear only in image items and enabled section-level `view_all` objects.

Image items support `product`, `category`, `brand`, and `url`; there is no "no action" option.

General action example:

```json
{
  "title": "لپ‌تاپ",
  "type": "category",
  "destination_id": 55,
  "on_sale": false,
  "url": null,
  "orderby": "date",
  "order": "desc"
}
```

For `type = all`, `destination_id` and `url` are `null`.

For post-section `view_all` actions, the `on_sale` key is omitted because it is not applicable to posts.

## Home cache

The Home response is cached internally for 60 seconds. The TTL is fixed in the plugin and is not exposed in administrator settings. Relevant content/configuration changes invalidate the versioned cache so Home can be rebuilt after changes.
