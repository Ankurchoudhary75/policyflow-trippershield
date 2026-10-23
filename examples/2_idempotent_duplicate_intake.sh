#!/usr/bin/env bash
set -e

BASE_URL="${BASE_URL:-http://localhost:8000}"

echo "=========================================================="
echo "Scenario 2: Duplicate Intake Replay (Returns HTTP 200)"
echo "=========================================================="

echo "Submitting identical client_request_id + agent_id..."
curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }' | json_pp || cat
echo ""
