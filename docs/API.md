# Application API v2.4.0

## Product list

`GET /wp-json/app-api/v1/products`

Supported query parameters:

- `page`
- `per_page`
- `search`
- `category`
- `brand`
- `attributes`
- `min_price`
- `max_price`
- `on_sale`
- `orderby`: `date`, `price`, `rating`, `popularity`, `count_sales`, `id`, `title`
- `order`: `asc`, `desc`

Product cards use one shared contract in the product list, Home product sections, and related-product sections:

```json
{
  "id": 2440,
  "name": "",
  "price": 5700000,
  "regular_price": 5700000,
  "discount_percent": 0,
  "stock_quantity": 1,
  "image": "",
  "variation_name": "",
  "total_sales": 0,
  "average_rating": "3.5",
  "categories": [
    {"id": 197, "name": "خنک‌کننده پردازنده", "image": ""}
  ],
  "brand": {"id": 364, "name": "", "image": ""},
  "colors": ["#000000", "#ffffff"]
}
```

## Product detail

`GET /wp-json/app-api/v1/products/{id}`

Variable attributes are grouped in `variations`. Each option represents an actual published WooCommerce variation and exposes its variation ID, option name, SKU, swatch color/image, price, stock, discount and product image. `default_variation` is only the numeric variation ID.

Configurable detail-page sections are returned in `data.sections`. The built-in sections are:

- related products (`type: products`)
- customer reviews (`type: reviews`)

Their active state and order are configurable from **Application API → Products → Product detail**.

## Home

`GET /wp-json/app-api/v1/{configured-home-endpoint}`

Home product sections use the same product-card contract as the product list. Post entries include a Solar Hijri `date` and a `categories` list after it.
