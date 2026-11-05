-- ============================================================================
-- RedStore – migration 004: enable Row Level Security on application tables
-- ============================================================================
-- Run this on an existing database created before RLS was added to schema.sql.
-- It is idempotent and does not affect the PHP API, which connects with the
-- Supabase `postgres` role (table owner) or a service-role connection.
--
-- No anon/authenticated policies are created: the storefront must use the PHP
-- API, never Supabase directly from the browser. Add policies later if the
-- admin dashboard uses Supabase Auth/PostgREST.
-- ============================================================================

BEGIN;

ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE products ENABLE ROW LEVEL SECURITY;
ALTER TABLE product_images ENABLE ROW LEVEL SECURITY;
ALTER TABLE product_variants ENABLE ROW LEVEL SECURITY;
ALTER TABLE cart_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE order_items ENABLE ROW LEVEL SECURITY;

COMMIT;
