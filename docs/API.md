# Application API 2.7.1

## Endpoints

- Home: `/wp-json/app-api/v1/home` (endpoint name may be changed in settings)
- Products: `/wp-json/app-api/v1/products`
- Product detail: `/wp-json/app-api/v1/products/{id}`
- Categories page: `/wp-json/app-api/v1/categories`
- Category detail: `/wp-json/app-api/v1/categories/{id}`
- Login: `POST /wp-json/app-api/v1/auth/login`
- Registration: `POST /wp-json/app-api/v1/auth/register`

## Category detail

`/categories/{id}` has a fixed semantic structure. After the category information, the API returns direct child categories, then the product list, then the brand list.

```json
{
  "success": true,
  "data": {
    "id": 55,
    "name": "...",
    "description": "...",
    "image": "...",
    "count": 12,
    "parents": [],
    "children": [],
    "products": [
      {
        "data": [],
        "view_all": {}
      }
    ],
    "brands": [
      {
        "data": [],
        "view_all": {}
      }
    ]
  }
}
```

`children` contains only direct child categories. Each child follows the common category item contract and therefore contains `id`, `name`, `count`, `image`, and `children: []`.

The `products` list uses the same product-card formatter as the product list, Home product sections, and related products. The `brands` list uses the same brand item contract as Home brand sections: `id`, `name`, `count`, and `image`. Brand `count` is scoped to products belonging to the selected category.

The administrator can enable/disable the product and brand blocks independently, set how many items each block initially returns, and enable/disable its `view_all` button. Their order is fixed: products first, brands second. No row/column/direction settings are used on category detail.

## Product detail

The product-detail response keeps its fixed semantic order after `variations`: reviews first and related products second. The output-layout controls (direction, rows, columns) were removed from the product-detail administration settings because these blocks are fixed API data blocks rather than page-builder sections.

## Categories page

The `/categories` landing page remains configurable with `image`, `products`, and `category` sections. Category items always contain a `children` array. Custom category sections may optionally include descendants; otherwise `children` is `[]`.


## Authentication

Both authentication endpoints accept the same two request fields:

```json
{
  "identifier": "09121234567",
  "password": "example-password"
}
```

`identifier` may be a WordPress username, a valid email address, or an Iranian mobile number in the `09xxxxxxxxx` form. Persian and Arabic digits are normalized before mobile detection.

Successful login and registration responses contain `id`, `username`, `email`, `mobile`, and `access_token` directly inside `data`. The public response does not expose token type or expiration fields. Internally, tokens still expire and only a keyed hash is stored in user meta. Tokens use the form `[user_id]<random-secret>`. Future protected Application API routes can authenticate the user with:

```text
Authorization: Bearer <access_token>
```

For registration, an email or mobile identifier receives an automatically generated unique username in the form `user-xxxxxxxx`, where the last eight characters contain lowercase English letters and digits. A username identifier is stored as the chosen username and must contain at least four characters. Mobile registration stores the normalized mobile in both `app_api_mobile` and WooCommerce `billing_phone` user meta.
