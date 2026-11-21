#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p build
SDK=$(xcrun --sdk iphoneos --show-sdk-path)
COMMON="-arch arm64 -miphoneos-version-min=15.0 -isysroot $SDK -O2 -fvisibility=hidden"
xcrun --sdk iphoneos clang $COMMON -c src/fishhook.c -o build/fishhook.o
xcrun --sdk iphoneos clang++ $COMMON -std=c++17 -fobjc-arc -dynamiclib \
  -install_name @rpath/VoiceFX.dylib \
  -framework UIKit -framework Foundation -framework AudioToolbox -framework QuartzCore \
  src/VoiceFX.mm build/fishhook.o -o build/VoiceFX.dylib
codesign -f -s - build/VoiceFX.dylib
file build/VoiceFX.dylib
