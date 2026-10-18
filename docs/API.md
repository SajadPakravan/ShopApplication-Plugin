# Application API 2.6.0

## Endpoints

- Home: `/wp-json/app-api/v1/home` (endpoint name may be changed in settings)
- Products: `/wp-json/app-api/v1/products`
- Product detail: `/wp-json/app-api/v1/products/{id}`
- Categories page: `/wp-json/app-api/v1/categories`
- Category detail: `/wp-json/app-api/v1/categories/{id}`

## Categories page

The `/categories` response follows the configurable page-builder contract:

```json
{
  "success": true,
  "sections": []
}
```

Administrators can add and order these section types:

- `image`: same image/banner/menu-style items and actions used elsewhere in the plugin.
- `product`: one specific published WooCommerce product by ID.
- `category`: category collections.

A category section supports four source modes:

1. `parents`: top-level product categories only.
2. `children`: direct child categories of one selected category.
3. `custom`: explicit category IDs in the administrator-defined order.
4. `tree`: all top-level categories with every descendant nested in `children`.

Every category item contains `id`, `name`, `count`, and the original/full `image`. Tree mode additionally contains a nested `children` array.

## Category detail

`/categories/{id}` is fixed and has no page-builder sections. It returns the category itself plus hierarchy information:

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
    "children": []
  }
}
```

`parents` is ordered from the top-level ancestor to the direct parent. `children` contains all descendants as a nested tree.


## 2.6.1
- Category-page product sections use `type: products` and can show either one product or a category-filtered product list.
- Home product sections can also show one specific product.
- Image actions support `type: post` with `post_id`.

## 2.6.2
- Home `category` sections now use the same nested category-item contract as the category APIs.
- The legacy `parent` field was removed from Home category items.
- Every Home category item always contains `children`; leaf categories return `children: []` and parent categories recursively contain their descendants.
- Home category counts use published-product counts and category images continue to use the original/full taxonomy image.
