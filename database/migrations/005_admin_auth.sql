-- ============================================================================
-- RedStore – migration 005: admin login rate limiting
-- ============================================================================
-- Adds a small table used to throttle failed admin logins. It does not change
-- any catalogue, customer or order table.
--
-- Run in the Supabase SQL Editor, or:
--   psql "$DATABASE_URL" -f database/migrations/005_admin_auth.sql
--
-- This migration is idempotent.
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS admin_login_attempts (
  id           bigserial PRIMARY KEY,
  email        citext NOT NULL,
  ip_address   inet,
  user_agent   varchar(255),
  successful   boolean NOT NULL DEFAULT false,
  attempted_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_admin_login_attempts_email_time
  ON admin_login_attempts (email, attempted_at DESC);

CREATE INDEX IF NOT EXISTS idx_admin_login_attempts_ip_time
  ON admin_login_attempts (ip_address, attempted_at DESC);

CREATE INDEX IF NOT EXISTS idx_admin_login_attempts_time
  ON admin_login_attempts (attempted_at DESC);

ALTER TABLE admin_login_attempts ENABLE ROW LEVEL SECURITY;

COMMIT;
