import Foundation

public struct ParsedMetadataResult: Sendable {
    public let status: MetadataProtectionStatus
    public let version: Int32
    public let images: [Il2CppImageInfo]
    public let types: [Il2CppTypeInfo]
    public let stringLiterals: [Il2CppStringLiteral]
    public let typeFieldCounts: [UInt16]
    public let ctorPairsByImage: [String: [(typeDefIndex: Int, methodId: Int)]]
    public let totalMethodsCount: Int
    public let filteredRealMoneyCount: Int
}

public enum Il2CppMetadataParser {
    private static let sanityMagic: UInt32 = 0xFAB1_1BAF

    public static func inspectAndParse(
        url: URL,
        registration: Il2CppRegistrationResult? = nil,
        macho: MachOParser? = nil,
        options: DumpOptions = .default,
        log: (String) -> Void = { _ in }
    ) throws -> ParsedMetadataResult {
        let mapped = try MappedFile(url: url)
        guard mapped.count >= 256 else {
            return ParsedMetadataResult(
                status: .encryptedOrProtected(
                    magicHex: mapped.hexPreview(at: 0, length: 8),
                    entropy: mapped.shannonEntropy(),
                    reason: "Tệp global-metadata.dat quá nhỏ (< 256 bytes)"
                ),
                version: 0, images: [], types: [], stringLiterals: [],
                typeFieldCounts: [], ctorPairsByImage: [:], totalMethodsCount: 0, filteredRealMoneyCount: 0
            )
        }

        // Check standard plaintext magic at 0 or aligned container offset (no decryption/cracking!)
        var baseOffset = -1
        if mapped.readUInt32(at: 0) == sanityMagic {
            let ver = mapped.readInt32(at: 4)
            if ver >= 16 && ver <= 36 {
                baseOffset = 0
            }
        } else {
            let scanCap = min(mapped.count - 256, 65_536)
            for off in stride(from: 4, through: scanCap, by: 4) {
                if mapped.readUInt32(at: off) == sanityMagic {
                    let ver = mapped.readInt32(at: off + 4)
                    if ver >= 16 && ver <= 36 {
                        baseOffset = off
                        break
                    }
                }
            }
        }

        guard baseOffset >= 0 else {
            let magic = mapped.hexPreview(at: 0, length: 8)
            let entropy = mapped.shannonEntropy()
            let reason = entropy >= 7.2
                ? "Entropy cao (đã mã hoá hoặc nén tuỳ chỉnh; ứng dụng không bẻ khoá DRM/encryption — tự động chuyển sang quét Mach-O rõ)"
                : "Header không khớp chuẩn Unity 0xFAB11BAF (đã bảo vệ tuỳ chỉnh — tự động chuyển sang quét Mach-O rõ)"
            return ParsedMetadataResult(
                status: .encryptedOrProtected(magicHex: magic, entropy: entropy, reason: reason),
                version: 0, images: [], types: [], stringLiterals: [],
                typeFieldCounts: [], ctorPairsByImage: [:], totalMethodsCount: 0, filteredRealMoneyCount: 0
            )
        }

        let version = mapped.readInt32(at: baseOffset + 4)
        let stringLiteralOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 8))
        let stringLiteralSize = Int(mapped.readUInt32(at: baseOffset + 12))
        let stringLiteralDataOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 16))
        let stringLiteralDataSize = Int(mapped.readUInt32(at: baseOffset + 20))
        let stringOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 24))
        let stringSize = Int(mapped.readUInt32(at: baseOffset + 28))

        let propertiesOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 40))
        let propertiesSize = Int(mapped.readUInt32(at: baseOffset + 44))
        let methodsOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 48))
        let methodsSize = Int(mapped.readUInt32(at: baseOffset + 52))
        let paramDefValsOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 56))
        let paramDefValsSize = Int(mapped.readUInt32(at: baseOffset + 60))
        let fieldDefValsOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 64))
        let fieldDefValsSize = Int(mapped.readUInt32(at: baseOffset + 68))
        let defValDataOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 72))
        let defValDataSize = Int(mapped.readUInt32(at: baseOffset + 76))
        let parametersOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 88))
        let parametersSize = Int(mapped.readUInt32(at: baseOffset + 92))
        let fieldsOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 96))
        let fieldsSize = Int(mapped.readUInt32(at: baseOffset + 100))

        // Attributes (v24-v27: attributesInfo @120, attributeTypes @128; v29+: customAttributeData @256, customAttributeDataRange @264)
        let attrInfoOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 120))
        let attrInfoSize = Int(mapped.readUInt32(at: baseOffset + 124))
        let attrTypesOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 128))
        let attrTypesSize = Int(mapped.readUInt32(at: baseOffset + 132))
        let attrDataOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 256))
        let attrDataSize = Int(mapped.readUInt32(at: baseOffset + 260))
        let attrDataRangeOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 264))
        let attrDataRangeSize = Int(mapped.readUInt32(at: baseOffset + 268))

        let nestedTypesOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 144))
        let nestedTypesSize = Int(mapped.readUInt32(at: baseOffset + 148))
        let interfacesOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 152))
        let interfacesSize = Int(mapped.readUInt32(at: baseOffset + 156))

        let typeDefsOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + 160))
        let typeDefsSize = Int(mapped.readUInt32(at: baseOffset + 164))

        // Validate basic table bounds
        guard mapped.inRange(stringOff, stringSize),
              mapped.inRange(typeDefsOff, typeDefsSize),
              typeDefsSize >= 64 else {
            let magic = mapped.hexPreview(at: 0, length: 8)
            return ParsedMetadataResult(
                status: .encryptedOrProtected(
                    magicHex: magic,
                    entropy: mapped.shannonEntropy(),
                    reason: "Bảng offset bên trong metadata không hợp lệ (có thể đã bị xáo trộn/mã hoá từng phần)"
                ),
                version: 0, images: [], types: [], stringLiterals: [],
                typeFieldCounts: [], ctorPairsByImage: [:], totalMethodsCount: 0, filteredRealMoneyCount: 0
            )
        }

        func readMetaString(_ strIndex: UInt32) -> String {
            let idx = Int(strIndex)
            guard idx >= 0 && idx < stringSize && stringOff + idx < mapped.count else { return "" }
            return mapped.readCString(at: stringOff + idx, maxLength: min(512, stringSize - idx))
        }

        func isGoodIdent(_ idx: UInt32) -> Bool {
            if idx == 0 || Int(idx) >= stringSize { return false }
            let s = readMetaString(idx)
            guard !s.isEmpty else { return false }
            for u in s.unicodeScalars {
                let v = u.value
                if v < 0x20 || v > 0x7E { return false }
            }
            return true
        }

        func autoProbeStride(tableOff: Int, tableSize: Int, candidates: [Int]) -> Int {
            guard tableOff > 0 && tableSize > 0 && mapped.inRange(tableOff, tableSize) else { return 0 }
            var bestStride = 0
            var bestScore = -1.0
            for st in candidates {
                guard tableSize >= st else { continue }
                let rem = tableSize % st
                if rem != 0 && rem != 4 && rem != 8 { continue }
                let cnt = tableSize / st
                let step = max(1, cnt / 40)
                var hits = 0
                var tested = 0
                for i in stride(from: 0, to: cnt, by: step) where tested < 40 {
                    if isGoodIdent(mapped.readUInt32(at: tableOff + i * st)) { hits += 1 }
                    tested += 1
                }
                let score = tested > 0 ? (Double(hits) / Double(tested)) + (rem == 0 ? 0.05 : 0.0) : 0
                if score >= 0.40 && score > bestScore {
                    bestScore = score
                    bestStride = st
                }
            }
            return bestStride
        }

        // Probe Images table
        var imagesOff = 0
        var imagesSize = 0
        var imgStride = 0
        let imgHeaderCandidates = version >= 27 ? [168, 184, 176] : [184, 176, 168]
        for hOff in imgHeaderCandidates {
            let candOff = baseOffset + Int(mapped.readUInt32(at: baseOffset + hOff))
            let candSz = Int(mapped.readUInt32(at: baseOffset + hOff + 4))
            let candSt = autoProbeStride(tableOff: candOff, tableSize: candSz, candidates: [40, 36, 32, 44, 48])
            if candSt > 0 {
                let nm0 = readMetaString(mapped.readUInt32(at: candOff))
                if nm0.lowercased().hasSuffix(".dll") || imgStride == 0 {
                    imagesOff = candOff
                    imagesSize = candSz
                    imgStride = candSt
                    if nm0.lowercased().hasSuffix(".dll") { break }
                }
            }
        }

        let typeStride = autoProbeStride(
            tableOff: typeDefsOff,
            tableSize: typeDefsSize,
            candidates: version >= 27 ? [88, 92, 96, 100, 104, 108] : [100, 104, 92, 96, 88, 108]
        )
        let methodStride = autoProbeStride(
            tableOff: methodsOff,
            tableSize: methodsSize,
            candidates: version >= 29 ? [32, 36, 40, 44, 48, 52, 56] : [52, 48, 40, 32, 36, 44, 56]
        )
        let fieldStride = autoProbeStride(tableOff: fieldsOff, tableSize: fieldsSize, candidates: [12, 16])
        let resolvedFieldStride = fieldStride > 0 ? fieldStride : 12
        let paramStride = autoProbeStride(tableOff: parametersOff, tableSize: parametersSize, candidates: [12, 16])
        let resolvedParamStride = paramStride > 0 ? paramStride : 12
        let propStride = autoProbeStride(tableOff: propertiesOff, tableSize: propertiesSize, candidates: [24, 20, 28])
        let resolvedPropStride = propStride > 0 ? propStride : 24

        guard typeStride > 0 else {
            return ParsedMetadataResult(
                status: .encryptedOrProtected(
                    magicHex: mapped.hexPreview(at: 0, length: 8),
                    entropy: mapped.shannonEntropy(),
                    reason: "Không thể nhận diện cấu trúc bảng TypeDef trong global-metadata.dat"
                ),
                version: 0, images: [], types: [], stringLiterals: [],
                typeFieldCounts: [], ctorPairsByImage: [:], totalMethodsCount: 0, filteredRealMoneyCount: 0
            )
        }

        let totalTypes = typeDefsSize / typeStride
        let numMethods = (methodStride > 0 && mapped.inRange(methodsOff, methodsSize)) ? (methodsSize / methodStride) : 0
        let numFields = mapped.inRange(fieldsOff, fieldsSize) ? (fieldsSize / resolvedFieldStride) : 0
        let numParams = mapped.inRange(parametersOff, parametersSize) ? (parametersSize / resolvedParamStride) : 0
        let numProps = mapped.inRange(propertiesOff, propertiesSize) ? (propertiesSize / resolvedPropStride) : 0

        // Auto-detect TypeDef fieldShift (accounts for v24 vs v27 vs v29 header fields)
        var tdShift = max(0, typeStride - 88)
        if totalTypes > 0 {
            var bestShift = tdShift
            var bestScore = -1
            let candidates = Array(Set([max(0, typeStride - 88), 0, 4, 8, 12, 16])).filter { 84 + $0 + 4 <= typeStride }
            let tStep = max(1, totalTypes / 32)
            for sh in candidates {
                var score = 0
                for ti in stride(from: 0, to: min(totalTypes, tStep * 32), by: tStep) {
                    let tp = typeDefsOff + ti * typeStride
                    let fs = mapped.readInt32(at: tp + 32 + sh)
                    let ms = mapped.readInt32(at: tp + 36 + sh)
                    let mc = Int(mapped.readUInt16(at: tp + 64 + sh))
                    let fc = Int(mapped.readUInt16(at: tp + 68 + sh))
                    let tk = mapped.readUInt32(at: tp + 84 + sh)
                    if ((tk >> 24) & 0xFF) == 0x02 { score += 4 }
                    if (ms == -1 || (ms >= 0 && ms + mc <= numMethods)) && mc <= 4000 { score += 2 }
                    if (fs == -1 || (fs >= 0 && fs + fc <= numFields)) && fc <= 2000 { score += 2 }
                }
                if score > bestScore { bestScore = score; bestShift = sh }
            }
            tdShift = bestShift
        }

        // Auto-detect MethodDef token offset
        var mTokOff = methodStride == 32 ? 20 : max(16, methodStride - 12)
        if numMethods > 0 && methodStride >= 24 {
            var bestTok = mTokOff
            var bestHits = -1
            let mStep = max(1, numMethods / 32)
            for cand in stride(from: 16, through: methodStride - 12, by: 4) {
                var hits = 0
                for mi in stride(from: 0, to: min(numMethods, mStep * 32), by: mStep) {
                    let tk = mapped.readUInt32(at: methodsOff + mi * methodStride + cand)
                    if ((tk >> 24) & 0xFF) == 0x06 { hits += 1 }
                }
                if hits > bestHits { bestHits = hits; bestTok = cand }
            }
            mTokOff = bestTok
        }

        // Parse Images
        var images: [Il2CppImageInfo] = []
        if imgStride > 0 && mapped.inRange(imagesOff, imagesSize) {
            let numImages = imagesSize / imgStride
            images.reserveCapacity(numImages)
            for idx in 0..<numImages {
                let ip = imagesOff + idx * imgStride
                let name = readMetaString(mapped.readUInt32(at: ip))
                let typeStart = Int(mapped.readInt32(at: ip + 8))
                let typeCount = Int(mapped.readUInt32(at: ip + 12))
                let entryPt = mapped.readInt32(at: ip + 16)
                let token = imgStride >= 24 ? mapped.readUInt32(at: ip + 20) : 0
                let asmName = name.lowercased().hasSuffix(".dll") ? String(name.dropLast(4)) : name
                images.append(Il2CppImageInfo(
                    index: idx,
                    name: name,
                    typeStart: typeStart,
                    typeCount: typeCount,
                    entryPointIndex: entryPt,
                    token: token,
                    assemblyName: asmName
                ))
            }
        }
        if images.isEmpty {
            images.append(Il2CppImageInfo(
                index: 0,
                name: "Assembly-CSharp.dll",
                typeStart: 0,
                typeCount: totalTypes,
                entryPointIndex: -1,
                token: 0x2000_0001,
                assemblyName: "Assembly-CSharp"
            ))
        }

        // Map TypeDef -> ImageIndex
        var typeToImage = [Int](repeating: 0, count: totalTypes)
        for img in images {
            let start = max(0, img.typeStart)
            let end = min(totalTypes, start + max(0, img.typeCount))
            if start < end {
                for ti in start..<end { typeToImage[ti] = img.index }
            }
        }

        // Field & Parameter Default Values lookup
        var fieldDefaultMap: [Int: (typeIndex: Int32, dataOffset: Int)] = [:]
        if mapped.inRange(fieldDefValsOff, fieldDefValsSize) {
            let cnt = fieldDefValsSize / 12
            fieldDefaultMap.reserveCapacity(cnt)
            for i in 0..<cnt {
                let p = fieldDefValsOff + i * 12
                let fIdx = Int(mapped.readInt32(at: p))
                let tIdx = mapped.readInt32(at: p + 4)
                let dIdx = Int(mapped.readInt32(at: p + 8))
                if fIdx >= 0 && dIdx >= 0 {
                    fieldDefaultMap[fIdx] = (tIdx, dIdx)
                }
            }
        }

        var paramDefaultMap: [Int: (typeIndex: Int32, dataOffset: Int)] = [:]
        if mapped.inRange(paramDefValsOff, paramDefValsSize) {
            let cnt = paramDefValsSize / 12
            paramDefaultMap.reserveCapacity(cnt)
            for i in 0..<cnt {
                let p = paramDefValsOff + i * 12
                let pIdx = Int(mapped.readInt32(at: p))
                let tIdx = mapped.readInt32(at: p + 4)
                let dIdx = Int(mapped.readInt32(at: p + 8))
                if pIdx >= 0 && dIdx >= 0 {
                    paramDefaultMap[pIdx] = (tIdx, dIdx)
                }
            }
        }

        func decodeDefaultValue(typeEnum: UInt8, dataRel: Int) -> String? {
            guard mapped.inRange(defValDataOff, defValDataSize) else { return nil }
            var ptr = defValDataOff + dataRel
            guard ptr >= defValDataOff && ptr < defValDataOff + defValDataSize else { return nil }
            switch typeEnum {
            case 0x02: // BOOLEAN
                return mapped.readUInt8(at: ptr) != 0 ? "true" : "false"
            case 0x03: // CHAR
                let ch = mapped.readUInt16(at: ptr)
                return String(format: "'\\u%04X'", ch)
            case 0x04: // I1
                return "\(mapped.readInt8(at: ptr))"
            case 0x05: // U1
                return "\(mapped.readUInt8(at: ptr))"
            case 0x06: // I2
                return "\(mapped.readInt16(at: ptr))"
            case 0x07: // U2
                return "\(mapped.readUInt16(at: ptr))"
            case 0x08: // I4
                return "\(version >= 29 ? mapped.readCompressedInt32(at: &ptr) : mapped.readInt32(at: ptr))"
            case 0x09: // U4
                return "\(version >= 29 ? mapped.readCompressedUInt32(at: &ptr) : mapped.readUInt32(at: ptr))"
            case 0x0A: // I8
                return "\(mapped.readInt64(at: ptr))"
            case 0x0B: // U8
                return "\(mapped.readUInt64(at: ptr))"
            case 0x0C: // R4
                return String(format: "%g", mapped.readFloat(at: ptr))
            case 0x0D: // R8
                return String(format: "%g", mapped.readDouble(at: ptr))
            case 0x0E: // STRING
                let len: Int
                if version >= 29 {
                    let u = mapped.readCompressedUInt32(at: &ptr)
                    if u == UInt32.max { return "null" }
                    len = Int(u)
                } else {
                    let s = mapped.readInt32(at: ptr)
                    if s < 0 { return "null" }
                    len = Int(s)
                    ptr += 4
                }
                let text = mapped.readUTF8(at: ptr, length: min(len, 128))
                    .replacingOccurrences(of: "\"", with: "\\\"")
                    .replacingOccurrences(of: "\n", with: "\\n")
                return "\"\(text)\""
            default:
                return nil
            }
        }

        // First pass over TypeDefs to gather quick names + fieldCounts + .ctor pairs
        var rawTypeNames = [String](repeating: "", count: totalTypes)
        var rawNamespaces = [String](repeating: "", count: totalTypes)
        var typeFieldCounts = [UInt16](repeating: 0, count: totalTypes)
        var ctorPairsByImage: [String: [(typeDefIndex: Int, methodId: Int)]] = [:]

        for ti in 0..<totalTypes {
            let tp = typeDefsOff + ti * typeStride
            let name = readMetaString(mapped.readUInt32(at: tp))
            let ns = readMetaString(mapped.readUInt32(at: tp + 4))
            rawTypeNames[ti] = name.isEmpty ? "Type_\(ti)" : name
            rawNamespaces[ti] = ns
            let fc = mapped.readUInt16(at: tp + 68 + tdShift)
            typeFieldCounts[ti] = fc

            if methodStride > 0 && numMethods > 0 {
                let mStart = Int(mapped.readInt32(at: tp + 36 + tdShift))
                let mCount = Int(mapped.readUInt16(at: tp + 64 + tdShift))
                if mStart >= 0 && mStart + mCount <= numMethods {
                    let imgName = images[typeToImage[ti]].name
                    for mi in mStart..<(mStart + mCount) {
                        let mp = methodsOff + mi * methodStride
                        let mName = readMetaString(mapped.readUInt32(at: mp))
                        if mName == ".ctor" {
                            let tok = mapped.readUInt32(at: mp + mTokOff)
                            let rid = Int(tok & 0x00FF_FFFF)
                            let mId = version >= 25 && rid > 0 ? (rid - 1) : mi
                            ctorPairsByImage[imgName, default: []].append((ti, mId))
                            break
                        }
                    }
                }
            }
        }

        // Build TypeResolver helper
        let il2cppTypes = registration?.il2cppTypes ?? []
        let resolver = Il2CppTypeResolver(
            il2cppTypes: il2cppTypes,
            typeNames: rawTypeNames,
            typeNamespaces: rawNamespaces
        )

        // Parse CustomAttributes (optional, fast lookup by token)
        var attrTypeIndicesByToken: [UInt32: [Int32]] = [:]
        if options.restoreAttributes {
            if version < 29, mapped.inRange(attrInfoOff, attrInfoSize), mapped.inRange(attrTypesOff, attrTypesSize) {
                let cnt = attrInfoSize / 8
                for i in 0..<cnt {
                    let p = attrInfoOff + i * 8
                    let token = mapped.readUInt32(at: p)
                    let start = Int(mapped.readInt32(at: p + 4))
                    let count = (i + 1 < cnt) ? (Int(mapped.readInt32(at: p + 12)) - start) : 0
                    if token != 0 && start >= 0 && count > 0 && count < 64 && attrTypesOff + (start + count) * 4 <= attrTypesOff + attrTypesSize {
                        var arr: [Int32] = []
                        for k in 0..<count {
                            arr.append(mapped.readInt32(at: attrTypesOff + (start + k) * 4))
                        }
                        attrTypeIndicesByToken[token] = arr
                    }
                }
            } else if version >= 29, mapped.inRange(attrDataRangeOff, attrDataRangeSize), mapped.inRange(attrDataOff, attrDataSize) {
                let cnt = attrDataRangeSize / 8
                for i in 0..<max(0, cnt - 1) {
                    let p = attrDataRangeOff + i * 8
                    let token = mapped.readUInt32(at: p)
                    let startOff = Int(mapped.readUInt32(at: p + 4))
                    let nextOff = Int(mapped.readUInt32(at: p + 12))
                    if token != 0 && startOff >= 0 && nextOff > startOff && attrDataOff + nextOff <= attrDataOff + attrDataSize {
                        var cur = attrDataOff + startOff
                        let attrCount = Int(mapped.readCompressedUInt32(at: &cur))
                        if attrCount > 0 && attrCount <= 32 {
                            var ctorIndices: [Int32] = []
                            for _ in 0..<attrCount where cur + 4 <= attrDataOff + nextOff {
                                let ctorIdx = Int32(mapped.readUInt32(at: cur))
                                cur += 4
                                ctorIndices.append(ctorIdx)
                            }
                            if !ctorIndices.isEmpty {
                                attrTypeIndicesByToken[token] = ctorIndices
                            }
                        }
                    }
                }
            }
        }

        func resolveAttributes(forToken token: UInt32) -> [Il2CppAttributeInfo] {
            guard options.restoreAttributes, let list = attrTypeIndicesByToken[token] else { return [] }
            var res: [Il2CppAttributeInfo] = []
            for rawIdx in list {
                if version >= 29 {
                    // rawIdx is methodIndex of the attribute .ctor
                    let mi = Int(rawIdx)
                    if mi >= 0 && mi < numMethods {
                        let mp = methodsOff + mi * methodStride
                        let declIdx = Int(mapped.readInt32(at: mp + 4))
                        let attrName = (declIdx >= 0 && declIdx < totalTypes) ? rawTypeNames[declIdx] : "CustomAttribute"
                        res.append(Il2CppAttributeInfo(name: attrName, typeIndex: Int32(declIdx), ctorMethodIndex: rawIdx))
                    }
                } else {
                    let name = resolver.resolveTypeName(typeIndex: rawIdx)
                    res.append(Il2CppAttributeInfo(name: name, typeIndex: rawIdx, ctorMethodIndex: -1))
                }
            }
            return res
        }

        // Parse StringLiterals
        var stringLiterals: [Il2CppStringLiteral] = []
        var filteredCount = 0
        if mapped.inRange(stringLiteralOff, stringLiteralSize), mapped.inRange(stringLiteralDataOff, stringLiteralDataSize) {
            let slCount = stringLiteralSize / 8
            stringLiterals.reserveCapacity(min(slCount, 50_000))
            for i in 0..<slCount {
                let p = stringLiteralOff + i * 8
                let len = Int(mapped.readUInt32(at: p))
                let dataIdx = Int(mapped.readUInt32(at: p + 4))
                guard len >= 0 && dataIdx >= 0 && stringLiteralDataOff + dataIdx + len <= stringLiteralDataOff + stringLiteralDataSize else {
                    continue
                }
                let val = mapped.readUTF8(at: stringLiteralDataOff + dataIdx, length: min(len, 2048))
                if SafetyGuard.isRealMoneyBlocked(val) {
                    filteredCount += 1
                    continue
                }
                let addr: UInt64
                if let regAddrs = registration?.stringLiteralAddresses, i < regAddrs.count {
                    addr = regAddrs[i]
                } else {
                    addr = 0
                }
                stringLiterals.append(Il2CppStringLiteral(index: i, value: val, address: addr))
            }
        }

        // Parse Full TypeDefs, Fields, Properties, Methods
        var parsedTypes: [Il2CppTypeInfo] = []
        parsedTypes.reserveCapacity(totalTypes)

        for ti in 0..<totalTypes {
            let tp = typeDefsOff + ti * typeStride
            let img = images[typeToImage[ti]]
            let typeName = rawTypeNames[ti]
            let ns = rawNamespaces[ti]
            let fullName = ns.isEmpty ? typeName : "\(ns).\(typeName)"

            if SafetyGuard.isRealMoneyBlocked(fullName) {
                filteredCount += 1
                continue
            }

            let parentTypeIdx = mapped.readInt32(at: tp + 16)
            let declaringTypeIdx = mapped.readInt32(at: tp + 20)
            let fieldStart = Int(mapped.readInt32(at: tp + 32 + tdShift))
            let methodStart = Int(mapped.readInt32(at: tp + 36 + tdShift))
            let propStart = Int(mapped.readInt32(at: tp + 44 + tdShift))
            let ifaceStart = Int(mapped.readInt32(at: tp + 48 + tdShift))
            let flags = mapped.readUInt32(at: tp + 60 + tdShift)
            let methodCount = Int(mapped.readUInt16(at: tp + 64 + tdShift))
            let propCount = Int(mapped.readUInt16(at: tp + 66 + tdShift))
            let fieldCount = Int(mapped.readUInt16(at: tp + 68 + tdShift))
            let ifaceCount = Int(mapped.readUInt16(at: tp + 72 + tdShift))
            let token = mapped.readUInt32(at: tp + 84 + tdShift)

            let parentName = parentTypeIdx >= 0 ? resolver.resolveTypeName(typeIndex: parentTypeIdx) : nil
            let isInterface = (flags & 0x0000_0020) != 0
            let isAbstract = (flags & 0x0000_0080) != 0
            let isSealed = (flags & 0x0000_0100) != 0
            let isValueType = (parentName == "System.ValueType" || parentName == "ValueType" || parentName == "System.Enum" || parentName == "Enum")
            let isEnum = (parentName == "System.Enum" || parentName == "Enum")

            var ifaceIndices: [Int32] = []
            var ifaceNames: [String] = []
            if ifaceStart >= 0 && ifaceCount > 0 && mapped.inRange(interfacesOff, interfacesSize) {
                let iEnd = min(interfacesSize / 4, ifaceStart + ifaceCount)
                for ii in ifaceStart..<iEnd {
                    let it = mapped.readInt32(at: interfacesOff + ii * 4)
                    ifaceIndices.append(it)
                    ifaceNames.append(resolver.resolveTypeName(typeIndex: it))
                }
            }

            // Fields
            var parsedFields: [Il2CppFieldInfo] = []
            if options.includeFields && fieldStart >= 0 && fieldCount > 0 && fieldStart + fieldCount <= numFields {
                let realOffsets = (registration != nil && ti < registration!.fieldOffsetsByType.count)
                    ? registration!.fieldOffsetsByType[ti]
                    : []
                let ctorMap = registration?.ctorFieldInits[ti] ?? [:]

                for fi in 0..<fieldCount {
                    let fGlobal = fieldStart + fi
                    let fp = fieldsOff + fGlobal * resolvedFieldStride
                    let fName = readMetaString(mapped.readUInt32(at: fp))
                    let fTypeIdx = mapped.readInt32(at: fp + 4)
                    let fTok = resolvedFieldStride >= 12 ? mapped.readUInt32(at: fp + 8) : UInt32(0x0400_0000 | (fGlobal + 1))

                    if SafetyGuard.isRealMoneyBlocked("\(fullName).\(fName)") {
                        filteredCount += 1
                        continue
                    }

                    let tMeta = (fTypeIdx >= 0 && Int(fTypeIdx) < il2cppTypes.count) ? il2cppTypes[Int(fTypeIdx)] : nil
                    let tAttrs = tMeta?.attrs ?? 0
                    let isStatic = (tAttrs & 0x0010) != 0
                    let isConst = (tAttrs & 0x0040) != 0
                    let fTypeName = resolver.resolveTypeName(typeIndex: fTypeIdx)

                    var offsetVal: Int32 = fi < realOffsets.count ? realOffsets[fi] : -1
                    if isValueType && !isEnum && !isStatic && offsetVal >= 0x10 {
                        offsetVal -= 0x10
                    }

                    var defVal: String? = nil
                    if let dvEntry = fieldDefaultMap[fGlobal] {
                        let dvEnum = (dvEntry.typeIndex >= 0 && Int(dvEntry.typeIndex) < il2cppTypes.count)
                            ? il2cppTypes[Int(dvEntry.typeIndex)].typeEnum
                            : (tMeta?.typeEnum ?? 0)
                        defVal = decodeDefaultValue(typeEnum: dvEnum, dataRel: dvEntry.dataOffset)
                    }

                    let ctorInit = offsetVal >= 0 ? ctorMap[offsetVal] : nil
                    let fAttrs = resolveAttributes(forToken: fTok)

                    parsedFields.append(Il2CppFieldInfo(
                        index: fGlobal,
                        name: fName.isEmpty ? "field_\(fGlobal)" : fName,
                        typeIndex: fTypeIdx,
                        typeName: fTypeName,
                        typeAttrs: tAttrs,
                        token: fTok,
                        offset: offsetVal,
                        isStatic: isStatic,
                        isConst: isConst,
                        defaultValue: defVal,
                        ctorInitValue: ctorInit,
                        attributes: fAttrs
                    ))
                }
            }

            // Methods
            var parsedMethods: [Il2CppMethodInfo] = []
            if methodStart >= 0 && methodCount > 0 && methodStart + methodCount <= numMethods && methodStride > 0 {
                let modRVAs = registration?.codeGenModules[img.name]?.methodRVAs
                let legRVAs = registration?.legacyMethodRVAs ?? []
                let baseVM = macho?.baseVM ?? 0x1_0000_0000

                for mi in 0..<methodCount {
                    let mGlobal = methodStart + mi
                    let mp = methodsOff + mGlobal * methodStride
                    let mName = readMetaString(mapped.readUInt32(at: mp))
                    let retTypeIdx = mapped.readInt32(at: mp + 8)
                    let paramStart = Int(mapped.readInt32(at: mp + 12))
                    let mTok = mapped.readUInt32(at: mp + mTokOff)
                    let mFlags = mapped.readUInt16(at: mp + mTokOff + 4)
                    let mIFlags = mapped.readUInt16(at: mp + mTokOff + 6)
                    let mSlot = mapped.readUInt16(at: mp + mTokOff + 8)
                    let mParamCount = Int(mapped.readUInt16(at: mp + mTokOff + 10))

                    if SafetyGuard.isRealMoneyBlocked("\(fullName).\(mName)") {
                        filteredCount += 1
                        continue
                    }

                    var params: [Il2CppParameterInfo] = []
                    if paramStart >= 0 && mParamCount > 0 && paramStart + mParamCount <= numParams {
                        for pi in 0..<mParamCount {
                            let pGlobal = paramStart + pi
                            let pp = parametersOff + pGlobal * resolvedParamStride
                            let pName = readMetaString(mapped.readUInt32(at: pp))
                            let pTok = mapped.readUInt32(at: pp + 4)
                            let pTypeIdx = mapped.readInt32(at: pp + 8)
                            let pTypeMeta = (pTypeIdx >= 0 && Int(pTypeIdx) < il2cppTypes.count) ? il2cppTypes[Int(pTypeIdx)] : nil
                            let pAttrs = pTypeMeta?.attrs ?? 0
                            var pDef: String? = nil
                            if let pvEntry = paramDefaultMap[pGlobal] {
                                let pvEnum = (pvEntry.typeIndex >= 0 && Int(pvEntry.typeIndex) < il2cppTypes.count)
                                    ? il2cppTypes[Int(pvEntry.typeIndex)].typeEnum
                                    : (pTypeMeta?.typeEnum ?? 0)
                                pDef = decodeDefaultValue(typeEnum: pvEnum, dataRel: pvEntry.dataOffset)
                            }
                            params.append(Il2CppParameterInfo(
                                name: pName.isEmpty ? "arg\(pi)" : pName,
                                token: pTok,
                                typeIndex: pTypeIdx,
                                typeName: resolver.resolveTypeName(typeIndex: pTypeIdx),
                                defaultValue: pDef,
                                isOut: (pAttrs & 0x0002) != 0,
                                isIn: (pAttrs & 0x0001) != 0,
                                isRef: pTypeMeta?.isByRef ?? false
                            ))
                        }
                    }

                    let rid = Int(mTok & 0x00FF_FFFF)
                    let rva: UInt64
                    if let modRVAs = modRVAs, version >= 25, rid > 0, rid - 1 < modRVAs.count {
                        rva = modRVAs[rid - 1]
                    } else if mGlobal < legRVAs.count {
                        rva = legRVAs[mGlobal]
                    } else {
                        rva = 0
                    }

                    let va = rva > 0 ? (baseVM + rva) : 0
                    let fOff = (rva > 0 && macho != nil) ? UInt64(macho!.vmToSliceRelOffset(va) ?? 0) : 0
                    var armSem = ""
                    if options.deepMachOScan && rva > 0, let macho = macho, let sliceOff = macho.vmToSliceRelOffset(va) {
                        armSem = macho.analyzeArm64FunctionValue(atSliceRelOffset: sliceOff)
                    }

                    parsedMethods.append(Il2CppMethodInfo(
                        index: mGlobal,
                        name: mName.isEmpty ? "Method_\(mGlobal)" : mName,
                        returnTypeIndex: retTypeIdx,
                        returnTypeName: resolver.resolveTypeName(typeIndex: retTypeIdx),
                        parameters: params,
                        token: mTok,
                        flags: mFlags,
                        iflags: mIFlags,
                        slot: mSlot,
                        rva: rva,
                        va: va,
                        fileOffset: fOff,
                        arm64Semantics: armSem,
                        attributes: resolveAttributes(forToken: mTok)
                    ))
                }
            }

            // Properties
            var parsedProps: [Il2CppPropertyInfo] = []
            if options.includeProperties && propStart >= 0 && propCount > 0 && propStart + propCount <= numProps {
                let methodByRelIdx = Dictionary(uniqueKeysWithValues: parsedMethods.enumerated().map { ($0.element.index - max(0, methodStart), $0.element) })
                for pri in 0..<propCount {
                    let pGlobal = propStart + pri
                    let pp = propertiesOff + pGlobal * resolvedPropStride
                    let pName = readMetaString(mapped.readUInt32(at: pp))
                    let getRel = mapped.readInt32(at: pp + 4)
                    let setRel = mapped.readInt32(at: pp + 8)
                    let pAttrs = mapped.readUInt32(at: pp + 12)
                    let pTok = resolvedPropStride >= 20 ? mapped.readUInt32(at: pp + 16) : UInt32(0x1700_0000 | (pGlobal + 1))

                    if SafetyGuard.isRealMoneyBlocked("\(fullName).\(pName)") {
                        filteredCount += 1
                        continue
                    }

                    var pTypeName = "object"
                    if getRel >= 0, let gm = methodByRelIdx[Int(getRel)] {
                        pTypeName = gm.returnTypeName
                    } else if setRel >= 0, let sm = methodByRelIdx[Int(setRel)], let lastParam = sm.parameters.last {
                        pTypeName = lastParam.typeName
                    }

                    parsedProps.append(Il2CppPropertyInfo(
                        index: pGlobal,
                        name: pName.isEmpty ? "Property_\(pGlobal)" : pName,
                        getMethodIndex: getRel >= 0 ? Int32(methodStart) + getRel : -1,
                        setMethodIndex: setRel >= 0 ? Int32(methodStart) + setRel : -1,
                        typeName: pTypeName,
                        attrs: pAttrs,
                        token: pTok,
                        attributes: resolveAttributes(forToken: pTok)
                    ))
                }
            }

            parsedTypes.append(Il2CppTypeInfo(
                typeDefIndex: ti,
                imageIndex: img.index,
                assemblyName: img.name,
                namespace: ns,
                name: typeName,
                fullName: fullName,
                flags: flags,
                token: token,
                parentTypeIndex: parentTypeIdx,
                parentName: parentName,
                declaringTypeIndex: declaringTypeIdx,
                interfaceTypeIndices: ifaceIndices,
                interfaceNames: ifaceNames,
                isValueType: isValueType,
                isEnum: isEnum,
                isInterface: isInterface,
                isAbstract: isAbstract,
                isSealed: isSealed,
                fields: parsedFields,
                methods: options.includeMethods ? parsedMethods : [],
                properties: parsedProps,
                attributes: resolveAttributes(forToken: token)
            ))
        }

        _ = nestedTypesOff; _ = nestedTypesSize
        log("[IL2CPP Metadata] Phiên bản v\(version): \(images.count) Assemblies · \(parsedTypes.count) Types · \(stringLiterals.count) StringLiterals (đã lọc \(filteredCount) mục tiền thật).")

        return ParsedMetadataResult(
            status: .valid(version: version, containerOffset: UInt64(baseOffset)),
            version: version,
            images: images,
            types: parsedTypes,
            stringLiterals: stringLiterals,
            typeFieldCounts: typeFieldCounts,
            ctorPairsByImage: ctorPairsByImage,
            totalMethodsCount: numMethods,
            filteredRealMoneyCount: filteredCount
        )
    }

    /// Lightweight Phase-1 header peek used before Mach-O registration scan.
    public static func quickPeek(url: URL) throws -> (
        status: MetadataProtectionStatus,
        version: Int32,
        imageNames: [String],
        typeFieldCounts: [UInt16],
        totalTypes: Int,
        totalMethods: Int,
        ctorPairsByImage: [String: [(typeDefIndex: Int, methodId: Int)]]
    ) {
        let parsed = try inspectAndParse(url: url, registration: nil, macho: nil, options: DumpOptions(
            generateDumpCS: false, generateStructAndJSON: false, generateDummyDLLs: false,
            includeMethods: false, includeFields: false, includeProperties: false,
            restoreAttributes: false, writeFieldOffsets: false, writeMethodOffsets: false,
            writeTypeDefIndexes: false, restoreDummyDllTokens: false, workerThreads: 1,
            deepMachOScan: false, autoExportZip: false
        ))
        return (
            parsed.status,
            parsed.version,
            parsed.images.map { $0.name },
            parsed.typeFieldCounts,
            parsed.typeFieldCounts.count,
            parsed.totalMethodsCount,
            parsed.ctorPairsByImage
        )
    }
}
