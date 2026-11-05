# RedStore

RedStore is a responsive sportswear storefront. The public pages keep the
original HTML/CSS/vanilla-JavaScript design, but product data now comes from a
PHP REST API backed by Supabase PostgreSQL. Product images can be served from
the local `assets/images/` folder or from Supabase Storage.

This phase implements the database, PHP API, dynamic product pages and
deployment configuration. The admin dashboard, authentication, cart and
checkout workflows are intentionally **not** part of this phase.

## Architecture

```text
Browser (HTML/CSS/vanilla JS)
        |
        | fetch() /api/*
        v
Apache + PHP 8.3 (Render Docker service)
        |
        | PDO / SSL
        v
Supabase PostgreSQL

Supabase Storage (public bucket: product-images)
        ^
        | public image URLs returned by the PHP API
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
├── api/
│   ├── config/
│   │   ├── config.php          # env loading and app config
│   │   └── database.php        # PDO PostgreSQL connection
│   ├── controllers/
│   │   └── ProductController.php
│   ├── lib/
│   │   ├── ApiException.php
│   │   ├── ImageUrl.php        # local/storage image URL resolution
│   │   └── Response.php        # consistent JSON responses
│   ├── models/
│   │   ├── Category.php
│   │   └── Product.php
│   ├── routes/
│   │   └── products.php
│   ├── .htaccess               # Apache rewrite to index.php
│   └── index.php               # API front controller
├── assets/
│   ├── css/                    # existing design system
│   ├── images/                 # existing images + placeholder.svg
│   └── js/
│       ├── api.js              # shared fetch client
│       ├── products-ui.js      # safe DOM renderers
│       ├── home-products.js
│       ├── products-page.js
│       ├── product-details.js
│       ├── product-gallery.js
│       └── navigation.js
├── database/
│   ├── schema.sql              # fresh Supabase install
│   └── migrations/
│       ├── 001_product_features.sql
│       ├── 002_storage_bucket.sql
│       ├── 003_migrate_images_to_storage.sql   # optional, after upload
│       └── 004_enable_rls.sql
├── docker/
│   └── entrypoint.sh           # maps Apache to Render's PORT
├── scripts/
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

3. Start the app from the project root so `/api` and `/assets` resolve:

   ```bash
   php -S 127.0.0.1:8080 server.php
   ```

   Then open <http://127.0.0.1:8080/>.

4. Alternatively run the Apache + PHP image:

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
| `SUPABASE_SERVICE_ROLE_KEY` | Used only by the optional upload script; never by the browser. |
| `CORS_ALLOWED_ORIGIN` | Optional; only needed if the static frontend runs on a different origin. |
| `APP_ENV` | `production` or `development`. |

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
4. `database/migrations/003_migrate_images_to_storage.sql` — optional, only
   after the image files have actually been uploaded.

### 3. Verify the database

```sql
select count(*) from categories;
select count(*) from products where is_active;
select count(*) from products where is_featured;
select count(*) from product_images;
select count(*) from product_variants;
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
or writing store data. The PHP API still works because it connects as the table
owner/server-side role. Add explicit policies later only if the admin dashboard
uses Supabase Auth directly.

## Supabase Storage

1. The bucket is named `product-images` and is public for reads.
2. Run `database/migrations/002_storage_bucket.sql` in the SQL Editor, or create
   the bucket manually in **Storage** with public access.
3. The script also creates a public `SELECT` policy for the bucket. It creates
   **no** insert/update/delete policy: writes must use the service-role key from
   a trusted server process, or the future authenticated admin dashboard.
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

Status codes: `200` success, `400` invalid input, `404` missing product/route,
`405` unsupported method, `500` generic server error. Internal SQL errors and
stack traces are logged server-side and never returned to the browser.

## Dynamic frontend

- `index.html` loads featured and latest products from the API, with loading,
  empty, error and retry states.
- `pages/products.html` supports category filtering, search, sorting,
  pagination and shareable URL state (`?category=...&search=...&sort=...&page=...`).
- `pages/product-details.html?slug=<slug>` renders any active product, including
  gallery images, variants, stock, description and related products.
- Product cards and detail content are inserted with DOM `textContent` and
  attributes rather than raw HTML, so database text cannot inject markup.
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
   - `APP_ENV=production`.
   - `DB_SSLMODE=require`.
   - Optionally `CORS_ALLOWED_ORIGIN` (leave blank for same-origin).

5. Render supplies `PORT`; `docker/entrypoint.sh` makes Apache listen on it.
6. Use `/` as the health check path. The API is served from `/api`.
7. Deploy, then test:

   ```bash
   curl -sS https://YOUR-SERVICE.onrender.com/api/products?limit=2
   curl -sS https://YOUR-SERVICE.onrender.com/api/categories
   ```

   Then open the homepage, product listing and a product detail page in the
   browser and check the developer console for errors.

Render free-tier services can sleep after inactivity and have usage limits.
The first request after sleep may be slow.

## Security notes

- PDO prepared statements are used for all database queries.
- Sort keys and limits are allowlisted/validated; user input is never
  interpolated into SQL.
- Only active products are returned from public endpoints.
- Database credentials and service-role keys are server-side environment
  variables only.
- `.env` and dotfiles are excluded from Git and denied by Apache.
- Backend source folders and SQL files are denied by the root `.htaccess`.
- RLS is enabled on application tables without public policies.
- Product image writes are restricted to service-role/server-side operations.
- Error responses do not expose SQL, stack traces or credentials.

## Out of scope in this phase

- Admin dashboard and admin product/image management UI.
- Full authentication and user sessions.
- Cart persistence, checkout and order placement.
- Payment integration.

The schema already includes tables for users, carts, orders and order items so
those features can be added later without redesigning the product catalogue.
