#!/usr/bin/env bash
# Smoke tests for Finbro producer endpoints.
#
# Usage (local):
#   export FINBRO_INTEGRATION_TOKEN='your-shared-secret'
#   export BUGRICER_API_BASE_URL='http://localhost/BugRicer/backend/api'
#   ./backend/scripts/smoke_finbro_integration.sh
#
# Usage (prod):
#   export FINBRO_INTEGRATION_TOKEN='<prod FINBRO_INTEGRATION_TOKEN>'
#   export BUGRICER_API_BASE_URL='https://bugbackend.bugricer.com/api'
#   ./backend/scripts/smoke_finbro_integration.sh
#
# Ops checklist (prod):
#   - FINBRO_INTEGRATION_TOKEN set in prod env (non-empty)
#   - Token matches Finbro BUGRICER_INTEGRATION_TOKEN
#   - Migration 055 idx_users_updated_at applied
#   - Migration 094 idx_ws_submission_date_user applied
#   - Migration 097 finbro_payroll_acknowledgements applied
#   - Routes:
#       /v1/integrations/finbro/users/status
#       /v1/integrations/finbro/hours?year=YYYY&month=M
#       POST /v1/integrations/finbro/payroll-hours
#   - Wrong token → 401 JSON; valid → 200 JSON (never HTML)
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

is_json() {
  local body="$1"
  echo "$body" | jq -e . >/dev/null 2>&1
}

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

  if ! is_json "$body"; then
    echo "FAIL: $name (body is not JSON — Finbro treats non-JSON as 503)"
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

# 1) Missing Bearer → 401 JSON
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  "$BASE/v1/integrations/finbro/users/status" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "status without token → 401 JSON" "401" "$code" "$body" 'has("error")'

# 2) Wrong Bearer → 401 JSON
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer wrong-token" \
  "$BASE/v1/integrations/finbro/users/status" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "status wrong token → 401 JSON" "401" "$code" "$body" 'has("error")'

# 3) Valid status → 200 with users array (+ role)
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/v1/integrations/finbro/users/status" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "users/status → 200" "200" "$code" "$body" \
  'has("users") and (.users | type == "array") and ((.users | length) == 0 or (.users[0] | has("role") and has("accountStatus") and has("updatedAt")))'

# 4) hours month → 200 (+ role on members)
YEAR=$(date +%Y)
MONTH=$(date +%-m 2>/dev/null || date +%m | sed 's/^0//')
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/v1/integrations/finbro/hours?year=${YEAR}&month=${MONTH}" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "hours year/month → 200" "200" "$code" "$body" \
  'has("period") and has("members") and (.members | type == "array") and ((.members | length) == 0 or (.members[0] | has("role") and has("totalHours") and has("overtimeHours")))'

# 5) invalid month → 422 JSON
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/v1/integrations/finbro/hours?year=${YEAR}&month=13" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "hours invalid month → 422 JSON" "422" "$code" "$body" 'has("error")'

# 6) by-user missing params → 422
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/v1/integrations/finbro/hours/by-user?email=a@b.com&from=2026-08-01" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "hours/by-user missing to → 422" "422" "$code" "$body" 'has("error")'

# 7) by-user from > to → 422
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/v1/integrations/finbro/hours/by-user?email=a@b.com&from=2026-08-31&to=2026-08-01" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "hours/by-user from>to → 422" "422" "$code" "$body" 'has("error")'

# 8) by-user valid range → 200
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE/v1/integrations/finbro/hours/by-user?email=nobody@example.com&from=2026-08-01&to=2026-08-31" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "hours/by-user → 200" "200" "$code" "$body" \
  'has("period") and has("members") and (.members | length) <= 1'

# 10) payroll-hours POST → 201 with data.acknowledged
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"smoke-test@example.com\",\"employeeId\":\"emp-smoke\",\"payDate\":\"2026-09-06\",\"hoursFrom\":\"2026-08-01\",\"hoursTo\":\"2026-08-31\",\"hoursWorked\":290,\"hourlyRate\":25,\"grossAmount\":7250,\"netAmount\":7250,\"bugricerHoursUsed\":313,\"manuallyEdited\":true,\"narration\":\"Smoke test payroll ack\",\"payrollEntryId\":\"pr-smoke-$(date +%s)\",\"source\":\"finbro\"}" \
  "$BASE/v1/integrations/finbro/payroll-hours" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "payroll-hours POST → 201" "201" "$code" "$body" \
  'has("data") and (.data.acknowledged == true)'

# 11) payroll-hours missing fields → 422
code=$(curl -sS -o /tmp/finbro_smoke_body.json -w '%{http_code}' \
  -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"email":"a@b.com"}' \
  "$BASE/v1/integrations/finbro/payroll-hours" || true)
body=$(cat /tmp/finbro_smoke_body.json 2>/dev/null || true)
check "payroll-hours invalid body → 422" "422" "$code" "$body" 'has("error")'

# 9) Parallel identical Bearer GETs (status + hours) — expect all 200 JSON, no HTML
echo "Running parallel concurrency smoke (4× status + 4× hours)…"
tmpdir=$(mktemp -d)
pids=()
for i in 1 2 3 4; do
  (
    curl -sS -o "$tmpdir/status_$i.body" -w '%{http_code}' \
      -H "Authorization: Bearer $TOKEN" \
      "$BASE/v1/integrations/finbro/users/status" > "$tmpdir/status_$i.code"
  ) &
  pids+=($!)
  (
    curl -sS -o "$tmpdir/hours_$i.body" -w '%{http_code}' \
      -H "Authorization: Bearer $TOKEN" \
      "$BASE/v1/integrations/finbro/hours?year=${YEAR}&month=${MONTH}" > "$tmpdir/hours_$i.code"
  ) &
  pids+=($!)
done
for pid in "${pids[@]}"; do
  wait "$pid" || true
done

parallel_ok=1
for i in 1 2 3 4; do
  sc=$(cat "$tmpdir/status_$i.code" 2>/dev/null || echo "000")
  sb=$(cat "$tmpdir/status_$i.body" 2>/dev/null || true)
  hc=$(cat "$tmpdir/hours_$i.code" 2>/dev/null || echo "000")
  hb=$(cat "$tmpdir/hours_$i.body" 2>/dev/null || true)
  if [[ "$sc" != "200" ]] || ! is_json "$sb"; then
    echo "FAIL: parallel status #$i (HTTP $sc)"
    echo "  body: $sb"
    parallel_ok=0
  fi
  if [[ "$hc" != "200" ]] || ! is_json "$hb"; then
    echo "FAIL: parallel hours #$i (HTTP $hc)"
    echo "  body: $hb"
    parallel_ok=0
  fi
done
rm -rf "$tmpdir"

if [[ "$parallel_ok" -eq 1 ]]; then
  echo "PASS: parallel status+hours → 200 JSON"
  pass=$((pass + 1))
else
  fail=$((fail + 1))
fi

echo ""
echo "Passed: $pass  Failed: $fail"
[[ "$fail" -eq 0 ]]
