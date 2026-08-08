#!/usr/bin/env bash
# Smoke tests for Finbro producer endpoints.
#
# Usage:
#   export FINBRO_INTEGRATION_TOKEN='your-shared-secret'
#   export BUGRICER_API_BASE_URL='http://localhost/BugRicer/backend/api'   # or https://bugbackend.bugricer.com/api
#   ./backend/scripts/smoke_finbro_integration.sh
#
# Finbro consumer should set:
#   BUGRICER_API_BASE_URL=https://bugbackend.bugricer.com/api
#   BUGRICER_INTEGRATION_TOKEN=<same as FINBRO_INTEGRATION_TOKEN>

set -euo pipefail

BASE="${BUGRICER_API_BASE_URL:-http://localhost/BugRicer/backend/api}"
BASE="${BASE%/}"
TOKEN="${FINBRO_INTEGRATION_TOKEN:-}"

if [[ -z "$TOKEN" ]]; then
  echo "FAIL: set FINBRO_INTEGRATION_TOKEN"
  exit 1
fi

pass=0
fail=0

check() {
  local name="$1"
  local expect_code="$2"
  local got_code="$3"
  local body="$4"
  local jq_expr="${5:-}"

  if [[ "$got_code" != "$expect_code" ]]; then
    echo "FAIL: $name (HTTP $got_code, expected $expect_code)"
    echo "  body: $body"
    fail=$((fail + 1))
    return
  fi

  if [[ -n "$jq_expr" ]]; then
    if ! echo "$body" | jq -e "$jq_expr" >/dev/null 2>&1; then
      echo "FAIL: $name (JSON check failed: $jq_expr)"
      echo "  body: $body"
      fail=$((fail + 1))
      return
    fi
  fi

  echo "PASS: $name"
  pass=$((pass + 1))
}

echo "Base: $BASE"

# 1) Missing Bearer → 401
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  "$BASE/v1/integrations/finbro/users/status" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "status without token → 401" "401" "$code" "$body"

# 2) Wrong Bearer → 401
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer wrong-token" \
  "$BASE/v1/integrations/finbro/users/status" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "status wrong token → 401" "401" "$code" "$body"

# 3) Valid status → 200 with users array
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/v1/integrations/finbro/users/status" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "users/status → 200" "200" "$code" "$body" \
  'has("users") and (.users | type == "array")'

# 4) hours month → 200
YEAR=$(date +%Y)
MONTH=$(date +%-m 2>/dev/null || date +%m | sed 's/^0//')
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/v1/integrations/finbro/hours?year=${YEAR}&month=${MONTH}" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "hours year/month → 200" "200" "$code" "$body" \
  'has("period") and has("members") and (.members | type == "array")'

# 5) invalid month → 422
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/v1/integrations/finbro/hours?year=${YEAR}&month=13" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "hours invalid month → 422" "422" "$code" "$body"

# 6) by-user missing params → 422
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/v1/integrations/finbro/hours/by-user?email=a@b.com&from=2026-08-01" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "hours/by-user missing to → 422" "422" "$code" "$body"

# 7) by-user from > to → 422
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/v1/integrations/finbro/hours/by-user?email=a@b.com&from=2026-08-31&to=2026-08-01" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "hours/by-user from>to → 422" "422" "$code" "$body"

# 8) by-user valid range → 200
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/v1/integrations/finbro/hours/by-user?email=nobody@example.com&from=2026-08-01&to=2026-08-31" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "hours/by-user → 200" "200" "$code" "$body" \
  'has("period") and has("members") and (.members | length) <= 1'

echo ""
echo "Passed: $pass  Failed: $fail"
[[ "$fail" -eq 0 ]]
