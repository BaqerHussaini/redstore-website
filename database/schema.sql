-- ============================================================================
-- RedStore – PostgreSQL schema (Supabase compatible)
-- ============================================================================
-- Fresh Supabase project:
--   paste this file into the Supabase SQL Editor, or run:
--   psql "$DATABASE_URL" -f database/schema.sql
--
-- Existing RedStore database created with the original schema:
--   run database/migrations/001_product_features.sql instead, then optionally
--   database/migrations/002_storage_bucket.sql.
--
-- This file is idempotent: tables, indexes and triggers are guarded, and all
-- sample rows use ON CONFLICT so re-running it does not duplicate data.
--
-- The PHP/PDO API connects with a server-side role (Supabase's `postgres` role
-- or a dedicated API role). Never expose the connection string to the static
-- frontend. The browser talks to the PHP API only, never to Supabase directly.
-- ============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- ----------------------------------------------------------------------------
-- Shared updated_at trigger
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$;

-- ----------------------------------------------------------------------------
-- Users & authentication
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  email         citext NOT NULL,
  password_hash varchar(255) NOT NULL,
  full_name     varchar(120),
  role          varchar(10) NOT NULL DEFAULT 'user',
  is_active     boolean NOT NULL DEFAULT true,
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT users_email_unique UNIQUE (email),
  CONSTRAINT users_role_check CHECK (role IN ('user', 'admin')),
  CONSTRAINT users_email_not_blank CHECK (length(trim(email::text)) > 0),
  CONSTRAINT users_password_hash_not_blank CHECK (length(password_hash) > 0)
);

-- ----------------------------------------------------------------------------
-- Categories
-- Powers the product listing filters, product detail breadcrumb and
-- related-product grouping.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name       varchar(80) NOT NULL,
  slug       varchar(100) NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT categories_name_unique UNIQUE (name),
  CONSTRAINT categories_slug_unique UNIQUE (slug),
  CONSTRAINT categories_name_not_blank CHECK (length(trim(name)) > 0)
);

-- ----------------------------------------------------------------------------
-- Products & inventory
-- stock_quantity is the aggregate/cached stock. For sized products, the
-- per-variant stock lives in product_variants.
-- image_url is the listing-card fallback image. The detail gallery lives in
-- product_images. Values may be either local asset paths (assets/...) or
-- Supabase Storage object paths (products/<slug>/main.webp). The PHP API turns
-- storage paths into public Supabase Storage URLs.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  category_id    uuid,
  name           varchar(150) NOT NULL,
  slug           varchar(180) NOT NULL,
  description    text,
  price          numeric(10, 2) NOT NULL,
  sale_price     numeric(10, 2),
  stock_quantity integer NOT NULL DEFAULT 0,
  image_url      text NOT NULL,
  is_featured    boolean NOT NULL DEFAULT false,
  is_active      boolean NOT NULL DEFAULT true,
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT products_category_fkey
    FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
  CONSTRAINT products_slug_unique UNIQUE (slug),
  CONSTRAINT products_name_not_blank CHECK (length(trim(name)) > 0),
  CONSTRAINT products_image_url_not_blank CHECK (length(trim(image_url)) > 0),
  CONSTRAINT products_price_non_negative CHECK (price >= 0),
  CONSTRAINT products_sale_price_check CHECK (
    sale_price IS NULL OR (sale_price >= 0 AND sale_price <= price)
  ),
  CONSTRAINT products_stock_non_negative CHECK (stock_quantity >= 0)
);

-- ----------------------------------------------------------------------------
-- Product gallery images (product-details page)
-- One product may have many images; exactly one primary image is allowed.
-- sort_order preserves the gallery order shown on the storefront.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_images (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id uuid NOT NULL,
  image_url  text NOT NULL,
  alt_text   varchar(255) NOT NULL DEFAULT '',
  sort_order smallint NOT NULL DEFAULT 0,
  is_primary boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT product_images_product_fkey
    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT product_images_product_sort_unique UNIQUE (product_id, sort_order),
  CONSTRAINT product_images_url_not_blank CHECK (length(trim(image_url)) > 0),
  CONSTRAINT product_images_sort_non_negative CHECK (sort_order >= 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_product_images_primary
  ON product_images (product_id)
  WHERE is_primary;

-- ----------------------------------------------------------------------------
-- Product variants (sizes and colours for the product-details page)
-- stock_quantity is per variant. sku is optional but unique when present.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_variants (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id     uuid NOT NULL,
  size           varchar(20) NOT NULL,
  color          varchar(40) NOT NULL DEFAULT '',
  sku            varchar(64),
  stock_quantity integer NOT NULL DEFAULT 0,
  sort_order     smallint NOT NULL DEFAULT 0,
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT product_variants_product_fkey
    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT product_variants_product_size_color_unique
    UNIQUE (product_id, size, color),
  CONSTRAINT product_variants_id_product_unique UNIQUE (id, product_id),
  CONSTRAINT product_variants_sku_unique UNIQUE (sku),
  CONSTRAINT product_variants_size_not_blank CHECK (length(trim(size)) > 0),
  CONSTRAINT product_variants_stock_non_negative CHECK (stock_quantity >= 0),
  CONSTRAINT product_variants_sort_non_negative CHECK (sort_order >= 0)
);

-- ----------------------------------------------------------------------------
-- Shopping cart persistence
-- A cart row belongs to a user, product and optional variant.
-- The (variant_id, product_id) FK guarantees the selected variant belongs to
-- the product. NULLS NOT DISTINCT prevents duplicate no-variant cart rows.
-- (Requires PostgreSQL 15+; Supabase runs PostgreSQL 15 or newer.)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cart_items (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id    uuid NOT NULL,
  product_id uuid NOT NULL,
  variant_id uuid,
  quantity   integer NOT NULL DEFAULT 1,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT cart_items_user_fkey
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT cart_items_product_fkey
    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT cart_items_variant_fkey
    FOREIGN KEY (variant_id, product_id)
    REFERENCES product_variants (id, product_id) ON DELETE CASCADE,
  CONSTRAINT cart_items_user_product_variant_unique
    UNIQUE NULLS NOT DISTINCT (user_id, product_id, variant_id),
  CONSTRAINT cart_items_quantity_positive CHECK (quantity > 0)
);

-- ----------------------------------------------------------------------------
-- Orders (checkout summaries)
-- user_id is RESTRICT: financial records must survive user deletion.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id                     uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_number           varchar(20) NOT NULL,
  user_id                uuid NOT NULL,
  status                 varchar(20) NOT NULL DEFAULT 'pending',
  subtotal               numeric(10, 2) NOT NULL,
  shipping_fee           numeric(10, 2) NOT NULL DEFAULT 0,
  total_amount           numeric(10, 2) NOT NULL,
  currency               char(3) NOT NULL DEFAULT 'USD',
  shipping_full_name     varchar(120) NOT NULL,
  shipping_email         citext NOT NULL,
  shipping_phone         varchar(30),
  shipping_address_line1 varchar(180) NOT NULL,
  shipping_address_line2 varchar(180),
  shipping_city          varchar(100) NOT NULL,
  shipping_state         varchar(100),
  shipping_postal_code   varchar(20) NOT NULL,
  shipping_country       varchar(100) NOT NULL DEFAULT 'United States',
  notes                  text,
  created_at             timestamptz NOT NULL DEFAULT now(),
  updated_at             timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT orders_order_number_unique UNIQUE (order_number),
  CONSTRAINT orders_user_fkey
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT orders_status_check CHECK (
    status IN ('pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded')
  ),
  CONSTRAINT orders_subtotal_non_negative CHECK (subtotal >= 0),
  CONSTRAINT orders_shipping_fee_non_negative CHECK (shipping_fee >= 0),
  CONSTRAINT orders_total_non_negative CHECK (total_amount >= 0),
  CONSTRAINT orders_total_matches CHECK (total_amount = subtotal + shipping_fee),
  CONSTRAINT orders_currency_format CHECK (currency ~ '^[A-Z]{3}$')
);

-- ----------------------------------------------------------------------------
-- Order line items
-- Product name, image, size and unit price are snapshotted so order history
-- is immutable even if the product or variant is later edited or deleted.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id          uuid NOT NULL,
  product_id        uuid,
  variant_id        uuid,
  product_name      varchar(150) NOT NULL,
  product_image_url text,
  variant_size      varchar(20),
  unit_price        numeric(10, 2) NOT NULL,
  quantity          integer NOT NULL,
  line_total        numeric(12, 2) GENERATED ALWAYS AS (unit_price * quantity) STORED,
  created_at        timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT order_items_order_fkey
    FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT order_items_product_fkey
    FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
  CONSTRAINT order_items_variant_fkey
    FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE SET NULL,
  CONSTRAINT order_items_unit_price_non_negative CHECK (unit_price >= 0),
  CONSTRAINT order_items_quantity_positive CHECK (quantity > 0)
);

-- ----------------------------------------------------------------------------
-- Upgrade safety for databases created with the original schema.
-- These statements are harmless on a fresh install and make this file safe to
-- re-run, but database/migrations/001_product_features.sql should still be
-- used for a versioned upgrade.
-- ----------------------------------------------------------------------------
ALTER TABLE products ADD COLUMN IF NOT EXISTS sale_price numeric(10, 2);
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_featured boolean NOT NULL DEFAULT false;
ALTER TABLE products ALTER COLUMN image_url TYPE text;
ALTER TABLE product_images ALTER COLUMN image_url TYPE text;
ALTER TABLE order_items ALTER COLUMN product_image_url TYPE text;

ALTER TABLE products DROP CONSTRAINT IF EXISTS products_sale_price_check;
ALTER TABLE products ADD CONSTRAINT products_sale_price_check CHECK (
  sale_price IS NULL OR (sale_price >= 0 AND sale_price <= price)
);

ALTER TABLE product_variants ADD COLUMN IF NOT EXISTS color varchar(40) NOT NULL DEFAULT '';
ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS product_variants_product_size_unique;
ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS product_variants_product_size_color_unique;
ALTER TABLE product_variants ADD CONSTRAINT product_variants_product_size_color_unique
  UNIQUE (product_id, size, color);

-- ----------------------------------------------------------------------------
-- updated_at triggers
-- ----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS users_set_updated_at ON users;
CREATE TRIGGER users_set_updated_at
  BEFORE UPDATE ON users
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS categories_set_updated_at ON categories;
CREATE TRIGGER categories_set_updated_at
  BEFORE UPDATE ON categories
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS products_set_updated_at ON products;
CREATE TRIGGER products_set_updated_at
  BEFORE UPDATE ON products
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS product_images_set_updated_at ON product_images;
CREATE TRIGGER product_images_set_updated_at
  BEFORE UPDATE ON product_images
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS product_variants_set_updated_at ON product_variants;
CREATE TRIGGER product_variants_set_updated_at
  BEFORE UPDATE ON product_variants
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS cart_items_set_updated_at ON cart_items;
CREATE TRIGGER cart_items_set_updated_at
  BEFORE UPDATE ON cart_items
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS orders_set_updated_at ON orders;
CREATE TRIGGER orders_set_updated_at
  BEFORE UPDATE ON orders
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ----------------------------------------------------------------------------
-- Indexes
-- ----------------------------------------------------------------------------

-- Product listing, sorting, search and low-stock admin views
CREATE INDEX IF NOT EXISTS idx_products_price ON products (price);
CREATE INDEX IF NOT EXISTS idx_products_created_at ON products (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_products_name_lower ON products (lower(name));
CREATE INDEX IF NOT EXISTS idx_products_stock_quantity ON products (stock_quantity);
CREATE INDEX IF NOT EXISTS idx_products_category_id ON products (category_id);
CREATE INDEX IF NOT EXISTS idx_products_category_active
  ON products (category_id, created_at DESC)
  WHERE is_active;
CREATE INDEX IF NOT EXISTS idx_products_active_created
  ON products (created_at DESC)
  WHERE is_active;
CREATE INDEX IF NOT EXISTS idx_products_featured_created
  ON products (created_at DESC)
  WHERE is_active AND is_featured;

-- Case-insensitive product search (ILIKE '%term%')
CREATE INDEX IF NOT EXISTS idx_products_name_trgm
  ON products USING gin (name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_products_description_trgm
  ON products USING gin (description gin_trgm_ops);

-- Product gallery and variant lookups
CREATE INDEX IF NOT EXISTS idx_product_variants_product_sort
  ON product_variants (product_id, sort_order);

-- Cart lookups (user_id is covered by the unique constraint)
CREATE INDEX IF NOT EXISTS idx_cart_items_product_id ON cart_items (product_id);
CREATE INDEX IF NOT EXISTS idx_cart_items_variant_id ON cart_items (variant_id);

-- Customer order history and admin order management
CREATE INDEX IF NOT EXISTS idx_orders_user_created_at ON orders (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_status_created_at ON orders (status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders (created_at DESC);

-- Order detail lookups
CREATE INDEX IF NOT EXISTS idx_order_items_order_id ON order_items (order_id);
CREATE INDEX IF NOT EXISTS idx_order_items_product_id ON order_items (product_id);
CREATE INDEX IF NOT EXISTS idx_order_items_variant_id ON order_items (variant_id);

-- ----------------------------------------------------------------------------
-- Sample categories
-- ----------------------------------------------------------------------------
INSERT INTO categories (name, slug) VALUES
  ('T-Shirts',    't-shirts'),
  ('Shoes',       'shoes'),
  ('Pants',       'pants'),
  ('Accessories', 'accessories')
ON CONFLICT DO NOTHING;

-- ----------------------------------------------------------------------------
-- Sample products (matches assets/images/products/*)
-- created_at values are staggered so the homepage "Featured" and "Latest"
-- sections keep their original order until real products are added.
-- ----------------------------------------------------------------------------
INSERT INTO products (
  category_id, name, slug, description, price, stock_quantity, image_url,
  is_featured, created_at
) VALUES
  ((SELECT id FROM categories WHERE slug = 't-shirts'),
   'Red Graphic T-Shirt', 'red-graphic-t-shirt',
   'A soft, breathable cotton t-shirt with a bold RedStore graphic. Cut for everyday comfort and built to keep its shape wash after wash.',
   29.00, 40, 'assets/images/products/product-1.webp',
   true, now() - interval '20 minutes'),
  ((SELECT id FROM categories WHERE slug = 'shoes'),
   'Black Running Shoes', 'black-running-shoes',
   'Lightweight running shoes with a cushioned midsole and a grippy outsole. Designed for daily runs, gym sessions and long walks.',
   64.00, 25, 'assets/images/products/product-2.webp',
   true, now() - interval '21 minutes'),
  ((SELECT id FROM categories WHERE slug = 'pants'),
   'Charcoal Slim Track Pants', 'charcoal-slim-track-pants',
   'Slim-fit track pants in a stretchy, quick-drying fabric. A tapered leg and elastic waist make them ideal for training or relaxing.',
   45.00, 30, 'assets/images/products/product-3.webp',
   true, now() - interval '22 minutes'),
  ((SELECT id FROM categories WHERE slug = 't-shirts'),
   'Navy Polo T-Shirt', 'navy-polo-t-shirt',
   'A classic navy polo with a ribbed collar and a clean two-button placket. Smart enough for the office and comfortable enough for the weekend.',
   35.00, 35, 'assets/images/products/product-4.webp',
   true, now() - interval '23 minutes'),
  ((SELECT id FROM categories WHERE slug = 'shoes'),
   'Grey High-Top Sneakers', 'grey-high-top-sneakers',
   'High-top sneakers with a padded ankle collar and a retro gum sole. A versatile pair that works with jeans, joggers and shorts.',
   72.00, 18, 'assets/images/products/product-5.webp',
   false, now() - interval '1 minute'),
  ((SELECT id FROM categories WHERE slug = 't-shirts'),
   'Black Chevron T-Shirt', 'black-chevron-t-shirt',
   'A black crew-neck t-shirt with a chevron stripe print across the chest. Made from a soft cotton blend with a regular fit.',
   32.00, 50, 'assets/images/products/product-6.webp',
   false, now() - interval '2 minutes'),
  ((SELECT id FROM categories WHERE slug = 'accessories'),
   'Crew Socks, 3-Pack', 'crew-socks-3-pack',
   'Three pairs of cushioned crew socks in black, white and grey. Reinforced heels and toes keep them comfortable through repeated wear.',
   12.00, 100, 'assets/images/products/product-7.webp',
   false, now() - interval '3 minutes'),
  ((SELECT id FROM categories WHERE slug = 'accessories'),
   'Black Steel Watch', 'black-steel-watch',
   'A minimal stainless-steel watch with a black dial and a brushed metal strap. Water resistant and powered by a reliable quartz movement.',
   120.00, 12, 'assets/images/products/product-8.webp',
   false, now() - interval '4 minutes'),
  ((SELECT id FROM categories WHERE slug = 'accessories'),
   'Black Leather-Strap Watch', 'black-leather-strap-watch',
   'A black leather-strap watch with a clean dial and warm yellow numerals. Dress it up for evenings or wear it daily.',
   95.00, 15, 'assets/images/products/product-9.webp',
   false, now() - interval '5 minutes'),
  ((SELECT id FROM categories WHERE slug = 'shoes'),
   'Black & Red Trail Shoes', 'black-red-trail-shoes',
   'Trail-ready shoes with a durable upper and an aggressive red-camo outsole. Built for grip on loose ground and uneven paths.',
   68.00, 22, 'assets/images/products/product-10.webp',
   false, now() - interval '6 minutes'),
  ((SELECT id FROM categories WHERE slug = 'shoes'),
   'Grey Slip-On Sneakers', 'grey-slip-on-sneakers',
   'Easy slip-on sneakers with a breathable knit upper and a flexible white sole. A no-lace pair for quick errands and relaxed days.',
   58.00, 28, 'assets/images/products/product-11.webp',
   false, now() - interval '7 minutes'),
  ((SELECT id FROM categories WHERE slug = 'pants'),
   'Black Cuffed Joggers', 'black-cuffed-joggers',
   'Black joggers with a soft brushed interior and ribbed cuffs. Side pockets and a drawcord waist keep them practical.',
   42.00, 32, 'assets/images/products/product-12.webp',
   false, now() - interval '8 minutes')
ON CONFLICT (slug) DO NOTHING;

-- Product featured on pages/product-details.html
INSERT INTO products (
  category_id, name, slug, description, price, stock_quantity, image_url,
  is_featured, created_at
) VALUES (
  (SELECT id FROM categories WHERE slug = 't-shirts'),
  'Product Printed T-shirt by HRX',
  'product-printed-t-shirt-by-hrx',
  'Give your Summer wardrobe a style upgrade with the HRX Men''s Active T-shirt. Team it with a pair of shorts for your morning workout or denims for an evening out with the guys.',
  50.00,
  64,
  'assets/images/product-details-images/gallery-1.jpg',
  false,
  now() - interval '30 minutes'
)
ON CONFLICT (slug) DO NOTHING;

-- ----------------------------------------------------------------------------
-- Sample gallery images
-- Every product gets a primary image; the detail product gets 4.
-- ----------------------------------------------------------------------------
INSERT INTO product_images (product_id, image_url, alt_text, sort_order, is_primary)
SELECT id, image_url, name, 0, true
FROM products
WHERE slug IN (
  'red-graphic-t-shirt',
  'black-running-shoes',
  'charcoal-slim-track-pants',
  'navy-polo-t-shirt',
  'grey-high-top-sneakers',
  'black-chevron-t-shirt',
  'crew-socks-3-pack',
  'black-steel-watch',
  'black-leather-strap-watch',
  'black-red-trail-shoes',
  'grey-slip-on-sneakers',
  'black-cuffed-joggers'
)
ON CONFLICT DO NOTHING;

INSERT INTO product_images (product_id, image_url, alt_text, sort_order, is_primary)
SELECT p.id, v.image_url, v.alt_text, v.sort_order, v.is_primary
FROM products p
CROSS JOIN (VALUES
  ('assets/images/product-details-images/gallery-1.jpg', 'HRX printed T-shirt, front view',  0, true),
  ('assets/images/product-details-images/gallery-2.jpg', 'HRX printed T-shirt, back view',   1, false),
  ('assets/images/product-details-images/gallery-3.jpg', 'HRX printed T-shirt, side view',   2, false),
  ('assets/images/product-details-images/gallery-4.jpg', 'HRX printed T-shirt, detail view', 3, false)
) AS v(image_url, alt_text, sort_order, is_primary)
WHERE p.slug = 'product-printed-t-shirt-by-hrx'
ON CONFLICT DO NOTHING;

-- ----------------------------------------------------------------------------
-- Sample size variants for the product-details product
-- The page lists XXL, XL, Large, Medium and Small.
-- ----------------------------------------------------------------------------
INSERT INTO product_variants (product_id, size, sku, stock_quantity, sort_order)
SELECT p.id, v.size, v.sku, v.stock_quantity, v.sort_order
FROM products p
CROSS JOIN (VALUES
  ('XXL',   'HRX-TEE-XXL', 4,  1),
  ('XL',    'HRX-TEE-XL',  10, 2),
  ('Large', 'HRX-TEE-L',   20, 3),
  ('Medium','HRX-TEE-M',   18, 4),
  ('Small', 'HRX-TEE-S',   12, 5)
) AS v(size, sku, stock_quantity, sort_order)
WHERE p.slug = 'product-printed-t-shirt-by-hrx'
ON CONFLICT DO NOTHING;

-- ----------------------------------------------------------------------------
-- Row Level Security (defence in depth)
-- The storefront never talks to Supabase directly; PHP connects with a
-- server-side role. With RLS enabled and no policies for anon/authenticated,
-- Supabase's public PostgREST endpoint cannot read or write these tables.
-- Table owners / service_role still bypass RLS for the PHP API.
-- Add explicit policies later if the admin dashboard needs Supabase Auth.
-- ----------------------------------------------------------------------------
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE products ENABLE ROW LEVEL SECURITY;
ALTER TABLE product_images ENABLE ROW LEVEL SECURITY;
ALTER TABLE product_variants ENABLE ROW LEVEL SECURITY;
ALTER TABLE cart_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE order_items ENABLE ROW LEVEL SECURITY;

COMMIT;

-- ----------------------------------------------------------------------------
-- Promote an existing account to admin:
--   UPDATE users SET role = 'admin' WHERE email = 'owner@redstore.test';
--
-- Passwords must be hashed by PHP (password_hash / PASSWORD_ARGON2ID or
-- PASSWORD_DEFAULT), never stored as plain text.
-- ----------------------------------------------------------------------------
