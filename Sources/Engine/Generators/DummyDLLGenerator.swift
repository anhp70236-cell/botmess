import Foundation

/// Genuine ECMA-335 PE32 + CLI Metadata generator producing managed Dummy DLLs compatible with dnSpy / ILSpy.
public enum DummyDLLGenerator {
    public static func generateAll(
        to directoryURL: URL,
        images: [Il2CppImageInfo],
        types: [Il2CppTypeInfo],
        options: DumpOptions,
        log: (String) -> Void
    ) throws -> Int {
        let fm = FileManager.default
        if !fm.fileExists(atPath: directoryURL.path) {
            try fm.createDirectory(at: directoryURL, withIntermediateDirectories: true)
        }

        let dummyRuntimeURL = directoryURL.appendingPathComponent("Il2CppDummyDll.dll")
        let dummyBytes = buildIl2CppDummySupportDLL()
        try dummyBytes.write(to: dummyRuntimeURL, options: .atomic)

        var typesByImage: [Int: [Il2CppTypeInfo]] = [:]
        for t in types {
            typesByImage[t.imageIndex, default: []].append(t)
        }

        let workers = max(1, min(4, options.workerThreads))
        let sem = DispatchSemaphore(value: workers)
        let queue = DispatchQueue(label: "com.botmess.dummydll", attributes: .concurrent)
        let group = DispatchGroup()
        let lock = NSLock()
        var generatedCount = 1
        var firstError: Error?

        for img in images {
            let imgTypes = typesByImage[img.index] ?? []
            sem.wait()
            group.enter()
            queue.async {
                defer {
                    sem.signal()
                    group.leave()
                }
                do {
                    let safeFileName = img.name.lowercased().hasSuffix(".dll") ? img.name : "\(img.name).dll"
                    let cleanName = safeFileName.replacingOccurrences(of: "/", with: "_")
                    let outURL = directoryURL.appendingPathComponent(cleanName)
                    let peData = buildAssemblyDLL(image: img, types: imgTypes, options: options)
                    try peData.write(to: outURL, options: .atomic)
                    lock.lock()
                    generatedCount += 1
                    lock.unlock()
                } catch {
                    lock.lock()
                    if firstError == nil { firstError = error }
                    lock.unlock()
                }
            }
        }
        group.wait()
        if let err = firstError { throw err }

        log("[DummyDLL] Đã tạo \(generatedCount) tệp .dll chuẩn ECMA-335 (hỗ trợ dnSpy / ILSpy).")
        return generatedCount
    }

    // MARK: - ECMA-335 Builder Core

    private final class MetadataHeaps {
        var strings = Data([0])
        var stringCache: [String: UInt32] = ["": 0]
        var us = Data([0])
        var guid = Data()
        var blob = Data([0])
        var blobCache: [Data: UInt32] = [Data(): 0]

        func addString(_ s: String) -> UInt32 {
            if let existing = stringCache[s] { return existing }
            let idx = UInt32(strings.count)
            strings.append(contentsOf: s.utf8)
            strings.append(0)
            stringCache[s] = idx
            return idx
        }

        func addGUID(_ bytes: [UInt8]) -> UInt32 {
            let idx = UInt32((guid.count / 16) + 1)
            guid.append(contentsOf: bytes.prefix(16))
            if bytes.count < 16 {
                guid.append(contentsOf: [UInt8](repeating: 0, count: 16 - bytes.count))
            }
            return idx
        }

        func addBlob(_ payload: [UInt8]) -> UInt32 {
            let key = Data(payload)
            if let existing = blobCache[key] { return existing }
            let idx = UInt32(blob.count)
            let len = payload.count
            if len < 0x80 {
                blob.append(UInt8(len))
            } else if len < 0x4000 {
                blob.append(UInt8((len >> 8) | 0x80))
                blob.append(UInt8(len & 0xFF))
            } else {
                blob.append(UInt8((len >> 24) | 0xC0))
                blob.append(UInt8((len >> 16) & 0xFF))
                blob.append(UInt8((len >> 8) & 0xFF))
                blob.append(UInt8(len & 0xFF))
            }
            blob.append(contentsOf: payload)
            blobCache[key] = idx
            return idx
        }
    }

    private struct TypeRefRow { var scope: UInt32; var name: UInt32; var ns: UInt32 }
    private struct TypeDefRow { var flags: UInt32; var name: UInt32; var ns: UInt32; var extends: UInt32; var fieldList: UInt32; var methodList: UInt32 }
    private struct FieldRow { var flags: UInt16; var name: UInt32; var sig: UInt32 }
    private struct MethodDefRow { var rva: UInt32; var implFlags: UInt16; var flags: UInt16; var name: UInt32; var sig: UInt32; var paramList: UInt32 }
    private struct ParamRow { var flags: UInt16; var seq: UInt16; var name: UInt32 }
    private struct MemberRefRow { var klass: UInt32; var name: UInt32; var sig: UInt32 }
    private struct ConstantRow { var type: UInt8; var parent: UInt32; var value: UInt32 }
    private struct CustomAttrRow { var parent: UInt32; var type: UInt32; var value: UInt32 }
    private struct PropertyMapRow { var parent: UInt32; var propList: UInt32 }
    private struct PropertyRow { var flags: UInt16; var name: UInt32; var sig: UInt32 }

    private static func buildIl2CppDummySupportDLL() -> Data {
        let img = Il2CppImageInfo(
            index: 0,
            name: "Il2CppDummyDll.dll",
            typeStart: 0,
            typeCount: 0,
            entryPointIndex: -1,
            token: 0x2000_0001,
            assemblyName: "Il2CppDummyDll"
        )
        let dummyAttrs: [(String, [(String, String)])] = [
            ("TokenAttribute", [("Token", "string")]),
            ("AddressAttribute", [("RVA", "string"), ("Offset", "string"), ("VA", "string"), ("Slot", "string")]),
            ("FieldOffsetAttribute", [("Offset", "string")]),
            ("AttributeAttribute", [("Name", "string"), ("RVA", "string"), ("Offset", "string")])
        ]
        var types: [Il2CppTypeInfo] = []
        for (idx, pair) in dummyAttrs.enumerated() {
            let fields = pair.1.enumerated().map { (fi, fp) in
                Il2CppFieldInfo(
                    index: fi, name: fp.0, typeIndex: -1, typeName: fp.1,
                    typeAttrs: 0x0006, token: UInt32(0x0400_0001 + fi), offset: Int32(0x10 + fi * 8),
                    isStatic: false, isConst: false, defaultValue: nil, ctorInitValue: nil, attributes: []
                )
            }
            let ctor = Il2CppMethodInfo(
                index: idx, name: ".ctor", returnTypeIndex: -1, returnTypeName: "void",
                genericParamNames: [],
                parameters: [], token: UInt32(0x0600_0001 + idx), flags: 0x1886, iflags: 0,
                slot: 0xFFFF, rva: 0, va: 0, fileOffset: 0, arm64Semantics: "", attributes: []
            )
            types.append(Il2CppTypeInfo(
                typeDefIndex: idx, imageIndex: 0, assemblyName: "Il2CppDummyDll.dll",
                namespace: "Il2CppDummyDll", name: pair.0, fullName: "Il2CppDummyDll.\(pair.0)",
                genericParamNames: [],
                flags: 0x0010_0001, token: UInt32(0x0200_0002 + idx),
                parentTypeIndex: -1, parentName: "System.Attribute", declaringTypeIndex: -1,
                declaringTypeName: nil, nestedTypeNames: [],
                interfaceTypeIndices: [], interfaceNames: [],
                isValueType: false, isEnum: false, isInterface: false, isAbstract: false, isSealed: false,
                fields: fields, methods: [ctor], properties: [], events: [], attributes: []
            ))
        }
        return buildAssemblyDLL(image: img, types: types, options: DumpOptions(
            restoreAttributes: false, writeFieldOffsets: false, writeMethodOffsets: false, restoreDummyDllTokens: false
        ), isSupportDummyDll: true)
    }

    private static func buildAssemblyDLL(
        image: Il2CppImageInfo,
        types: [Il2CppTypeInfo],
        options: DumpOptions,
        isSupportDummyDll: Bool = false
    ) -> Data {
        let heaps = MetadataHeaps()
        let mvidIdx = heaps.addGUID(deterministicGUID(for: image.name))

        let asmNameStr = image.assemblyName.isEmpty ? "Assembly" : image.assemblyName
        let modNameIdx = heaps.addString(image.name.isEmpty ? "\(asmNameStr).dll" : image.name)
        let asmNameIdx = heaps.addString(asmNameStr)

        let mscorlibRefIdx: UInt32 = 1
        let dummyRefIdx: UInt32 = 2
        let mscorlibNameIdx = heaps.addString("mscorlib")
        let dummyDllNameIdx = heaps.addString("Il2CppDummyDll")

        var typeRefs: [TypeRefRow] = []
        var typeRefIndexMap: [String: UInt32] = [:]
        func ensureTypeRef(scope: UInt32, ns: String, name: String) -> UInt32 {
            let key = "\(scope):\(ns).\(name)"
            if let existing = typeRefIndexMap[key] { return existing }
            let idx = UInt32(typeRefs.count + 1)
            typeRefs.append(TypeRefRow(scope: scope, name: heaps.addString(name), ns: heaps.addString(ns)))
            typeRefIndexMap[key] = idx
            return idx
        }

        let objTypeRef = ensureTypeRef(scope: (mscorlibRefIdx << 2) | 2, ns: "System", name: "Object")
        let valTypeRef = ensureTypeRef(scope: (mscorlibRefIdx << 2) | 2, ns: "System", name: "ValueType")
        let enumTypeRef = ensureTypeRef(scope: (mscorlibRefIdx << 2) | 2, ns: "System", name: "Enum")
        let attrTypeRef = ensureTypeRef(scope: (mscorlibRefIdx << 2) | 2, ns: "System", name: "Attribute")

        var tokenAttrCtorMemberRef: UInt32 = 0
        var addrAttrCtorMemberRef: UInt32 = 0
        var fieldOffAttrCtorMemberRef: UInt32 = 0
        var attrAttrCtorMemberRef: UInt32 = 0
        var memberRefs: [MemberRefRow] = []

        let voidCtorSig = heaps.addBlob([0x20, 0x00, 0x01])

        if !isSupportDummyDll {
            let dummyScope = (dummyRefIdx << 2) | 2
            let tokTR = ensureTypeRef(scope: dummyScope, ns: "Il2CppDummyDll", name: "TokenAttribute")
            let adrTR = ensureTypeRef(scope: dummyScope, ns: "Il2CppDummyDll", name: "AddressAttribute")
            let offTR = ensureTypeRef(scope: dummyScope, ns: "Il2CppDummyDll", name: "FieldOffsetAttribute")
            let attTR = ensureTypeRef(scope: dummyScope, ns: "Il2CppDummyDll", name: "AttributeAttribute")
            let ctorName = heaps.addString(".ctor")
            memberRefs.append(MemberRefRow(klass: (tokTR << 3) | 1, name: ctorName, sig: voidCtorSig))
            tokenAttrCtorMemberRef = UInt32(memberRefs.count)
            memberRefs.append(MemberRefRow(klass: (adrTR << 3) | 1, name: ctorName, sig: voidCtorSig))
            addrAttrCtorMemberRef = UInt32(memberRefs.count)
            memberRefs.append(MemberRefRow(klass: (offTR << 3) | 1, name: ctorName, sig: voidCtorSig))
            fieldOffAttrCtorMemberRef = UInt32(memberRefs.count)
            memberRefs.append(MemberRefRow(klass: (attTR << 3) | 1, name: ctorName, sig: voidCtorSig))
            attrAttrCtorMemberRef = UInt32(memberRefs.count)
        }

        var typeDefs: [TypeDefRow] = []
        var fieldsTable: [FieldRow] = []
        var methodsTable: [MethodDefRow] = []
        var paramsTable: [ParamRow] = []
        var constantsTable: [ConstantRow] = []
        var propMaps: [PropertyMapRow] = []
        var propsTable: [PropertyRow] = []
        var customAttrs: [CustomAttrRow] = []

        typeDefs.append(TypeDefRow(
            flags: 0,
            name: heaps.addString("<Module>"),
            ns: 0,
            extends: 0,
            fieldList: 1,
            methodList: 1
        ))

        let ilBodyRVA: UInt32 = 0x2050

        func encodeElemType(_ csType: String) -> UInt8 {
            switch csType {
            case "void": return 0x01
            case "bool": return 0x02
            case "char": return 0x03
            case "sbyte": return 0x04
            case "byte": return 0x05
            case "short": return 0x06
            case "ushort": return 0x07
            case "int": return 0x08
            case "uint": return 0x09
            case "long": return 0x0A
            case "ulong": return 0x0B
            case "float": return 0x0C
            case "double": return 0x0D
            case "string", "System.String": return 0x0E
            case "System.IntPtr", "IntPtr": return 0x18
            case "System.UIntPtr", "UIntPtr": return 0x19
            default: return 0x1C
            }
        }

        func buildNamedArgAttrBlob(_ args: [(String, String)]) -> UInt32 {
            var b: [UInt8] = [0x01, 0x00]
            let cnt = UInt16(args.count)
            b.append(UInt8(cnt & 0xFF))
            b.append(UInt8((cnt >> 8) & 0xFF))
            for (name, val) in args {
                b.append(0x53) // FIELD
                b.append(0x0E) // STRING
                appendSerString(&b, name)
                appendSerString(&b, val)
            }
            return heaps.addBlob(b)
        }

        for t in types {
            let tdRowIndex = UInt32(typeDefs.count + 1)
            let fieldListStart = UInt32(fieldsTable.count + 1)
            let methodListStart = UInt32(methodsTable.count + 1)

            let extendsCoded: UInt32
            if t.isInterface {
                extendsCoded = 0
            } else if t.isEnum {
                extendsCoded = (enumTypeRef << 2) | 1
            } else if t.isValueType {
                extendsCoded = (valTypeRef << 2) | 1
            } else if t.parentName == "System.Attribute" {
                extendsCoded = (attrTypeRef << 2) | 1
            } else {
                extendsCoded = (objTypeRef << 2) | 1
            }

            let tdFlags = t.isInterface ? (t.flags | 0x20) : (t.flags == 0 ? 0x0010_0001 : t.flags)
            typeDefs.append(TypeDefRow(
                flags: tdFlags,
                name: heaps.addString(t.name),
                ns: heaps.addString(t.namespace),
                extends: extendsCoded,
                fieldList: fieldListStart,
                methodList: methodListStart
            ))

            if !isSupportDummyDll && options.restoreDummyDllTokens && t.token != 0 {
                let blobIdx = buildNamedArgAttrBlob([("Token", String(format: "0x%X", t.token))])
                customAttrs.append(CustomAttrRow(
                    parent: (tdRowIndex << 5) | 3,
                    type: (tokenAttrCtorMemberRef << 3) | 3,
                    value: blobIdx
                ))
            }
            if !isSupportDummyDll && options.restoreAttributes {
                for attr in t.attributes {
                    let blobIdx = buildNamedArgAttrBlob([("Name", attr.name)])
                    customAttrs.append(CustomAttrRow(
                        parent: (tdRowIndex << 5) | 3,
                        type: (attrAttrCtorMemberRef << 3) | 3,
                        value: blobIdx
                    ))
                }
            }

            for f in t.fields {
                let fRowIdx = UInt32(fieldsTable.count + 1)
                let fSig = heaps.addBlob([0x06, encodeElemType(f.typeName)])
                var fFlags: UInt16 = f.typeAttrs != 0 ? (f.typeAttrs & ~0x0020) : 0x0006
                if let dv = f.defaultValue, (f.isConst || (t.isEnum && f.name != "value__")),
                   let intVal = Int32(dv) {
                    fFlags |= 0x8050 // HasDefault | Literal | Static
                    var bytes = [UInt8](repeating: 0, count: 4)
                    let u = UInt32(bitPattern: intVal)
                    bytes[0] = UInt8(u & 0xFF)
                    bytes[1] = UInt8((u >> 8) & 0xFF)
                    bytes[2] = UInt8((u >> 16) & 0xFF)
                    bytes[3] = UInt8((u >> 24) & 0xFF)
                    constantsTable.append(ConstantRow(
                        type: 0x08, // ELEMENT_TYPE_I4
                        parent: (fRowIdx << 2) | 0, // HasConstant tag 0 = Field
                        value: heaps.addBlob(bytes)
                    ))
                }
                fieldsTable.append(FieldRow(flags: fFlags, name: heaps.addString(f.name), sig: fSig))

                if !isSupportDummyDll && options.restoreDummyDllTokens && f.token != 0 {
                    let blobIdx = buildNamedArgAttrBlob([("Token", String(format: "0x%X", f.token))])
                    customAttrs.append(CustomAttrRow(
                        parent: (fRowIdx << 5) | 1,
                        type: (tokenAttrCtorMemberRef << 3) | 3,
                        value: blobIdx
                    ))
                }
                if !isSupportDummyDll && options.writeFieldOffsets && f.offset >= 0 {
                    let blobIdx = buildNamedArgAttrBlob([("Offset", String(format: "0x%X", f.offset))])
                    customAttrs.append(CustomAttrRow(
                        parent: (fRowIdx << 5) | 1,
                        type: (fieldOffAttrCtorMemberRef << 3) | 3,
                        value: blobIdx
                    ))
                }
            }

            if options.includeProperties && !t.properties.isEmpty {
                let propListStart = UInt32(propsTable.count + 1)
                propMaps.append(PropertyMapRow(parent: tdRowIndex, propList: propListStart))
                for p in t.properties {
                    let pRowIdx = UInt32(propsTable.count + 1)
                    let pSig = heaps.addBlob([0x28, 0x00, encodeElemType(p.typeName)])
                    propsTable.append(PropertyRow(flags: UInt16(p.attrs & 0xFFFF), name: heaps.addString(p.name), sig: pSig))
                    if !isSupportDummyDll && options.restoreDummyDllTokens && p.token != 0 {
                        let blobIdx = buildNamedArgAttrBlob([("Token", String(format: "0x%X", p.token))])
                        customAttrs.append(CustomAttrRow(
                            parent: (pRowIdx << 5) | 9,
                            type: (tokenAttrCtorMemberRef << 3) | 3,
                            value: blobIdx
                        ))
                    }
                }
            }

            for m in t.methods {
                let mRowIdx = UInt32(methodsTable.count + 1)
                let pListStart = UInt32(paramsTable.count + 1)

                let isStatic = (m.flags & 0x0010) != 0
                var mSigBytes: [UInt8] = [isStatic ? 0x00 : 0x20]
                let pCnt = min(m.parameters.count, 127)
                mSigBytes.append(UInt8(pCnt))
                mSigBytes.append(encodeElemType(m.returnTypeName))
                for pi in 0..<pCnt {
                    let p = m.parameters[pi]
                    mSigBytes.append(encodeElemType(p.typeName))
                    let pFlags: UInt16 = p.isOut ? 0x0002 : (p.isIn ? 0x0001 : 0x0000)
                    paramsTable.append(ParamRow(flags: pFlags, seq: UInt16(pi + 1), name: heaps.addString(p.name)))
                }
                let mSigIdx = heaps.addBlob(mSigBytes)
                let isAbstract = (m.flags & 0x0400) != 0
                let mRVA: UInt32 = (isAbstract || t.isInterface) ? 0 : ilBodyRVA
                let mFlags = m.flags == 0 ? 0x0086 : m.flags

                methodsTable.append(MethodDefRow(
                    rva: mRVA,
                    implFlags: m.iflags,
                    flags: mFlags,
                    name: heaps.addString(m.name),
                    sig: mSigIdx,
                    paramList: pListStart
                ))

                if !isSupportDummyDll && options.restoreDummyDllTokens && m.token != 0 {
                    let blobIdx = buildNamedArgAttrBlob([("Token", String(format: "0x%X", m.token))])
                    customAttrs.append(CustomAttrRow(
                        parent: (mRowIdx << 5) | 0,
                        type: (tokenAttrCtorMemberRef << 3) | 3,
                        value: blobIdx
                    ))
                }
                if !isSupportDummyDll && options.writeMethodOffsets && m.rva > 0 {
                    var args: [(String, String)] = [
                        ("RVA", String(format: "0x%llX", m.rva)),
                        ("Offset", String(format: "0x%llX", m.fileOffset)),
                        ("VA", String(format: "0x%llX", m.va))
                    ]
                    if m.slot != 0xFFFF {
                        args.append(("Slot", String(format: "0x%X", m.slot)))
                    }
                    let blobIdx = buildNamedArgAttrBlob(args)
                    customAttrs.append(CustomAttrRow(
                        parent: (mRowIdx << 5) | 0,
                        type: (addrAttrCtorMemberRef << 3) | 3,
                        value: blobIdx
                    ))
                }
            }
        }

        constantsTable.sort { $0.parent < $1.parent }
        customAttrs.sort { $0.parent < $1.parent }
        propMaps.sort { $0.parent < $1.parent }

        let str4 = heaps.strings.count > 65_535
        let guid4 = (heaps.guid.count / 16) > 65_535
        let blob4 = heaps.blob.count > 65_535

        let fieldIdx4 = fieldsTable.count >= 65_535
        let methodIdx4 = methodsTable.count >= 65_535
        let paramIdx4 = paramsTable.count >= 65_535
        let typeDefIdx4 = typeDefs.count >= 65_535
        let propIdx4 = propsTable.count >= 65_535

        let typeDefOrRef4 = max(typeDefs.count, typeRefs.count) >= 16_384
        let resScope4 = max(1, typeRefs.count) >= 16_384
        let memberRefParent4 = max(typeDefs.count, max(typeRefs.count, methodsTable.count)) >= 8_192
        let hasConstant4 = max(fieldsTable.count, max(paramsTable.count, propsTable.count)) >= 16_384
        let hasCustomAttr4 = max(methodsTable.count, max(fieldsTable.count, max(typeDefs.count, max(typeRefs.count, propsTable.count)))) >= 2_048
        let customAttrType4 = max(methodsTable.count, memberRefs.count) >= 8_192

        var tilde = Data()
        appendU32(&tilde, 0)
        tilde.append(2)
        tilde.append(0)
        var heapSizes: UInt8 = 0
        if str4 { heapSizes |= 0x01 }
        if guid4 { heapSizes |= 0x02 }
        if blob4 { heapSizes |= 0x04 }
        tilde.append(heapSizes)
        tilde.append(1)

        var validMask: UInt64 = (1 << 0x00) | (1 << 0x02) | (1 << 0x20) | (1 << 0x23)
        if !typeRefs.isEmpty { validMask |= (1 << 0x01) }
        if !fieldsTable.isEmpty { validMask |= (1 << 0x04) }
        if !methodsTable.isEmpty { validMask |= (1 << 0x06) }
        if !paramsTable.isEmpty { validMask |= (1 << 0x08) }
        if !memberRefs.isEmpty { validMask |= (1 << 0x0A) }
        if !constantsTable.isEmpty { validMask |= (1 << 0x0B) }
        if !customAttrs.isEmpty { validMask |= (1 << 0x0C) }
        if !propMaps.isEmpty { validMask |= (1 << 0x15) }
        if !propsTable.isEmpty { validMask |= (1 << 0x17) }

        let sortedMask: UInt64 = (1 << 0x0B) | (1 << 0x0C) | (1 << 0x15)
        appendU64(&tilde, validMask)
        appendU64(&tilde, sortedMask)

        let asmRefCount = isSupportDummyDll ? 1 : 2
        let rowCounts: [(Int, Int)] = [
            (0x00, 1),
            (0x01, typeRefs.count),
            (0x02, typeDefs.count),
            (0x04, fieldsTable.count),
            (0x06, methodsTable.count),
            (0x08, paramsTable.count),
            (0x0A, memberRefs.count),
            (0x0B, constantsTable.count),
            (0x0C, customAttrs.count),
            (0x15, propMaps.count),
            (0x17, propsTable.count),
            (0x20, 1),
            (0x23, asmRefCount)
        ]
        for (bit, cnt) in rowCounts where (validMask & (1 << bit)) != 0 {
            appendU32(&tilde, UInt32(cnt))
        }

        func putStr(_ v: UInt32) { putIdx(&tilde, v, is4: str4) }
        func putGuid(_ v: UInt32) { putIdx(&tilde, v, is4: guid4) }
        func putBlob(_ v: UInt32) { putIdx(&tilde, v, is4: blob4) }

        // 0x00 Module
        appendU16(&tilde, 0)
        putStr(modNameIdx)
        putGuid(mvidIdx)
        putGuid(0)
        putGuid(0)

        // 0x01 TypeRef
        for tr in typeRefs {
            putIdx(&tilde, tr.scope, is4: resScope4)
            putStr(tr.name)
            putStr(tr.ns)
        }

        // 0x02 TypeDef
        for td in typeDefs {
            appendU32(&tilde, td.flags)
            putStr(td.name)
            putStr(td.ns)
            putIdx(&tilde, td.extends, is4: typeDefOrRef4)
            putIdx(&tilde, td.fieldList, is4: fieldIdx4)
            putIdx(&tilde, td.methodList, is4: methodIdx4)
        }

        // 0x04 Field
        for f in fieldsTable {
            appendU16(&tilde, f.flags)
            putStr(f.name)
            putBlob(f.sig)
        }

        // 0x06 MethodDef
        for m in methodsTable {
            appendU32(&tilde, m.rva)
            appendU16(&tilde, m.implFlags)
            appendU16(&tilde, m.flags)
            putStr(m.name)
            putBlob(m.sig)
            putIdx(&tilde, m.paramList, is4: paramIdx4)
        }

        // 0x08 Param
        for p in paramsTable {
            appendU16(&tilde, p.flags)
            appendU16(&tilde, p.seq)
            putStr(p.name)
        }

        // 0x0A MemberRef
        for mr in memberRefs {
            putIdx(&tilde, mr.klass, is4: memberRefParent4)
            putStr(mr.name)
            putBlob(mr.sig)
        }

        // 0x0B Constant
        for c in constantsTable {
            tilde.append(c.type)
            tilde.append(0)
            putIdx(&tilde, c.parent, is4: hasConstant4)
            putBlob(c.value)
        }

        // 0x0C CustomAttribute
        for ca in customAttrs {
            putIdx(&tilde, ca.parent, is4: hasCustomAttr4)
            putIdx(&tilde, ca.type, is4: customAttrType4)
            putBlob(ca.value)
        }

        // 0x15 PropertyMap
        for pm in propMaps {
            putIdx(&tilde, pm.parent, is4: typeDefIdx4)
            putIdx(&tilde, pm.propList, is4: propIdx4)
        }

        // 0x17 Property
        for pr in propsTable {
            appendU16(&tilde, pr.flags)
            putStr(pr.name)
            putBlob(pr.sig)
        }

        // 0x20 Assembly
        appendU32(&tilde, 0x8004)
        appendU16(&tilde, 1); appendU16(&tilde, 0); appendU16(&tilde, 0); appendU16(&tilde, 0)
        appendU32(&tilde, 0)
        putBlob(0)
        putStr(asmNameIdx)
        putStr(0)

        // 0x23 AssemblyRef
        appendU16(&tilde, 4); appendU16(&tilde, 0); appendU16(&tilde, 0); appendU16(&tilde, 0)
        appendU32(&tilde, 0)
        putBlob(0)
        putStr(mscorlibNameIdx)
        putStr(0)
        putBlob(0)
        if !isSupportDummyDll {
            appendU16(&tilde, 1); appendU16(&tilde, 0); appendU16(&tilde, 0); appendU16(&tilde, 0)
            appendU32(&tilde, 0)
            putBlob(0)
            putStr(dummyDllNameIdx)
            putStr(0)
            putBlob(0)
        }

        pad4(&tilde)
        pad4(&heaps.strings)
        pad4(&heaps.us)
        pad4(&heaps.guid)
        pad4(&heaps.blob)

        let streams: [(String, Data)] = [
            ("#~", tilde),
            ("#Strings", heaps.strings),
            ("#US", heaps.us),
            ("#GUID", heaps.guid),
            ("#Blob", heaps.blob)
        ]

        var metaHeader = Data()
        appendU32(&metaHeader, 0x424A_5342)
        appendU16(&metaHeader, 1)
        appendU16(&metaHeader, 1)
        appendU32(&metaHeader, 0)
        let verBytes: [UInt8] = Array("v4.0.30319\0\0".utf8)
        appendU32(&metaHeader, UInt32(verBytes.count))
        metaHeader.append(contentsOf: verBytes)
        appendU16(&metaHeader, 0)
        appendU16(&metaHeader, UInt16(streams.count))

        var streamHeaderSize = 0
        for (name, _) in streams {
            let nameLen = (name.utf8.count + 1 + 3) & ~3
            streamHeaderSize += 8 + nameLen
        }

        var streamCursor = UInt32(metaHeader.count + streamHeaderSize)
        for (name, sData) in streams {
            appendU32(&metaHeader, streamCursor)
            appendU32(&metaHeader, UInt32(sData.count))
            var nameBytes = Array(name.utf8)
            nameBytes.append(0)
            while nameBytes.count % 4 != 0 { nameBytes.append(0) }
            metaHeader.append(contentsOf: nameBytes)
            streamCursor += UInt32(sData.count)
        }

        var metadataBlob = metaHeader
        for (_, sData) in streams {
            metadataBlob.append(sData)
        }

        var textSection = Data(repeating: 0, count: 0x60)
        writeU32(&textSection, at: 0x08, 72)
        writeU16(&textSection, at: 0x0C, 2)
        writeU16(&textSection, at: 0x0E, 5)
        writeU32(&textSection, at: 0x10, 0x2060)
        writeU32(&textSection, at: 0x14, UInt32(metadataBlob.count))
        writeU32(&textSection, at: 0x18, 1)
        textSection[0x50] = 0x0A
        textSection[0x51] = 0x14
        textSection[0x52] = 0x7A
        textSection[0x53] = 0x00

        textSection.append(metadataBlob)
        let rawTextSize = alignUp(textSection.count, to: 0x200)
        if textSection.count < rawTextSize {
            textSection.append(Data(repeating: 0, count: rawTextSize - textSection.count))
        }
        let virtTextSize = alignUp(textSection.count, to: 0x2000)

        var pe = Data(repeating: 0, count: 0x200)
        pe[0] = 0x4D; pe[1] = 0x5A
        writeU32(&pe, at: 0x3C, 0x80)
        writeU32(&pe, at: 0x80, 0x0000_4550)
        writeU16(&pe, at: 0x84, 0x014C)
        writeU16(&pe, at: 0x86, 1)
        writeU32(&pe, at: 0x88, 0x6700_0000)
        writeU16(&pe, at: 0x94, 0x00E0)
        writeU16(&pe, at: 0x96, 0x2102)

        writeU16(&pe, at: 0x98, 0x010B)
        pe[0x9A] = 48
        writeU32(&pe, at: 0x9C, UInt32(rawTextSize))
        writeU32(&pe, at: 0xAC, 0x2000)
        writeU32(&pe, at: 0xB0, 0x4000)
        writeU32(&pe, at: 0xB4, 0x1000_0000)
        writeU32(&pe, at: 0xB8, 0x2000)
        writeU32(&pe, at: 0xBC, 0x0200)
        writeU16(&pe, at: 0xC0, 4)
        writeU16(&pe, at: 0xC8, 4)
        writeU32(&pe, at: 0xD0, UInt32(0x2000 + virtTextSize))
        writeU32(&pe, at: 0xD4, 0x0200)
        writeU16(&pe, at: 0xDC, 3)
        writeU16(&pe, at: 0xDE, 0x8540)
        writeU32(&pe, at: 0xE0, 0x10_0000)
        writeU32(&pe, at: 0xE4, 0x1000)
        writeU32(&pe, at: 0xE8, 0x10_0000)
        writeU32(&pe, at: 0xEC, 0x1000)
        writeU32(&pe, at: 0xF4, 16)
        writeU32(&pe, at: 0x168, 0x2008)
        writeU32(&pe, at: 0x16C, 72)

        let secNameBytes = Array(".text\0\0\0".utf8)
        for i in 0..<8 { pe[0x178 + i] = secNameBytes[i] }
        writeU32(&pe, at: 0x180, UInt32(textSection.count))
        writeU32(&pe, at: 0x184, 0x2000)
        writeU32(&pe, at: 0x188, UInt32(rawTextSize))
        writeU32(&pe, at: 0x18C, 0x0200)
        writeU32(&pe, at: 0x19C, 0x6000_0020)

        pe.append(textSection)
        return pe
    }

    private static func deterministicGUID(for name: String) -> [UInt8] {
        var bytes = [UInt8](repeating: 0, count: 16)
        var h1: UInt64 = 0xCBF2_9CE4_8422_2325
        var h2: UInt64 = 0x9E37_79B9_7F4A_7C15
        for b in name.utf8 {
            h1 ^= UInt64(b); h1 &*= 0x100_0000_01B3
            h2 ^= UInt64(b); h2 = (h2 << 7) ^ (h2 >> 3) ^ 0x517C_C1B7_2722_0A95
        }
        for i in 0..<8 {
            bytes[i] = UInt8((h1 >> (i * 8)) & 0xFF)
            bytes[8 + i] = UInt8((h2 >> (i * 8)) & 0xFF)
        }
        bytes[6] = (bytes[6] & 0x0F) | 0x40
        bytes[8] = (bytes[8] & 0x3F) | 0x80
        return bytes
    }

    private static func appendSerString(_ out: inout [UInt8], _ s: String) {
        let utf8 = Array(s.utf8)
        let len = utf8.count
        if len < 0x80 {
            out.append(UInt8(len))
        } else if len < 0x4000 {
            out.append(UInt8((len >> 8) | 0x80))
            out.append(UInt8(len & 0xFF))
        } else {
            out.append(UInt8((len >> 24) | 0xC0))
            out.append(UInt8((len >> 16) & 0xFF))
            out.append(UInt8((len >> 8) & 0xFF))
            out.append(UInt8(len & 0xFF))
        }
        out.append(contentsOf: utf8)
    }

    private static func putIdx(_ d: inout Data, _ val: UInt32, is4: Bool) {
        if is4 { appendU32(&d, val) }
        else { appendU16(&d, UInt16(val & 0xFFFF)) }
    }

    private static func appendU16(_ d: inout Data, _ v: UInt16) {
        d.append(UInt8(v & 0xFF))
        d.append(UInt8((v >> 8) & 0xFF))
    }

    private static func appendU32(_ d: inout Data, _ v: UInt32) {
        d.append(UInt8(v & 0xFF))
        d.append(UInt8((v >> 8) & 0xFF))
        d.append(UInt8((v >> 16) & 0xFF))
        d.append(UInt8((v >> 24) & 0xFF))
    }

    private static func appendU64(_ d: inout Data, _ v: UInt64) {
        appendU32(&d, UInt32(v & 0xFFFF_FFFF))
        appendU32(&d, UInt32((v >> 32) & 0xFFFF_FFFF))
    }

    private static func writeU16(_ d: inout Data, at off: Int, _ v: UInt16) {
        d[off] = UInt8(v & 0xFF)
        d[off + 1] = UInt8((v >> 8) & 0xFF)
    }

    private static func writeU32(_ d: inout Data, at off: Int, _ v: UInt32) {
        d[off] = UInt8(v & 0xFF)
        d[off + 1] = UInt8((v >> 8) & 0xFF)
        d[off + 2] = UInt8((v >> 16) & 0xFF)
        d[off + 3] = UInt8((v >> 24) & 0xFF)
    }

    private static func pad4(_ d: inout Data) {
        while d.count % 4 != 0 { d.append(0) }
    }

    private static func alignUp(_ value: Int, to alignment: Int) -> Int {
        return (value + alignment - 1) & ~(alignment - 1)
    }
}
