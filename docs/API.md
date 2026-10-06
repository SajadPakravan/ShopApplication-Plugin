# Application API v2.2.2

Base namespace:

`/wp-json/app-api/v1`

## Configurable endpoint names

The endpoint name is configurable from the corresponding WordPress settings tab.

Defaults:

- Home: `/home`
- Product list: `/products`
- Product detail: `/products/{id}`

For example, changing the Home endpoint from `home` to `main-page` changes its address to:

`/wp-json/app-api/v1/main-page`

Endpoint names accept English letters, numbers, hyphens, and underscores.

## Product list and cards

The product-list API supports:

- `page`, `per_page`
- `search`
- `type`
- `on_sale`
- `category`, `brand`, `tag`
- `min_price`, `max_price`
- `orderby`, `order`

`category`, `brand`, and `tag` keep the same singular key whether they contain one ID or several IDs.

## Product detail

The product-detail endpoint ends with the numeric product ID. Its endpoint base can be configured independently from the product-list endpoint.

## Home sections

The visual Home builder supports these addable section types:

- `banner`
- `products`
- `category`
- `brand`

The existing quick-access menu uses the fixed `menu` type.

A section type is selected when the section is created and cannot be changed afterward. Section `id`, `title`, `subtitle`, `Component`, direction, rows, columns, and type-specific content remain editable.

Every section layout always contains integer `rows` and `columns`. Empty, zero, or invalid values become `1`; values below `1` are not emitted.

### Banner section

Banner items contain only:

```json
{
  "title": "",
  "subtitle": "",
  "image": "https://example.com/banner.webp"
}
```

Banner items do not emit `id`, `parent`, or `action`.

### Product section

The final `view_all.action` always uses stable singular keys:

```json
{
  "type": "products",
  "category": [166, 350],
  "brand": [313, 362],
  "orderby": "date",
  "order": "desc"
}
```

The key names never change to `categories` or `brands`, regardless of the number of IDs.

### Category section

Category items use their numeric `id` and do not emit an `action` object.
