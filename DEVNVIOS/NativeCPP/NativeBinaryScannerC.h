#ifndef NativeBinaryScannerC_h
#define NativeBinaryScannerC_h

#include <stdint.h>
#include <stddef.h>

#ifdef __cplusplus
extern "C" {
#endif

typedef struct {
    uint64_t functionRVA;
    uint64_t functionVA;
    uint32_t instructionCount;
    uint32_t incomingCalls;
    uint32_t outgoingCalls;
    uint32_t primaryFieldOffset;
    uint8_t  hasValidPrologue;
    uint8_t  isGetterIdiom;
    uint8_t  isSetterIdiom;
    uint8_t  prologueBytes[12];
} DNVFunctionMetric;

typedef struct {
    uint64_t accessorFunctionVA;
    uint64_t accessorFunctionRVA;
    uint32_t descriptorFileOffset;
    uint8_t  kind; // 1 = Class, 2 = Struct, 3 = Enum
    char     typeName[160];
} DNVSwiftNominalType;

typedef struct {
    uint32_t totalInstructionsScanned;
    uint32_t discoveredProloguesCount;
    uint32_t getterIdiomsCount;
    uint32_t setterIdiomsCount;
    uint32_t directBLCallsCount;
} DNVScanSummary;

/// Performs a multi-pass read-only static scan of an unencrypted ARM64 `__TEXT,__text` buffer.
/// Populates up to `maxOutMetrics` entries in `outMetrics` and writes aggregate statistics to `outSummary`.
size_t dnv_analyze_arm64_text(
    const uint8_t* textBytes,
    size_t textSize,
    uint64_t textVirtualAddress,
    uint64_t imageBaseAddress,
    const uint64_t* knownFuncRVAs,
    size_t knownFuncCount,
    DNVFunctionMetric* outMetrics,
    size_t maxOutMetrics,
    DNVScanSummary* outSummary
);

/// Parses Objective-C / Swift `__TEXT,__swift5_types` relative 32-bit nominal type descriptors
/// inside a Mach-O slice buffer to extract real Swift Class/Struct/Enum names and their ARM64 metadata accessor RVAs.
size_t dnv_scan_swift5_types(
    const uint8_t* sliceBytes,
    size_t sliceSize,
    uint64_t swiftTypesFileOffset,
    uint64_t swiftTypesSize,
    uint64_t swiftTypesVA,
    uint64_t textStartVA,
    uint64_t textEndVA,
    uint64_t imageBaseAddress,
    DNVSwiftNominalType* outTypes,
    size_t maxOutTypes
);

#ifdef __cplusplus
}
#endif

#endif /* NativeBinaryScannerC_h */
