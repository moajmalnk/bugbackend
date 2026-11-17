#!/usr/bin/env bash
# Smoke test: CODO AI Innovations Profile Photo API (M2M).
#
# Usage:
#   CODO_AI_INTEGRATION_TOKEN=secret \
#   BUGRICER_API_BASE_URL=https://bugbackend.bugricer.com/api \
#   ./backend/scripts/smoke_codo_ai_avatars.sh
#
# Optional overrides:
#   SMOKE_EMPLOYEE_ID=CODO-KTLP-TKS0
#   SMOKE_EMPLOYEE_ID_2=CODO-TSLP-TNTK
#   SMOKE_USERNAME=rubza
#   SMOKE_EMAIL=rizwanrubza@gmail.com

set -euo pipefail

BASE_URL="${BUGRICER_API_BASE_URL:-https://bugbackend.bugricer.com/api}"
BASE_URL="${BASE_URL%/}"
TOKEN="${CODO_AI_INTEGRATION_TOKEN:-}"

EMP1="${SMOKE_EMPLOYEE_ID:-CODO-KTLP-TKS0}"
EMP2="${SMOKE_EMPLOYEE_ID_2:-CODO-TSLP-TNTK}"
USER1="${SMOKE_USERNAME:-rubza}"
EMAIL1="${SMOKE_EMAIL:-rizwanrubza@gmail.com}"

if [[ -z "${TOKEN}" ]]; then
  echo "FAIL: set CODO_AI_INTEGRATION_TOKEN" >&2
  exit 1
fi

auth_hdr=(-H "Authorization: Bearer ${TOKEN}" -H "Accept: application/json")

echo "== 401 without token =="
code=$(curl -sS -o /tmp/codo_ai_smoke_401.json -w "%{http_code}" \
  "${BASE_URL}/v1/users/${EMP1}/avatar" || true)
if [[ "${code}" != "401" ]]; then
  echo "FAIL: expected 401, got ${code}" >&2
  cat /tmp/codo_ai_smoke_401.json >&2 || true
  exit 1
fi
echo "OK 401"

echo "== GET single by employee_id =="
code=$(curl -sS -o /tmp/codo_ai_smoke_single.json -w "%{http_code}" \
  "${auth_hdr[@]}" \
  "${BASE_URL}/v1/users/${EMP1}/avatar")
if [[ "${code}" != "200" && "${code}" != "404" ]]; then
  echo "FAIL: unexpected status ${code}" >&2
  cat /tmp/codo_ai_smoke_single.json >&2
  exit 1
fi
if ! grep -q '"success"' /tmp/codo_ai_smoke_single.json; then
  echo "FAIL: missing success field" >&2
  cat /tmp/codo_ai_smoke_single.json >&2
  exit 1
fi
echo "OK single (${code})"
cat /tmp/codo_ai_smoke_single.json
echo

echo "== GET single by username query =="
code=$(curl -sS -o /tmp/codo_ai_smoke_user.json -w "%{http_code}" \
  "${auth_hdr[@]}" \
  "${BASE_URL}/v1/users/${EMP1}/avatar?username=${USER1}")
echo "OK username query (${code})"

echo "== GET single by email query =="
code=$(curl -sS -o /tmp/codo_ai_smoke_email.json -w "%{http_code}" \
  "${auth_hdr[@]}" \
  --get --data-urlencode "email=${EMAIL1}" \
  "${BASE_URL}/v1/users/_/avatar")
echo "OK email query (${code})"

echo "== GET batch ids =="
code=$(curl -sS -o /tmp/codo_ai_smoke_batch.json -w "%{http_code}" \
  "${auth_hdr[@]}" \
  "${BASE_URL}/v1/users/avatars?ids=${EMP1},${EMP2}")
if [[ "${code}" != "200" ]]; then
  echo "FAIL: batch GET expected 200, got ${code}" >&2
  cat /tmp/codo_ai_smoke_batch.json >&2
  exit 1
fi
if ! grep -q '"data"' /tmp/codo_ai_smoke_batch.json; then
  echo "FAIL: missing data array" >&2
  cat /tmp/codo_ai_smoke_batch.json >&2
  exit 1
fi
echo "OK batch GET"
cat /tmp/codo_ai_smoke_batch.json
echo

echo "== POST batch =="
code=$(curl -sS -o /tmp/codo_ai_smoke_post.json -w "%{http_code}" \
  "${auth_hdr[@]}" \
  -H "Content-Type: application/json" \
  -d "{\"employee_ids\":[\"${EMP1}\",\"${EMP2}\"]}" \
  "${BASE_URL}/v1/users/avatars")
if [[ "${code}" != "200" ]]; then
  echo "FAIL: batch POST expected 200, got ${code}" >&2
  cat /tmp/codo_ai_smoke_post.json >&2
  exit 1
fi
echo "OK batch POST"
cat /tmp/codo_ai_smoke_post.json
echo

echo "All CODO AI avatar smoke checks passed against ${BASE_URL}"
