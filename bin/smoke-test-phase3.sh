#!/usr/bin/env bash
#
# Phase 3 smoke test for v0.6.0 scheduled triggers.
#
# Four passes:
#   1. PHP syntax check (php -l) on every file touched in Phase 3
#      plus the two new step types.
#   2. Phase 1 regression.
#   3. Phase 2 regression.
#   4. Phase 3 functional smoke test (new step types + end-to-end
#      retention workflow).
#
# Run from Local's Site Shell:
#   cd "/Users/breonwilliams/Local Sites/ai-section-builder/app/public/wp-content/plugins/flowmint-workflows"
#   bash bin/smoke-test-phase3.sh
#
# Exit code: total failures across all four passes (0 = clean).

set -u

PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$PLUGIN_DIR" || exit 99

echo
echo "================================================"
echo " FlowMint Workflows v0.6.0 — Phase 3 Smoke Test"
echo "================================================"
echo
echo "Plugin dir: $PLUGIN_DIR"
echo

# -----------------------------------------------------------------
# Pass 1: php -l on every file modified in Phase 3.
# -----------------------------------------------------------------
echo "------------------------------------------------"
echo " Pass 1 / 4 — PHP syntax check (php -l)"
echo "------------------------------------------------"

FILES=(
  "includes/Core/class-fmw-step-registry.php"
  "includes/Steps/Core/class-step-fre-list-entries.php"
  "includes/Steps/Core/class-step-fre-delete-entries.php"
  "bin/smoke-test-phase3.php"
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
  echo "  WP-CLI not on PATH. Run this script from Local's site shell."
  exit 100
fi

# Mark debug.log position so we can show only NEW entries on failure.
DEBUG_LOG="$PLUGIN_DIR/../../debug.log"
DEBUG_LOG_BEFORE=0
if [[ -f "$DEBUG_LOG" ]]; then
  DEBUG_LOG_BEFORE=$( wc -c < "$DEBUG_LOG" | tr -d ' ' )
fi

# -----------------------------------------------------------------
# Pass 2: Phase 1 regression
# -----------------------------------------------------------------
echo "------------------------------------------------"
echo " Pass 2 / 4 — Phase 1 regression"
echo "------------------------------------------------"
echo

wp eval-file bin/smoke-test-phase1.php
PHASE1_FAILS=$?
echo
echo "Pass 2 result: $PHASE1_FAILS Phase 1 regression failure(s)."
echo

# -----------------------------------------------------------------
# Pass 3: Phase 2 regression
# -----------------------------------------------------------------
echo "------------------------------------------------"
echo " Pass 3 / 4 — Phase 2 regression"
echo "------------------------------------------------"
echo

wp eval-file bin/smoke-test-phase2.php
PHASE2_FAILS=$?
echo
echo "Pass 3 result: $PHASE2_FAILS Phase 2 regression failure(s)."
echo

# -----------------------------------------------------------------
# Pass 4: Phase 3 functional smoke test
# -----------------------------------------------------------------
echo "------------------------------------------------"
echo " Pass 4 / 4 — Phase 3 functional smoke test"
echo "------------------------------------------------"
echo

wp eval-file bin/smoke-test-phase3.php
PHASE3_FAILS=$?

# On any functional failure, dump new debug.log entries (Xdebug filtered).
TOTAL_FUNC_FAILS=$(( PHASE1_FAILS + PHASE2_FAILS + PHASE3_FAILS ))
if [[ $TOTAL_FUNC_FAILS -gt 0 && -f "$DEBUG_LOG" ]]; then
  echo
  echo "------------------------------------------------"
  echo " New debug.log entries from this run (Xdebug filtered)"
  echo "------------------------------------------------"
  DEBUG_LOG_AFTER=$( wc -c < "$DEBUG_LOG" | tr -d ' ' )
  if [[ "$DEBUG_LOG_AFTER" -gt "$DEBUG_LOG_BEFORE" ]]; then
    tail -c +$(( DEBUG_LOG_BEFORE + 1 )) "$DEBUG_LOG" \
      | grep -v "Xdebug" \
      | tail -100
  else
    echo "(no new debug.log entries from this run)"
  fi
fi

echo
echo "================================================"
echo " TOTAL: $LINT_FAILS lint + $PHASE1_FAILS P1 + $PHASE2_FAILS P2 + $PHASE3_FAILS P3"
echo "================================================"

exit $(( LINT_FAILS + PHASE1_FAILS + PHASE2_FAILS + PHASE3_FAILS ))
