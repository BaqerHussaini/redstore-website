-- ============================================================================
-- RedStore – migration 002: Supabase Storage bucket for product images
-- ============================================================================
-- Run this in the Supabase SQL Editor only. Supabase owns the `storage`
-- schema, so this script is a no-op on a plain PostgreSQL database.
--
-- It creates/updates the public `product-images` bucket and a public read
-- policy. It deliberately creates NO insert/update/delete policy for the
-- public API: uploads, replacements and deletions must be performed by a
-- trusted server-side process using the service-role key, or by the future
-- admin dashboard after authentication is implemented.
-- ============================================================================

DO $$
BEGIN
  IF to_regclass('storage.buckets') IS NOT NULL THEN
    EXECUTE $sql$
      INSERT INTO storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
      VALUES (
        'product-images',
        'product-images',
        true,
        5242880,
        ARRAY['image/webp', 'image/jpeg', 'image/png', 'image/avif']
      )
      ON CONFLICT (id) DO UPDATE
        SET public = EXCLUDED.public,
            file_size_limit = EXCLUDED.file_size_limit,
            allowed_mime_types = EXCLUDED.allowed_mime_types
    $sql$;
  END IF;

  IF to_regclass('storage.objects') IS NOT NULL THEN
    EXECUTE 'DROP POLICY IF EXISTS "Public read product images" ON storage.objects';
    EXECUTE $sql$
      CREATE POLICY "Public read product images"
      ON storage.objects
      FOR SELECT
      TO public
      USING (bucket_id = 'product-images')
    $sql$;
  END IF;
END $$;

-- Verify the bucket (Supabase SQL Editor):
--   SELECT id, name, public, file_size_limit, allowed_mime_types
--   FROM storage.buckets
--   WHERE id = 'product-images';
