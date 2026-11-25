import Foundation

public struct ARM64StringXRef: Sendable, Equatable {
    public let instructionVA: UInt64
    public let enclosingFunctionVA: UInt64?
    public let enclosingFunctionRVA: UInt64?
    public let targetStringVA: UInt64
    public let referencedString: String
    public let prologueHex: String?
    public let nearbyFieldOffsetHint: UInt32?
    public let structFieldOffsets: [UInt32]
}

public struct ARM64DataPointerXRef: Sendable, Equatable {
    public let instructionVA: UInt64
    public let enclosingFunctionVA: UInt64?
    public let enclosingFunctionRVA: UInt64?
    public let targetDataVA: UInt64
    public let targetDataRVA: UInt64
    public let sectionDescription: String
}

public struct ARM64DeepScanOutput: Sendable {
    public let stringXRefs: [ARM64StringXRef]
    public let dataPointerXRefs: [ARM64DataPointerXRef]
    public let incomingCallCountsByFuncVA: [UInt64: Int]
}

/// Decodes ARM64 A64 instructions (`ADRP`, `ADD`, `LDR`/`STR` scalar & SIMD, `BL`, `STP`/`PACIBSP` prologues, `RET`)
/// on unencrypted `__TEXT,__text` sections to resolve PC-relative string/data cross-references, intra-binary call graphs,
/// and object instance field offsets without executing or modifying the binary.
public enum ARM64InstructionAnalyzer {
    public static func isLikelyFunctionPrologue(_ instruction: UInt32) -> Bool {
        if instruction == 0xD503233F || instruction == 0xD503237F || (instruction & 0xFFFFF0FF) == 0xD503241F {
            return true
        }
        if (instruction & 0x7FC003E0) == 0x298003E0 || (instruction & 0x7FC003E0) == 0x290003E0 {
            return true
        }
        if (instruction & 0xFF0003FF) == 0xD10003FF {
            return true
        }
        return false
    }

    public static func isValidARM64Instruction(_ instruction: UInt32) -> Bool {
        instruction != 0x0000_0000 && instruction != 0xFFFF_FFFF && instruction != 0xD420_0000
    }

    /// Decodes `ADRP Xd, label` -> `(rd, targetPageVA)`.
    @inline(__always)
    public static func decodeADRP(_ instruction: UInt32, pc: UInt64) -> (rd: Int, pageVA: UInt64)? {
        guard (instruction & 0x9F00_0000) == 0x9000_0000 else { return nil }
        let rd = Int(instruction & 0x1F)
        let immlo = Int64((instruction >> 29) & 0x3)
        let immhi = Int64((instruction >> 5) & 0x7FFFF)
        let raw21 = (immhi << 2) | immlo
        let signExtendedPages = (raw21 << 43) >> 43
        let pcPage = Int64(bitPattern: pc & ~UInt64(0xFFF))
        let targetPage = UInt64(bitPattern: pcPage &+ (signExtendedPages << 12))
        return (rd, targetPage)
    }

    /// Decodes 64-bit `ADD Xd, Xn, #imm12{, LSL #12}` -> `(rd, rn, imm)`.
    @inline(__always)
    public static func decodeADDImmediate64(_ instruction: UInt32) -> (rd: Int, rn: Int, imm: UInt64)? {
        guard (instruction & 0xFF80_0000) == 0x9100_0000 else { return nil }
        let rd = Int(instruction & 0x1F)
        let rn = Int((instruction >> 5) & 0x1F)
        let sh = (instruction >> 22) & 0x1
        let imm12 = UInt64((instruction >> 10) & 0xFFF)
        let imm = sh == 1 ? (imm12 << 12) : imm12
        return (rd, rn, imm)
    }

    /// Decodes 64-bit unsigned immediate `LDR Xt, [Xn, #imm12*8]` used with `ADRP` to access global pointers / GOT / `__DATA`.
    @inline(__always)
    public static func decodeLDRPointer64(_ instruction: UInt32) -> (rt: Int, rn: Int, byteOffset: UInt64)? {
        guard (instruction & 0xFFC0_0000) == 0xF940_0000 else { return nil }
        let rt = Int(instruction & 0x1F)
        let rn = Int((instruction >> 5) & 0x1F)
        let imm12 = UInt64((instruction >> 10) & 0xFFF)
        return (rt, rn, imm12 * 8)
    }

    /// Decodes `BL #imm26` direct branch-with-link -> target function VA.
    @inline(__always)
    public static func decodeBLTarget(_ instruction: UInt32, pc: UInt64) -> UInt64? {
        guard (instruction & 0xFC00_0000) == 0x9400_0000 else { return nil }
        let imm26 = Int64(instruction & 0x03FF_FFFF)
        let signedWords = (imm26 << 38) >> 38
        let target = Int64(bitPattern: pc) &+ (signedWords << 2)
        return UInt64(bitPattern: target)
    }

    /// Decodes unsigned immediate `LDR`/`STR` (32-bit W, 64-bit X, 32-bit float S, 64-bit double D, 128-bit SIMD Q) on `X0..X8` object pointers.
    @inline(__always)
    public static func decodeFieldLoadOffset(_ instruction: UInt32) -> UInt32? {
        let imm12 = (instruction >> 10) & 0xFFF
        let rn = (instruction >> 5) & 0x1F
        guard rn <= 8 else { return nil }
        let opMask = instruction & 0xFFC0_0000

        // 32-bit scalar W or float S LDR/STR
        if opMask == 0xB940_0000 || opMask == 0xB900_0000 || opMask == 0xBD40_0000 || opMask == 0xBD00_0000 {
            let byteOff = imm12 * 4
            return (byteOff >= 0x10 && byteOff <= 0x800) ? byteOff : nil
        }
        // 64-bit scalar X or double D LDR/STR
        if opMask == 0xF940_0000 || opMask == 0xF900_0000 || opMask == 0xFD40_0000 || opMask == 0xFD00_0000 {
            let byteOff = imm12 * 8
            return (byteOff >= 0x10 && byteOff <= 0x800) ? byteOff : nil
        }
        // 128-bit SIMD Q (Vector3/Vector4/Quaternion) LDR/STR
        if opMask == 0x3DC0_0000 || opMask == 0x3D80_0000 {
            let byteOff = imm12 * 16
            return (byteOff >= 0x10 && byteOff <= 0x800) ? byteOff : nil
        }
        return nil
    }

    public static func readPrologueSignature(
        sliceCursor: BinaryCursor,
        machO: ParsedMachOImage,
        virtualAddress: UInt64
    ) -> (hex: String, hasStandardPrologue: Bool, validEncoding: Bool)? {
        guard let fileOff = machO.virtualAddressToSliceFileOffset(virtualAddress),
              !machO.isSliceRangeEncrypted(offset: fileOff, length: 16),
              let bytes = sliceCursor.slice(offset: Int(fileOff), length: 12),
              let firstInsn = sliceCursor.readUInt32LE(at: Int(fileOff)) else {
            return nil
        }
        let hexString = bytes.map { String(format: "%02X", $0) }.joined(separator: " ")
        return (
            hex: hexString,
            hasStandardPrologue: isLikelyFunctionPrologue(firstInsn),
            validEncoding: isValidARM64Instruction(firstInsn)
        )
    }

    /// Profiles up to `maxInstructions` of a resolved ARM64 function (until `RET`) to extract verified prologue hex
    /// and exact `X0` (`this` instance pointer) field load/store offsets (`+0x10..+0x800`).
    public static func profileFunctionBody(
        sliceCursor: BinaryCursor,
        machO: ParsedMachOImage,
        functionVA: UInt64,
        maxInstructions: Int = 48
    ) -> (prologueHex: String?, thisFieldOffsets: [UInt32], validCode: Bool) {
        guard machO.isARM64,
              (functionVA & 0x3) == 0,
              let startOff = machO.virtualAddressToSliceFileOffset(functionVA),
              !machO.isSliceRangeEncrypted(offset: startOff, length: 16),
              let firstInsn = sliceCursor.readUInt32LE(at: Int(startOff)),
              isValidARM64Instruction(firstInsn) else {
            return (nil, [], false)
        }

        let prologueBytes = sliceCursor.slice(offset: Int(startOff), length: min(12, sliceCursor.count - Int(startOff)))
        let prologueHex = prologueBytes?.map { String(format: "%02X", $0) }.joined(separator: " ")

        var fields: [UInt32] = []
        let limit = min(maxInstructions, (sliceCursor.count - Int(startOff)) / 4)
        for i in 0..<limit {
            guard let insn = sliceCursor.readUInt32LE(at: Int(startOff) + i * 4) else { break }
            if insn == 0xD65F_03C0 { break } // RET
            let rn = (insn >> 5) & 0x1F
            // Focus specifically on X0 (`this` / `self` instance pointer in ARM64 ABI) for high-precision field extraction
            if rn == 0, let off = decodeFieldLoadOffset(insn), !fields.contains(off) {
                fields.append(off)
                if fields.count >= 6 { break }
            }
        }
        return (prologueHex, fields, true)
    }

    public static func performDeepCodeScan(
        sliceCursor: BinaryCursor,
        machO: ParsedMachOImage,
        indexedStringsByVA: [UInt64: String],
        maxInstructionsToScan: Int = 1_800_000
    ) -> ARM64DeepScanOutput {
        guard machO.isARM64,
              let textSection = machO.section(forSectionName: "__text", inSegment: "__TEXT"),
              textSection.size >= 4 else {
            return ARM64DeepScanOutput(stringXRefs: [], dataPointerXRefs: [], incomingCallCountsByFuncVA: [:])
        }

        let secFileOff = UInt64(textSection.fileOffset)
        guard !machO.isSliceRangeEncrypted(offset: secFileOff, length: min(textSection.size, 4096)) else {
            return ARM64DeepScanOutput(stringXRefs: [], dataPointerXRefs: [], incomingCallCountsByFuncVA: [:])
        }

        let sortedFuncVAs: [UInt64] = {
            let base = machO.defaultBaseAddress
            if !machO.functionStartRVAs.isEmpty {
                return machO.functionStartRVAs.map { base &+ $0 }.sorted()
            }
            return machO.symbols.filter(\.isFunctionSymbol).map(\.virtualAddress).sorted()
        }()
        let knownFuncSet = Set(sortedFuncVAs)

        let dataSections: [ParsedMachOSection] = machO.segments
            .filter { $0.name.hasPrefix("__DATA") }
            .flatMap(\.sections)

        func findDataSection(containing va: UInt64) -> ParsedMachOSection? {
            for sec in dataSections where va >= sec.address && va < sec.address + sec.size {
                return sec
            }
            return nil
        }

        let totalInsns = min(Int(textSection.size / 4), maxInstructionsToScan)
        let startByteOffset = Int(textSection.fileOffset)
        guard sliceCursor.hasBytes(at: startByteOffset, count: totalInsns * 4) else {
            return ARM64DeepScanOutput(stringXRefs: [], dataPointerXRefs: [], incomingCallCountsByFuncVA: [:])
        }

        var adrpPageByReg = [UInt64](repeating: 0, count: 32)
        var adrpStepByReg = [Int](repeating: -100, count: 32)

        var stringResults: [ARM64StringXRef] = []
        stringResults.reserveCapacity(256)
        var dataResults: [ARM64DataPointerXRef] = []
        dataResults.reserveCapacity(128)
        var callCounts: [UInt64: Int] = [:]

        for step in 0..<totalInsns {
            if step & 16_383 == 0 && Task.isCancelled { break }
            let bytePos = startByteOffset + step * 4
            guard let insn = sliceCursor.readUInt32LE(at: bytePos) else { break }
            let pc = textSection.address &+ UInt64(step * 4)

            if let blTarget = decodeBLTarget(insn, pc: pc), knownFuncSet.contains(blTarget) {
                callCounts[blTarget, default: 0] += 1
                continue
            }

            if let adrp = decodeADRP(insn, pc: pc), adrp.rd < 31 {
                adrpPageByReg[adrp.rd] = adrp.pageVA
                adrpStepByReg[adrp.rd] = step
                continue
            }

            if let add = decodeADDImmediate64(insn),
               add.rn < 31,
               step - adrpStepByReg[add.rn] <= 6,
               !indexedStringsByVA.isEmpty {
                let candidateVA = adrpPageByReg[add.rn] &+ add.imm
                if let matchedString = indexedStringsByVA[candidateVA] {
                    let funcVA = findEnclosingFunctionVA(for: pc, in: sortedFuncVAs)
                    let funcRVA = funcVA.map { machO.rva(forVirtualAddress: $0) }
                    let prologue = funcVA.flatMap {
                        readPrologueSignature(sliceCursor: sliceCursor, machO: machO, virtualAddress: $0)?.hex
                    }

                    var fieldOffsets: [UInt32] = []
                    for ahead in 1...12 where (step + ahead) < totalInsns {
                        if let nextInsn = sliceCursor.readUInt32LE(at: bytePos + ahead * 4) {
                            if nextInsn == 0xD65F_03C0 { break } // Stop at RET
                            if let decodedOff = decodeFieldLoadOffset(nextInsn), !fieldOffsets.contains(decodedOff) {
                                fieldOffsets.append(decodedOff)
                            }
                        }
                    }

                    if stringResults.count < 3000 {
                        stringResults.append(
                            ARM64StringXRef(
                                instructionVA: pc,
                                enclosingFunctionVA: funcVA,
                                enclosingFunctionRVA: funcRVA,
                                targetStringVA: candidateVA,
                                referencedString: matchedString,
                                prologueHex: prologue,
                                nearbyFieldOffsetHint: fieldOffsets.first,
                                structFieldOffsets: fieldOffsets
                            )
                        )
                    }
                }
                continue
            }

            if dataResults.count < 500,
               !dataSections.isEmpty,
               let ldr = decodeLDRPointer64(insn),
               ldr.rn < 31,
               step - adrpStepByReg[ldr.rn] <= 4 {
                let targetVA = adrpPageByReg[ldr.rn] &+ ldr.byteOffset
                if let dSec = findDataSection(containing: targetVA) {
                    let funcVA = findEnclosingFunctionVA(for: pc, in: sortedFuncVAs)
                    dataResults.append(
                        ARM64DataPointerXRef(
                            instructionVA: pc,
                            enclosingFunctionVA: funcVA,
                            enclosingFunctionRVA: funcVA.map { machO.rva(forVirtualAddress: $0) },
                            targetDataVA: targetVA,
                            targetDataRVA: machO.rva(forVirtualAddress: targetVA),
                            sectionDescription: "\(dSec.segmentName),\(dSec.sectionName)"
                        )
                    )
                }
            }
        }

        return ARM64DeepScanOutput(
            stringXRefs: stringResults,
            dataPointerXRefs: dataResults,
            incomingCallCountsByFuncVA: callCounts
        )
    }

    public static func scanStringCrossReferences(
        sliceCursor: BinaryCursor,
        machO: ParsedMachOImage,
        indexedStringsByVA: [UInt64: String],
        maxInstructionsToScan: Int = 1_800_000
    ) -> [ARM64StringXRef] {
        performDeepCodeScan(
            sliceCursor: sliceCursor,
            machO: machO,
            indexedStringsByVA: indexedStringsByVA,
            maxInstructionsToScan: maxInstructionsToScan
        ).stringXRefs
    }

    private static func findEnclosingFunctionVA(for pc: UInt64, in sortedStarts: [UInt64]) -> UInt64? {
        guard !sortedStarts.isEmpty else { return nil }
        var low = 0
        var high = sortedStarts.count - 1
        var best: UInt64?
        while low <= high {
            let mid = (low + high) / 2
            let val = sortedStarts[mid]
            if val <= pc {
                best = val
                low = mid + 1
            } else {
                high = mid - 1
            }
        }
        if let best, pc - best <= 0x10_000 {
            return best
        }
        return nil
    }
}
