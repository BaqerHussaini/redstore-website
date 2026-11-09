# RedStore

RedStore is a responsive sportswear storefront. The public pages keep the
original HTML/CSS/vanilla-JavaScript design, but product data now comes from a
PHP REST API backed by Supabase PostgreSQL. Product images can be served from
the local `assets/images/` folder or from Supabase Storage.

This phase implements the database, PHP API, dynamic product pages, guest and
saved (database-backed) shopping carts, customer accounts, customer order
placement, a secure server-rendered admin dashboard, and deployment
configuration. Payment integration remains **out of scope**.

## Architecture

```text
Browser (HTML/CSS/vanilla JS)
        |
        | fetch() /api/*  +  /admin (PHP sessions, CSRF)
        v
Apache + PHP 8.3 (Render Docker service)
        |
        | PDO / SSL
        v
Supabase PostgreSQL

Supabase Storage (public bucket: product-images)
        ^
        | public image URLs returned by the PHP API
        | server-side uploads from the admin dashboard
```

- The browser never receives database credentials and never calls Supabase
  directly.
- Product information and image references live in PostgreSQL.
- Image binaries live in Supabase Storage, not in PostgreSQL.
- Static branding, About content, header, footer and other non-product copy
  remain in the HTML source.

## Requirements

- PHP 8.1+ with `pdo_pgsql` (the Docker image uses PHP 8.3).
- Apache with `mod_rewrite` and `AllowOverride All`, or PHP's built-in server
  for local development.
- PostgreSQL 15+ for `UNIQUE NULLS NOT DISTINCT` (Supabase runs 15+).
- No Composer, npm build step or frontend framework is required.

## Project structure

```text
redstore-website/
├── admin/
│   ├── index.php               # server-rendered admin router/controller
│   ├── bootstrap.php           # admin session + view helpers
│   ├── views/                  # login, dashboard, products, categories,
│   │                           # inventory, orders, customers, settings
│   └── .htaccess               # denies direct access to internals
├── api/
│   ├── config/
│   │   ├── config.php          # env loading and app config
│   │   └── database.php        # PDO PostgreSQL connection
│   ├── controllers/
│   │   ├── ProductController.php
│   │   ├── AuthController.php  # customer register/login/logout/session
│   │   ├── CartController.php  # guest validation + saved cart operations
│   │   ├── AdminDashboardController.php
│   │   ├── AdminProductController.php
│   │   ├── AdminCategoryController.php
│   │   ├── AdminInventoryController.php
│   │   ├── AdminOrderController.php
│   │   └── AdminCustomerController.php
│   ├── lib/
│   │   ├── ApiException.php
│   │   ├── Auth.php            # admin sessions, CSRF, rate limiting, hashing
│   │   ├── CustomerAuth.php    # separate customer session + CSRF
│   │   ├── ImageUploader.php   # MIME/size validation + safe object paths
│   │   ├── ImageUrl.php        # local/storage image URL resolution
│   │   ├── Input.php           # typed request input validation
│   │   ├── Response.php        # consistent JSON responses
│   │   └── SupabaseStorage.php # service-role Storage uploads/deletes
│   ├── models/
│   │   ├── Category.php
│   │   ├── Product.php
│   │   ├── User.php
│   │   ├── Cart.php            # saved cart_items persistence
│   │   ├── ProductImage.php
│   │   ├── LoginAttempt.php
│   │   ├── AdminDashboard.php
│   │   ├── AdminProduct.php
│   │   ├── AdminCategory.php
│   │   ├── AdminInventory.php
│   │   ├── AdminOrder.php
│   │   └── AdminCustomer.php
│   ├── routes/
│   │   ├── products.php
│   │   ├── auth.php            # /api/auth/* customer account routes
│   │   ├── cart.php            # guest validation + saved cart routes
│   │   └── admin.php
│   ├── .htaccess               # Apache rewrite to index.php
│   └── index.php               # API front controller
├── assets/
│   ├── admin/                  # admin.css + admin.js
│   ├── css/                    # existing design system
│   ├── images/                 # existing images + placeholder.svg
│   └── js/                     # public storefront scripts (api, cart,
│                               # cart-page, product pages)
├── database/
│   ├── schema.sql              # fresh Supabase install
│   └── migrations/
│       ├── 001_product_features.sql
│       ├── 002_storage_bucket.sql
│       ├── 003_migrate_images_to_storage.sql   # optional, after upload
│       ├── 004_enable_rls.sql
│       └── 005_admin_auth.sql                  # admin login attempts
├── docker/
│   ├── entrypoint.sh           # maps Apache to Render's PORT
│   └── php.ini                 # upload limits (5 MB per image)
├── scripts/
│   ├── create-admin.php        # create/reset an administrator
│   └── upload-product-images.sh
├── pages/                      # existing public pages
├── Dockerfile
├── docker-compose.yml
├── render.yaml
├── server.php                  # local PHP built-in-server router
├── .env.example
└── README.md
```

## Local development

1. Copy the environment example and fill in real values:

   ```bash
   cp .env.example .env
   ```

   For a local PostgreSQL database set `DB_SSLMODE=disable`; for Supabase use
   `DB_SSLMODE=require` (or include `?sslmode=require` in `DATABASE_URL`).

2. Create the schema in your database:

   ```bash
   psql "$DATABASE_URL" -f database/schema.sql
   ```

   For an existing database created before the admin dashboard, also run the
   migrations in order (see [Supabase setup](#supabase-setup)).

3. Create the first administrator (prompts for a password of 12+ characters):

   ```bash
   php scripts/create-admin.php --email=admin@example.com --name="Store Admin"
   ```

   Reset an existing administrator's password with
   `php scripts/create-admin.php --email=admin@example.com --update-password`.

4. Start the app from the project root so `/api` and `/assets` resolve:

   ```bash
   php -S 127.0.0.1:8080 server.php
   ```

   Then open <http://127.0.0.1:8080/> and sign in at
   <http://127.0.0.1:8080/admin/login>.

5. Alternatively run the Apache + PHP image:

   ```bash
   docker compose up --build
   ```

   The site is available at <http://localhost:8080/>.

Do not open the HTML files with `file://`; the API and root-relative image URLs
need an HTTP server rooted at the project directory.

### Local PHP requirements check

```bash
php -m | grep pdo_pgsql
php -l api/index.php
```

If `pdo_pgsql` is missing on a native install, enable the extension in
`php.ini` (or install `php-pgsql`). The supplied `Dockerfile` installs it.

## Environment variables

| Variable | Purpose |
|---|---|
| `DATABASE_URL` | Supabase/PostgreSQL connection URL. Takes priority over `DB_*`. |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Alternative to `DATABASE_URL`. |
| `DB_SSLMODE` | `require` for Supabase, `disable` for a local test database. |
| `SUPABASE_URL` | Project URL used to build public Storage image URLs. |
| `SUPABASE_STORAGE_BUCKET` | Bucket name, default `product-images`. |
| `SUPABASE_STORAGE_PUBLIC_URL` | Optional full public base URL for the bucket. |
| `SUPABASE_SERVICE_ROLE_KEY` | Server-side key used by admin image uploads and the optional upload script; never exposed to the browser. |
| `CORS_ALLOWED_ORIGIN` | Optional; only needed if the static frontend runs on a different origin. |
| `APP_ENV` | `production` or `development`. |
| `SESSION_COOKIE_SECURE` | Optional override for the admin session cookie; defaults to secure in production. |

Never commit `.env`. `SUPABASE_SERVICE_ROLE_KEY` must stay server-side and must
not be added to Render as a client-side/build-time variable.

## Supabase setup

### 1. Create or open the project

Create a project at <https://supabase.com/dashboard>, or open the existing
RedStore project.

### 2. Run the database schema

New project:

1. Open **SQL Editor**.
2. Paste the contents of `database/schema.sql`.
3. Run it.
4. Re-running the script is safe: tables/indexes/triggers are guarded and
   sample rows use `ON CONFLICT`.

Existing project created with the original schema, run in this order:

1. `database/migrations/001_product_features.sql` — sale price, featured flag,
   variant colours, text image columns, indexes and sample descriptions.
2. `database/migrations/002_storage_bucket.sql` — Storage bucket and public
   read policy.
3. `database/migrations/004_enable_rls.sql` — Row Level Security.
4. `database/migrations/005_admin_auth.sql` — `admin_login_attempts` table used
   for login rate limiting.
5. `database/migrations/003_migrate_images_to_storage.sql` — optional, only
   after the image files have actually been uploaded.

### 3. Verify the database

```sql
select count(*) from categories;
select count(*) from products where is_active;
select count(*) from products where is_featured;
select count(*) from product_images;
select count(*) from product_variants;
select count(*) from admin_login_attempts;
```

Expected sample data: 4 categories, 13 products, 4 featured products,
16 gallery images and 5 HRX size variants.

### 4. Get connection details

In the Supabase dashboard open **Project Settings → Database → Connection
string**. Prefer the **Session pooler** connection (port `5432`) or the direct
connection if your host has IPv6. Render needs an IPv4-capable connection, so
the session pooler is usually the right choice there. Copy the connection
string into `DATABASE_URL`.

Example shape (do not copy these placeholders literally):

```text
postgresql://postgres.<project-ref>:<password>@<region>.pooler.supabase.com:5432/postgres?sslmode=require
```

The API connects with PDO using the server-side role. Do not expose this URL to
the frontend.

### 5. Row Level Security

`schema.sql` enables RLS on all application tables with no `anon` or
`authenticated` policies. This blocks the public PostgREST endpoint from reading
or writing store data. The PHP API and admin dashboard still work because they
connect as the table owner/server-side role. The admin dashboard uses PHP
sessions, not Supabase Auth, so no additional policies are required for it.

## Supabase Storage

1. The bucket is named `product-images` and is public for reads.
2. Run `database/migrations/002_storage_bucket.sql` in the SQL Editor, or create
   the bucket manually in **Storage** with public access.
3. The script also creates a public `SELECT` policy for the bucket. It creates
   **no** insert/update/delete policy: writes must use the service-role key from
   a trusted server process. The admin dashboard does exactly this through
   `api/lib/SupabaseStorage.php`; the browser never receives the key.
4. Recommended object layout:

   ```text
   product-images/
   ├── products/
   │   ├── red-graphic-t-shirt/main.webp
   │   ├── black-running-shoes/main.webp
   │   └── product-printed-t-shirt-by-hrx/
   │       ├── main.jpg
   │       ├── gallery-1.jpg
   │       ├── gallery-2.jpg
   │       ├── gallery-3.jpg
   │       └── gallery-4.jpg
   ```

5. Upload options:
   - **Dashboard:** Storage → `product-images` → upload the files into the
     matching folders.
   - **Script:** `./scripts/upload-product-images.sh` with
     `SUPABASE_URL` and `SUPABASE_SERVICE_ROLE_KEY` set in the terminal.
6. After upload, run `database/migrations/003_migrate_images_to_storage.sql` to
   replace local asset paths in the sample rows with storage object paths.

The API accepts both `assets/...` local paths and storage object paths such as
`products/red-graphic-t-shirt/main.webp`. It builds public URLs like:

```text
<SUPABASE_URL>/storage/v1/object/public/product-images/<object-path>
```

If an image is missing or cannot be loaded, the frontend uses
`assets/images/products/placeholder.svg`.

## API

Base URL: `/api`. All public endpoints return only active products.

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/api/products` | Active products with filtering, sorting and pagination |
| `GET` | `/api/products/featured` | Featured products (`limit` 1–24, default 8) |
| `GET` | `/api/products/latest` | Latest products (`limit` 1–24, default 8) |
| `GET` | `/api/products/{slug}` | Single active product by slug |
| `GET` | `/api/categories` | Categories with active product counts |
| `POST` | `/api/auth/register` | Create a customer account and start a session |
| `POST` | `/api/auth/login` | Sign in a customer |
| `POST` | `/api/auth/logout` | Destroy the customer session (CSRF required) |
| `GET` | `/api/auth/me` | Current customer (or `null`) and a CSRF token |
| `POST` | `/api/cart/validate` | Validate a guest cart against current PostgreSQL prices and stock |
| `GET` | `/api/cart` | Saved cart for the signed-in customer (reconciles stock) |
| `POST` | `/api/cart/items` | Add or merge a saved cart line (CSRF required) |
| `PATCH` | `/api/cart/items/{id}` | Change a saved cart line quantity (CSRF required) |
| `DELETE` | `/api/cart/items/{id}` | Remove a saved cart line (CSRF required) |
| `DELETE` | `/api/cart` | Clear the saved cart (CSRF required) |
| `POST` | `/api/cart/merge` | Merge a guest cart into the saved cart after login (CSRF required) |
| `POST` | `/api/orders` | Place an order from the saved cart (CSRF required) |

### `/api/products` query parameters

| Parameter | Values |
|---|---|
| `category` | Category slug, e.g. `shoes` |
| `search` | Up to 100 characters, searches name and description |
| `sort` | `default`, `featured`, `newest`, `price_asc`, `price_desc`, `name_asc`, `name_desc` |
| `page` | Integer ≥ 1 (default 1) |
| `limit` | Integer 1–48 (default 12) |

Examples:

```text
/api/products?category=shoes
/api/products?search=shirt
/api/products?sort=price_asc
/api/products?page=1&limit=12
/api/products?category=shoes&search=trail&sort=price_desc&page=1
```

### Response shape

List endpoint:

```json
{
  "success": true,
  "data": [
    {
      "id": "uuid",
      "name": "Red Graphic T-Shirt",
      "slug": "red-graphic-t-shirt",
      "description": "...",
      "price": 29,
      "sale_price": null,
      "effective_price": 29,
      "currency": "USD",
      "category": { "id": "uuid", "name": "T-Shirts", "slug": "t-shirts" },
      "main_image": { "url": "/assets/images/products/product-1.webp", "alt": "Red Graphic T-Shirt" },
      "gallery": [
        { "url": "/assets/images/products/product-1.webp", "alt": "Red Graphic T-Shirt", "is_primary": true, "sort_order": 0 }
      ],
      "variants": [],
      "stock_quantity": 40,
      "in_stock": true,
      "is_featured": true,
      "created_at": "2026-11-04T20:59:42+04:30",
      "updated_at": "2026-11-04T21:19:42+04:30"
    }
  ],
  "meta": {
    "page": 1,
    "limit": 12,
    "total": 13,
    "total_pages": 2,
    "has_previous": false,
    "has_next": true,
    "filters": { "category": null, "search": null, "sort": "default" }
  }
}
```

Errors use:

```json
{
  "success": false,
  "error": {
    "code": "product_not_found",
    "message": "Product not found."
  }
}
```

Status codes: `200` success, `201` created, `400` invalid input, `401`
authentication required, `404` missing product/route, `405` unsupported method,
`422` invalid cart/order payload, `500` generic server error. Internal SQL errors
and stack traces are logged server-side and never returned to the browser.

### `POST /api/cart/validate`

The guest cart lives in the browser, which means the browser could send stale or
tampered values. This endpoint therefore re-reads every product and variant from
PostgreSQL and returns the authoritative name, image, effective price (sale
price when valid), stock and a server-calculated summary. It never persists the
cart and never trusts a price, total or stock value from the client.

Request:

```json
{
  "items": [
    { "product_id": "uuid", "variant_id": "uuid-or-null", "quantity": 2 }
  ]
}
```

- At most 50 line items; quantities are integers 1–999.
- Duplicate product/variant lines are merged.
- `variant_id` is required when the product has variants and is ignored/cleared
  for products without variants.
- Products must exist and be active.

Response (`data.items[]` plus `data.summary`):

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "product_id": "uuid",
        "variant_id": "uuid",
        "requested_quantity": 2,
        "quantity": 2,
        "unit_price": 50,
        "line_total": 100,
        "currency": "USD",
        "product": {
          "id": "uuid",
          "name": "Product Printed T-shirt by HRX",
          "slug": "product-printed-t-shirt-by-hrx",
          "image": { "url": "/assets/images/...", "alt": "..." }
        },
        "size": "Medium",
        "color": null,
        "stock_quantity": 18,
        "in_stock": true,
        "available": true,
        "within_stock": true,
        "adjusted": false,
        "reason": null
      }
    ],
    "summary": {
      "item_count": 2,
      "line_count": 1,
      "currency": "USD",
      "subtotal": 100,
      "shipping": null,
      "tax": null,
      "total": 100
    }
  },
  "meta": { "count": 1 }
}
```

`available: false` reasons are `unavailable`, `variant_required`,
`variant_unavailable` and `out_of_stock`. When `quantity` exceeds stock the
response keeps `available: true`, sets `within_stock: false`, clamps `quantity`
to the available stock and sets `reason: "insufficient_stock"`. The storefront
uses these flags to block the change and show a validation message.

## Shopping cart

RedStore ships with a complete guest shopping cart:

- Add To Cart works on the product details page, including size/colour variant
  selection, quantity selection and stock validation.
- The header cart badge shows total units (not distinct rows) on every page.
- `pages/cart.html` loads real cart data with quantity controls (buttons and
  direct entry), remove, clear, empty state and an accurate subtotal.
- Prices, names, images and stock always come from PostgreSQL through the PHP
  API, including sale prices. Nothing authoritative is read from the browser.
- Adding the same product and variant twice increases the existing line instead
  of creating a duplicate.
- The Add To Cart button is disabled while a request is running and when the
  product/variant is out of stock or no size is selected.
- Unavailable products are removed and quantities above current stock are
  clamped when the cart is opened; a notification explains what changed.

### Guest cart persistence

Guests do not need an account. The cart is stored in `localStorage` under
`redstore_cart_v1` as IDs and quantities only:

```json
{
  "version": 1,
  "items": [
    { "productId": "uuid", "variantId": "uuid-or-null", "quantity": 2 }
  ]
}
```

It survives refreshes, navigation between storefront pages, browser restarts
and new sessions in the same browser profile. Invalid JSON, unknown UUIDs,
negative/zero quantities, duplicate lines and unavailable products are handled
safely. If `localStorage` is unavailable (private mode or quota), the cart keeps
working in memory for the current page session.

### Customer accounts and saved carts

Customers register or sign in on `pages/account.html`. Customer authentication
uses a **separate session cookie** (`redstore_customer`) from the admin dashboard
(`redstore_admin`) and only accepts accounts with `role = 'user'`. Passwords are
hashed with `password_hash()`/`bcrypt`, login attempts are rate-limited per email
and IP, and customer state-changing requests require a CSRF token returned by
`GET /api/auth/me`.

Signed-in customers get a database-backed cart stored in `cart_items`
(`user_id`, `product_id`, `variant_id`, `quantity`). Every route is scoped to the
session user id — the browser never supplies a user id.

- `GET /api/cart` returns the saved cart and reconciles it: unavailable products
  and variants are removed, and quantities above current stock are clamped.
- Adding the same product/variant again increases the existing line through the
  unique `(user_id, product_id, variant_id)` constraint.
- Quantity changes, removal and clearing go through the authenticated cart
  routes; prices, names, images and stock still come from PostgreSQL.
- After login or registration, the guest `localStorage` cart is validated and
  merged into `cart_items` (duplicate lines combined, stock clamped, unavailable
  items skipped). The local copy is cleared only after a successful merge.
- After sign-out the saved cart remains in the database and is restored on the
  next login. A guest cart is used again while signed out.

Signed-in customers can place an order from the cart page by entering their
delivery details. `POST /api/orders` re-reads the saved cart, locks the affected
product/variant rows, revalidates prices and stock in a transaction, snapshots
the line items in `orders`/`order_items`, decrements inventory and clears the
cart. The new order then appears in the admin dashboard.

A cart item does not reserve inventory; stock must be validated again at
order placement.

## Admin dashboard

The admin dashboard is server-rendered PHP at `/admin`; it shares the same
PostgreSQL database and helper libraries as the public API.

- Sign in at `/admin/login`. Customers (`role = 'user'`) and inactive accounts
  are rejected with the same generic error as an unknown email.
- Passwords are stored with `password_hash()`/`password_verify()` (bcrypt).
  Minimum length is 12 characters.
- Admin sessions use the `redstore_admin` cookie: `HttpOnly`, `SameSite=Lax`,
  `Secure` when `APP_ENV=production` (override with
  `SESSION_COOKIE_SECURE`). Session IDs are regenerated on login and
  periodically afterwards.
- Every state-changing request requires a CSRF token. Successful profile and
  password changes rotate the token.
- Login rate limiting is server-side: 5 failures per email or 20 per IP in a
  15-minute window returns HTTP 429. Successful login clears the failures.
- Security headers (`CSP`, `X-Frame-Options`, `nosniff`, `Referrer-Policy`,
  `Cache-Control: no-store`) are sent on admin and admin API responses.

Modules:

- **Overview** — revenue, product/order/customer counts, recent orders,
  customers and products, best sellers, and low/out-of-stock summary.
- **Products** — search/category/status filters, sorting and pagination;
  create/edit with slug, category, pricing, sale price, stock, featured/active
  flags, variants, and image gallery management (upload, replace, reorder,
  set primary, alt text, delete).
- **Categories** — create, rename, and delete; deleting a category that still
  has products requires explicit confirmation and unassigns those products.
- **Inventory** — search and `in`/`low`/`out` stock filters, inline stock edits
  for simple products and per-variant stock edits.
- **Orders** — search, status/date-range filters, sorting, pagination, customer
  and shipping details, line items with snapshotted names/prices, and status
  updates. Transitions are validated server-side: `pending → paid/processing/
  cancelled`, `paid → processing/shipped/cancelled/refunded`,
  `processing → shipped/delivered/cancelled/refunded`,
  `shipped → delivered/refunded`, `delivered → refunded`; `cancelled` and
  `refunded` are terminal.
- **Customers** — search, active/inactive filters, sorting by newest/name/
  orders/spend, order history, lifetime value, and deactivate/reactivate.
  Password hashes are never selected or returned. Customers are never deleted
  or promoted here.
- **Settings** — update the signed-in administrator's name/email and password.
  Static About/branding/layout content cannot be changed from the dashboard.

Product images are limited to JPEG, PNG, WebP or AVIF, 5 MB each, and 20 per
product. Uploads require `SUPABASE_URL` and `SUPABASE_SERVICE_ROLE_KEY`; without
them the dashboard returns a clear `storage_not_configured` error instead of
storing anything. Local `assets/...` image paths continue to work.

The admin JSON API lives under `/api/admin` (`/me`, `/dashboard`, `/products`,
`/categories`, `/inventory`, `/orders`, `/customers`, `/account`) and requires
the same admin session and CSRF token. It exists for progressive enhancement
and tooling; the dashboard itself posts to the server-rendered routes.

Known limitation: the schema has no `order_status_history` table, so status
changes update `orders.status`/`updated_at` but no per-change audit trail is
stored. Order line items are snapshots and are never rewritten by status
changes.

## Dynamic frontend

- `index.html` loads featured and latest products from the API, with loading,
  empty, error and retry states.
- `pages/products.html` supports category filtering, search, sorting,
  pagination and shareable URL state (`?category=...&search=...&sort=...&page=...`).
- `pages/product-details.html?slug=<slug>` renders any active product, including
  gallery images, variants, stock, description, Add To Cart and related products.
- `pages/cart.html` renders the guest or saved cart with quantity controls,
  remove, clear, empty state and a server-validated subtotal.
- `pages/account.html` handles customer registration, login and sign-out, then
  merges the guest cart into the saved cart.
- Product cards, cart rows and detail content are inserted with DOM `textContent`
  and attributes rather than raw HTML, so database text cannot inject markup.
- Products without a usable image fall back to
  `assets/images/products/placeholder.svg`.
- The original hard-coded star ratings on product cards were removed because the
  schema stores no rating data. All remaining card content comes from PostgreSQL.

## Render deployment

1. Commit and push the project to GitHub:

   ```bash
   git add .
   git commit -m "feat: add PHP product API and dynamic product pages"
   git push origin main
   ```

2. In Render, create a **New → Web Service** and connect the GitHub repository.
3. Choose **Docker** as the runtime. Render uses the repository `Dockerfile`
   (or deploy the included `render.yaml` Blueprint).
4. Add environment variables in **Environment**:

   - `DATABASE_URL` — Supabase session-pooler connection string with SSL.
   - `SUPABASE_URL` — project URL.
   - `SUPABASE_STORAGE_BUCKET=product-images`.
   - `SUPABASE_SERVICE_ROLE_KEY` — required for admin image uploads; mark it as
     a secret and never expose it to the browser.
   - `APP_ENV=production`.
   - `DB_SSLMODE=require`.
   - `SESSION_COOKIE_SECURE=true` (recommended in production).
   - Optionally `CORS_ALLOWED_ORIGIN` (leave blank for same-origin).

5. Run `database/migrations/005_admin_auth.sql` if the database was created
   before the admin dashboard, then create the first administrator from the
   Render shell:

   ```bash
   php scripts/create-admin.php --email=admin@example.com --name="Store Admin"
   ```

6. Render supplies `PORT`; `docker/entrypoint.sh` makes Apache listen on it.
7. Use `/` as the health check path. The API is served from `/api` and the
   dashboard from `/admin`.
8. Deploy, then test:

   ```bash
   curl -sS https://YOUR-SERVICE.onrender.com/api/products?limit=2
   curl -sS https://YOUR-SERVICE.onrender.com/api/categories
   curl -sS -o /dev/null -w '%{http_code}\n' https://YOUR-SERVICE.onrender.com/admin/login
   ```

   Then open the homepage, product listing and a product detail page in the
   browser and check the developer console for errors. Sign in at `/admin/login`
   and verify product, category, inventory, order and customer pages.

   Cart smoke test (no extra environment variables are needed):

   ```bash
   curl -sS -X POST https://YOUR-SERVICE.onrender.com/api/cart/validate \
     -H 'Content-Type: application/json' \
     -d '{"items":[{"product_id":"<product-uuid>","variant_id":null,"quantity":1}]}'
   ```

   Then in the browser: open a product, select a size if required, add it,
   confirm the header badge updates, open `/pages/cart.html`, change quantities,
   remove an item and refresh the page. The guest cart should persist. Test that
   adding more than the available stock is rejected.

   Saved cart smoke test: add an item as a guest, open `/pages/account.html`,
   register, and confirm the guest cart is merged and the header badge is kept.
   Sign out and back in; the saved cart should be restored from PostgreSQL.

Render free-tier services can sleep after inactivity and have usage limits.
The first request after sleep may be slow.

## Security notes

- PDO prepared statements are used for all database queries.
- Sort keys and limits are allowlisted/validated; user input is never
  interpolated into SQL.
- Only active products are returned from public endpoints.
- Guest cart storage contains only product IDs, variant IDs and quantities;
  names, prices and stock are re-read from PostgreSQL for every cart render and
  change. Client-supplied totals are never trusted.
- Database credentials and service-role keys are server-side environment
  variables only.
- `.env` and dotfiles are excluded from Git and denied by Apache and the local
  `server.php` router.
- Backend source folders, SQL files and tooling are denied by the root
  `.htaccess` and by `server.php` in local development.
- RLS is enabled on application tables without public policies.
- Product image writes are restricted to service-role/server-side operations.
  Uploads are MIME-checked with `finfo`, size-limited, generated with random
  object names, and limited to 20 images per product.
- Passwords use bcrypt; admin and customer sessions use separate cookies
  (`redstore_admin`, `redstore_customer`) with `HttpOnly`/`SameSite=Lax` (and
  `Secure` in production) and periodic ID regeneration.
- Admin and customer state-changing requests require CSRF tokens; login attempts
  are rate-limited per email and IP. Customer cart routes are scoped to the
  session user and never accept a user id from the browser.
- Customer passwords and password hashes are never selected by admin queries or
  returned by admin APIs.
- Error responses do not expose SQL, stack traces or credentials.

## Out of scope in this phase

- Payment integration.
- Password reset and transactional email delivery.
- Per-change order status history (the schema has no history table).
- Editing static site content (About Us, branding, layout, CSS) from the admin
  dashboard.

The schema includes tables for carts, orders and order items. Guest and saved
carts, customer accounts, order placement and the admin order/customer views are
functional; online payment processing is not implemented.
