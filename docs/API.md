# Application API v2.2.5

Base namespace:

`/wp-json/app-api/v1`

## Configurable endpoint names

The endpoint name is configurable from the corresponding WordPress settings tab.

Defaults:

- Home: `/home`
- Product list: `/products`
- Product detail: `/products/{id}`

## Home sections

The visual Home builder supports these fixed section types:

- `image`
- `products`
- `category`
- `brand`

Each section exposes a compact `layout` object containing only:

```json
{
  "direction": "horizontal",
  "rows": 1,
  "columns": 1
}
```

`rows` and `columns` are always integers with a minimum value of `1`. The former `component` field is no longer stored or emitted.

## Unified action contract

An `action` object is emitted only for:

- each item in an `image` section;
- `view_all` in `products`, `category`, and `brand` sections.

It is not repeated inside individual product, category, or brand items.

Every action always has the same keys:

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

Supported destination types are `product`, `category`, `brand`, `url`, and JSON `null` for no action.

- `product`, `category`, and `brand` use a numeric `destination_id` and set `url` to `null`.
- `url` sets `destination_id` to `null` and stores the sanitized address in `url`.
- `on_sale` is boolean only for `category` and `brand`; it is `null` for product, URL, or no action.
- `orderby` accepts `date`, `price`, `popularity`, `rating`, or `cout_sales`.
- `order` accepts `desc` or `asc`.
