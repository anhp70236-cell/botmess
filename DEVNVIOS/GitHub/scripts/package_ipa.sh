#!/usr/bin/env bash
set -euo pipefail

mkdir -p build/Package/Payload build/Artifacts build/Reports

echo "=== [DEVNVIOS CI] Building Release ARM64 iOS Application ==="
xcodebuild build \
  -project DEVNVIOS.xcodeproj \
  -scheme DEVNVIOS \
  -configuration Release \
  -destination "generic/platform=iOS" \
  -derivedDataPath build/DerivedDataIOS \
  CODE_SIGN_IDENTITY="" \
  CODE_SIGNING_REQUIRED=NO \
  CODE_SIGNING_ALLOWED=NO \
  AD_HOC_CODE_SIGNING_ALLOWED=YES \
  COMPILER_INDEX_STORE_ENABLE=NO | tee build/Reports/xcodebuild-release.log

APP_PATH="build/DerivedDataIOS/Build/Products/Release-iphoneos/DEVNVIOS.app"
if [ ! -d "${APP_PATH}" ]; then
  echo "ERROR: Built DEVNVIOS.app not found at ${APP_PATH}"
  exit 1
fi

echo "=== Applying Ad-Hoc Signature for Sideload / Re-sign Compatibility ==="
rm -rf build/Package/Payload/DEVNVIOS.app
cp -R "${APP_PATH}" build/Package/Payload/DEVNVIOS.app
codesign --force --deep --sign - build/Package/Payload/DEVNVIOS.app

echo "=== Verifying Compiled ARM64 Mach-O Executable ==="
EXEC_PATH="build/Package/Payload/DEVNVIOS.app/DEVNVIOS"
file "${EXEC_PATH}"
otool -hv "${EXEC_PATH}"

echo "=== Packaging DEVNVIOS-IPA-Analyzer.ipa ==="
IPA_OUT="$(pwd)/build/Artifacts/DEVNVIOS-IPA-Analyzer.ipa"
rm -f "${IPA_OUT}"
(
  cd build/Package
  ditto -c -k --sequesterRsrc --keepParent Payload "${IPA_OUT}"
)

echo "=== Verifying Packaged IPA Contents ==="
if [ ! -s "${IPA_OUT}" ]; then
  echo "ERROR: IPA file is missing or empty!"
  exit 1
fi
unzip -l "${IPA_OUT}" | tee build/Reports/ipa-contents.txt
grep -q "Payload/DEVNVIOS.app/DEVNVIOS" build/Reports/ipa-contents.txt
grep -q "Payload/DEVNVIOS.app/Info.plist" build/Reports/ipa-contents.txt

shasum -a 256 "${IPA_OUT}" | tee build/Artifacts/DEVNVIOS-IPA-Analyzer.ipa.sha256
ls -lh "${IPA_OUT}"
echo "=== IPA Packaging & Verification Succeeded ==="
