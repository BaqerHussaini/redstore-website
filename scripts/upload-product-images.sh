#!/usr/bin/env bash
# =============================================================================
# RedStore – upload the local sample product images to Supabase Storage.
# =============================================================================
# Usage (from the project root):
#   export SUPABASE_URL="https://<project-ref>.supabase.co"
#   export SUPABASE_SERVICE_ROLE_KEY="<service-role-key>"
#   ./scripts/upload-product-images.sh
#
# The service-role key is read from the environment and is never printed or
# committed. Run this only from a trusted machine/terminal.
#
# After the upload finishes, run:
#   database/migrations/003_migrate_images_to_storage.sql
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUCKET="${SUPABASE_STORAGE_BUCKET:-product-images}"
: "${SUPABASE_URL:?Set SUPABASE_URL}"
: "${SUPABASE_SERVICE_ROLE_KEY:?Set SUPABASE_SERVICE_ROLE_KEY}"

BASE_URL="${SUPABASE_URL%/}/storage/v1/object/${BUCKET}"

content_type() {
  case "${1##*.}" in
    webp) printf 'image/webp' ;;
    jpg|jpeg) printf 'image/jpeg' ;;
    png) printf 'image/png' ;;
    avif) printf 'image/avif' ;;
    *) printf 'application/octet-stream' ;;
  esac
}

upload() {
  local file="$1"
  local destination="$2"
  local source="${ROOT}/${file}"

  if [ ! -f "$source" ]; then
    echo "Skipping missing file: ${file}" >&2
    return 0
  fi

  echo "Uploading ${file} -> ${BUCKET}/${destination}"
  curl --fail --silent --show-error \
    -X POST "${BASE_URL}/${destination}" \
    -H "Authorization: Bearer ${SUPABASE_SERVICE_ROLE_KEY}" \
    -H "Content-Type: $(content_type "$file")" \
    -H "x-upsert: true" \
    --data-binary "@${source}" >/dev/null
}

while IFS='|' read -r file destination; do
  [ -n "${destination:-}" ] || continue
  upload "$file" "$destination"
done <<'EOF'
assets/images/products/product-1.webp|products/red-graphic-t-shirt/main.webp
assets/images/products/product-2.webp|products/black-running-shoes/main.webp
assets/images/products/product-3.webp|products/charcoal-slim-track-pants/main.webp
assets/images/products/product-4.webp|products/navy-polo-t-shirt/main.webp
assets/images/products/product-5.webp|products/grey-high-top-sneakers/main.webp
assets/images/products/product-6.webp|products/black-chevron-t-shirt/main.webp
assets/images/products/product-7.webp|products/crew-socks-3-pack/main.webp
assets/images/products/product-8.webp|products/black-steel-watch/main.webp
assets/images/products/product-9.webp|products/black-leather-strap-watch/main.webp
assets/images/products/product-10.webp|products/black-red-trail-shoes/main.webp
assets/images/products/product-11.webp|products/grey-slip-on-sneakers/main.webp
assets/images/products/product-12.webp|products/black-cuffed-joggers/main.webp
assets/images/product-details-images/gallery-1.jpg|products/product-printed-t-shirt-by-hrx/gallery-1.jpg
assets/images/product-details-images/gallery-2.jpg|products/product-printed-t-shirt-by-hrx/gallery-2.jpg
assets/images/product-details-images/gallery-3.jpg|products/product-printed-t-shirt-by-hrx/gallery-3.jpg
assets/images/product-details-images/gallery-4.jpg|products/product-printed-t-shirt-by-hrx/gallery-4.jpg
EOF

# The HRX product uses its first gallery image as the main/fallback image.
upload "assets/images/product-details-images/gallery-1.jpg" \
  "products/product-printed-t-shirt-by-hrx/main.jpg"

echo
echo "Upload complete. Next: run database/migrations/003_migrate_images_to_storage.sql"
echo "in the Supabase SQL Editor (only after confirming the files are listed in Storage)."
