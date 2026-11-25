import Foundation

/// Parses Unity IL2CPP `global-metadata.dat` (header magic `0xFAB11BAF`, versions 24..31+).
/// Accurately detects valid, truncated/incomplete, and encrypted/obfuscated metadata files without fabricating entries.
public enum IL2CPPMetadataParser {
    public static let standardMagic: UInt32 = 0xFAB11BAF

    public static func parse(data: Data, logger: AnalysisLogger? = nil) -> IL2CPPAnalysisResult {
        guard data.count >= 176 else {
            return IL2CPPAnalysisResult(
                availability: .truncatedOrIncomplete,
                metadataVersion: nil,
                types: [],
                methods: [],
                fields: [],
                relevantStringLiterals: [],
                diagnostics: ["global-metadata.dat is smaller than minimum header size (\(data.count) bytes)."]
            )
        }

        let cursor = BinaryCursor(data: data)
        guard let magic = cursor.readUInt32LE(at: 0),
              let rawVersion = cursor.readInt32LE(at: 4) else {
            return IL2CPPAnalysisResult(
                availability: .truncatedOrIncomplete,
                metadataVersion: nil,
                types: [],
                methods: [],
                fields: [],
                relevantStringLiterals: [],
                diagnostics: ["Unable to read global-metadata.dat header."]
            )
        }

        guard magic == standardMagic else {
            logger?.warning(
                stage: "IL2CPP",
                String(format: "Non-standard global-metadata.dat magic 0x%08X (metadata is encrypted or obfuscated). Static metadata parsing skipped without bypass.", magic)
            )
            return IL2CPPAnalysisResult(
                availability: .encryptedOrObfuscated,
                metadataVersion: nil,
                types: [],
                methods: [],
                fields: [],
                relevantStringLiterals: [],
                diagnostics: [
                    String(format: "Header magic 0x%08X != 0xFAB11BAF. Metadata is protected/encrypted.", magic),
                    "Requires authorized test/debug build or runtime inspection in permitted test environment."
                ]
            )
        }

        let version = Int(rawVersion)
        guard version >= 20 && version <= 40 else {
            return IL2CPPAnalysisResult(
                availability: .encryptedOrObfuscated,
                metadataVersion: version,
                types: [],
                methods: [],
                fields: [],
                relevantStringLiterals: [],
                diagnostics: ["Unsupported or obfuscated IL2CPP metadata version: \(version)."]
            )
        }

        guard let stringLiteralOffset = cursor.readInt32LE(at: 0x08).map(Int.init),
              let stringLiteralSize = cursor.readInt32LE(at: 0x0C).map(Int.init),
              let stringLiteralDataOffset = cursor.readInt32LE(at: 0x10).map(Int.init),
              let stringLiteralDataSize = cursor.readInt32LE(at: 0x14).map(Int.init),
              let stringOffset = cursor.readInt32LE(at: 0x18).map(Int.init),
              let stringSize = cursor.readInt32LE(at: 0x1C).map(Int.init),
              let methodsOffset = cursor.readInt32LE(at: 0x30).map(Int.init),
              let methodsSize = cursor.readInt32LE(at: 0x34).map(Int.init),
              let fieldsOffset = cursor.readInt32LE(at: 0x60).map(Int.init),
              let fieldsSize = cursor.readInt32LE(at: 0x64).map(Int.init),
              let typeDefsOffset = cursor.readInt32LE(at: 0xA0).map(Int.init),
              let typeDefsSize = cursor.readInt32LE(at: 0xA4).map(Int.init) else {
            return IL2CPPAnalysisResult(
                availability: .truncatedOrIncomplete,
                metadataVersion: version,
                types: [],
                methods: [],
                fields: [],
                relevantStringLiterals: [],
                diagnostics: ["Metadata header offset fields could not be read."]
            )
        }

        // Validate table bounds against file size
        let requiredBounds = [
            (stringOffset, stringSize, "stringTable"),
            (methodsOffset, methodsSize, "methods"),
            (fieldsOffset, fieldsSize, "fields"),
            (typeDefsOffset, typeDefsSize, "typeDefinitions")
        ]
        for (off, sz, label) in requiredBounds {
            if off < 0 || sz < 0 || !cursor.hasBytes(at: off, count: sz) {
                return IL2CPPAnalysisResult(
                    availability: .truncatedOrIncomplete,
                    metadataVersion: version,
                    types: [],
                    methods: [],
                    fields: [],
                    relevantStringLiterals: [],
                    diagnostics: ["Metadata table '\(label)' (offset=\(off), size=\(sz)) is truncated (file size=\(data.count))."]
                )
            }
        }

        func readIdentifier(index: Int32) -> String? {
            let idx = Int(index)
            guard idx >= 0 && idx < stringSize else { return nil }
            return cursor.readCString(at: stringOffset + idx, maxLength: min(512, stringSize - idx))
        }

        var diagnostics: [String] = ["Parsed IL2CPP global-metadata.dat v\(version)."]

        // Parse TypeDefinitions using self-verifying stride detection (88 for v27/v29/v31, 92/96/100 for v24 variants)
        var parsedTypes: [IL2CPPTypeRecord] = []
        var typeByIndex: [Int: IL2CPPTypeRecord] = [:]

        if typeDefsSize > 0 {
            let candidateTypeStrides = [88, 92, 96, 100, 104, 84]
            if let typeStride = detectTypeStride(
                cursor: cursor,
                offset: typeDefsOffset,
                size: typeDefsSize,
                stringSize: stringSize,
                candidates: candidateTypeStrides,
                readIdentifier: readIdentifier
            ) {
                let typeCount = min(typeDefsSize / typeStride, 80_000)
                parsedTypes.reserveCapacity(min(typeCount, 8192))

                for i in 0..<typeCount {
                    if i & 4095 == 0 && Task.isCancelled { break }
                    let base = typeDefsOffset + i * typeStride
                    guard let nameIdx = cursor.readInt32LE(at: base),
                          let nsIdx = cursor.readInt32LE(at: base + 4),
                          let className = readIdentifier(index: nameIdx),
                          !className.isEmpty,
                          let nsName = readIdentifier(index: nsIdx) else {
                        continue
                    }

                    let fieldStart = cursor.readInt32LE(at: base + typeStride - 56) ?? 0
                    let methodStart = cursor.readInt32LE(at: base + typeStride - 52) ?? 0
                    let methodCount = cursor.readUInt16LE(at: base + typeStride - 24) ?? 0
                    let fieldCount = cursor.readUInt16LE(at: base + typeStride - 20) ?? 0
                    let token = cursor.readUInt32LE(at: base + typeStride - 4) ?? 0

                    let record = IL2CPPTypeRecord(
                        typeIndex: i,
                        namespaceName: nsName,
                        className: className,
                        token: token,
                        methodStart: methodStart,
                        methodCount: methodCount,
                        fieldStart: fieldStart,
                        fieldCount: fieldCount
                    )
                    typeByIndex[i] = record
                    if SafetyPolicyFilter.isPermittedIdentifier(record.fullName) {
                        parsedTypes.append(record)
                    }
                }
                diagnostics.append("Decoded \(parsedTypes.count) IL2CPP classes (stride=\(typeStride)B).")
            } else {
                diagnostics.append("Could not verify TypeDefinition struct stride for version \(version).")
            }
        }

        // Parse Methods (stride 32 for v24.2..v29, 36 for v31, 52/56 for v24.0..v24.1)
        var parsedMethods: [IL2CPPMethodRecord] = []
        if methodsSize > 0 && !typeByIndex.isEmpty {
            let candidateMethodStrides = [32, 36, 52, 56]
            if let methodStride = detectMethodStride(
                cursor: cursor,
                offset: methodsOffset,
                size: methodsSize,
                stringSize: stringSize,
                maxTypes: max(typeByIndex.keys.max().map { $0 + 1 } ?? 1, 1),
                candidates: candidateMethodStrides,
                readIdentifier: readIdentifier
            ) {
                let methodCount = min(methodsSize / methodStride, 200_000)
                for i in 0..<methodCount {
                    if i & 8191 == 0 && Task.isCancelled { break }
                    let base = methodsOffset + i * methodStride
                    guard let nameIdx = cursor.readInt32LE(at: base),
                          let declIdx = cursor.readInt32LE(at: base + 4),
                          let methodName = readIdentifier(index: nameIdx),
                          !methodName.isEmpty else {
                        continue
                    }

                    let declaringFull = typeByIndex[Int(declIdx)]?.fullName ?? "Global"
                    let combined = "\(declaringFull).\(methodName)"
                    guard SafetyPolicyFilter.isPermittedIdentifier(combined) else {
                        continue
                    }

                    let tokenOff = (methodStride == 36) ? 24 : 20
                    let paramCountOff = (methodStride == 36) ? 34 : 30
                    let token = cursor.readUInt32LE(at: base + tokenOff) ?? 0
                    let paramCount = (paramCountOff + 2 <= methodStride)
                        ? (cursor.readUInt16LE(at: base + paramCountOff) ?? 0)
                        : 0

                    parsedMethods.append(
                        IL2CPPMethodRecord(
                            methodIndex: i,
                            declaringTypeIndex: Int(declIdx),
                            declaringClassFullName: declaringFull,
                            methodName: methodName,
                            token: token,
                            parameterCount: paramCount
                        )
                    )
                }
                diagnostics.append("Decoded \(parsedMethods.count) IL2CPP methods (stride=\(methodStride)B).")
            }
        }

        // Parse Fields (Il2CppFieldDefinition = 12 bytes in v24..v31: nameIndex(4), typeIndex(4), token(4))
        var parsedFields: [IL2CPPFieldRecord] = []
        if fieldsSize >= 12 && fieldsSize % 12 == 0 {
            let totalFields = fieldsSize / 12
            for typeRec in parsedTypes where typeRec.fieldCount > 0 && typeRec.fieldStart >= 0 {
                let start = Int(typeRec.fieldStart)
                let end = min(totalFields, start + Int(typeRec.fieldCount))
                guard start < end else { continue }
                for fIdx in start..<end {
                    let fBase = fieldsOffset + fIdx * 12
                    guard let fNameIdx = cursor.readInt32LE(at: fBase),
                          let fToken = cursor.readUInt32LE(at: fBase + 8),
                          let fName = readIdentifier(index: fNameIdx),
                          !fName.isEmpty else {
                        continue
                    }
                    let combined = "\(typeRec.fullName).\(fName)"
                    guard SafetyPolicyFilter.isPermittedIdentifier(combined) else { continue }
                    let ordinal = fIdx - start
                    // Standard ARM64 IL2CPP object header is 0x10 bytes (Il2CppObject: klass* + monitor*)
                    let estimatedOffset = UInt32(0x10 + ordinal * 8)
                    parsedFields.append(
                        IL2CPPFieldRecord(
                            fieldIndex: fIdx,
                            declaringClassFullName: typeRec.fullName,
                            fieldName: fName,
                            fieldOrdinalInClass: ordinal,
                            estimatedInstanceOffset: estimatedOffset,
                            token: fToken
                        )
                    )
                }
            }
            diagnostics.append("Decoded \(parsedFields.count) IL2CPP class fields.")
        }

        // Parse String Literals (8 bytes per descriptor: length(4), dataIndex(4))
        var literals: [IL2CPPStringLiteralRecord] = []
        if stringLiteralSize >= 8,
           cursor.hasBytes(at: stringLiteralOffset, count: stringLiteralSize),
           cursor.hasBytes(at: stringLiteralDataOffset, count: stringLiteralDataSize) {
            let litCount = min(stringLiteralSize / 8, 25_000)
            for i in 0..<litCount {
                let descOff = stringLiteralOffset + i * 8
                guard let len = cursor.readUInt32LE(at: descOff).map(Int.init),
                      let dataIdx = cursor.readInt32LE(at: descOff + 4).map(Int.init),
                      len >= 3, len <= 120,
                      dataIdx >= 0, dataIdx + len <= stringLiteralDataSize,
                      let slice = cursor.slice(offset: stringLiteralDataOffset + dataIdx, length: len) else {
                    continue
                }
                let text = String(decoding: slice, as: UTF8.self)
                if SafetyPolicyFilter.isPermittedIdentifier(text) {
                    literals.append(IL2CPPStringLiteralRecord(index: i, value: text))
                    if literals.count >= 1000 { break }
                }
            }
        }

        logger?.success(
            stage: "IL2CPP",
            "Metadata v\(version) analyzed: \(parsedTypes.count) classes, \(parsedMethods.count) methods, \(parsedFields.count) fields."
        )

        return IL2CPPAnalysisResult(
            availability: .validStandard,
            metadataVersion: version,
            types: parsedTypes,
            methods: parsedMethods,
            fields: parsedFields,
            relevantStringLiterals: literals,
            diagnostics: diagnostics
        )
    }

    private static func detectTypeStride(
        cursor: BinaryCursor,
        offset: Int,
        size: Int,
        stringSize: Int,
        candidates: [Int],
        readIdentifier: (Int32) -> String?
    ) -> Int? {
        for stride in candidates where size >= stride && size % stride == 0 {
            let sampleCount = min(size / stride, 12)
            var validSamples = 0
            for i in 0..<sampleCount {
                let base = offset + i * stride
                guard let nameIdx = cursor.readInt32LE(at: base),
                      let nsIdx = cursor.readInt32LE(at: base + 4),
                      let token = cursor.readUInt32LE(at: base + stride - 4),
                      nameIdx >= 0, Int(nameIdx) < stringSize,
                      nsIdx >= 0, Int(nsIdx) < stringSize,
                      (token & 0xFF00_0000) == 0x0200_0000,
                      readIdentifier(nameIdx) != nil else {
                    break
                }
                validSamples += 1
            }
            if validSamples == sampleCount {
                return stride
            }
        }
        return nil
    }

    private static func detectMethodStride(
        cursor: BinaryCursor,
        offset: Int,
        size: Int,
        stringSize: Int,
        maxTypes: Int,
        candidates: [Int],
        readIdentifier: (Int32) -> String?
    ) -> Int? {
        for stride in candidates where size >= stride && size % stride == 0 {
            let sampleCount = min(size / stride, 12)
            var validSamples = 0
            let tokenOff = (stride == 36) ? 24 : 20
            for i in 0..<sampleCount {
                let base = offset + i * stride
                guard let nameIdx = cursor.readInt32LE(at: base),
                      let declIdx = cursor.readInt32LE(at: base + 4),
                      let token = cursor.readUInt32LE(at: base + tokenOff),
                      nameIdx >= 0, Int(nameIdx) < stringSize,
                      declIdx >= -1, Int(declIdx) < maxTypes,
                      (token & 0xFF00_0000) == 0x0600_0000,
                      let name = readIdentifier(nameIdx), !name.isEmpty else {
                    break
                }
                validSamples += 1
            }
            if validSamples == sampleCount {
                return stride
            }
        }
        return nil
    }
}
