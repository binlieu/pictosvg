#!/usr/bin/env bash
# PicToSVG quality gate: environment, lint, tests (real conversions), fixture export checks.
# Exits non-zero on the first failed REQUIRED check. Optional checks print SKIPPED.
set -uo pipefail
cd "$(dirname "$0")/.."
fail=0
step() { printf '\n== %s\n' "$1"; }
ok()   { printf '   OK      %s\n' "$1"; }
bad()  { printf '   FAILED  %s\n' "$1"; fail=1; }
skip() { printf '   SKIPPED %s\n' "$1"; }

env_value() { grep -E "^$1=" .env 2>/dev/null | tail -1 | cut -d= -f2-; }
MAGICK="${PICTOSVG_MAGICK_BIN:-$(env_value PICTOSVG_MAGICK_BIN)}"; MAGICK="${MAGICK:-magick}"
POTRACE="${PICTOSVG_POTRACE_BIN:-$(env_value PICTOSVG_POTRACE_BIN)}"; POTRACE="${POTRACE:-potrace}"

step "Environment"
php -v | head -1 && ok "php" || bad "php missing"
[ -d vendor ] && ok "composer dependencies installed" || bad "run: composer install"
[ -f .env ] && ok ".env present" || bad "copy .env.example to .env and run: php artisan key:generate"
command -v "$MAGICK" >/dev/null && ok "ImageMagick: $("$MAGICK" -version | head -1 | cut -d' ' -f1-3)" || bad "ImageMagick ('$MAGICK') not found"
command -v "$POTRACE" >/dev/null && ok "Potrace: $("$POTRACE" --version | head -1)" || bad "Potrace ('$POTRACE') not found"
command -v vtracer >/dev/null && ok "vtracer present (not used)" || skip "vtracer not installed (not used by the app)"

step "PHP syntax"
if find app routes config tests -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null; then ok "php -l"; else bad "php -l"; fi

step "JavaScript syntax"
if command -v node >/dev/null; then node --check public/js/pictosvg.js && ok "node --check" || bad "public/js/pictosvg.js"; else skip "node not installed"; fi

step "Code style (Pint)"
if [ -x vendor/bin/pint ]; then vendor/bin/pint --test >/dev/null 2>&1 && ok "pint --test" || bad "pint --test (run vendor/bin/pint)"; else skip "pint not installed"; fi

step "Blade compiles"
php artisan view:cache >/dev/null 2>&1 && php artisan view:clear >/dev/null 2>&1 && ok "view:cache" || bad "view:cache"

step "Automated tests (real ImageMagick + Potrace conversions)"
if php artisan test; then ok "php artisan test"; else bad "php artisan test"; fi

step "Required documentation present"
for f in README.md docs/MASTER-SPEC.md docs/API.md docs/ARCHITECTURE.md docs/INSTALLATION.md docs/TESTING.md docs/14-current-status.md docs/15-agent-handoff.md docs/16-known-issues.md docs/17-change-log.md; do
  [ -s "$f" ] || { bad "missing $f"; }
done
[ "$fail" -eq 0 ] && ok "docs"

step "Not verified by this script"
skip "Importer checks (LightBurn, VCarve/Aspire, Inkscape) - manual"
skip "Physical laser/CNC output - manual"

echo
[ "$fail" -eq 0 ] && echo "check.sh: ALL REQUIRED CHECKS PASSED" || echo "check.sh: FAILURES ABOVE"
exit "$fail"
