import Foundation

public enum Il2CppRegistrationFinder {
    public static func find(
        in macho: MachOParser,
        imageNames: [String],
        typeFieldCounts: [UInt16],
        typeDefsCount: Int,
        totalMethodsCount: Int,
        metadataVersion: Int32,
        ctorPairsByImage: [String: [(typeDefIndex: Int, methodId: Int)]],
        log: (String) -> Void
    ) -> Il2CppRegistrationResult {
        var codeGenModules: [String: CodeGenModuleInfo] = [:]
        var legacyMethodRVAs: [UInt64] = []
        var fieldOffsetsByType: [[Int32]] = []
        var il2cppTypes: [Il2CppTypeMetaEntry] = []
        var codeRegRVA: UInt64 = 0
        var metaRegRVA: UInt64 = 0
        var strategyUsed = "None"

        let wantedSet = Set(imageNames)
        let sliceSize = macho.sliceSize
        let baseVM = macho.baseVM

        let dataSections = macho.sections.filter { sec in
            let isDataSeg = sec.segName == "__DATA_CONST" || sec.segName == "__AUTH_CONST" || sec.segName == "__AUTH" || sec.segName == "__DATA" || sec.segName == "__DATA_DIRTY"
            let isConstText = sec.segName == "__TEXT" && sec.sectName == "__const"
            let notBss = sec.sectName != "__bss" && sec.sectName != "__common"
            return (isDataSeg && notBss || isConstText)
                && sec.size >= 24
                && Int(sec.offset + sec.size) <= sliceSize
                && !macho.isInEncryptedRange(sliceRelOffset: Int(sec.offset))
        }

        func readModule(atRel modOff: Int, allowUnknownImage: Bool) -> CodeGenModuleInfo? {
            guard modOff >= 0 && modOff + 24 <= sliceSize && !macho.isInEncryptedRange(sliceRelOffset: modOff) else {
                return nil
            }
            let namePtrVM = macho.decodePointer(atSliceRelOffset: modOff)
            guard namePtrVM != 0, let nameOff = macho.vmToSliceRelOffset(namePtrVM), !macho.isInEncryptedRange(sliceRelOffset: nameOff) else {
                return nil
            }
            let dllName = macho.readSliceCString(nameOff, maxLength: 120)
            guard dllName.lowercased().hasSuffix(".dll") else { return nil }
            if !allowUnknownImage && !wantedSet.isEmpty && !wantedSet.contains(dllName) {
                return nil
            }

            let mCnt = Int(macho.readSliceUInt32(modOff + 8))
            let mHi = macho.readSliceUInt32(modOff + 12)
            guard mHi == 0 && mCnt >= 0 && mCnt <= 600_000 else { return nil }

            var rvas: [UInt64] = []
            if mCnt > 0 {
                let ptrsVM = macho.decodePointer(atSliceRelOffset: modOff + 16)
                guard let ptrsOff = macho.vmToSliceRelOffset(ptrsVM),
                      ptrsOff + mCnt * 8 <= sliceSize,
                      !macho.isInEncryptedRange(sliceRelOffset: ptrsOff) else {
                    return nil
                }
                var validCount = 0
                let step = max(1, mCnt / 64)
                for k in stride(from: 0, to: mCnt, by: step) {
                    let p = macho.decodePointer(atSliceRelOffset: ptrsOff + k * 8)
                    if p > 0 && macho.isInTextCode(p) {
                        validCount += 1
                    }
                    if validCount >= 4 { break }
                }
                if validCount == 0 && mCnt >= 4 {
                    return nil
                }
                rvas = [UInt64](repeating: 0, count: mCnt)
                for k in 0..<mCnt {
                    let mp = macho.decodePointer(atSliceRelOffset: ptrsOff + k * 8)
                    if mp >= baseVM && macho.isInTextCode(mp) {
                        rvas[k] = mp - baseVM
                    }
                }
            }

            let modRVA = (macho.sliceRelOffsetToVM(modOff) ?? baseVM) - baseVM
            return CodeGenModuleInfo(
                imageName: dllName,
                methodRVAs: rvas,
                methodTokens: [],
                codeGenModuleRVA: modRVA
            )
        }

        func tryParseMetaReg(atRel p: Int, relaxDualCount: Bool) -> Bool {
            guard fieldOffsetsByType.isEmpty, p >= 0, p + 32 <= sliceSize, !macho.isInEncryptedRange(sliceRelOffset: p) else {
                return false
            }
            guard typeDefsCount > 0 else { return false }
            let cnt1 = Int(macho.readSliceUInt32(p))
            let hi1 = macho.readSliceUInt32(p + 4)
            let cnt2 = Int(macho.readSliceUInt32(p + 16))
            let hi2 = macho.readSliceUInt32(p + 20)
            guard hi1 == 0 && hi2 == 0 else { return false }

            let exact = (cnt1 == typeDefsCount && cnt2 == typeDefsCount)
            let relaxed = (relaxDualCount && cnt1 == typeDefsCount && cnt2 > 0 && cnt2 <= typeDefsCount + 64)
            guard exact || relaxed else { return false }

            let foTableVM = macho.decodePointer(atSliceRelOffset: p + 8)
            let tdSizeVM = macho.decodePointer(atSliceRelOffset: p + 24)
            guard let foTableOff = macho.vmToSliceRelOffset(foTableVM),
                  macho.vmToSliceRelOffset(tdSizeVM) != nil,
                  !macho.isInEncryptedRange(sliceRelOffset: foTableOff) else {
                return false
            }

            // Determine whether fieldOffsets is int32** (pointer per TypeDef) or flat int32*
            var pointerMode = false
            if foTableOff + cnt1 * 8 <= sliceSize {
                var hits = 0
                var tested = 0
                for ti in 0..<typeDefsCount where tested < 256 {
                    let fc = ti < typeFieldCounts.count ? Int(typeFieldCounts[ti]) : 1
                    if fc == 0 { continue }
                    tested += 1
                    let arrVM = macho.decodePointer(atSliceRelOffset: foTableOff + ti * 8)
                    if let arrOff = macho.vmToSliceRelOffset(arrVM), arrOff + 4 <= sliceSize {
                        let v = macho.readSliceInt32(arrOff)
                        if v >= 0 && v <= 0x40000 {
                            hits += 1
                            if hits >= 2 { pointerMode = true; break }
                        }
                    }
                }
            }

            guard pointerMode else { return false }

            var parsedFields = [[Int32]](repeating: [], count: typeDefsCount)
            for ti in 0..<typeDefsCount {
                let fc = ti < typeFieldCounts.count ? Int(typeFieldCounts[ti]) : 0
                if fc == 0 { continue }
                let arrVM = macho.decodePointer(atSliceRelOffset: foTableOff + ti * 8)
                guard let arrOff = macho.vmToSliceRelOffset(arrVM), arrOff + fc * 4 <= sliceSize else { continue }
                var arr: [Int32] = []
                arr.reserveCapacity(fc)
                for fi in 0..<fc {
                    arr.append(macho.readSliceInt32(arrOff + fi * 4))
                }
                parsedFields[ti] = arr
            }
            fieldOffsetsByType = parsedFields

            // Also parse Il2CppType table located at p - 32 (typesCount @ p-32, types** @ p-24)
            if il2cppTypes.isEmpty && p >= 32 {
                let tCnt = Int(macho.readSliceUInt32(p - 32))
                let tHi = macho.readSliceUInt32(p - 28)
                let tArrVM = macho.decodePointer(atSliceRelOffset: p - 24)
                if tHi == 0 && tCnt >= 1 && tCnt <= 800_000,
                   let tArrOff = macho.vmToSliceRelOffset(tArrVM),
                   tArrOff + tCnt * 8 <= sliceSize,
                   !macho.isInEncryptedRange(sliceRelOffset: tArrOff) {
                    var typesOut: [Il2CppTypeMetaEntry] = []
                    typesOut.reserveCapacity(tCnt)
                    var validTypes = 0
                    for k in 0..<tCnt {
                        let tVM = macho.decodePointer(atSliceRelOffset: tArrOff + k * 8)
                        guard let tOff = macho.vmToSliceRelOffset(tVM),
                              tOff + 16 <= sliceSize,
                              !macho.isInEncryptedRange(sliceRelOffset: tOff) else {
                            typesOut.append(Il2CppTypeMetaEntry(typeEnum: 0, attrs: 0, dataIndex: -1, elementEnum: 0, elementDataIndex: -1, arrayRank: 1, genericBaseTypeIndex: -1, genericArgTypeIndices: [], isByRef: false))
                            continue
                        }
                        let tEnum = macho.readSliceUInt8(tOff + 10)
                        let attrs = macho.readSliceUInt16(tOff + 8)
                        let bits11 = macho.readSliceUInt8(tOff + 11)
                        let isByRef = (bits11 & 0x20) != 0
                        var dataIdx: Int32 = -1
                        var elemEnum: UInt8 = 0
                        var elemDataIdx: Int32 = -1
                        var rank: UInt8 = 1
                        let genBase: Int32 = -1
                        let genArgs: [Int32] = []

                        if tEnum != 0 && tEnum <= 0x55 {
                            validTypes += 1
                        }
                        if tEnum == 0x11 || tEnum == 0x12 { // VALUETYPE / CLASS
                            dataIdx = macho.readSliceInt32(tOff)
                        } else if tEnum == 0x1D || tEnum == 0x0F { // SZARRAY / PTR
                            let innerVM = macho.decodePointer(atSliceRelOffset: tOff)
                            if let innerOff = macho.vmToSliceRelOffset(innerVM), innerOff + 16 <= sliceSize {
                                elemEnum = macho.readSliceUInt8(innerOff + 10)
                                if elemEnum == 0x11 || elemEnum == 0x12 {
                                    elemDataIdx = macho.readSliceInt32(innerOff)
                                }
                            }
                        } else if tEnum == 0x14 { // ARRAY (Il2CppArrayType*)
                            let arrTVM = macho.decodePointer(atSliceRelOffset: tOff)
                            if let arrTOff = macho.vmToSliceRelOffset(arrTVM), arrTOff + 12 <= sliceSize {
                                rank = max(1, macho.readSliceUInt8(arrTOff + 8))
                                let innerVM = macho.decodePointer(atSliceRelOffset: arrTOff)
                                if let innerOff = macho.vmToSliceRelOffset(innerVM), innerOff + 16 <= sliceSize {
                                    elemEnum = macho.readSliceUInt8(innerOff + 10)
                                    if elemEnum == 0x11 || elemEnum == 0x12 {
                                        elemDataIdx = macho.readSliceInt32(innerOff)
                                    }
                                }
                            }
                        } else if tEnum == 0x15 { // GENERICINST (Il2CppGenericClass*)
                            let gcVM = macho.decodePointer(atSliceRelOffset: tOff)
                            if let gcOff = macho.vmToSliceRelOffset(gcVM), gcOff + 16 <= sliceSize {
                                let baseTVM = macho.decodePointer(atSliceRelOffset: gcOff)
                                if let baseTOff = macho.vmToSliceRelOffset(baseTVM), baseTOff + 16 <= sliceSize {
                                    let bEnum = macho.readSliceUInt8(baseTOff + 10)
                                    if bEnum == 0x11 || bEnum == 0x12 {
                                        dataIdx = macho.readSliceInt32(baseTOff)
                                    }
                                } else {
                                    // Unity v27+ stores TypeDefinitionIndex directly at gcOff
                                    dataIdx = macho.readSliceInt32(gcOff)
                                }
                            }
                        } else if tEnum == 0x13 || tEnum == 0x1E { // VAR / MVAR
                            dataIdx = macho.readSliceInt32(tOff)
                        }

                        typesOut.append(Il2CppTypeMetaEntry(
                            typeEnum: tEnum,
                            attrs: attrs,
                            dataIndex: dataIdx,
                            elementEnum: elemEnum,
                            elementDataIndex: elemDataIdx,
                            arrayRank: rank,
                            genericBaseTypeIndex: genBase,
                            genericArgTypeIndices: genArgs,
                            isByRef: isByRef
                        ))
                    }
                    if validTypes > 0 {
                        il2cppTypes = typesOut
                    }
                }
            }

            metaRegRVA = (macho.sliceRelOffsetToVM(p) ?? baseVM) - baseVM
            return true
        }

        func tryParseCodeReg(atRel crOff: Int) -> Int {
            guard crOff >= 0 && crOff + 16 <= sliceSize && !macho.isInEncryptedRange(sliceRelOffset: crOff) else {
                return 0
            }
            let scanEnd = min(sliceSize - 16, crOff + 0x120)
            var loaded = 0
            for off in stride(from: crOff, through: scanEnd, by: 8) {
                let cnt = Int(macho.readSliceUInt32(off))
                let hi = macho.readSliceUInt32(off + 4)
                guard hi == 0 && cnt >= 1 && cnt <= 3000 else { continue }
                if !imageNames.isEmpty && abs(cnt - imageNames.count) > 12 { continue }
                let tblVM = macho.decodePointer(atSliceRelOffset: off + 8)
                guard let tblOff = macho.vmToSliceRelOffset(tblVM),
                      tblOff + cnt * 8 <= sliceSize,
                      !macho.isInEncryptedRange(sliceRelOffset: tblOff) else {
                    continue
                }
                var probeHits = 0
                for k in 0..<min(cnt, 4) {
                    let mVM = macho.decodePointer(atSliceRelOffset: tblOff + k * 8)
                    if let mOff = macho.vmToSliceRelOffset(mVM), readModule(atRel: mOff, allowUnknownImage: true) != nil {
                        probeHits += 1
                    }
                }
                if probeHits > 0 {
                    for k in 0..<cnt {
                        let mVM = macho.decodePointer(atSliceRelOffset: tblOff + k * 8)
                        if let mOff = macho.vmToSliceRelOffset(mVM),
                           let mod = readModule(atRel: mOff, allowUnknownImage: true),
                           codeGenModules[mod.imageName] == nil {
                            codeGenModules[mod.imageName] = mod
                            loaded += 1
                        }
                    }
                    if loaded > 0 {
                        codeRegRVA = (macho.sliceRelOffsetToVM(crOff) ?? baseVM) - baseVM
                        return loaded
                    }
                }
            }
            return 0
        }

        // STRATEGY 1: __mod_init_func / __TEXT,__init_offsets -> X0=CodeReg, X1=MetaReg
        var initFuncVMs: [UInt64] = []
        for sec in macho.sections {
            if (sec.sectName == "__mod_init_func" || (sec.flags & 0xFF) == 0x09),
               sec.size >= 8, Int(sec.offset + sec.size) <= sliceSize,
               !macho.isInEncryptedRange(sliceRelOffset: Int(sec.offset)) {
                let cnt = min(Int(sec.size / 8), 2048)
                for i in 0..<cnt {
                    let fVM = macho.decodePointer(atSliceRelOffset: Int(sec.offset) + i * 8)
                    if fVM != 0 && macho.isInTextCode(fVM) { initFuncVMs.append(fVM) }
                }
            } else if sec.sectName == "__init_offsets",
                      sec.size >= 4, Int(sec.offset + sec.size) <= sliceSize,
                      !macho.isInEncryptedRange(sliceRelOffset: Int(sec.offset)) {
                let cnt = min(Int(sec.size / 4), 2048)
                for i in 0..<cnt {
                    let rel = UInt64(macho.readSliceUInt32(Int(sec.offset) + i * 4))
                    if rel > 0 && baseVM + rel < macho.maxVM { initFuncVMs.append(baseVM + rel) }
                }
            }
        }

        for fVM in initFuncVMs {
            guard let fOff = macho.vmToSliceRelOffset(fVM),
                  fOff + 12 <= sliceSize,
                  !macho.isInEncryptedRange(sliceRelOffset: fOff) else { continue }
            var adrpPages: [Int: UInt64] = [:]
            var regs: [Int: UInt64] = [:]
            for st in 0..<24 {
                let ioff = fOff + st * 4
                guard ioff + 4 <= sliceSize else { break }
                let w = macho.readSliceUInt32(ioff)
                if w == 0xD65F_03C0 { break }
                if (w & 0x9F00_0000) == 0x9000_0000 { // ADRP
                    let rd = Int(w & 0x1F)
                    var imm21 = (Int64((w >> 5) & 0x7FFFF) << 2) | Int64((w >> 29) & 0x3)
                    if (imm21 & 0x10_0000) != 0 { imm21 |= ~0x1F_FFFF }
                    let pcVM = fVM + UInt64(st * 4)
                    adrpPages[rd] = (pcVM & ~0xFFF) &+ UInt64(bitPattern: imm21 &* 4096)
                } else if (w & 0xFF80_0000) == 0x9100_0000 { // ADD Xd, Xn, #imm12
                    let rd = Int(w & 0x1F)
                    let rn = Int((w >> 5) & 0x1F)
                    let imm12 = UInt64((w >> 10) & 0xFFF) << (((w >> 22) & 1) == 1 ? 12 : 0)
                    if let pg = adrpPages[rn] {
                        regs[rd] = pg + imm12
                    }
                } else if (w & 0xFFC0_0000) == 0xF940_0000 { // LDR Xd, [Xn, #imm*8]
                    let rd = Int(w & 0x1F)
                    let rn = Int((w >> 5) & 0x1F)
                    let imm = UInt64((w >> 10) & 0xFFF) * 8
                    if let pg = adrpPages[rn], let ptrOff = macho.vmToSliceRelOffset(pg + imm) {
                        regs[rd] = macho.decodePointer(atSliceRelOffset: ptrOff)
                    }
                }
            }
            if let x0 = regs[0], let x1 = regs[1],
               let crOff = macho.vmToSliceRelOffset(x0),
               let mrOff = macho.vmToSliceRelOffset(x1) {
                for delta in [0x50, 0x48, 0x40, 0x38, 0x58] {
                    if tryParseMetaReg(atRel: mrOff + delta, relaxDualCount: true) { break }
                }
                let loaded = tryParseCodeReg(atRel: crOff)
                if loaded > 0 {
                    strategyUsed = "__mod_init_func"
                    log("[IL2CPP Reg] Chiến lược 1 (__mod_init_func): Đã tìm thấy \(loaded) module & MetadataRegistration.")
                    break
                }
            }
        }

        // STRATEGY 2 & 3: Scan data/const sections structurally
        if codeGenModules.isEmpty || fieldOffsetsByType.isEmpty {
            for ds in dataSections {
                let start = Int(ds.offset)
                let end = min(sliceSize - 24, start + min(Int(ds.size), 120 * 1024 * 1024) - 24)
                guard start <= end else { continue }
                for p in stride(from: start, through: end, by: 8) {
                    if !imageNames.isEmpty && codeGenModules.count < imageNames.count {
                        let cgCnt = Int(macho.readSliceUInt32(p))
                        let cgHi = macho.readSliceUInt32(p + 4)
                        if cgHi == 0 && cgCnt == imageNames.count {
                            let tblVM = macho.decodePointer(atSliceRelOffset: p + 8)
                            if let tblOff = macho.vmToSliceRelOffset(tblVM),
                               tblOff + cgCnt * 8 <= sliceSize,
                               !macho.isInEncryptedRange(sliceRelOffset: tblOff) {
                                let m0VM = macho.decodePointer(atSliceRelOffset: tblOff)
                                if let m0Off = macho.vmToSliceRelOffset(m0VM),
                                   readModule(atRel: m0Off, allowUnknownImage: true) != nil {
                                    for k in 0..<cgCnt {
                                        let mVM = macho.decodePointer(atSliceRelOffset: tblOff + k * 8)
                                        if let mOff = macho.vmToSliceRelOffset(mVM),
                                           let mod = readModule(atRel: mOff, allowUnknownImage: true),
                                           codeGenModules[mod.imageName] == nil {
                                            codeGenModules[mod.imageName] = mod
                                        }
                                    }
                                    if strategyUsed == "None" { strategyUsed = "CodeRegistrationScan" }
                                }
                            }
                        }
                    }

                    if let mod = readModule(atRel: p, allowUnknownImage: false),
                       codeGenModules[mod.imageName] == nil {
                        codeGenModules[mod.imageName] = mod
                        if strategyUsed == "None" { strategyUsed = "CodeGenModuleScan" }
                    }

                    // Legacy v24 CodeRegistration
                    if legacyMethodRVAs.isEmpty && metadataVersion <= 24 && totalMethodsCount >= 2 {
                        let mc = Int(macho.readSliceUInt32(p))
                        let mhi = macho.readSliceUInt32(p + 4)
                        if mhi == 0 && mc >= max(2, totalMethodsCount / 3) && mc <= totalMethodsCount + 2048 {
                            let tblVM = macho.decodePointer(atSliceRelOffset: p + 8)
                            if let tblOff = macho.vmToSliceRelOffset(tblVM),
                               tblOff + mc * 8 <= sliceSize,
                               !macho.isInEncryptedRange(sliceRelOffset: tblOff) {
                                var okPtrs = 0
                                let step = max(1, mc / 32)
                                for k in stride(from: 0, to: mc, by: step) {
                                    let mp = macho.decodePointer(atSliceRelOffset: tblOff + k * 8)
                                    if mp > 0 && macho.isInTextCode(mp) { okPtrs += 1 }
                                    if okPtrs >= 4 { break }
                                }
                                if okPtrs >= min(4, mc) {
                                    var arr = [UInt64](repeating: 0, count: mc)
                                    for k in 0..<mc {
                                        let mp = macho.decodePointer(atSliceRelOffset: tblOff + k * 8)
                                        if mp >= baseVM && macho.isInTextCode(mp) {
                                            arr[k] = mp - baseVM
                                        }
                                    }
                                    legacyMethodRVAs = arr
                                    if strategyUsed == "None" { strategyUsed = "LegacyCodeReg_v24" }
                                }
                            }
                        }
                    }

                    if fieldOffsetsByType.isEmpty {
                        _ = tryParseMetaReg(atRel: p, relaxDualCount: false)
                    }
                }
            }
        }

        // Analyze .ctor ARM64 instructions to recover instance field initial values
        var ctorInits: [Int: [Int32: String]] = [:]
        if !codeGenModules.isEmpty || !legacyMethodRVAs.isEmpty {
            for (imgName, pairs) in ctorPairsByImage {
                let modRVAs = codeGenModules[imgName]?.methodRVAs
                for pair in pairs {
                    let rva: UInt64
                    if let modRVAs = modRVAs, pair.methodId >= 0 && pair.methodId < modRVAs.count {
                        rva = modRVAs[pair.methodId]
                    } else if pair.methodId >= 0 && pair.methodId < legacyMethodRVAs.count {
                        rva = legacyMethodRVAs[pair.methodId]
                    } else {
                        continue
                    }
                    guard rva > 0, let mOff = macho.vmToSliceRelOffset(baseVM + rva) else { continue }
                    let inits = macho.analyzeArm64CtorInits(atSliceRelOffset: mOff)
                    if !inits.isEmpty {
                        ctorInits[pair.typeDefIndex] = inits
                    }
                }
            }
        }

        log("[IL2CPP Reg] Chiến lược=\(strategyUsed) · Modules=\(codeGenModules.count) · FieldOffsetTypes=\(fieldOffsetsByType.count) · Il2CppTypes=\(il2cppTypes.count) · CtorInits=\(ctorInits.count)")

        return Il2CppRegistrationResult(
            codeRegistrationRVA: codeRegRVA,
            metadataRegistrationRVA: metaRegRVA,
            strategyUsed: strategyUsed,
            codeGenModules: codeGenModules,
            legacyMethodRVAs: legacyMethodRVAs,
            fieldOffsetsByType: fieldOffsetsByType,
            il2cppTypes: il2cppTypes,
            stringLiteralAddresses: [],
            ctorFieldInits: ctorInits
        )
    }
}
