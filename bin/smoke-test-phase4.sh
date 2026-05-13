#!/usr/bin/env bash
#
# Phase 4 smoke test for v0.6.0 scheduled triggers.
#
# Five passes:
#   1. PHP syntax check on every doc-related file change (docs themselves
#      don't lint, but the build-release.sh script change does).
#   2. Phase 1 regression.
#   3. Phase 2 regression.
#   4. Phase 3 regression.
#   5. Phase 4 — real Action Scheduler dispatch test (compressed time):
#        a. Setup PHP: create scheduled workflow + schedule one immediate
#           AS single-action tick.
#        b. Run `wp action-scheduler run` to force AS to dispatch.
#        c. Verify PHP: confirm the action completed AND a new completed
#           run row exists (via the tick handler).
#
# Run from Local's Site Shell:
#   cd "/Users/breonwilliams/Local Sites/ai-section-builder/app/public/wp-content/plugins/flowmint-workflows"
#   bash bin/smoke-test-phase4.sh
#
# Exit code: total failures across all five passes (0 = clean).

set -u

PLUGIN_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$PLUGIN_DIR" || exit 99

echo
echo "================================================"
echo " FlowMint Workflows v0.6.0 — Phase 4 Smoke Test"
echo "================================================"
echo
echo "Plugin dir: $PLUGIN_DIR"
echo

# -----------------------------------------------------------------
# Pass 1: lint (Phase 4 added two PHP files; build script is bash).
# -----------------------------------------------------------------
echo "------------------------------------------------"
echo " Pass 1 / 5 — PHP syntax check (php -l)"
echo "------------------------------------------------"

FILES=(
  "bin/smoke-test-phase4-as-setup.php"
  "bin/smoke-test-phase4-as-verify.php"
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

# Also bash-lint the build script — it's the canonical release path.
echo
echo "------------------------------------------------"
echo " Build script sanity check"
echo "------------------------------------------------"
if bash -n bin/build-release.sh 2>&1 | tee /tmp/fmw-build-syntax.log; then
  echo "  OK       bin/build-release.sh (syntactically valid)"
else
  echo "  FAIL     bin/build-release.sh (bash syntax error)"
  cat /tmp/fmw-build-syntax.log | sed 's/^/             /'
  LINT_FAILS=$((LINT_FAILS + 1))
fi

# Verify the exclude list includes the smoke-test files — they must
# NOT ship in the production zip.
if grep -q 'bin/smoke-test-\*' bin/build-release.sh; then
  echo "  OK       build-release.sh excludes bin/smoke-test-* files"
else
  echo "  FAIL     build-release.sh does NOT exclude bin/smoke-test-* — dev"
  echo "             scaffolding would ship in the production zip."
  LINT_FAILS=$((LINT_FAILS + 1))
fi

echo
echo "Pass 1 result: $LINT_FAILS lint / build-config failure(s)."
echo

if [[ $LINT_FAILS -gt 0 ]]; then
  echo "Stopping — fix syntax / config errors first."
  exit $LINT_FAILS
fi

if ! command -v wp >/dev/null 2>&1; then
  echo "  WP-CLI not on PATH. Run from Local's site shell."
  exit 100
fi

DEBUG_LOG="$PLUGIN_DIR/../../debug.log"
DEBUG_LOG_BEFORE=0
if [[ -f "$DEBUG_LOG" ]]; then
  DEBUG_LOG_BEFORE=$( wc -c < "$DEBUG_LOG" | tr -d ' ' )
fi

# -----------------------------------------------------------------
# Passes 2–4: full regression from prior phases.
# -----------------------------------------------------------------
echo "------------------------------------------------"
echo " Pass 2 / 5 — Phase 1 regression"
echo "------------------------------------------------"
echo
wp eval-file bin/smoke-test-phase1.php
P1_FAILS=$?
echo
echo "Pass 2 result: $P1_FAILS failure(s)."
echo

echo "------------------------------------------------"
echo " Pass 3 / 5 — Phase 2 regression"
echo "------------------------------------------------"
echo
wp eval-file bin/smoke-test-phase2.php
P2_FAILS=$?
echo
echo "Pass 3 result: $P2_FAILS failure(s)."
echo

echo "------------------------------------------------"
echo " Pass 4 / 5 — Phase 3 regression"
echo "------------------------------------------------"
echo
wp eval-file bin/smoke-test-phase3.php
P3_FAILS=$?
echo
echo "Pass 4 result: $P3_FAILS failure(s)."
echo

# -----------------------------------------------------------------
# Pass 5: REAL AS dispatch — setup → wp action-scheduler run → verify
# -----------------------------------------------------------------
echo "------------------------------------------------"
echo " Pass 5 / 5 — Real Action Scheduler dispatch"
echo "------------------------------------------------"
echo

echo "Step 5a — Setup: create workflow + schedule single-action immediate tick..."
wp eval-file bin/smoke-test-phase4-as-setup.php
SETUP_FAILS=$?
echo

if [[ $SETUP_FAILS -ne 0 ]]; then
  echo "Setup failed; skipping the rest of Pass 5."
  P4_FAILS=$SETUP_FAILS
else
  echo "Step 5b — Force AS to dispatch via 'wp action-scheduler run'..."
  #
  # A scheduled workflow run is a two-hop dispatch:
  #   pass 1: AS fires `fmw_scheduled_workflow_tick`. The listener
  #           handler creates a queued run row AND enqueues a fresh
  #           `fmw_run_workflow` async action.
  #   pass 2: AS fires `fmw_run_workflow`. The workflow_job handler
  #           builds context, runs the executor, marks the run
  #           completed.
  #
  # NB: --group=<name> is rejected by wp-cli unless the group is
  # registered as a taxonomy term (which AS doesn't do for runtime
  # groups). --hooks=<name> works because hooks are direct strings.
  # So we filter by hook, one per pass, in the order the dispatch
  # chain runs.
  for hook in fmw_scheduled_workflow_tick fmw_run_workflow; do
    echo "    Action Scheduler pass: $hook..."
    wp action-scheduler run --batch-size=10 --hooks="$hook" 2>&1 \
      | sed 's/^/      /' \
      | grep -vE "(Deprecated|Notice|Function as_|Using null as)" \
      || true
  done
  echo
  echo "Step 5c — Verify: did the dispatch path complete the workflow run?"
  echo
  wp eval-file bin/smoke-test-phase4-as-verify.php
  P4_FAILS=$?
fi

# On any functional failure, dump new debug.log entries (Xdebug filtered).
TOTAL_FUNC=$(( P1_FAILS + P2_FAILS + P3_FAILS + P4_FAILS ))
if [[ $TOTAL_FUNC -gt 0 && -f "$DEBUG_LOG" ]]; then
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
echo " TOTAL: $LINT_FAILS lint + $P1_FAILS P1 + $P2_FAILS P2 + $P3_FAILS P3 + $P4_FAILS P4"
echo "================================================"

exit $(( LINT_FAILS + P1_FAILS + P2_FAILS + P3_FAILS + P4_FAILS ))
