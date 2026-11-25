import Foundation

/// Resolves raw 64-bit pointer slots inside Mach-O `__DATA` / `__DATA_CONST`, transparently handling both
/// classic raw virtual addresses and iOS 15+ `LC_DYLD_CHAINED_FIXUPS` (`DYLD_CHAINED_PTR_64` / `DYLD_CHAINED_PTR_64_OFFSET`)
/// rebase encodings on unencrypted ARM64 binaries.
public enum MachOPointerFixupDecoder {
    @inline(__always)
    public static func resolveVirtualAddress(_ raw64: UInt64, machO: ParsedMachOImage) -> UInt64? {
        if raw64 == 0 { return nil }

        // 1. Direct Virtual Address (classic relocation or unchained image)
        if machO.isVirtualAddressValid(raw64) {
            return raw64
        }

        // 2. DYLD_CHAINED_PTR_64 / DYLD_CHAINED_PTR_64_OFFSET rebase (bit 63 == 0 means rebase, not bind import)
        if (raw64 & (1 << 63)) == 0 {
            // Low 36 bits hold either RVA offset from image base (PTR_64_OFFSET) or low 36 bits of VA (PTR_64)
            let low36 = raw64 & 0x000F_FFFF_FFFF
            if low36 > 0 {
                let candidateFromBase = machO.defaultBaseAddress &+ low36
                if machO.isVirtualAddressValid(candidateFromBase) {
                    return candidateFromBase
                }
                if machO.isVirtualAddressValid(low36) {
                    return low36
                }
            }
        }

        return nil
    }
}

/// Scans unencrypted Unity IL2CPP Mach-O binaries (`UnityFramework` or main executable) for `Il2CppCodeGenModule`
/// structures in `__DATA_CONST` / `__DATA` and maps `methodPointers` arrays directly to `global-metadata.dat`
/// `Il2CppMethodDefinition` indices — recovering exact ARM64 RVAs even when `LC_SYMTAB` is 100% stripped.
public enum IL2CPPBinaryRegistrationScanner {
    public static func resolveMethodVirtualAddresses(
        machO: ParsedMachOImage,
        sliceCursor: BinaryCursor,
        images: [IL2CPPImageRecord],
        logger: AnalysisLogger? = nil
    ) -> [Int: UInt64] {
        guard !machO.encryptionInfo.isEncrypted, !images.isEmpty else {
            return [:]
        }

        // 1. Index all ".dll" C-strings inside __TEXT,__cstring by their Virtual Address
        guard let cstrSec = machO.section(forSectionName: "__cstring", inSegment: "__TEXT"),
              cstrSec.size > 0,
              !machO.isSliceRangeEncrypted(offset: UInt64(cstrSec.fileOffset), length: min(cstrSec.size, 4096)),
              let cstrSlice = sliceCursor.slice(offset: Int(cstrSec.fileOffset), length: Int(min(cstrSec.size, 16 * 1024 * 1024))) else {
            return [:]
        }

        let imageByName: [String: IL2CPPImageRecord] = {
            var dict: [String: IL2CPPImageRecord] = [:]
            for img in images where !img.orderedMethodIndices.isEmpty {
                dict[img.assemblyName] = img
                let noExt = (img.assemblyName as NSString).deletingPathExtension
                if dict[noExt] == nil {
                    dict[noExt] = img
                }
            }
            return dict
        }()
        guard !imageByName.isEmpty else { return [:] }

        var dllByStringVA: [UInt64: IL2CPPImageRecord] = [:]
        let rawBytes = Array(cstrSlice)
        var start = 0
        for idx in 0..<rawBytes.count {
            if rawBytes[idx] == 0 {
                let len = idx - start
                if len >= 4 && len <= 128 {
                    let s = String(decoding: rawBytes[start..<idx], as: UTF8.self)
                    if let matchedImg = imageByName[s] {
                        let va = cstrSec.address &+ UInt64(start)
                        dllByStringVA[va] = matchedImg
                    }
                }
                start = idx + 1
            }
        }
        guard !dllByStringVA.isEmpty else { return [:] }

        // 2. Scan __DATA_CONST and __DATA sections for Il2CppCodeGenModule structs:
        //    +0x00: const char* moduleName (points to DLL string VA)
        //    +0x08: int64_t methodPointerCount
        //    +0x10: const Il2CppMethodPointer* methodPointers
        let candidateSections = machO.segments
            .filter { $0.name.hasPrefix("__DATA") || $0.name == "__TEXT" }
            .flatMap(\.sections)
            .filter { !$0.isExecutableInstructions && $0.size >= 24 && $0.fileOffset > 0 }

        var methodVAByMethodIndex: [Int: UInt64] = [:]
        var matchedModulesCount = 0

        for sec in candidateSections {
            if Task.isCancelled { break }
            let secOff = Int(sec.fileOffset)
            let scanLen = Int(min(sec.size, 32 * 1024 * 1024))
            guard !machO.isSliceRangeEncrypted(offset: UInt64(secOff), length: UInt64(min(scanLen, 4096))),
                  sliceCursor.hasBytes(at: secOff, count: scanLen) else {
                continue
            }

            var pos = 0
            while pos + 24 <= scanLen {
                guard let rawNamePtr = sliceCursor.readUInt64LE(at: secOff + pos),
                      let nameVA = MachOPointerFixupDecoder.resolveVirtualAddress(rawNamePtr, machO: machO),
                      let imgRecord = dllByStringVA[nameVA],
                      let rawCount = sliceCursor.readUInt64LE(at: secOff + pos + 8),
                      let rawMethodArrayPtr = sliceCursor.readUInt64LE(at: secOff + pos + 16) else {
                    pos += 8
                    continue
                }

                let expectedCount = imgRecord.orderedMethodIndices.count
                // Allow exact match or non-generic subset match when methodPointers array points into valid segment
                if rawCount > 0 && rawCount <= 500_000,
                   abs(Int(rawCount) - expectedCount) <= max(4, expectedCount / 20),
                   let arrayVA = MachOPointerFixupDecoder.resolveVirtualAddress(rawMethodArrayPtr, machO: machO),
                   let arrayFileOff = machO.virtualAddressToSliceFileOffset(arrayVA),
                   sliceCursor.hasBytes(at: Int(arrayFileOff), count: Int(rawCount) * 8) {

                    let pairCount = min(Int(rawCount), expectedCount)
                    var validExecPtrs = 0
                    let sampleCheck = min(pairCount, 16)
                    for k in 0..<sampleCheck {
                        if let pRaw = sliceCursor.readUInt64LE(at: Int(arrayFileOff) + k * 8) {
                            if pRaw == 0 {
                                validExecPtrs += 1 // Abstract / interface method slot is NULL (0)
                            } else if let fnVA = MachOPointerFixupDecoder.resolveVirtualAddress(pRaw, machO: machO),
                                      (fnVA & 0x3) == 0,
                                      machO.isVirtualAddressValid(fnVA, requireExecutable: true) {
                                validExecPtrs += 1
                            }
                        }
                    }

                    if sampleCheck > 0 && validExecPtrs * 10 >= sampleCheck * 8 {
                        matchedModulesCount += 1
                        for k in 0..<pairCount {
                            guard let pRaw = sliceCursor.readUInt64LE(at: Int(arrayFileOff) + k * 8),
                                  pRaw != 0,
                                  let fnVA = MachOPointerFixupDecoder.resolveVirtualAddress(pRaw, machO: machO),
                                  (fnVA & 0x3) == 0,
                                  machO.isVirtualAddressValid(fnVA, requireExecutable: true) else {
                                continue
                            }
                            let mIdx = imgRecord.orderedMethodIndices[k]
                            methodVAByMethodIndex[mIdx] = fnVA
                        }
                        pos += 24
                        continue
                    }
                }
                pos += 8
            }
        }

        if matchedModulesCount > 0 {
            logger?.success(
                stage: "IL2CPP-CodeGen",
                "Ánh xạ thành công \(matchedModulesCount) Il2CppCodeGenModule -> Giải mã chính xác \(methodVAByMethodIndex.count) địa chỉ RVA hàm ARM64!"
            )
        }
        return methodVAByMethodIndex
    }
}
