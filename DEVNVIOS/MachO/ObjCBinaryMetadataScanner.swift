import Foundation

public struct ObjCResolvedMethodIMP: Sendable, Equatable {
    public let className: String
    public let selectorName: String
    public let impVirtualAddress: UInt64
    public let impRVA: UInt64
    public let impFileOffset: UInt64?
}

/// Parses Objective-C `__objc_classlist` -> `objc_class` -> `class_ro_t` -> `baseMethodList`
/// (supporting both 64-bit pointer method lists and iOS 14+ relative offset method lists)
/// to recover exact ARM64 `IMP` function RVAs even on stripped binaries.
public enum ObjCBinaryMetadataScanner {
    public static func scanResolvedMethodIMPs(
        machO: ParsedMachOImage,
        sliceCursor: BinaryCursor
    ) -> [ObjCResolvedMethodIMP] {
        guard !machO.encryptionInfo.isEncrypted else { return [] }

        let classListSections = machO.segments
            .filter { $0.name.hasPrefix("__DATA") }
            .flatMap(\.sections)
            .filter { $0.sectionName.hasPrefix("__objc_classlist") && $0.size >= 8 }

        guard !classListSections.isEmpty else { return [] }

        var results: [ObjCResolvedMethodIMP] = []
        results.reserveCapacity(256)

        for listSec in classListSections {
            let count = min(Int(listSec.size / 8), 8000)
            let listFileOff = Int(listSec.fileOffset)
            guard sliceCursor.hasBytes(at: listFileOff, count: count * 8) else { continue }

            for i in 0..<count {
                if i & 511 == 0 && Task.isCancelled { break }
                guard let rawClassPtr = sliceCursor.readUInt64LE(at: listFileOff + i * 8),
                      let classVA = MachOPointerFixupDecoder.resolveVirtualAddress(rawClassPtr, machO: machO),
                      let classFileOff = machO.virtualAddressToSliceFileOffset(classVA),
                      sliceCursor.hasBytes(at: Int(classFileOff), count: 40) else {
                    continue
                }

                // objc_class +0x20 is `bits` (class_ro_t* masked by FAST_DATA_MASK 0x00007ffffffffff8)
                guard let rawDataBits = sliceCursor.readUInt64LE(at: Int(classFileOff) + 32) else { continue }
                let roVA: UInt64? = {
                    if let resolved = MachOPointerFixupDecoder.resolveVirtualAddress(rawDataBits, machO: machO) {
                        return resolved & ~UInt64(0x7)
                    }
                    let masked = rawDataBits & 0x0000_7FFF_FFFF_FFF8
                    return MachOPointerFixupDecoder.resolveVirtualAddress(masked, machO: machO)
                }()

                guard let validROVA = roVA,
                      let roFileOff = machO.virtualAddressToSliceFileOffset(validROVA),
                      sliceCursor.hasBytes(at: Int(roFileOff), count: 40) else {
                    continue
                }

                // class_ro_t on 64-bit: +24 = namePtr, +32 = baseMethodListPtr
                guard let rawNamePtr = sliceCursor.readUInt64LE(at: Int(roFileOff) + 24),
                      let nameVA = MachOPointerFixupDecoder.resolveVirtualAddress(rawNamePtr, machO: machO),
                      let nameFileOff = machO.virtualAddressToSliceFileOffset(nameVA),
                      let rawClassName = sliceCursor.readCString(at: Int(nameFileOff), maxLength: 160),
                      !rawClassName.isEmpty else {
                    continue
                }

                let cleanClass = SymbolDemangler.demangle(rawClassName)
                guard SafetyPolicyFilter.isPermittedIdentifier(cleanClass) else { continue }

                guard let rawMethodsPtr = sliceCursor.readUInt64LE(at: Int(roFileOff) + 32),
                      rawMethodsPtr != 0,
                      let methodsVA = MachOPointerFixupDecoder.resolveVirtualAddress(rawMethodsPtr, machO: machO),
                      let methodsFileOff = machO.virtualAddressToSliceFileOffset(methodsVA),
                      sliceCursor.hasBytes(at: Int(methodsFileOff), count: 8),
                      let entsizeAndFlags = sliceCursor.readUInt32LE(at: Int(methodsFileOff)),
                      let methodCount = sliceCursor.readUInt32LE(at: Int(methodsFileOff) + 4),
                      methodCount > 0, methodCount <= 2000 else {
                    continue
                }

                let isRelativeList = (entsizeAndFlags & 0x8000_0000) != 0
                let entryBaseFileOff = Int(methodsFileOff) + 8
                let entryBaseVA = methodsVA &+ 8

                if isRelativeList {
                    // Relative method list: 12 bytes per entry (int32 nameOff, int32 typesOff, int32 impOff)
                    guard sliceCursor.hasBytes(at: entryBaseFileOff, count: Int(methodCount) * 12) else { continue }
                    for m in 0..<Int(methodCount) {
                        let eOff = entryBaseFileOff + m * 12
                        let eVA = entryBaseVA &+ UInt64(m * 12)
                        guard let nameRel = sliceCursor.readInt32LE(at: eOff),
                              let impRel = sliceCursor.readInt32LE(at: eOff + 8) else { continue }

                        let impVA = UInt64(bitPattern: Int64(bitPattern: eVA &+ 8) &+ Int64(impRel))
                        guard (impVA & 0x3) == 0,
                              machO.isVirtualAddressValid(impVA, requireExecutable: true) else { continue }

                        let selRefVA = UInt64(bitPattern: Int64(bitPattern: eVA) &+ Int64(nameRel))
                        let selName = resolveRelativeSelectorName(selRefVA: selRefVA, machO: machO, cursor: sliceCursor) ?? "method_\(m)"
                        guard SafetyPolicyFilter.isPermittedIdentifier(selName) else { continue }

                        results.append(
                            ObjCResolvedMethodIMP(
                                className: cleanClass,
                                selectorName: selName,
                                impVirtualAddress: impVA,
                                impRVA: machO.rva(forVirtualAddress: impVA),
                                impFileOffset: machO.virtualAddressToSliceFileOffset(impVA)
                            )
                        )
                        if results.count >= 4000 { return results }
                    }
                } else {
                    // Classic 64-bit method_t: 24 bytes (uint64 name, uint64 types, uint64 imp)
                    let stride = max(24, Int(entsizeAndFlags & 0xFFFC))
                    guard sliceCursor.hasBytes(at: entryBaseFileOff, count: Int(methodCount) * stride) else { continue }
                    for m in 0..<Int(methodCount) {
                        let eOff = entryBaseFileOff + m * stride
                        guard let rawSelPtr = sliceCursor.readUInt64LE(at: eOff),
                              let rawImpPtr = sliceCursor.readUInt64LE(at: eOff + 16),
                              let selVA = MachOPointerFixupDecoder.resolveVirtualAddress(rawSelPtr, machO: machO),
                              let impVA = MachOPointerFixupDecoder.resolveVirtualAddress(rawImpPtr, machO: machO),
                              (impVA & 0x3) == 0,
                              machO.isVirtualAddressValid(impVA, requireExecutable: true),
                              let selFileOff = machO.virtualAddressToSliceFileOffset(selVA),
                              let selName = sliceCursor.readCString(at: Int(selFileOff), maxLength: 160),
                              !selName.isEmpty,
                              SafetyPolicyFilter.isPermittedIdentifier(selName) else {
                            continue
                        }

                        results.append(
                            ObjCResolvedMethodIMP(
                                className: cleanClass,
                                selectorName: selName,
                                impVirtualAddress: impVA,
                                impRVA: machO.rva(forVirtualAddress: impVA),
                                impFileOffset: machO.virtualAddressToSliceFileOffset(impVA)
                            )
                        )
                        if results.count >= 4000 { return results }
                    }
                }
            }
        }

        return results
    }

    private static func resolveRelativeSelectorName(
        selRefVA: UInt64,
        machO: ParsedMachOImage,
        cursor: BinaryCursor
    ) -> String? {
        guard let fileOff = machO.virtualAddressToSliceFileOffset(selRefVA) else { return nil }
        // In iOS 14+, nameRel points either directly to selector C-string or to a __objc_selrefs 64-bit pointer
        if let ptr = cursor.readUInt64LE(at: Int(fileOff)),
           let targetVA = MachOPointerFixupDecoder.resolveVirtualAddress(ptr, machO: machO),
           let strOff = machO.virtualAddressToSliceFileOffset(targetVA),
           let s = cursor.readCString(at: Int(strOff), maxLength: 160),
           !s.isEmpty, s.unicodeScalars.allSatisfy({ $0.value >= 0x20 && $0.value <= 0x7E }) {
            return s
        }
        if let direct = cursor.readCString(at: Int(fileOff), maxLength: 160),
           !direct.isEmpty, direct.unicodeScalars.allSatisfy({ $0.value >= 0x20 && $0.value <= 0x7E }) {
            return direct
        }
        return nil
    }
}
