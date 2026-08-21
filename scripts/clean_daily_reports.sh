#!/usr/bin/env bash
# scripts/clean_daily_reports.sh
# ---------------------------------------------------------------------------
# Deletes locally-generated daily report files that must never be committed
# or deployed. Matches the same two naming conventions the report generator
# produces:
#   - Report_DD_Mon_YYYY.{html,md,pdf}            (repo root)
#   - docs/BMS_Daily_Report_*.{html,pdf}           (docs/)
#
# These are local scratch artifacts (see .gitignore) — safe to delete anytime;
# they get regenerated as needed and are never tracked in git history.
#
# Usage:
#   ./scripts/clean_daily_reports.sh            # delete matching files
#   ./scripts/clean_daily_reports.sh --dry-run  # list what would be deleted
# ---------------------------------------------------------------------------
set -euo pipefail

cd "$(dirname "$0")/.."

DRY_RUN=0
if [[ "${1:-}" == "--dry-run" ]]; then
    DRY_RUN=1
fi

shopt -s nullglob
files=(Report_*.html Report_*.md Report_*.pdf docs/BMS_Daily_Report_*.html docs/BMS_Daily_Report_*.pdf)
shopt -u nullglob

if [[ ${#files[@]} -eq 0 ]]; then
    echo "No daily report files found."
    exit 0
fi

if [[ $DRY_RUN -eq 1 ]]; then
    echo "Would delete ${#files[@]} file(s):"
    printf '  %s\n' "${files[@]}"
else
    echo "Deleting ${#files[@]} file(s):"
    for f in "${files[@]}"; do
        rm -f -- "$f"
        echo "  removed: $f"
    done
fi
