#!/usr/bin/env bash
# Bygger en installationsklar WordPress-plugin-zip: woo-kustom-export.zip
# Användning: npm run plugin-zip

set -euo pipefail

SLUG="woo-kustom-export"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGE_DIR="${ROOT_DIR}/.build"
ZIP_FILE="${ROOT_DIR}/${SLUG}.zip"

# Pluginfilerna som ska paketeras.
FILES=(
  "woo-kustom-export.php"
  "includes/class-woo-kustom-export.php"
  "includes/class-woo-kustom-csv-generator.php"
  "assets/admin.css"
  "readme.txt"
)

# Kontrollera att alla filer finns innan vi bygger.
for file in "${FILES[@]}"; do
  if [[ ! -f "${ROOT_DIR}/${file}" ]]; then
    echo "Fel: Saknad fil: ${file}" >&2
    exit 1
  fi
done

# Rensa ev. tidigare byggen.
rm -rf "${STAGE_DIR}" "${ZIP_FILE}"
mkdir -p "${STAGE_DIR}/${SLUG}"

# Kopiera pluginfilerna till staging-mappen.
for file in "${FILES[@]}"; do
  mkdir -p "${STAGE_DIR}/${SLUG}/$(dirname "${file}")"
  cp "${ROOT_DIR}/${file}" "${STAGE_DIR}/${SLUG}/${file}"
done

# Skapa zip-filen med pluginmappen som rot.
cd "${STAGE_DIR}"
zip -r -q "${ZIP_FILE}" "${SLUG}"

# Rensa staging-mappen.
cd "${ROOT_DIR}"
rm -rf "${STAGE_DIR}"

echo "Klar: ${ZIP_FILE}"
