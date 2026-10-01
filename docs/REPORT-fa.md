# گزارش بازطراحی پلاگین Application API – نسخه 2.0.0

## خروجی نهایی

- API محصولات: `/wp-json/app-api/v1/products`
- API کارت یک محصول: `/wp-json/app-api/v1/products/{id}`
- API صفحه خانه: `/wp-json/app-api/v1/home`
- صفحه تنظیمات: `WooCommerce > Application API`

## تغییرات API محصولات

1. جست‌وجو فقط بر اساس عنوان محصول، SKU محصول والد و SKU متغیرها انجام می‌شود و شرط‌ها با OR ترکیب شده‌اند.
2. فیلترهای زیر اضافه یا اصلاح شده‌اند:
   - `type=simple,variable`
   - `on_sale=true|false`
   - `categories=1,2,3`
   - `brands=1,2,3`
   - `tags=1,2,3`
   - `min_price` و `max_price`
   - `orderby=price|date|rating|id|title|popularity`
   - `order=asc|desc`
3. مقادیر پیش‌فرض دقیقاً `orderby=date` و `order=desc` هستند.
4. صفحه‌بندی شامل تعداد آیتم صفحه، تعداد کل نتایج فیلترشده، تعداد کل صفحات و تعداد کل محصولات منتشرشده سایت است.
5. فیلتر چند شناسه در یک taxonomy با منطق OR و ترکیب taxonomyهای متفاوت با منطق AND انجام می‌شود.
6. برای محصولات متغیر دیگر به درخواست جداگانه variations نیاز نیست:
   - اگر متغیر تخفیف‌دار موجود باشد، مناسب‌ترین متغیر قابل خرید و موجود انتخاب می‌شود.
   - اگر تخفیفی وجود نداشته باشد، متغیر موجود با کمترین قیمت انتخاب می‌شود.
   - قیمت، قیمت عادی، قیمت فروش، درصد تخفیف، تصویر، موجودی، SKU، شناسه و ویژگی‌های متغیر در همان کارت برمی‌گردند.
7. بازه کامل قیمت محصول متغیر نیز در `price_range` موجود است.
8. برند به taxonomy ثابت وابسته نیست و taxonomyهای رایج، از جمله `product_brand` ووکامرس، شناسایی می‌شوند. برای سایت‌های خاص امکان تعیین taxonomy با فیلتر `app_api_brand_taxonomy` وجود دارد.
9. کوئری قیمت، فروش ویژه، SKU، محبوبیت و امتیاز از جدول بهینه `wc_product_meta_lookup` استفاده می‌کند.

## ساختار پیشنهادی پاسخ محصولات

```json
{
  "success": true,
  "count": 20,
  "pagination": {
    "current_page": 1,
    "per_page": 20,
    "total_items": 125,
    "total_pages": 7,
    "total_site_products": 840,
    "has_next": true,
    "has_previous": false
  },
  "filters": {},
  "currency": {},
  "data": []
}
```

## تغییرات API خانه

1. ترتیب صفحه در آرایه `sections` نگهداری می‌شود. ترتیب اعضای این آرایه همان ترتیب نمایش در اپلیکیشن فلاتر است.
2. بخش‌های پشتیبانی‌شده:
   - `banner_slider`
   - `menu`
   - `products`
   - `categories`
   - `custom`
3. هر بخش دارای `id`، `type`، `position`، `title`، `layout` و `data` است.
4. برای هر بخش محصول می‌توان همان فیلترهای API محصولات را تعریف کرد.
5. چینش، ترتیب و کوئری بخش‌ها بدون تغییر اپلیکیشن از صفحه تنظیمات پلاگین و با JSON قابل مدیریت است.
6. منوی خانه از موقعیت منوی وردپرس با نام `Application API home menu` خوانده می‌شود.
7. بنرها از تنظیمات JSON دریافت می‌شوند و از `action` برای مسیریابی داخل اپ پشتیبانی می‌کنند.
8. پاسخ خانه به‌صورت پیش‌فرض 60 ثانیه cache می‌شود و با تغییر محصول، متغیر، taxonomy، منو یا تنظیمات به‌صورت خودکار نسخه cache تغییر می‌کند.
9. کل صفحه خانه در یک درخواست HTTP دریافت می‌شود، هرچند داخل سرور برای تولید بخش‌ها ممکن است چند کوئری بهینه اجرا شود.

## مثال چینش قابل تغییر صفحه خانه

```json
[
  {
    "id": "banner_slider",
    "type": "banner_slider",
    "enabled": true,
    "layout": { "component": "banner_slider" }
  },
  {
    "id": "amazing_offers",
    "type": "products",
    "enabled": true,
    "title": "پیشنهادهای شگفت‌انگیز",
    "query": {
      "on_sale": true,
      "orderby": "date",
      "order": "desc",
      "per_page": 10
    },
    "layout": { "component": "product_carousel" }
  },
  {
    "id": "special_categories",
    "type": "categories",
    "enabled": true,
    "title": "دسته‌بندی‌های ویژه"
  }
]
```

برای جابه‌جایی دو بخش فقط جای آن‌ها در آرایه بالا عوض می‌شود.

## معماری جدید

- `src/Controllers`: کنترل ورودی و خروجی endpointها
- `src/Services`: کوئری محصولات، ساخت کارت و ساخت صفحه خانه
- `src/Rest`: ثبت routeها و پارامترهای REST
- `src/Support`: پردازش درخواست، taxonomy، cache و response
- `src/Admin`: صفحه تنظیمات مدیریت

## فایل‌های اصلی تغییرکرده یا اضافه‌شده

- `application_api.php`
- `config.php`
- `src/Plugin.php`
- `src/Rest/Routes.php`
- `src/Controllers/ProductController.php`
- `src/Controllers/HomeController.php`
- `src/Services/ProductRepository.php`
- `src/Services/ProductFormatter.php`
- `src/Services/HomeBuilder.php`
- `src/Support/Request.php`
- `src/Support/Taxonomy.php`
- `src/Support/Cache.php`
- `src/Support/Response.php`
- `src/Admin/SettingsPage.php`
- `docs/API.md`
- `readme.txt`

فایل‌های قدیمی `routes.php` و `controllers/ProductController.php` دیگر در بسته نهایی وجود ندارند و با معماری جدید جایگزین شده‌اند.

## کنترل کیفیت انجام‌شده

- تمام فایل‌های PHP با `php -l` بررسی شدند و خطای نحوی ندارند.
- انتخاب متغیر برای محصول متغیر با تست ساختگی بررسی شد:
  - متغیر تخفیف‌دار ناموجود کنار گذاشته شد و متغیر تخفیف‌دار موجود انتخاب شد.
  - در محصول متغیر بدون تخفیف، متغیر موجود با کمترین قیمت انتخاب شد.
- تست نهایی روی یک نصب واقعی وردپرس/ووکامرس هنوز باید پس از نصب در staging انجام شود؛ مخصوصاً سازگاری با افزونه برند و افزونه چندارزی همان سایت.
