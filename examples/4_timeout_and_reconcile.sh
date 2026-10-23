#!/usr/bin/env bash
set -e

BASE_URL="${BASE_URL:-http://localhost:8000}"

echo "=========================================================="
echo "Scenario 4: Timeout After Issue & Safe Reconciliation"
echo "=========================================================="

echo "1. Creating request configured with TIMEOUT_AFTER_ISSUE..."
CREATE_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-TIMEOUT-'$(date +%s)'",
    "agent_id": "AGENT-17",
    "customer_name": "Timeout Customer",
    "date_of_birth": "1988-11-23",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-20",
    "trip_end": "2026-11-25",
    "plan_code": "PREMIUM",
    "sum_insured": 500000,
    "mock_scenario": "TIMEOUT_AFTER_ISSUE"
  }')

POLICY_ID=$(echo "$CREATE_RESP" | grep -o '"id":"[^"]*' | head -n 1 | cut -d'"' -f4)
echo "Created Policy ID: $POLICY_ID"

echo "2. Submitting (Simulates connection timeout)..."
curl -s -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Accept: application/json" | json_pp || cat

echo ""
echo "3. Status is now NEEDS_RECONCILIATION. Re-submitting to trigger safe reconciliation lookup..."
curl -s -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Accept: application/json" | json_pp || cat
echo ""
