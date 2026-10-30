#!/usr/bin/env bash
# The same mandatory checks run before every tagged package is published.
set -euo pipefail
cd "$(dirname "$0")/.."
node --test tests/connection-ui.test.mjs
find . -name '*.php' -not -path './.git/*' -not -path './dist/*' -print0 | xargs -0 -n1 php -l
php tests/control-origin-check.php
php tests/error-diagnostics-check.php
php tests/error-context-check.php
if test "$#" != 1; then
  echo 'Usage: scripts/verify.sh /absolute/disposable/wordpress/root' >&2
  exit 1
fi
task_wp_root=$1
# These fixtures refuse non-local/non-test sites and intercept their outbound requests.
for task_test in agent-check agent-profile agent-cadence agent-fallback agent-wake identity agent-restart agent-job-recovery plugin-management theme-management manual-updates manual-report hourly-updates update-failures update-emails; do
  case "$task_test" in agent-check) task_file=tests/agent-check.php ;; *) task_file=tests/$task_test-check.php ;; esac
  wp --require="$PWD/tests/no-network.php" --path="$task_wp_root" --user=1 eval-file "$PWD/$task_file"
done
php tests/background-context-check.php "$task_wp_root"
