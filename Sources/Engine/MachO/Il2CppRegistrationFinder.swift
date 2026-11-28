import Foundation

public enum Il2CppRegistrationFinder {
    public static func find(
        in macho: MachOParser,
        imageNames: [String],
        typeFieldCounts: [UInt16],
        typeDefsCount: Int,
        totalMethodsCount: Int,
        metadataVersion: Int32,
        ctorPairsByImage: [String: [(typeDefIndex: Int, ridIndex: Int, globalIndex: Int)]],
        isCancelled: () -> Bool = { false },
        log: (String) -> Void
    ) -> Il2CppRegistrationResult {
        var codeGenModules: [String: CodeGenModuleInfo] = [:]
        var legacyMethodRVAs: [UInt64] = []
        var fieldOffsetsByType: [[Int32]] = []
        var il2cppTypes: [Il2CppTypeMetaEntry] = []
        var codeRegRVA: UInt64 = 0
        var metaRegRVA: UInt64 = 0
        var strategyUsed = "None"
        var foundCodeRegBaseOff: Int? = nil
        var foundCodeGenModulesOff: Int? = nil
        var rawGenericMethodPairs: [(methodDefIndex: Int, ptrIndex: Int)] = []
        var maxGenericPtrIndex: Int = -1

        let wantedSet = Set(imageNames)
        let sliceSize = macho.sliceSize
        let baseVM = macho.baseVM

        let excludedDataSects: Set<String> = [
            "__bss", "__common", "__got", "__la_symbol_ptr", "__mod_init_func", "__mod_term_func"
        ]
        let dataSections = macho.sections.filter { sec in
            guard (sec.flags & 0xFF) != 0x01,
                  sec.size >= 24,
                  Int(sec.offset + sec.size) <= sliceSize,
                  !macho.isInEncryptedRange(sliceRelOffset: Int(sec.offset)) else {
                return false
            }
            if sec.segName == "__TEXT" {
                return sec.sectName == "__const"
            }
            if sec.segName == "__LINKEDIT" || sec.segName == "__PAGEZERO" {
                return false
            }
            if sec.sectName.hasPrefix("__objc_") || sec.sectName.hasPrefix("__swift5_") || excludedDataSects.contains(sec.sectName) {
                return false
            }
            return true
        }

        func readModule(atRel modOff: Int, allowUnknownImage: Bool) -> CodeGenModuleInfo? {
            guard modOff >= 0 && modOff + 24 <= sliceSize && !macho.isInEncryptedRange(sliceRelOffset: modOff) else {
                return nil
            }
            let mCnt = Int(macho.readSliceUInt32(modOff + 8))
            let mHi = macho.readSliceUInt32(modOff + 12)
            guard mHi == 0 && mCnt >= 0 && mCnt <= 600_000 else { return nil }

            let namePtrVM = macho.decodePointer(atSliceRelOffset: modOff)
            guard namePtrVM != 0, let nameOff = macho.vmToSliceRelOffset(namePtrVM), !macho.isInEncryptedRange(sliceRelOffset: nameOff) else {
                return nil
            }
            let dllName = macho.readSliceCString(nameOff, maxLength: 160)
            let lowerDll = dllName.lowercased()
            let knownMatch = wantedSet.contains(dllName)
            guard knownMatch || lowerDll.hasSuffix(".dll") || lowerDll.hasSuffix(".exe") else { return nil }
            if !allowUnknownImage && !wantedSet.isEmpty && !knownMatch {
                return nil
            }

            var rvas: [UInt64] = []
            if mCnt > 0 {
                let ptrsVM = macho.decodePointer(atSliceRelOffset: modOff + 16)
                guard let ptrsOff = macho.vmToSliceRelOffset(ptrsVM),
                      ptrsOff + mCnt * 8 <= sliceSize,
                      !macho.isInEncryptedRange(sliceRelOffset: ptrsOff) else {
                    return nil
                }
                var validCount = 0
                var badNonZeroCount = 0
                let step = max(1, mCnt / 64)
                for k in stride(from: 0, to: mCnt, by: step) {
                    let p = macho.decodePointer(atSliceRelOffset: ptrsOff + k * 8)
                    if p == 0 { continue }
                    if macho.isInTextCode(p) {
                        validCount += 1
                    } else {
                        badNonZeroCount += 1
                    }
                    if validCount >= 4 && badNonZeroCount == 0 { break }
                }
                if badNonZeroCount > 0 {
                    return nil
                }
                if validCount == 0 && mCnt >= 4 && !knownMatch {
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
                            pointerMode = true
                            break
                        }
                    }
                }
            }

            if pointerMode {
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
            } else {
                // Legacy flat int32* fieldOffsets table fallback
                let totalFields = typeFieldCounts.reduce(0) { $0 + Int($1) }
                guard totalFields > 0, foTableOff + totalFields * 4 <= sliceSize else { return false }
                var sane = 0
                let checkCount = min(totalFields, 32)
                for i in 0..<checkCount {
                    let v = macho.readSliceInt32(foTableOff + i * 4)
                    if v >= 0 && v <= 0x40000 { sane += 1 }
                }
                guard sane >= max(1, checkCount / 2) else { return false }
                var parsedFields = [[Int32]](repeating: [], count: typeDefsCount)
                var cursor = 0
                for ti in 0..<typeDefsCount {
                    let fc = ti < typeFieldCounts.count ? Int(typeFieldCounts[ti]) : 0
                    if fc == 0 { continue }
                    var arr: [Int32] = []
                    arr.reserveCapacity(fc)
                    for _ in 0..<fc where cursor < totalFields {
                        arr.append(macho.readSliceInt32(foTableOff + cursor * 4))
                        cursor += 1
                    }
                    parsedFields[ti] = arr
                }
                fieldOffsetsByType = parsedFields
            }

            // Parse Il2CppType table located at p - 32 or p - 40 (typesCount, types**)
            for typeHdrDelta in [32, 40] where il2cppTypes.isEmpty && p >= typeHdrDelta {
                let tCnt = Int(macho.readSliceUInt32(p - typeHdrDelta))
                let tHi = macho.readSliceUInt32(p - typeHdrDelta + 4)
                let tArrVM = macho.decodePointer(atSliceRelOffset: p - typeHdrDelta + 8)
                if tHi == 0 && tCnt >= 1 && tCnt <= 800_000,
                   let tArrOff = macho.vmToSliceRelOffset(tArrVM),
                   tArrOff + tCnt * 8 <= sliceSize,
                   !macho.isInEncryptedRange(sliceRelOffset: tArrOff) {
                    var typeVMToIndex: [UInt64: Int32] = [:]
                    typeVMToIndex.reserveCapacity(tCnt)
                    for k in 0..<tCnt {
                        let tVM = macho.decodePointer(atSliceRelOffset: tArrOff + k * 8)
                        if tVM != 0 {
                            typeVMToIndex[tVM] = Int32(k)
                        }
                    }

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
                        var genBase: Int32 = -1
                        var genArgs: [Int32] = []

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
                            if let gcOff = macho.vmToSliceRelOffset(gcVM), gcOff + 24 <= sliceSize {
                                let baseTVM = macho.decodePointer(atSliceRelOffset: gcOff)
                                if let mappedBase = typeVMToIndex[baseTVM] {
                                    genBase = mappedBase
                                }
                                if let baseTOff = macho.vmToSliceRelOffset(baseTVM), baseTOff + 16 <= sliceSize {
                                    let bEnum = macho.readSliceUInt8(baseTOff + 10)
                                    if bEnum == 0x11 || bEnum == 0x12 {
                                        dataIdx = macho.readSliceInt32(baseTOff)
                                    }
                                } else {
                                    dataIdx = macho.readSliceInt32(gcOff)
                                }

                                // Il2CppGenericContext { Il2CppGenericInst* class_inst; Il2CppGenericInst* method_inst; } at +8
                                let classInstVM = macho.decodePointer(atSliceRelOffset: gcOff + 8)
                                if let instOff = macho.vmToSliceRelOffset(classInstVM), instOff + 16 <= sliceSize {
                                    let argc = Int(macho.readSliceUInt32(instOff))
                                    let argvVM = macho.decodePointer(atSliceRelOffset: instOff + 8)
                                    if argc > 0 && argc <= 16,
                                       let argvOff = macho.vmToSliceRelOffset(argvVM),
                                       argvOff + argc * 8 <= sliceSize {
                                        for ai in 0..<argc {
                                            let argTVM = macho.decodePointer(atSliceRelOffset: argvOff + ai * 8)
                                            if let argIdx = typeVMToIndex[argTVM] {
                                                genArgs.append(argIdx)
                                            }
                                        }
                                    }
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

            // Parse methodSpecs at p - 16 and genericMethodTable at p - 48
            if rawGenericMethodPairs.isEmpty && p >= 48 && totalMethodsCount > 0 {
                let msCnt = Int(macho.readSliceUInt32(p - 16))
                let msHi = macho.readSliceUInt32(p - 12)
                let msPtrVM = macho.decodePointer(atSliceRelOffset: p - 8)
                var parsedSpecs: [Int32] = []
                if msHi == 0 && msCnt > 0 && msCnt <= 1_000_000,
                   let msOff = macho.vmToSliceRelOffset(msPtrVM),
                   msOff + msCnt * 12 <= sliceSize,
                   !macho.isInEncryptedRange(sliceRelOffset: msOff) {
                    var validSpecs = 0
                    let specSamples = min(msCnt, 16)
                    let specStep = max(1, msCnt / specSamples)
                    for si in stride(from: 0, to: msCnt, by: specStep) {
                        let mdIdx = Int(macho.readSliceInt32(msOff + si * 12))
                        if mdIdx >= 0 && mdIdx < totalMethodsCount {
                            validSpecs += 1
                        }
                    }
                    if validSpecs > 0 {
                        parsedSpecs.reserveCapacity(msCnt)
                        for si in 0..<msCnt {
                            parsedSpecs.append(macho.readSliceInt32(msOff + si * 12))
                        }
                    }
                }

                let gmCnt = Int(macho.readSliceUInt32(p - 48))
                let gmHi = macho.readSliceUInt32(p - 44)
                let gmPtrVM = macho.decodePointer(atSliceRelOffset: p - 40)
                if !parsedSpecs.isEmpty && gmHi == 0 && gmCnt > 0 && gmCnt <= 1_000_000,
                   let gmOff = macho.vmToSliceRelOffset(gmPtrVM),
                   !macho.isInEncryptedRange(sliceRelOffset: gmOff) {
                    let strideCandidates = metadataVersion >= 25 ? [16, 12] : [12, 16]
                    for gmStride in strideCandidates {
                        guard gmOff + gmCnt * gmStride <= sliceSize else { continue }
                        var gmHits = 0
                        let gmSamples = min(gmCnt, 32)
                        let gmStep = max(1, gmCnt / gmSamples)
                        for gi in stride(from: 0, to: gmCnt, by: gmStep) {
                            let specIdx = Int(macho.readSliceInt32(gmOff + gi * gmStride))
                            let methIdx = Int(macho.readSliceInt32(gmOff + gi * gmStride + 4))
                            if specIdx >= 0 && specIdx < parsedSpecs.count && methIdx >= -1 && methIdx < 2_000_000 {
                                gmHits += 1
                            }
                        }
                        if gmHits >= max(1, gmSamples / 2) {
                            rawGenericMethodPairs.reserveCapacity(gmCnt)
                            for gi in 0..<gmCnt {
                                let specIdx = Int(macho.readSliceInt32(gmOff + gi * gmStride))
                                let methIdx = Int(macho.readSliceInt32(gmOff + gi * gmStride + 4))
                                guard specIdx >= 0 && specIdx < parsedSpecs.count && methIdx >= 0 else { continue }
                                let mdIdx = Int(parsedSpecs[specIdx])
                                guard mdIdx >= 0 && mdIdx < totalMethodsCount else { continue }
                                rawGenericMethodPairs.append((mdIdx, methIdx))
                                if methIdx > maxGenericPtrIndex { maxGenericPtrIndex = methIdx }
                            }
                            break
                        }
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
                let tol = max(16, imageNames.count / 5)
                if !imageNames.isEmpty && abs(cnt - imageNames.count) > tol { continue }
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
                        foundCodeRegBaseOff = crOff
                        foundCodeGenModulesOff = off
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
                let cnt = Int(sec.size / 8)
                for i in 0..<cnt {
                    let fVM = macho.decodePointer(atSliceRelOffset: Int(sec.offset) + i * 8)
                    if fVM != 0 && macho.isInTextCode(fVM) { initFuncVMs.append(fVM) }
                }
            } else if sec.sectName == "__init_offsets",
                      sec.size >= 4, Int(sec.offset + sec.size) <= sliceSize,
                      !macho.isInEncryptedRange(sliceRelOffset: Int(sec.offset)) {
                let cnt = Int(sec.size / 4)
                for i in 0..<cnt {
                    let rel = UInt64(macho.readSliceUInt32(Int(sec.offset) + i * 4))
                    if rel > 0 && baseVM + rel < macho.maxVM { initFuncVMs.append(baseVM + rel) }
                }
            }
        }

        func decodeRegistrationRegs(atVM startVM: UInt64, adrpPages: inout [Int: UInt64], regs: inout [Int: UInt64], allowBranchHop: Bool) {
            guard let fOff = macho.vmToSliceRelOffset(startVM),
                  fOff + 8 <= sliceSize,
                  !macho.isInEncryptedRange(sliceRelOffset: fOff) else { return }
            var hopTarget: UInt64? = nil
            for st in 0..<48 {
                let ioff = fOff + st * 4
                guard ioff + 4 <= sliceSize else { break }
                let w = macho.readSliceUInt32(ioff)
                if w == 0xD65F_03C0 { break }
                if (w & 0xFFE0_FFE0) == 0xAA00_03E0 { // MOV Xd, Xm
                    let rd = Int(w & 0x1F)
                    let rm = Int((w >> 16) & 0x1F)
                    if let val = regs[rm] { regs[rd] = val }
                } else if (w & 0x9F00_0000) == 0x1000_0000 { // ADR
                    let rd = Int(w & 0x1F)
                    var imm21 = (Int64((w >> 5) & 0x7FFFF) << 2) | Int64((w >> 29) & 0x3)
                    if (imm21 & 0x10_0000) != 0 { imm21 |= ~0x1F_FFFF }
                    let pcVM = startVM + UInt64(st * 4)
                    let target = UInt64(bitPattern: Int64(bitPattern: pcVM) &+ imm21)
                    adrpPages[rd] = target
                    regs[rd] = target
                } else if (w & 0x9F00_0000) == 0x9000_0000 { // ADRP
                    let rd = Int(w & 0x1F)
                    var imm21 = (Int64((w >> 5) & 0x7FFFF) << 2) | Int64((w >> 29) & 0x3)
                    if (imm21 & 0x10_0000) != 0 { imm21 |= ~0x1F_FFFF }
                    let pcVM = startVM + UInt64(st * 4)
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
                } else if allowBranchHop && ((w & 0xFC00_0000) == 0x1400_0000 || (w & 0xFC00_0000) == 0x9400_0000) {
                    var imm26 = Int64(w & 0x03FF_FFFF)
                    if (imm26 & 0x0200_0000) != 0 { imm26 |= ~0x03FF_FFFF }
                    let pcVM = startVM + UInt64(st * 4)
                    let dest = UInt64(bitPattern: Int64(bitPattern: pcVM) &+ imm26 &* 4)
                    if macho.isInTextCode(dest) {
                        hopTarget = dest
                    }
                    if (w & 0xFC00_0000) == 0x1400_0000 { break } // unconditional tail B
                }
            }
            if allowBranchHop, (regs[0] == nil || regs[1] == nil), let hop = hopTarget {
                decodeRegistrationRegs(atVM: hop, adrpPages: &adrpPages, regs: &regs, allowBranchHop: false)
            }
        }

        for fVM in initFuncVMs {
            var adrpPages: [Int: UInt64] = [:]
            var regs: [Int: UInt64] = [:]
            decodeRegistrationRegs(atVM: fVM, adrpPages: &adrpPages, regs: &regs, allowBranchHop: true)
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
        let needsModulesScan = imageNames.isEmpty ? codeGenModules.isEmpty : (codeGenModules.count < imageNames.count)
        if (needsModulesScan && legacyMethodRVAs.isEmpty) || fieldOffsetsByType.isEmpty {
            outerDataLoop: for ds in dataSections {
                let start = Int(ds.offset)
                let end = min(sliceSize - 24, start + Int(ds.size) - 24)
                guard start <= end else { continue }
                for p in stride(from: start, through: end, by: 8) {
                    if ((p - start) & 0x3FFF8) == 0 && isCancelled() { break outerDataLoop }

                    let needMoreModules = imageNames.isEmpty ? codeGenModules.isEmpty : (codeGenModules.count < imageNames.count)
                    let haveAllCode = (!needMoreModules) || !legacyMethodRVAs.isEmpty
                    if haveAllCode && !fieldOffsetsByType.isEmpty {
                        break outerDataLoop
                    }

                    if !imageNames.isEmpty && codeGenModules.count < imageNames.count {
                        let cgCnt = Int(macho.readSliceUInt32(p))
                        let cgHi = macho.readSliceUInt32(p + 4)
                        if cgHi == 0 && cgCnt == imageNames.count {
                            let tblVM = macho.decodePointer(atSliceRelOffset: p + 8)
                            if let tblOff = macho.vmToSliceRelOffset(tblVM),
                               tblOff + cgCnt * 8 <= sliceSize,
                               !macho.isInEncryptedRange(sliceRelOffset: tblOff) {
                                var probeHits = 0
                                for k in 0..<min(cgCnt, 8) {
                                    let mVM = macho.decodePointer(atSliceRelOffset: tblOff + k * 8)
                                    if let mOff = macho.vmToSliceRelOffset(mVM),
                                       readModule(atRel: mOff, allowUnknownImage: true) != nil {
                                        probeHits += 1
                                        break
                                    }
                                }
                                if probeHits > 0 {
                                    for k in 0..<cgCnt {
                                        let mVM = macho.decodePointer(atSliceRelOffset: tblOff + k * 8)
                                        if let mOff = macho.vmToSliceRelOffset(mVM),
                                           let mod = readModule(atRel: mOff, allowUnknownImage: true),
                                           codeGenModules[mod.imageName] == nil {
                                            codeGenModules[mod.imageName] = mod
                                        }
                                    }
                                    if foundCodeGenModulesOff == nil { foundCodeGenModulesOff = p }
                                    if strategyUsed == "None" { strategyUsed = "CodeRegistrationScan" }
                                }
                            }
                        }
                    }

                    if needMoreModules,
                       let mod = readModule(atRel: p, allowUnknownImage: false),
                       codeGenModules[mod.imageName] == nil {
                        codeGenModules[mod.imageName] = mod
                        if strategyUsed == "None" { strategyUsed = "CodeGenModuleScan" }
                    }

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

        // Resolve genericMethodPointers -> genericMethodRVAsByMethodDef
        var genericMethodRVAsByMethodDef: [Int: [UInt64]] = [:]
        if !rawGenericMethodPairs.isEmpty, maxGenericPtrIndex >= 0 {
            let minNeeded = maxGenericPtrIndex + 1
            var bestGPtrsOff: Int? = nil
            var bestGCount: Int = 0
            var bestSlack: Int = Int.max

            func probeGenericPtrPair(atRel gpOff: Int) {
                guard gpOff >= 0 && gpOff + 16 <= sliceSize && !macho.isInEncryptedRange(sliceRelOffset: gpOff) else { return }
                let gCnt = Int(macho.readSliceUInt32(gpOff))
                let gHi = macho.readSliceUInt32(gpOff + 4)
                guard gHi == 0 && gCnt >= minNeeded && gCnt <= 2_000_000 else { return }
                let gPtrVM = macho.decodePointer(atSliceRelOffset: gpOff + 8)
                guard let gTblOff = macho.vmToSliceRelOffset(gPtrVM),
                      gTblOff + gCnt * 8 <= sliceSize,
                      !macho.isInEncryptedRange(sliceRelOffset: gTblOff) else {
                    return
                }
                var nonZeroOk = 0
                var nonZeroBad = 0
                let sampleStep = max(1, gCnt / 24)
                for k in stride(from: 0, to: gCnt, by: sampleStep) {
                    let mp = macho.decodePointer(atSliceRelOffset: gTblOff + k * 8)
                    if mp == 0 { continue }
                    if macho.isInTextCode(mp) {
                        nonZeroOk += 1
                    } else {
                        nonZeroBad += 1
                    }
                }
                if nonZeroOk >= 1 && nonZeroBad == 0 {
                    let slack = gCnt - minNeeded
                    if slack < bestSlack {
                        bestSlack = slack
                        bestGPtrsOff = gTblOff
                        bestGCount = gCnt
                    }
                }
            }

            if let anchor = foundCodeGenModulesOff ?? foundCodeRegBaseOff {
                let lo = max(0, anchor - 0xC0)
                let hi = min(sliceSize - 16, anchor + 0x40)
                if lo <= hi {
                    for gp in stride(from: lo, through: hi, by: 8) {
                        probeGenericPtrPair(atRel: gp)
                    }
                }
            }
            if bestGPtrsOff == nil && foundCodeGenModulesOff == nil && foundCodeRegBaseOff == nil {
                outerGenLoop: for ds in dataSections {
                    let start = Int(ds.offset)
                    let end = min(sliceSize - 16, start + Int(ds.size) - 16)
                    guard start <= end else { continue }
                    for gp in stride(from: start, through: end, by: 8) {
                        if ((gp - start) & 0x3FFF8) == 0 && isCancelled() { break outerGenLoop }
                        probeGenericPtrPair(atRel: gp)
                    }
                }
            }

            if let gTblOff = bestGPtrsOff, bestGCount > 0 {
                var genRVAs = [UInt64](repeating: 0, count: bestGCount)
                for k in 0..<bestGCount {
                    let mp = macho.decodePointer(atSliceRelOffset: gTblOff + k * 8)
                    if mp >= baseVM && macho.isInTextCode(mp) {
                        genRVAs[k] = mp - baseVM
                    }
                }
                for pair in rawGenericMethodPairs {
                    guard pair.ptrIndex >= 0 && pair.ptrIndex < genRVAs.count else { continue }
                    let rva = genRVAs[pair.ptrIndex]
                    guard rva > 0 else { continue }
                    var existing = genericMethodRVAsByMethodDef[pair.methodDefIndex] ?? []
                    if !existing.contains(rva) {
                        existing.append(rva)
                        genericMethodRVAsByMethodDef[pair.methodDefIndex] = existing
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
                    if let modRVAs = modRVAs, pair.ridIndex >= 0 && pair.ridIndex < modRVAs.count && modRVAs[pair.ridIndex] > 0 {
                        rva = modRVAs[pair.ridIndex]
                    } else if pair.globalIndex >= 0 && pair.globalIndex < legacyMethodRVAs.count && legacyMethodRVAs[pair.globalIndex] > 0 {
                        rva = legacyMethodRVAs[pair.globalIndex]
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

        log("[IL2CPP Reg] Chiến lược=\(strategyUsed) · Modules=\(codeGenModules.count) · FieldOffsetTypes=\(fieldOffsetsByType.count) · Il2CppTypes=\(il2cppTypes.count) · GenericMethodDefs=\(genericMethodRVAsByMethodDef.count) · CtorInits=\(ctorInits.count)")

        return Il2CppRegistrationResult(
            codeRegistrationRVA: codeRegRVA,
            metadataRegistrationRVA: metaRegRVA,
            strategyUsed: strategyUsed,
            codeGenModules: codeGenModules,
            legacyMethodRVAs: legacyMethodRVAs,
            fieldOffsetsByType: fieldOffsetsByType,
            il2cppTypes: il2cppTypes,
            stringLiteralAddresses: [],
            ctorFieldInits: ctorInits,
            genericMethodRVAsByMethodDef: genericMethodRVAsByMethodDef
        )
    }
}
