#!/usr/bin/env bash
set -euo pipefail

mkdir -p build/Reports

echo "=== [DEVNVIOS CI] Selecting Available iPhone Simulator ==="
SIM_UDID=$(xcrun simctl list devices available -j | python3 -c '
import json, sys
data = json.load(sys.stdin)
devices = data.get("devices", {})
candidates = []
for runtime, dev_list in sorted(devices.items(), reverse=True):
    if "iOS" not in runtime:
        continue
    for d in dev_list:
        if d.get("isAvailable") and "iPhone" in d.get("name", ""):
            candidates.append((runtime, d["udid"], d["name"]))
if not candidates:
    sys.exit(1)
print(candidates[0][1])
')

echo "Selected iPhone Simulator UDID: ${SIM_UDID}"
xcrun simctl boot "${SIM_UDID}" >/dev/null 2>&1 || true

MAX_ATTEMPTS=2
ATTEMPT=1
TEST_EXIT=0

while [ $ATTEMPT -le $MAX_ATTEMPTS ]; do
  echo "=== Running XCTest Suite (Attempt ${ATTEMPT}/${MAX_ATTEMPTS}) ==="
  rm -rf build/Reports/TestResults.xcresult
  set +e
  xcodebuild test \
    -project DEVNVIOS.xcodeproj \
    -scheme DEVNVIOS \
    -destination "platform=iOS Simulator,id=${SIM_UDID}" \
    -derivedDataPath build/DerivedDataSim \
    -resultBundlePath build/Reports/TestResults.xcresult \
    CODE_SIGNING_ALLOWED=NO \
    COMPILER_INDEX_STORE_ENABLE=NO | tee build/Reports/xcodebuild-test.log
  TEST_EXIT=${PIPESTATUS[0]}
  set -e

  if [ $TEST_EXIT -eq 0 ]; then
    echo "=== XCTest Suite Passed ==="
    break
  fi

  echo "Attempt ${ATTEMPT} exited with code ${TEST_EXIT}."
  ATTEMPT=$((ATTEMPT + 1))
  sleep 3
done

if [ $TEST_EXIT -ne 0 ]; then
  echo "ERROR: XCTest execution failed after ${MAX_ATTEMPTS} attempts."
  exit $TEST_EXIT
fi
