# Application API v2.2.3

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

Legacy `banner` and `menu` sections are migrated to `image` automatically. Section item IDs are not emitted for image items.

Every section layout always contains integer `rows` and `columns`, with a minimum value of `1`.

### Image section

Each image item has a stable shape:

```json
{
  "title": "",
  "subtitle": "",
  "image": "https://example.com/image.webp",
  "action": {
    "type": null,
    "destination": null
  }
}
```

Supported action types are:

- `product`
- `category`
- `brand`
- `tag`
- `url`
- `null` for no action

For product, category, brand, and tag actions, `destination` is a numeric ID. For URL actions, it is a URL string. When no action is selected, both fields are JSON `null`.

### Product section

The final `view_all.action` keeps stable singular filter keys:

```json
{
  "type": "products",
  "category": [166, 350],
  "brand": [313, 362],
  "orderby": "date",
  "order": "desc"
}
```

### Category and brand sections

Category and brand items use their numeric `id`. They do not emit an `action` object.
