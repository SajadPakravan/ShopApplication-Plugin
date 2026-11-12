# Shop Application REST API

Namespace: `app-api/v1`

## Public

- `GET /home`
- `GET /products`
- `GET /products/{id}`
- `GET /categories`
- `GET /categories/{id}`
- `POST /auth/login`
- `POST /auth/register`

## Authenticated customer

Send the access token as:

```http
Authorization: Bearer [USER_ID]TOKEN
```

### Profile

- `GET /customers/{id}`
- `PATCH /customers/{id}`
- `PUT /customers/{id}`

### Orders

- `GET /orders`
- `POST /orders`
- `PATCH /orders/{id}`
- `PUT /orders/{id}`

### Cart

- `GET /cart`
- `POST /cart`
- `PATCH /cart`
- `PUT /cart`
- `DELETE /cart`

### Favorites

- `GET /favorites`
- `POST /favorites`
- `PATCH /favorites`
- `PUT /favorites`
- `DELETE /favorites`

### Viewed products

- `GET /viewed-products`

### Comments / reviews

- `GET /comments`
- `POST /comments`
- `PATCH /comments/{id}`
- `PUT /comments/{id}`
- `DELETE /comments/{id}`
