import Foundation

public struct ARM64StringXRef: Sendable, Equatable {
    public let instructionVA: UInt64
    public let enclosingFunctionVA: UInt64?
    public let enclosingFunctionRVA: UInt64?
    public let targetStringVA: UInt64
    public let referencedString: String
    public let prologueHex: String?
    public let nearbyFieldOffsetHint: UInt32?
}

/// Decodes ARM64 A64 instructions (`ADRP`, `ADD`, `LDR`, `BL`, `STP`/`PACIBSP` prologues, `RET`)
/// to resolve PC-relative string/data cross-references and validate executable function addresses
/// even when Mach-O symbol tables are completely stripped.
public enum ARM64InstructionAnalyzer {
    public static func isLikelyFunctionPrologue(_ instruction: UInt32) -> Bool {
        // PACIASP / PACIBSP / BTI
        if instruction == 0xD503233F || instruction == 0xD503237F || (instruction & 0xFFFFF0FF) == 0xD503241F {
            return true
        }
        // STP Xn, Xm, [SP, #-imm]! (64-bit pre-index / signed offset on SP=31)
        if (instruction & 0x7FC003E0) == 0x298003E0 || (instruction & 0x7FC003E0) == 0x290003E0 {
            return true
        }
        // SUB SP, SP, #imm
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

    /// Decodes unsigned immediate `LDR Wt/Xt/St/Dt, [Xn, #offset]` to infer struct/class field offsets.
    @inline(__always)
    public static func decodeFieldLoadOffset(_ instruction: UInt32) -> UInt32? {
        let imm12 = (instruction >> 10) & 0xFFF
        let rn = (instruction >> 5) & 0x1F
        // Prefer object pointer registers X0..X8 (exclude SP=31, FP=29)
        guard rn <= 8 else { return nil }

        // LDR Wt, [Xn, #imm12*4] or LDR St, [Xn, #imm12*4]
        if (instruction & 0xFFC0_0000) == 0xB940_0000 || (instruction & 0xFFC0_0000) == 0xBD40_0000 {
            let byteOff = imm12 * 4
            return (byteOff >= 0x10 && byteOff <= 0x800) ? byteOff : nil
        }
        // LDR Xt, [Xn, #imm12*8] or LDR Dt, [Xn, #imm12*8]
        if (instruction & 0xFFC0_0000) == 0xF940_0000 || (instruction & 0xFFC0_0000) == 0xFD40_0000 {
            let byteOff = imm12 * 8
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

    /// Scans `__TEXT,__text` for `ADRP + ADD` pairs referencing indexed strings and resolves their enclosing functions.
    public static func scanStringCrossReferences(
        sliceCursor: BinaryCursor,
        machO: ParsedMachOImage,
        indexedStringsByVA: [UInt64: String],
        maxInstructionsToScan: Int = 1_500_000
    ) -> [ARM64StringXRef] {
        guard machO.isARM64,
              !indexedStringsByVA.isEmpty,
              let textSection = machO.section(forSectionName: "__text", inSegment: "__TEXT"),
              textSection.size >= 4 else {
            return []
        }

        let secFileOff = UInt64(textSection.fileOffset)
        guard !machO.isSliceRangeEncrypted(offset: secFileOff, length: min(textSection.size, 4096)) else {
            return []
        }

        let sortedFuncVAs: [UInt64] = {
            let base = machO.defaultBaseAddress
            if !machO.functionStartRVAs.isEmpty {
                return machO.functionStartRVAs.map { base &+ $0 }.sorted()
            }
            return machO.symbols.filter(\.isFunctionSymbol).map(\.virtualAddress).sorted()
        }()

        let totalInsns = min(Int(textSection.size / 4), maxInstructionsToScan)
        let startByteOffset = Int(textSection.fileOffset)
        guard sliceCursor.hasBytes(at: startByteOffset, count: totalInsns * 4) else {
            return []
        }

        var adrpPageByReg = [UInt64](repeating: 0, count: 32)
        var adrpStepByReg = [Int](repeating: -100, count: 32)
        var results: [ARM64StringXRef] = []
        results.reserveCapacity(256)

        for step in 0..<totalInsns {
            if step & 16_383 == 0 && Task.isCancelled { break }
            let bytePos = startByteOffset + step * 4
            guard let insn = sliceCursor.readUInt32LE(at: bytePos) else { break }
            let pc = textSection.address &+ UInt64(step * 4)

            if let adrp = decodeADRP(insn, pc: pc), adrp.rd < 31 {
                adrpPageByReg[adrp.rd] = adrp.pageVA
                adrpStepByReg[adrp.rd] = step
                continue
            }

            if let add = decodeADDImmediate64(insn),
               add.rn < 31,
               step - adrpStepByReg[add.rn] <= 6 {
                let candidateVA = adrpPageByReg[add.rn] &+ add.imm
                if let matchedString = indexedStringsByVA[candidateVA] {
                    let funcVA = findEnclosingFunctionVA(for: pc, in: sortedFuncVAs)
                    let funcRVA = funcVA.map { machO.rva(forVirtualAddress: $0) }
                    let prologue = funcVA.flatMap {
                        readPrologueSignature(sliceCursor: sliceCursor, machO: machO, virtualAddress: $0)?.hex
                    }

                    // Look ahead up to 8 instructions for field offset load hints (`LDR [X0, #imm]`)
                    var fieldHint: UInt32?
                    for ahead in 1...8 where (step + ahead) < totalInsns {
                        if let nextInsn = sliceCursor.readUInt32LE(at: bytePos + ahead * 4),
                           let decodedOff = decodeFieldLoadOffset(nextInsn) {
                            fieldHint = decodedOff
                            break
                        }
                    }

                    results.append(
                        ARM64StringXRef(
                            instructionVA: pc,
                            enclosingFunctionVA: funcVA,
                            enclosingFunctionRVA: funcRVA,
                            targetStringVA: candidateVA,
                            referencedString: matchedString,
                            prologueHex: prologue,
                            nearbyFieldOffsetHint: fieldHint
                        )
                    )
                    if results.count >= 2500 {
                        break
                    }
                }
            }
        }

        return results
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
