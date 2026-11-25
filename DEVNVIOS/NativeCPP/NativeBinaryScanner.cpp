#include "NativeBinaryScannerC.h"
#include <cstdint>
#include <vector>
#include <unordered_map>
#include <algorithm>
#include <cstring>

namespace {

inline uint32_t read_u32_le(const uint8_t* p) {
    return static_cast<uint32_t>(p[0])
        | (static_cast<uint32_t>(p[1]) << 8)
        | (static_cast<uint32_t>(p[2]) << 16)
        | (static_cast<uint32_t>(p[3]) << 24);
}

inline int32_t read_i32_le(const uint8_t* p) {
    return static_cast<int32_t>(read_u32_le(p));
}

inline bool is_arm64_prologue(uint32_t insn) {
    // PACIASP, PACIBSP, BTI
    if (insn == 0xD503233Fu || insn == 0xD503237Fu || (insn & 0xFFFFF0FFu) == 0xD503241Fu) {
        return true;
    }
    // STP Xn, Xm, [SP, #-imm]! or signed-offset STP onto SP
    if ((insn & 0x7FC003E0u) == 0x298003E0u || (insn & 0x7FC003E0u) == 0x290003E0u) {
        return true;
    }
    // SUB SP, SP, #imm
    if ((insn & 0xFF0003FFu) == 0xD10003FFu) {
        return true;
    }
    return false;
}

inline bool decode_x0_ldr_or_str(uint32_t insn, bool& isStore, uint32_t& byteOffsetOut) {
    const uint32_t rn = (insn >> 5) & 0x1Fu;
    if (rn != 0u) return false; // Must dereference X0 (`this`/`self`)

    const uint32_t imm12 = (insn >> 10) & 0xFFFu;
    const uint32_t opMask = insn & 0xFFC00000u;

    // 32-bit W or S float
    if (opMask == 0xB9400000u || opMask == 0xBD400000u) {
        isStore = false;
        byteOffsetOut = imm12 * 4u;
        return byteOffsetOut >= 0x08u && byteOffsetOut <= 0x1000u;
    }
    if (opMask == 0xB9000000u || opMask == 0xBD000000u) {
        isStore = true;
        byteOffsetOut = imm12 * 4u;
        return byteOffsetOut >= 0x08u && byteOffsetOut <= 0x1000u;
    }
    // 64-bit X or D double
    if (opMask == 0xF9400000u || opMask == 0xFD400000u) {
        isStore = false;
        byteOffsetOut = imm12 * 8u;
        return byteOffsetOut >= 0x08u && byteOffsetOut <= 0x1000u;
    }
    if (opMask == 0xF9000000u || opMask == 0xFD000000u) {
        isStore = true;
        byteOffsetOut = imm12 * 8u;
        return byteOffsetOut >= 0x08u && byteOffsetOut <= 0x1000u;
    }
    // 128-bit Q SIMD (Vector3 / Quaternion)
    if (opMask == 0x3DC00000u) {
        isStore = false;
        byteOffsetOut = imm12 * 16u;
        return byteOffsetOut >= 0x08u && byteOffsetOut <= 0x1000u;
    }
    if (opMask == 0x3D800000u) {
        isStore = true;
        byteOffsetOut = imm12 * 16u;
        return byteOffsetOut >= 0x08u && byteOffsetOut <= 0x1000u;
    }
    return false;
}

inline bool is_printable_ident_char(uint8_t c) {
    return (c >= 'A' && c <= 'Z')
        || (c >= 'a' && c <= 'z')
        || (c >= '0' && c <= '9')
        || c == '_' || c == '.';
}

} // namespace

extern "C" size_t dnv_analyze_arm64_text(
    const uint8_t* textBytes,
    size_t textSize,
    uint64_t textVirtualAddress,
    uint64_t imageBaseAddress,
    const uint64_t* knownFuncRVAs,
    size_t knownFuncCount,
    DNVFunctionMetric* outMetrics,
    size_t maxOutMetrics,
    DNVScanSummary* outSummary
) {
    if (outSummary != nullptr) {
        std::memset(outSummary, 0, sizeof(DNVScanSummary));
    }
    if (textBytes == nullptr || textSize < 4 || outMetrics == nullptr || maxOutMetrics == 0) {
        return 0;
    }

    const size_t totalInsns = std::min<size_t>(textSize / 4, 4000000);
    std::vector<uint64_t> funcVAs;
    funcVAs.reserve(knownFuncCount > 0 ? knownFuncCount : 4096);

    if (knownFuncRVAs != nullptr && knownFuncCount > 0) {
        for (size_t i = 0; i < knownFuncCount; ++i) {
            uint64_t va = imageBaseAddress + knownFuncRVAs[i];
            if (va >= textVirtualAddress && va < textVirtualAddress + textSize) {
                funcVAs.push_back(va);
            }
        }
    }

    // Pass 1: Discover additional function starts via prologue + RET boundary heuristics
    uint32_t discoveredPrologues = 0;
    bool prevWasTerminator = true;
    for (size_t i = 0; i < totalInsns; ++i) {
        const uint32_t insn = read_u32_le(textBytes + i * 4);
        const uint64_t pc = textVirtualAddress + static_cast<uint64_t>(i * 4);

        if (is_arm64_prologue(insn)) {
            ++discoveredPrologues;
            if (prevWasTerminator || funcVAs.empty()) {
                funcVAs.push_back(pc);
            }
        }
        // RET / RETA / RETB or unconditional B
        prevWasTerminator = (insn == 0xD65F03C0u || insn == 0xD65F0BFFu || insn == 0xD65F0FFFu || (insn & 0xFC000000u) == 0x14000000u);
    }

    std::sort(funcVAs.begin(), funcVAs.end());
    funcVAs.erase(std::unique(funcVAs.begin(), funcVAs.end()), funcVAs.end());

    // Pass 2: Build incoming BL call-graph counts across the whole __text section
    std::unordered_map<uint64_t, uint32_t> incomingCalls;
    incomingCalls.reserve(std::min<size_t>(funcVAs.size(), 65536));
    uint32_t totalBLCalls = 0;

    for (size_t i = 0; i < totalInsns; ++i) {
        const uint32_t insn = read_u32_le(textBytes + i * 4);
        if ((insn & 0xFC000000u) == 0x94000000u) {
            int64_t imm26 = static_cast<int64_t>(insn & 0x03FFFFFFu);
            int64_t signedWords = (imm26 << 38) >> 38;
            uint64_t pc = textVirtualAddress + static_cast<uint64_t>(i * 4);
            uint64_t targetVA = static_cast<uint64_t>(static_cast<int64_t>(pc) + (signedWords << 2));
            if (std::binary_search(funcVAs.begin(), funcVAs.end(), targetVA)) {
                incomingCalls[targetVA] += 1u;
                ++totalBLCalls;
            }
        }
    }

    // Pass 3: Profile each function body for Getter/Setter idioms, field offsets, outgoing calls, and prologue bytes
    uint32_t getterCount = 0;
    uint32_t setterCount = 0;
    size_t written = 0;

    for (size_t f = 0; f < funcVAs.size() && written < maxOutMetrics; ++f) {
        const uint64_t startVA = funcVAs[f];
        const uint64_t endVA = (f + 1 < funcVAs.size())
            ? std::min<uint64_t>(funcVAs[f + 1], startVA + 256u)
            : std::min<uint64_t>(textVirtualAddress + textSize, startVA + 256u);

        if (endVA <= startVA) continue;
        const size_t startByteOff = static_cast<size_t>(startVA - textVirtualAddress);
        const size_t fnInsns = static_cast<size_t>((endVA - startVA) / 4);
        if (fnInsns == 0 || startByteOff + fnInsns * 4 > textSize) continue;

        DNVFunctionMetric metric;
        std::memset(&metric, 0, sizeof(DNVFunctionMetric));
        metric.functionVA = startVA;
        metric.functionRVA = (startVA >= imageBaseAddress) ? (startVA - imageBaseAddress) : startVA;
        metric.incomingCalls = incomingCalls[startVA];

        const size_t copyBytes = std::min<size_t>(12, textSize - startByteOff);
        std::memcpy(metric.prologueBytes, textBytes + startByteOff, copyBytes);

        const uint32_t firstInsn = read_u32_le(textBytes + startByteOff);
        metric.hasValidPrologue = is_arm64_prologue(firstInsn) ? 1u : 0u;

        bool sawLoadX0 = false;
        bool sawStoreX0 = false;
        uint32_t detectedField = 0;
        uint32_t actualInsns = 0;

        for (size_t k = 0; k < fnInsns; ++k) {
            const uint32_t insn = read_u32_le(textBytes + startByteOff + k * 4);
            ++actualInsns;

            if ((insn & 0xFC000000u) == 0x94000000u) {
                metric.outgoingCalls += 1u;
            }

            bool isStore = false;
            uint32_t fieldOff = 0;
            if (decode_x0_ldr_or_str(insn, isStore, fieldOff)) {
                if (detectedField == 0) {
                    detectedField = fieldOff;
                }
                if (isStore) sawStoreX0 = true;
                else sawLoadX0 = true;
            }

            if (insn == 0xD65F03C0u || insn == 0xD65F0BFFu || insn == 0xD65F0FFFu) {
                break;
            }
        }

        metric.instructionCount = actualInsns;
        metric.primaryFieldOffset = detectedField;

        // Compact leaf accessor idiom: <= 8 instructions, 0 outgoing calls, dereferences [X0 + #field] then RET
        if (actualInsns <= 8 && metric.outgoingCalls == 0 && detectedField > 0) {
            if (sawLoadX0 && !sawStoreX0) {
                metric.isGetterIdiom = 1u;
                ++getterCount;
            } else if (sawStoreX0 && !sawLoadX0) {
                metric.isSetterIdiom = 1u;
                ++setterCount;
            }
        }

        // Keep functions that either have interesting accessor/field/call activity or valid prologues
        if (metric.primaryFieldOffset > 0 || metric.incomingCalls > 0 || metric.hasValidPrologue != 0) {
            outMetrics[written++] = metric;
        }
    }

    if (outSummary != nullptr) {
        outSummary->totalInstructionsScanned = static_cast<uint32_t>(totalInsns);
        outSummary->discoveredProloguesCount = discoveredPrologues;
        outSummary->getterIdiomsCount = getterCount;
        outSummary->setterIdiomsCount = setterCount;
        outSummary->directBLCallsCount = totalBLCalls;
    }

    return written;
}

extern "C" size_t dnv_scan_swift5_types(
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
) {
    if (sliceBytes == nullptr || outTypes == nullptr || maxOutTypes == 0 || swiftTypesSize < 4) {
        return 0;
    }
    if (swiftTypesFileOffset + swiftTypesSize > sliceSize) {
        return 0;
    }

    const size_t entryCount = std::min<size_t>(static_cast<size_t>(swiftTypesSize / 4), 16000);
    const int64_t vaMinusFileDelta = static_cast<int64_t>(swiftTypesVA) - static_cast<int64_t>(swiftTypesFileOffset);
    size_t written = 0;

    for (size_t i = 0; i < entryCount && written < maxOutTypes; ++i) {
        const size_t entryFileOff = static_cast<size_t>(swiftTypesFileOffset) + i * 4;
        const int32_t relDesc = read_i32_le(sliceBytes + entryFileOff);
        if (relDesc == 0) continue;

        const int64_t descFilePosSigned = static_cast<int64_t>(entryFileOff) + static_cast<int64_t>(relDesc);
        // Handle indirect relative pointer flag (lowest bit 1) by clearing bit 0 if direct inside __TEXT
        const int64_t cleanDescPos = descFilePosSigned & ~static_cast<int64_t>(1);
        if (cleanDescPos < 0 || static_cast<size_t>(cleanDescPos + 16) > sliceSize) {
            continue;
        }

        const size_t descOff = static_cast<size_t>(cleanDescPos);
        const uint32_t flags = read_u32_le(sliceBytes + descOff);
        const uint8_t contextKind = static_cast<uint8_t>(flags & 0x1Fu);
        // Swift ContextDescriptorKind: 16 = Class, 17 = Struct, 18 = Enum
        uint8_t mappedKind = 0;
        if (contextKind == 16u) mappedKind = 1u;
        else if (contextKind == 17u) mappedKind = 2u;
        else if (contextKind == 18u) mappedKind = 3u;
        if (mappedKind == 0) continue;

        // TargetTypeContextDescriptor: +8 is int32_t Name, +12 is int32_t AccessFunctionPtr
        const int32_t nameRel = read_i32_le(sliceBytes + descOff + 8);
        const int64_t nameFilePos = static_cast<int64_t>(descOff + 8) + static_cast<int64_t>(nameRel);
        if (nameFilePos <= 0 || static_cast<size_t>(nameFilePos + 2) >= sliceSize) {
            continue;
        }

        const uint8_t* nameStart = sliceBytes + static_cast<size_t>(nameFilePos);
        size_t nameLen = 0;
        bool validName = true;
        while (nameLen < 150 && (static_cast<size_t>(nameFilePos) + nameLen) < sliceSize) {
            const uint8_t ch = nameStart[nameLen];
            if (ch == 0) break;
            if (!is_printable_ident_char(ch)) {
                validName = false;
                break;
            }
            ++nameLen;
        }
        if (!validName || nameLen < 2 || nameLen >= 150) {
            continue;
        }

        const int32_t accessRel = read_i32_le(sliceBytes + descOff + 12);
        uint64_t accessorVA = 0;
        uint64_t accessorRVA = 0;
        if (accessRel != 0) {
            const int64_t accessFilePos = static_cast<int64_t>(descOff + 12) + static_cast<int64_t>(accessRel);
            const uint64_t candidateVA = static_cast<uint64_t>(accessFilePos + vaMinusFileDelta);
            if ((candidateVA & 0x3u) == 0 && candidateVA >= textStartVA && candidateVA < textEndVA) {
                accessorVA = candidateVA;
                accessorRVA = (candidateVA >= imageBaseAddress) ? (candidateVA - imageBaseAddress) : candidateVA;
            }
        }

        DNVSwiftNominalType rec;
        std::memset(&rec, 0, sizeof(DNVSwiftNominalType));
        rec.accessorFunctionVA = accessorVA;
        rec.accessorFunctionRVA = accessorRVA;
        rec.descriptorFileOffset = static_cast<uint32_t>(descOff);
        rec.kind = mappedKind;
        std::memcpy(rec.typeName, nameStart, nameLen);
        rec.typeName[nameLen] = '\0';

        outTypes[written++] = rec;
    }

    return written;
}
