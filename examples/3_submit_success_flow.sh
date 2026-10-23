#!/usr/bin/env bash
set -e

BASE_URL="${BASE_URL:-http://localhost:8000}"

echo "=========================================================="
echo "Scenario 3: Policy Submission & Successful Issuance"
echo "=========================================================="

echo "1. Creating request..."
CREATE_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-SUBMIT-'$(date +%s)'",
    "agent_id": "AGENT-17",
    "customer_name": "Success Customer",
    "date_of_birth": "1994-02-18",
    "destination": "DOMESTIC",
    "trip_start": "2026-12-01",
    "trip_end": "2026-12-05",
    "plan_code": "BASIC",
    "sum_insured": 100000,
    "mock_scenario": "SUCCESS"
  }')

POLICY_ID=$(echo "$CREATE_RESP" | grep -o '"id":"[^"]*' | head -n 1 | cut -d'"' -f4)
echo "Created Policy ID: $POLICY_ID"

echo "2. Submitting to mock insurer..."
curl -s -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Accept: application/json" | json_pp || cat

echo ""
echo "3. Submitting AGAIN to verify safe resume (No duplicate provider call)..."
curl -s -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Accept: application/json" | json_pp || cat
echo ""
