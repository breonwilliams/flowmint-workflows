#!/usr/bin/env bash
#
# Phase 2 smoke test for v0.6.0 scheduled triggers.
#
# Three passes:
#   1. PHP syntax check (php -l) on every file modified in Phase 2.
#   2. Phase 1 smoke test (regression — Phase 2 must not break anything
#      Phase 1 verified).
#   3. Phase 2 functional smoke test via wp-cli (listener wiring,
#      lifecycle handlers, end-to-end tick → run completes,
#      reconciliation, form-triggered regression).
#
# Run from Local by Flywheel's "Open Site Shell":
#   cd "/Users/breonwilliams/Local Sites/ai-section-builder/app/public/wp-content/plugins/flowmint-workflows"
#   bash bin/smoke-test-phase2.sh
#
# Exit code: total failures (lint + Phase 1 + Phase 2). 0 = clean.

set -u

PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$PLUGIN_DIR" || exit 99

echo
echo "================================================"
echo " FlowMint Workflows v0.6.0 — Phase 2 Smoke Test"
echo "================================================"
echo
echo "Plugin dir: $PLUGIN_DIR"
echo

# -----------------------------------------------------------------
# Pass 1: php -l on every file modified in Phase 2.
# -----------------------------------------------------------------
echo "------------------------------------------------"
echo " Pass 1 / 3 — PHP syntax check (php -l)"
echo "------------------------------------------------"

FILES=(
  "flowmint-workflows.php"
  "includes/Core/class-fmw-schedule-listener.php"
  "includes/Core/class-fmw-workflow-job.php"
  "includes/Database/class-fmw-run-repository.php"
  "bin/smoke-test-phase2.php"
)

LINT_FAILS=0
for f in "${FILES[@]}"; do
  if [[ ! -f "$f" ]]; then
    echo "  MISSING  $f"
    LINT_FAILS=$((LINT_FAILS + 1))
    continue
  fi

  OUTPUT=$( php -l "$f" 2>&1 )
  if [[ "$OUTPUT" == *"No syntax errors"* ]]; then
    echo "  OK       $f"
  else
    echo "  FAIL     $f"
    echo "$OUTPUT" | sed 's/^/             /'
    LINT_FAILS=$((LINT_FAILS + 1))
  fi
done

echo
echo "Pass 1 result: $LINT_FAILS lint failure(s)."
echo

if [[ $LINT_FAILS -gt 0 ]]; then
  echo "Stopping — fix syntax errors before running the functional tests."
  exit $LINT_FAILS
fi

if ! command -v wp >/dev/null 2>&1; then
  echo "  WP-CLI not on PATH. Run this script from Local's site shell"
  echo "  (right-click the site in Local → Open Site Shell)."
  exit 100
fi

# Mark debug.log position so we can show only NEW entries on failure.
DEBUG_LOG="$PLUGIN_DIR/../../debug.log"
DEBUG_LOG_BEFORE=0
if [[ -f "$DEBUG_LOG" ]]; then
  DEBUG_LOG_BEFORE=$( wc -c < "$DEBUG_LOG" | tr -d ' ' )
fi

# -----------------------------------------------------------------
# Pass 2: Re-run Phase 1 as a regression check.
# -----------------------------------------------------------------
echo "------------------------------------------------"
echo " Pass 2 / 3 — Phase 1 regression"
echo "------------------------------------------------"
echo

wp eval-file bin/smoke-test-phase1.php
PHASE1_FAILS=$?

echo
echo "Pass 2 result: $PHASE1_FAILS Phase 1 regression failure(s)."
echo

# -----------------------------------------------------------------
# Pass 3: Phase 2 functional smoke test.
# -----------------------------------------------------------------
echo "------------------------------------------------"
echo " Pass 3 / 3 — Phase 2 functional smoke test"
echo "------------------------------------------------"
echo

wp eval-file bin/smoke-test-phase2.php
PHASE2_FAILS=$?

# On any functional failure, dump new debug.log entries (Xdebug filtered).
if [[ $(( PHASE1_FAILS + PHASE2_FAILS )) -gt 0 && -f "$DEBUG_LOG" ]]; then
  echo
  echo "------------------------------------------------"
  echo " New debug.log entries from this run (Xdebug filtered)"
  echo "------------------------------------------------"
  DEBUG_LOG_AFTER=$( wc -c < "$DEBUG_LOG" | tr -d ' ' )
  if [[ "$DEBUG_LOG_AFTER" -gt "$DEBUG_LOG_BEFORE" ]]; then
    tail -c +$(( DEBUG_LOG_BEFORE + 1 )) "$DEBUG_LOG" \
      | grep -v "Xdebug" \
      | tail -80
  else
    echo "(no new debug.log entries from this run)"
  fi
fi

echo
echo "================================================"
echo " TOTAL: $LINT_FAILS lint + $PHASE1_FAILS Phase 1 regression + $PHASE2_FAILS Phase 2 functional"
echo "================================================"

exit $(( LINT_FAILS + PHASE1_FAILS + PHASE2_FAILS ))
