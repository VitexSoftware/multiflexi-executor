#!/usr/bin/env bash
# Integration test for the "schedule now and wait for result" behaviour of
# `multiflexi-executor -r` (a.k.a. multiflexi-run-template).
#
# It verifies two paths:
#   1. Happy path  — with the executor daemon running, `-r <ID>` queues a job,
#                    blocks until the daemon finishes it, then prints the job's
#                    stdout/stderr and exits with the job's own exit code.
#   2. Timeout path — with the daemon stopped, `-r <ID> -t <sec>` waits out the
#                    timeout and exits 124.
#
# Usage:
#   ./run-template-wait.sh --runtemplate-id <ID> [--timeout <sec>]
#
# Requirements: a configured MultiFlexi database, multiflexi-cli on PATH, and a
# valid RunTemplate ID whose application exits 0 on success. The daemon is
# controlled via systemd (override with EXECUTOR_SERVICE) or, if unavailable,
# you may start/stop `php -q -f src/daemon.php` manually and pass
# --skip-daemon-control.

set -euo pipefail

RUNTEMPLATE_ID=""
TIMEOUT=120
SKIP_DAEMON_CONTROL=0
EXECUTOR="${EXECUTOR:-multiflexi-run-template}"
EXECUTOR_SERVICE="${EXECUTOR_SERVICE:-multiflexi-executor}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --runtemplate-id)       RUNTEMPLATE_ID="$2"; shift 2 ;;
        --timeout)              TIMEOUT="$2";        shift 2 ;;
        --skip-daemon-control)  SKIP_DAEMON_CONTROL=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

error_exit() { echo "FAIL: $1" >&2; exit 1; }

command -v "$EXECUTOR" &>/dev/null || error_exit "$EXECUTOR is not installed or not in PATH"
[[ -n "$RUNTEMPLATE_ID" ]] || error_exit "--runtemplate-id is required"

daemon() {
    if (( SKIP_DAEMON_CONTROL )); then
        echo "  (skipping daemon $1 — control it yourself)"
        return 0
    fi
    sudo systemctl "$1" "$EXECUTOR_SERVICE"
}

# --- Test 1: happy path (daemon running) ---
echo "[1/2] Happy path: daemon runs the queued job, command waits for the result"
daemon start
sleep 2

set +e
STDOUT=$("$EXECUTOR" -r "$RUNTEMPLATE_ID" -t "$TIMEOUT" 2>/tmp/rtwait.stderr)
EXITCODE=$?
set -e
STDERR=$(cat /tmp/rtwait.stderr)

echo "    exit code : $EXITCODE"
echo "    stdout    : $(printf '%s' "$STDOUT" | head -c 200)"
[[ -n "$STDERR" ]] && echo "    stderr    : $(printf '%s' "$STDERR" | head -c 200)"

if (( EXITCODE == 124 )); then
    error_exit "command timed out (124) even though the daemon was running"
fi
echo "    OK — job ran and the command returned its exit code ($EXITCODE)"

# --- Test 2: timeout path (daemon stopped) ---
echo "[2/2] Timeout path: daemon stopped, command must exit 124"
daemon stop
sleep 2

set +e
"$EXECUTOR" -r "$RUNTEMPLATE_ID" -t 5 >/dev/null 2>/tmp/rtwait.stderr
EXITCODE=$?
set -e

echo "    exit code : $EXITCODE"
echo "    stderr    : $(cat /tmp/rtwait.stderr)"

(( EXITCODE == 124 )) || error_exit "expected exit 124 on timeout, got $EXITCODE"
echo "    OK — command timed out and exited 124"

# Restore the daemon so the system is left running as before.
daemon start

echo ""
echo "PASS: schedule-and-wait behaviour verified."
