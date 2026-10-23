#!/usr/bin/env bash
set -e

BASE_URL="${BASE_URL:-http://localhost:8000}"

echo "=========================================================="
echo "Scenario 7: List Requests and Inspect State History"
echo "=========================================================="

echo "1. Querying policy list with pagination..."
curl -s "${BASE_URL}/api/policy-requests?per_page=5" \
  -H "Accept: application/json" | json_pp || cat

echo ""
echo "2. Inspecting full audit trail for first policy..."
FIRST_ID=$(curl -s "${BASE_URL}/api/policy-requests?per_page=1" -H "Accept: application/json" | grep -o '"id":"[^"]*' | head -n 1 | cut -d'"' -f4)

if [ -n "$FIRST_ID" ]; then
  echo "Audit trail for Policy ID: $FIRST_ID"
  curl -s "${BASE_URL}/api/policy-requests/${FIRST_ID}" \
    -H "Accept: application/json" | json_pp || cat
fi
echo ""
