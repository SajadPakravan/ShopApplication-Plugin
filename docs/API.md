# Shop Application API 3.0.0

REST namespace remains:

```text
/wp-json/app-api/v1
```

## Public customer web services

- `GET /home` (or the configured Home endpoint)
- `GET /products`
- `GET /products/{id}`
- `GET /categories`
- `GET /categories/{id}`

These endpoints do not require authentication.

## Authentication web service

### Login

```text
POST /auth/login
```

### Register

```text
POST /auth/register
```

Body:

```json
{
  "identifier": "09121234567",
  "password": "example-password"
}
```

`identifier` can be a username, valid email address, or Iranian mobile number in `09xxxxxxxxx` form.

Successful login/registration response:

```json
{
  "success": true,
  "data": {
    "id": 123,
    "username": "user-a1b2c3d4",
    "email": "user@example.com",
    "phone": "09121234567",
    "token": "[123]xxxxxxxx..."
  }
}
```

Use protected APIs with:

```text
Authorization: Bearer [123]xxxxxxxx...
```

## Account customer web service

```text
GET /customers/{id}
```

Requires a Bearer token. A normal customer may only request their own ID. WordPress/WooCommerce administrators may request other customer IDs.

The response includes:

- account identity and profile fields
- phone and avatar
- WooCommerce billing address
- WooCommerce shipping address
- orders and order items
- persistent WooCommerce cart
- wishlist array
- available downloads

WooCommerce core does not include a native wishlist. The `wishlist` array is exposed through the `shop_application_customer_wishlist` filter so the active wishlist plugin/theme can be connected without changing the public API contract.
