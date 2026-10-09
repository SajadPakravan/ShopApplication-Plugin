# Application API v2.3.2

## Products

`GET /wp-json/app-api/v1/products`

Supported query parameters:

- `page`
- `per_page`
- `search`
- `category` (one ID or comma-separated IDs)
- `brand` (one ID or comma-separated IDs)
- `attributes` (attribute/option filters)
- `min_price`
- `max_price`
- `on_sale`
- `orderby`
- `order`

Recommended attribute query syntax:

```text
?attributes[1]=118,119&attributes[18]=209,238
```

Within the same attribute, selected option IDs use OR semantics. Separate attributes use AND semantics.

The response contains:

- `pagination`
- `filters.categories`
- `filters.brands`
- `filters.attributes`
- `filter_by`
- `data`

`filter_by.attributes` uses this shape:

```json
[
  {"id": 1, "options": [118, 119]},
  {"id": 18, "options": [209, 238]}
]
```

Each product-list item contains the compact card fields plus `total_sales`, numeric `average_rating`, `category`, `brand`, and filterable `attributes`. Category/brand objects and attribute option objects expose only `id` and `name`.

## Product detail

`GET /wp-json/app-api/v1/products/{id}`

## Home

`GET /wp-json/app-api/v1/{configured-home-endpoint}`

Post-section dates are returned as Solar Hijri dates in `YYYY-MM-DD` format with no time component.
