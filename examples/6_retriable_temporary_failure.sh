#!/usr/bin/env bash
set -e

BASE_URL="${BASE_URL:-http://localhost:8000}"

echo "=========================================================="
echo "Scenario 6: Temporary Failure & Safe Retry"
echo "=========================================================="

echo "1. Creating request with TEMPORARY_FAILURE scenario..."
CREATE_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-RETRY-'$(date +%s)'",
    "agent_id": "AGENT-17",
    "customer_name": "Temporary Error Customer",
    "date_of_birth": "1997-04-05",
    "destination": "DOMESTIC",
    "trip_start": "2026-11-01",
    "trip_end": "2026-11-03",
    "plan_code": "BASIC",
    "sum_insured": 100000,
    "mock_scenario": "TEMPORARY_FAILURE"
  }')

POLICY_ID=$(echo "$CREATE_RESP" | grep -o '"id":"[^"]*' | head -n 1 | cut -d'"' -f4)
echo "Created Policy ID: $POLICY_ID"

echo "2. Attempt #1 (Encounter 503, rolls back to VALIDATED with attempt_count=1)..."
curl -s -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Accept: application/json" | json_pp || cat

echo ""
echo "3. Attempt #2 (Safe retry, still VALIDATED with attempt_count=2)..."
curl -s -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Accept: application/json" | json_pp || cat

echo ""
echo "4. Attempt #3 (Exhausts max attempts -> terminal FAILED)..."
curl -s -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Accept: application/json" | json_pp || cat
echo ""
