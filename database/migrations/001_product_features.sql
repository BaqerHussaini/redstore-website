-- ============================================================================
-- RedStore – migration 001: product features, sale prices and colours
-- ============================================================================
-- Use this migration when the original database/schema.sql has already been
-- applied to a Supabase project. It is idempotent: it can be run more than
-- once without duplicating data or failing on existing objects.
--
-- Run in the Supabase SQL Editor, or:
--   psql "$DATABASE_URL" -f database/migrations/001_product_features.sql
-- ============================================================================

BEGIN;

CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- ----------------------------------------------------------------------------
-- Products: sale price and featured flag
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

-- ----------------------------------------------------------------------------
-- Variants: optional colour alongside size
-- Existing rows keep an empty colour, so their (product_id, size) uniqueness
-- is preserved under the new (product_id, size, color) constraint.
-- ----------------------------------------------------------------------------
ALTER TABLE product_variants ADD COLUMN IF NOT EXISTS color varchar(40) NOT NULL DEFAULT '';
ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS product_variants_product_size_unique;
ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS product_variants_product_size_color_unique;
ALTER TABLE product_variants ADD CONSTRAINT product_variants_product_size_color_unique
  UNIQUE (product_id, size, color);

-- ----------------------------------------------------------------------------
-- Indexes
-- ----------------------------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_products_active_created
  ON products (created_at DESC)
  WHERE is_active;
CREATE INDEX IF NOT EXISTS idx_products_featured_created
  ON products (created_at DESC)
  WHERE is_active AND is_featured;
DROP INDEX IF EXISTS idx_products_name_trgm;
CREATE INDEX idx_products_name_trgm
  ON products USING gin (name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_products_description_trgm
  ON products USING gin (description gin_trgm_ops);

-- ----------------------------------------------------------------------------
-- Preserve the original homepage featured strip for the sample catalogue.
-- Only rows that still exist are touched.
-- ----------------------------------------------------------------------------
UPDATE products
SET is_featured = true
WHERE slug IN (
  'red-graphic-t-shirt',
  'black-running-shoes',
  'charcoal-slim-track-pants',
  'navy-polo-t-shirt'
)
AND is_featured = false;

-- Backfill descriptions for the original sample products if they are missing.
UPDATE products SET description = v.description
FROM (VALUES
  ('red-graphic-t-shirt',
   'A soft, breathable cotton t-shirt with a bold RedStore graphic. Cut for everyday comfort and built to keep its shape wash after wash.'),
  ('black-running-shoes',
   'Lightweight running shoes with a cushioned midsole and a grippy outsole. Designed for daily runs, gym sessions and long walks.'),
  ('charcoal-slim-track-pants',
   'Slim-fit track pants in a stretchy, quick-drying fabric. A tapered leg and elastic waist make them ideal for training or relaxing.'),
  ('navy-polo-t-shirt',
   'A classic navy polo with a ribbed collar and a clean two-button placket. Smart enough for the office and comfortable enough for the weekend.'),
  ('grey-high-top-sneakers',
   'High-top sneakers with a padded ankle collar and a retro gum sole. A versatile pair that works with jeans, joggers and shorts.'),
  ('black-chevron-t-shirt',
   'A black crew-neck t-shirt with a chevron stripe print across the chest. Made from a soft cotton blend with a regular fit.'),
  ('crew-socks-3-pack',
   'Three pairs of cushioned crew socks in black, white and grey. Reinforced heels and toes keep them comfortable through repeated wear.'),
  ('black-steel-watch',
   'A minimal stainless-steel watch with a black dial and a brushed metal strap. Water resistant and powered by a reliable quartz movement.'),
  ('black-leather-strap-watch',
   'A black leather-strap watch with a clean dial and warm yellow numerals. Dress it up for evenings or wear it daily.'),
  ('black-red-trail-shoes',
   'Trail-ready shoes with a durable upper and an aggressive red-camo outsole. Built for grip on loose ground and uneven paths.'),
  ('grey-slip-on-sneakers',
   'Easy slip-on sneakers with a breathable knit upper and a flexible white sole. A no-lace pair for quick errands and relaxed days.'),
  ('black-cuffed-joggers',
   'Black joggers with a soft brushed interior and ribbed cuffs. Side pockets and a drawcord waist keep them practical.')
) AS v(slug, description)
WHERE products.slug = v.slug
  AND (products.description IS NULL OR trim(products.description) = '');

COMMIT;
