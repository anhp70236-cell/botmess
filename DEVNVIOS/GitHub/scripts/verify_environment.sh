#!/usr/bin/env bash
set -euo pipefail

echo "=== [DEVNVIOS CI] Environment Verification ==="
sw_vers
uname -a
df -h /

echo "=== Checking Xcode & Command Line Tools ==="
xcode-select -p
xcodebuild -version
swift --version
xcrun --sdk iphoneos --show-sdk-path
xcrun --sdk iphonesimulator --show-sdk-path

echo "=== Ensuring XcodeGen is Installed ==="
if ! command -v xcodegen >/dev/null 2>&1; then
  echo "Installing XcodeGen via Homebrew..."
  brew install xcodegen
fi
xcodegen --version
echo "=== Environment Check Passed ==="
