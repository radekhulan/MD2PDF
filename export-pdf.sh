#!/usr/bin/env bash
#
# export-pdf.sh — Markdown -> PDF (md2pdf engine), POSIX runner (Linux/macOS).
#
# Converts source .md files (per 'glob' in the config) to PDF via md2pdf.php.
# All constants/texts/paths come from the config passed with --config.
# Optionally renders PNG previews of every page via GhostScript.
#
# Usage:
#   ./export-pdf.sh --config /path/to/md2pdf.config.php
#   ./export-pdf.sh --config /path/to/md2pdf.config.php --preview
#   ./export-pdf.sh --config /path/to/md2pdf.config.php --only DocumentName
#
# If --config is omitted, md2pdf.config.php next to this script is used.
# PHP binary can be overridden via the $PHP env var.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENGINE="$SCRIPT_DIR/md2pdf.php"

CONFIG=""
PREVIEW=0
ONLY=""

usage() {
  sed -n '3,18p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
  case "$1" in
    -c|--config)  CONFIG="${2:-}"; shift 2 ;;
    --config=*)   CONFIG="${1#*=}"; shift ;;
    -p|--preview) PREVIEW=1; shift ;;
    -o|--only)    ONLY="${2:-}"; shift 2 ;;
    --only=*)     ONLY="${1#*=}"; shift ;;
    -h|--help)    usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage; exit 1 ;;
  esac
done

[ -f "$ENGINE" ] || { echo "Missing $ENGINE" >&2; exit 1; }

# --- Config ---------------------------------------------------------------
if [ -z "$CONFIG" ]; then
  if [ -f "$SCRIPT_DIR/md2pdf.config.php" ]; then
    CONFIG="$SCRIPT_DIR/md2pdf.config.php"
  else
    echo "Missing --config <path to md2pdf.config.php>." >&2
    echo "Sample: $SCRIPT_DIR/md2pdf.config.sample.php" >&2
    exit 1
  fi
fi
[ -f "$CONFIG" ] || { echo "Config not found: $CONFIG" >&2; exit 1; }
CONFIG="$(cd "$(dirname "$CONFIG")" && pwd)/$(basename "$CONFIG")"  # absolutize

# --- PHP ------------------------------------------------------------------
PHP="${PHP:-php}"
command -v "$PHP" >/dev/null 2>&1 || { echo "php not found (set the \$PHP env var)" >&2; exit 1; }

# --- Ensure vendor (mPDF) -------------------------------------------------
if [ ! -f "$SCRIPT_DIR/vendor/autoload.php" ]; then
  echo "vendor/ missing - running composer install..."
  command -v composer >/dev/null 2>&1 || {
    echo "vendor/ missing and composer not in PATH. Run: composer install (in $SCRIPT_DIR)" >&2
    exit 1
  }
  ( cd "$SCRIPT_DIR" && composer install --no-interaction --no-progress --no-dev )
fi

# --- Run export -----------------------------------------------------------
echo "PHP:    $(command -v "$PHP")"
echo "Script: $ENGINE"
echo "Config: $CONFIG"
echo

if [ -n "$ONLY" ]; then
  "$PHP" "$ENGINE" --config="$CONFIG" "$ONLY"
else
  "$PHP" "$ENGINE" --config="$CONFIG"
fi

# --- Optional: PNG previews (GhostScript) ---------------------------------
if [ "$PREVIEW" -eq 1 ]; then
  echo
  echo "Rendering PNG previews..."

  CFGJSON="$("$PHP" "$ENGINE" --config="$CONFIG" --print-config)"
  OUTDIR="$(printf '%s' "$CFGJSON" | "$PHP" -r '$j=json_decode(stream_get_contents(STDIN),true); echo (is_array($j)&&isset($j["output_dir"]))?$j["output_dir"]:"";')"
  [ -n "$OUTDIR" ] || { echo "Cannot read output_dir from --print-config" >&2; exit 1; }
  PREVDIR="$OUTDIR/_preview"

  GS=""
  for c in gs gswin64c; do
    if command -v "$c" >/dev/null 2>&1; then GS="$c"; break; fi
  done

  if [ -z "$GS" ]; then
    echo "GhostScript (gs) not found - previews skipped." >&2
  else
    mkdir -p "$PREVDIR"
    rm -f "$PREVDIR"/*.png 2>/dev/null || true
    for pdf in "$OUTDIR"/*.pdf; do
      [ -e "$pdf" ] || continue
      base="$(basename "$pdf" .pdf)"
      if [ -n "$ONLY" ] && [ "$base" != "$ONLY" ]; then continue; fi
      "$GS" -q -dNOPAUSE -dBATCH -sDEVICE=png16m -r110 \
            -dTextAlphaBits=4 -dGraphicsAlphaBits=4 \
            -o "$PREVDIR/${base}-p%02d.png" "$pdf" >/dev/null
      echo "  preview: $base"
    done
    echo "Done: previews in $PREVDIR"
  fi
fi

echo
echo "DONE."
