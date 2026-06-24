#!/usr/bin/env bash
# Parallel execution benchmark: schedules N jobs for multiflexi-probe (or any
# RunTemplate) and measures how long the daemon takes to drain them all.
#
# Usage:
#   ./benchmark-parallel.sh [--runtemplate-id <ID>] [--count <N>] [--timeout <sec>]
#
# When --runtemplate-id is omitted the script:
#   1. Imports the probe app from ~/Projects/Multi/multiflexi-probe
#   2. Creates a benchmark company (skipped if it already exists)
#   3. Assigns the probe app to the company (creates a default RunTemplate)
#   4. Uses the resulting RunTemplate ID
#
# Options:
#   --runtemplate-id   Skip setup and use this RunTemplate ID directly
#   --count            Number of jobs to schedule (default: 1000)
#   --timeout          Seconds to wait for drain (default: 600)

set -euo pipefail

RUNTEMPLATE_ID=""
COUNT=1000
TIMEOUT=600
PROBE_APP_UUID="775ed801-2489-4981-bc14-d8a01cba1938"
BENCHMARK_COMPANY_NAME="Benchmark"
BENCHMARK_COMPANY_SLUG="benchmark"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --runtemplate-id) RUNTEMPLATE_ID="$2"; shift 2 ;;
        --count)          COUNT="$2";          shift 2 ;;
        --timeout)        TIMEOUT="$2";        shift 2 ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

# --- Setup: import app + create company + assign if no explicit runtemplate-id ---
if [[ -z "$RUNTEMPLATE_ID" ]]; then

    # 1. Try to find an existing probe run template
    RUNTEMPLATE_ID=$(multiflexi-cli run-template:list --format json 2>/dev/null \
        | jq -r --arg uuid "$PROBE_APP_UUID" '.[] | select(.app_uuid == $uuid) | .id' \
        | head -1)

    if [[ -n "$RUNTEMPLATE_ID" ]]; then
        echo "[setup] Found existing probe RunTemplate ID: $RUNTEMPLATE_ID"
    else
        # The probe app is imported into the DB by multiflexi-probe's postinst —
        # no manual import needed here.

        # 2. Get or create the benchmark company
        COMPANY_ID=$(multiflexi-cli company:list --format json 2>/dev/null \
            | jq -r --arg slug "$BENCHMARK_COMPANY_SLUG" '.[] | select(.slug == $slug) | .id' \
            | head -1)

        if [[ -n "$COMPANY_ID" ]]; then
            echo "[setup] Using existing company '$BENCHMARK_COMPANY_NAME' (ID: $COMPANY_ID)"
        else
            echo "[setup] Creating company '$BENCHMARK_COMPANY_NAME' ..."
            COMPANY_ID=$(multiflexi-cli company:create \
                --name "$BENCHMARK_COMPANY_NAME" \
                --slug "$BENCHMARK_COMPANY_SLUG" \
                --email "benchmark@example.com" \
                --format json 2>/dev/null \
                | jq -r '.id')
            echo "[setup] Created company ID: $COMPANY_ID"
        fi

        # 4. Assign probe app to the company (creates default RunTemplate)
        echo "[setup] Assigning probe app to company $COMPANY_ID ..."
        multiflexi-cli company-app:assign \
            --company_id "$COMPANY_ID" \
            --app_uuid "$PROBE_APP_UUID" \
            --format json

        # 5. Read back the new run template ID
        RUNTEMPLATE_ID=$(multiflexi-cli run-template:list --format json 2>/dev/null \
            | jq -r --arg uuid "$PROBE_APP_UUID" '.[] | select(.app_uuid == $uuid) | .id' \
            | head -1)

        if [[ -z "$RUNTEMPLATE_ID" ]]; then
            echo "ERROR: Run template was not created. Check multiflexi-cli output above." >&2
            exit 1
        fi
        echo "[setup] Created probe RunTemplate ID: $RUNTEMPLATE_ID"
    fi
fi

echo ""
echo "=== MultiFlexi Parallel Execution Benchmark ==="
echo "RunTemplate ID  : $RUNTEMPLATE_ID"
echo "Jobs to schedule: $COUNT"
echo "Timeout         : ${TIMEOUT}s"
echo ""

# --- Phase 1: schedule N jobs ---
echo "[$(date '+%H:%M:%S')] Scheduling $COUNT jobs..."
SCHEDULE_START=$(date +%s%3N)

SCHEDULED=0
FAILED=0
for i in $(seq 1 "$COUNT"); do
    if multiflexi-cli run-template:schedule --id "$RUNTEMPLATE_ID" --format json >/dev/null 2>&1; then
        SCHEDULED=$((SCHEDULED + 1))
    else
        FAILED=$((FAILED + 1))
    fi

    if (( i % 100 == 0 )); then
        echo "  ... $i / $COUNT scheduled (${FAILED} failed so far)"
    fi
done

SCHEDULE_END=$(date +%s%3N)
SCHEDULE_MS=$(( SCHEDULE_END - SCHEDULE_START ))

echo "[$(date '+%H:%M:%S')] Scheduling done: $SCHEDULED ok, $FAILED failed (${SCHEDULE_MS} ms total)"
echo ""

if (( SCHEDULED == 0 )); then
    echo "ERROR: No jobs were scheduled. Aborting." >&2
    exit 1
fi

# --- Phase 2: wait for the daemon to drain ---
echo "[$(date '+%H:%M:%S')] Waiting for daemon to drain schedule table (timeout: ${TIMEOUT}s)..."
DRAIN_START=$(date +%s)
DEADLINE=$(( DRAIN_START + TIMEOUT ))

while true; do
    NOW=$(date +%s)
    if (( NOW >= DEADLINE )); then
        REMAINING=$(multiflexi-cli schedule:list --format json 2>/dev/null | jq 'length' 2>/dev/null || echo "?")
        echo ""
        echo "[$(date '+%H:%M:%S')] TIMEOUT — approximately $REMAINING job(s) still pending." >&2
        exit 2
    fi

    PENDING=$(multiflexi-cli schedule:list --format json 2>/dev/null | jq 'length' 2>/dev/null || echo "-1")

    if [[ "$PENDING" == "0" ]]; then
        break
    fi

    ELAPSED=$(( NOW - DRAIN_START ))
    printf "\r  pending: %-6s  elapsed: %3ds " "$PENDING" "$ELAPSED"
    sleep 2
done

echo ""
DRAIN_END=$(date +%s)
DRAIN_SEC=$(( DRAIN_END - DRAIN_START ))

# --- Summary ---
echo ""
echo "=== Results ==="
echo "Scheduled         : $SCHEDULED jobs"
echo "Scheduling time   : ${SCHEDULE_MS} ms  ($(( SCHEDULE_MS / SCHEDULED )) ms/job avg)"
echo "Drain time        : ${DRAIN_SEC}s"
if (( DRAIN_SEC > 0 )); then
    echo "Throughput        : $(( SCHEDULED / DRAIN_SEC )) jobs/s"
fi
echo "==============="
