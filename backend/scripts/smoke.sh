#!/usr/bin/env bash
#
# End-to-end smoke test against a running server.
#
# The unit and feature suites prove each module in isolation; this proves the
# wiring — that providers are registered, routes resolve, middleware lets a real
# token through, and the arithmetic still holds over HTTP.
#
#   ./scripts/smoke.sh [base-url]
#
set -euo pipefail

BASE="${1:-http://127.0.0.1:8000}/api/v1"
EMAIL="smoke-$(date +%s)@finora.test"
CURL=(curl -sS --noproxy '*' -H 'Accept: application/json' -H 'Content-Type: application/json')

pass=0
fail=0

check() {
  local label="$1" actual="$2" expected="$3"
  if [ "$actual" = "$expected" ]; then
    printf '  \033[32mok\033[0m   %-46s %s\n' "$label" "$actual"
    pass=$((pass + 1))
  else
    printf '  \033[31mFAIL\033[0m %-46s got %s, want %s\n' "$label" "$actual" "$expected"
    fail=$((fail + 1))
  fi
}

json() { php -r 'echo json_decode(stream_get_contents(STDIN), true)'"$1"' ?? "";'; }

echo "→ registering"
REG=$("${CURL[@]}" -X POST "$BASE/auth/register" \
  -d "{\"name\":\"Smoke\",\"email\":\"$EMAIL\",\"password\":\"password123\",\"locale\":\"fa\",\"base_currency\":\"TRY\"}")
TOKEN=$(printf '%s' "$REG" | json '["data"]["token"]')
WS=$(printf '%s' "$REG" | json '["data"]["workspace"]["id"]')
[ -n "$TOKEN" ] || { echo "registration failed: $REG"; exit 1; }

AUTH=(-H "Authorization: Bearer $TOKEN" -H "X-Workspace-Id: $WS")

echo "→ every module answers"
for ep in accounts categories transactions budgets reports/cash-flow reports/net-worth \
          checks loans investments assets buildings contacts invoices trips documents; do
  code=$("${CURL[@]}" "${AUTH[@]}" -o /dev/null -w '%{http_code}' "$BASE/$ep" || true)
  check "GET /$ep" "$code" "200"
done

echo "→ the ledger still balances over HTTP"
ACC=$("${CURL[@]}" "${AUTH[@]}" "$BASE/accounts" | json '["data"][0]["id"]')
"${CURL[@]}" "${AUTH[@]}" -X POST "$BASE/transactions" -H 'Idempotency-Key: smoke-1' \
  -d "{\"type\":\"expense\",\"account_id\":\"$ACC\",\"amount\":35000,\"currency\":\"TRY\"}" >/dev/null
"${CURL[@]}" "${AUTH[@]}" -X POST "$BASE/transactions" -H 'Idempotency-Key: smoke-1' \
  -d "{\"type\":\"expense\",\"account_id\":\"$ACC\",\"amount\":35000,\"currency\":\"TRY\"}" >/dev/null
COUNT=$("${CURL[@]}" "${AUTH[@]}" "$BASE/transactions" | json '["meta"]["total"]')
check "replayed Idempotency-Key posts once" "$COUNT" "1"

BAL=$("${CURL[@]}" "${AUTH[@]}" "$BASE/accounts/$ACC" | json '["data"]["balance"]["decimal"]')
check "balance moved by the posted amount" "$BAL" "-350.00"

echo "→ a mismatched currency is refused"
CODE=$("${CURL[@]}" "${AUTH[@]}" -X POST "$BASE/transactions" \
  -d "{\"type\":\"expense\",\"account_id\":\"$ACC\",\"amount\":1000,\"currency\":\"USD\"}" | json '["error"]["code"]')
check "currency mismatch rejected" "$CODE" "currency_mismatch"

echo "→ another workspace is invisible"
OTHER=$("${CURL[@]}" -X POST "$BASE/auth/register" \
  -d "{\"name\":\"Other\",\"email\":\"other-$EMAIL\",\"password\":\"password123\"}")
OTHER_TOKEN=$(printf '%s' "$OTHER" | json '["data"]["token"]')
CODE=$("${CURL[@]}" -H "Authorization: Bearer $OTHER_TOKEN" -H "X-Workspace-Id: $WS" \
  -o /dev/null -w '%{http_code}' "$BASE/transactions")
check "cross-workspace read forbidden" "$CODE" "403"

echo
if [ "$fail" -eq 0 ]; then
  printf '\033[32m%d checks passed\033[0m\n' "$pass"
else
  printf '\033[31m%d passed, %d failed\033[0m\n' "$pass" "$fail"
  exit 1
fi
