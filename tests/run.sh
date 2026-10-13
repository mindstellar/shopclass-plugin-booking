#!/usr/bin/env bash
# Unit tests: no database, no network. They load a few core classes from SHOPCLASS_CORE,
# or ../osclass. tests/integration/ needs core's scratch MySQL and runs on its own.
set -u
cd "$(dirname "$0")/.."
fail=0
for t in tests/*.php; do
    echo "── $t"
    out="$(php "$t" 2>&1)"
    code=$?
    printf '%s\n' "$out" | grep -E '^(FAIL|  FAILED|RESULT)|Fatal error|Parse error' || printf '%s\n' "$out" | tail -5
    [ "$code" -eq 0 ] || fail=1
done
exit "$fail"
