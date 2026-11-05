-- ============================================================================
-- RedStore – migration 003 (OPTIONAL): point sample rows at Supabase Storage
-- ============================================================================
-- Run this ONLY after the matching files have been uploaded to the public
-- `product-images` bucket. If you run it first, the API will return storage
-- URLs that do not exist yet and the storefront will show its fallback image.
--
-- Expected object paths (bucket root = product-images):
--   products/red-graphic-t-shirt/main.webp
--   products/black-running-shoes/main.webp
--   ...
--   products/product-printed-t-shirt-by-hrx/main.jpg
--   products/product-printed-t-shirt-by-hrx/gallery-1.jpg ... gallery-4.jpg
--
-- The API also accepts local `assets/...` paths, so the storefront keeps
-- working while the upload is in progress.
-- ============================================================================

BEGIN;

-- Listing-card / fallback image for the 12 catalogue products.
UPDATE products
SET image_url = 'products/' || slug || '/main.webp'
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
);

UPDATE products
SET image_url = 'products/product-printed-t-shirt-by-hrx/main.jpg'
WHERE slug = 'product-printed-t-shirt-by-hrx';

-- Primary gallery rows for the catalogue products.
UPDATE product_images AS pi
SET image_url = 'products/' || p.slug || '/main.webp'
FROM products AS p
WHERE pi.product_id = p.id
  AND pi.sort_order = 0
  AND p.slug IN (
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
  );

-- Full HRX gallery.
UPDATE product_images AS pi
SET image_url = v.image_url
FROM products AS p
JOIN (VALUES
  (0, 'products/product-printed-t-shirt-by-hrx/gallery-1.jpg'),
  (1, 'products/product-printed-t-shirt-by-hrx/gallery-2.jpg'),
  (2, 'products/product-printed-t-shirt-by-hrx/gallery-3.jpg'),
  (3, 'products/product-printed-t-shirt-by-hrx/gallery-4.jpg')
) AS v(sort_order, image_url) ON true
WHERE pi.product_id = p.id
  AND p.slug = 'product-printed-t-shirt-by-hrx'
  AND pi.sort_order = v.sort_order;

COMMIT;
