#!/usr/bin/env bash
set -e

BASE_URL="${BASE_URL:-http://localhost:8000}"

echo "=========================================================="
echo "Scenario 5: Asynchronous Callback & Duplicate Delivery"
echo "=========================================================="

echo "1. Creating request configured with DELAYED_CALLBACK..."
CREATE_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-ASYNC-'$(date +%s)'",
    "agent_id": "AGENT-17",
    "customer_name": "Async Webhook Customer",
    "date_of_birth": "1993-09-09",
    "destination": "DOMESTIC",
    "trip_start": "2026-12-10",
    "trip_end": "2026-12-15",
    "plan_code": "PLUS",
    "sum_insured": 300000,
    "mock_scenario": "DELAYED_CALLBACK"
  }')

POLICY_ID=$(echo "$CREATE_RESP" | grep -o '"id":"[^"]*' | head -n 1 | cut -d'"' -f4)
echo "Created Policy ID: $POLICY_ID"

echo "2. Submitting (Enters PENDING_CONFIRMATION)..."
curl -s -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Accept: application/json" | json_pp || cat

EVENT_ID="EVT-DEMO-$(date +%s)"

echo ""
echo "3. Provider delivers webhook callback 1st time (APPLIED)..."
curl -s -X POST "${BASE_URL}/api/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider_event_id": "'$EVENT_ID'",
    "policy_request_id": "'$POLICY_ID'",
    "event_type": "CONFIRMED",
    "policy_number": "POL-CALLBACK-2026-XYZ"
  }' | json_pp || cat

echo ""
echo "4. Provider re-delivers duplicate webhook callback 2nd time (DUPLICATE_IGNORED)..."
curl -s -X POST "${BASE_URL}/api/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider_event_id": "'$EVENT_ID'",
    "policy_request_id": "'$POLICY_ID'",
    "event_type": "CONFIRMED",
    "policy_number": "POL-CALLBACK-2026-XYZ"
  }' | json_pp || cat
echo ""
