import Foundation

public struct ObjCDiscoveredMetadata: Sendable, Equatable {
    public let classNames: [UInt64: String]
    public let selectorNames: [UInt64: String]

    public init(classNames: [UInt64: String], selectorNames: [UInt64: String]) {
        self.classNames = classNames
        self.selectorNames = selectorNames
    }
}

public struct StaticBinaryAnalysisOutput: Sendable {
    public let machO: ParsedMachOImage
    public let sliceCursor: BinaryCursor
    public let cStringsByVA: [UInt64: String]
    public let objcMetadata: ObjCDiscoveredMetadata
    public let objcResolvedMethods: [ObjCResolvedMethodIMP]
    public let nativeCPPScan: NativeCPPDeepScanResult
    public let arm64StringXRefs: [ARM64StringXRef]
    public let arm64DataXRefs: [ARM64DataPointerXRef]
    public let incomingCallCountsByFuncVA: [UInt64: Int]
    public let detectedEngineSummary: String
    public let binarySHA256: String
    public let symbolStripStatus: SymbolStripStatus
}

public enum StaticBinaryAnalyzer {
    public static func analyze(
        binaryData: Data,
        moduleName: String,
        logger: AnalysisLogger? = nil
    ) throws -> StaticBinaryAnalysisOutput {
        logger?.stage("Mach-O", "Parsing Mach-O headers, segments, sections, and linked dylibs for '\(moduleName)'...")
        let machO = try MachOParser.parse(data: binaryData, moduleName: moduleName)
        let sha256 = HexFormatters.sha256Hex(of: binaryData)

        guard let sliceData = BinaryCursor(data: binaryData).slice(
            offset: machO.sliceOffsetInFile,
            length: machO.sliceSize
        ) else {
            throw AnalysisError.invalidMachOBinary("Failed to read architecture slice for \(moduleName).")
        }
        let sliceCursor = BinaryCursor(data: sliceData)

        let stripStatus: SymbolStripStatus = {
            if machO.symbols.isEmpty {
                return .stripped
            } else if machO.symbols.count < 25 && machO.functionStartRVAs.count > 100 {
                return .partiallyStripped
            } else {
                return .fullSymbols
            }
        }()

        let engineSummary = inferEngineSignature(machO: machO)

        logger?.info(
            stage: "Mach-O",
            "Detected \(machO.architectureName) | Engine: \(engineSummary) | Segments: \(machO.segments.count) | Symbols: \(machO.symbols.count) (\(stripStatus.rawValue)) | FunctionStarts: \(machO.functionStartRVAs.count)"
        )

        if machO.encryptionInfo.isEncrypted {
            logger?.warning(
                stage: "Encryption",
                "FairPlay encryption detected (cryptid=\(machO.encryptionInfo.cryptID), offset=0x\(String(machO.encryptionInfo.cryptOffset, radix: 16)), size=\(machO.encryptionInfo.cryptSize) bytes). Encrypted segments will NOT be bypassed or misparsed."
            )
        }

        // Extract unencrypted C-strings and Objective-C class/method names
        let cStrings = extractStrings(
            fromSection: "__cstring",
            inSegment: "__TEXT",
            machO: machO,
            cursor: sliceCursor,
            filterKeywordsOnly: true
        )
        let objcClasses = extractStrings(
            fromSection: "__objc_classname",
            inSegment: "__TEXT",
            machO: machO,
            cursor: sliceCursor,
            filterKeywordsOnly: false
        )
        let objcSelectors = extractStrings(
            fromSection: "__objc_methname",
            inSegment: "__TEXT",
            machO: machO,
            cursor: sliceCursor,
            filterKeywordsOnly: true
        )

        var xrefTargetStrings = cStrings
        for (va, name) in objcClasses where SafetyPolicyFilter.isPermittedIdentifier(name) {
            xrefTargetStrings[va] = name
        }
        for (va, sel) in objcSelectors where SafetyPolicyFilter.isPermittedIdentifier(sel) {
            xrefTargetStrings[va] = sel
        }

        var deepScan = ARM64DeepScanOutput(stringXRefs: [], dataPointerXRefs: [], incomingCallCountsByFuncVA: [:])
        if machO.isARM64 {
            logger?.stage("ARM64", "Running deep A64 scan (ADRP+ADD strings, ADRP+LDR __DATA globals, BL call-graph, scalar/SIMD field offsets)...")
            deepScan = ARM64InstructionAnalyzer.performDeepCodeScan(
                sliceCursor: sliceCursor,
                machO: machO,
                indexedStringsByVA: xrefTargetStrings
            )
            logger?.info(
                stage: "ARM64",
                "Resolved \(deepScan.stringXRefs.count) string XRefs, \(deepScan.dataPointerXRefs.count) __DATA global XRefs, and \(deepScan.incomingCallCountsByFuncVA.count) call-graph targets."
            )
        }

        let objcResolvedIMPs = ObjCBinaryMetadataScanner.scanResolvedMethodIMPs(
            machO: machO,
            sliceCursor: sliceCursor
        )
        if !objcResolvedIMPs.isEmpty {
            logger?.info(stage: "ObjC", "Giải mã \(objcResolvedIMPs.count) hàm IMP Objective-C trực tiếp từ __objc_classlist.")
        }

        logger?.stage("C++17 Core", "Đang chạy lõi C++17 quét sâu toàn bộ lệnh máy ARM64, hàm Getter/Setter & Swift5 Types...")
        let cppScan = NativeCPPAnalyzerBridge.runDeepScan(sliceData: sliceData, machO: machO)
        logger?.success(
            stage: "C++17 Core",
            "Quét \(cppScan.totalInstructionsScanned) lệnh ARM64 | Prologues: \(cppScan.discoveredProloguesCount) | Getter/Setter: \(cppScan.getterIdiomsCount + cppScan.setterIdiomsCount) | Swift5 Types: \(cppScan.swiftNominalTypes.count)."
        )

        return StaticBinaryAnalysisOutput(
            machO: machO,
            sliceCursor: sliceCursor,
            cStringsByVA: cStrings,
            objcMetadata: ObjCDiscoveredMetadata(classNames: objcClasses, selectorNames: objcSelectors),
            objcResolvedMethods: objcResolvedIMPs,
            nativeCPPScan: cppScan,
            arm64StringXRefs: deepScan.stringXRefs,
            arm64DataXRefs: deepScan.dataPointerXRefs,
            incomingCallCountsByFuncVA: deepScan.incomingCallCountsByFuncVA,
            detectedEngineSummary: engineSummary,
            binarySHA256: sha256,
            symbolStripStatus: stripStatus
        )
    }

    private static func inferEngineSignature(machO: ParsedMachOImage) -> String {
        if machO.moduleName == "UnityFramework"
            || machO.section(forSectionName: "il2cpp", inSegment: "__TEXT") != nil
            || machO.linkedLibraries.contains(where: { $0.contains("UnityFramework") }) {
            return "Unity IL2CPP"
        }
        if machO.symbols.contains(where: { $0.cleanName.contains("UObject") || $0.cleanName.contains("AActor") }) {
            return "Unreal Engine C++"
        }
        if machO.symbols.contains(where: { $0.cleanName.contains("cocos2d") }) {
            return "Cocos2d-x C++"
        }
        if machO.linkedLibraries.contains(where: { $0.contains("SpriteKit") || $0.contains("SceneKit") }) {
            return "Apple SceneKit / SpriteKit"
        }
        return "Native iOS Mach-O"
    }

    private static let relevantKeywords: [String] = [
        "player", "health", "hp", "maxhealth", "state", "position", "pos",
        "transform", "movement", "move", "velocity", "speed", "jump",
        "rotation", "rot", "euler", "quaternion", "camera", "fov", "look",
        "character", "avatar", "controller", "anim", "animator", "skeleton",
        "gameobject", "entity", "actor", "spawn", "gamestate", "match",
        "level", "gravity", "stamina", "cooldown", "gameplay",
        "viewcontroller", "manager", "service", "engine", "render", "audio",
        "scene", "physics", "network", "session", "config", "handler", "model"
    ]

    private static func extractStrings(
        fromSection sectionName: String,
        inSegment segmentName: String,
        machO: ParsedMachOImage,
        cursor: BinaryCursor,
        filterKeywordsOnly: Bool
    ) -> [UInt64: String] {
        guard let sec = machO.section(forSectionName: sectionName, inSegment: segmentName),
              sec.size > 0 else {
            return [:]
        }
        let secOff = UInt64(sec.fileOffset)
        if machO.isSliceRangeEncrypted(offset: secOff, length: min(sec.size, 4096)) {
            return [:]
        }

        let maxBytes = Int(min(sec.size, 8 * 1024 * 1024))
        guard let rawSlice = cursor.slice(offset: Int(sec.fileOffset), length: maxBytes) else {
            return [:]
        }

        var map: [UInt64: String] = [:]
        var tokenStart = 0
        let bytes = Array(rawSlice)

        for idx in 0..<bytes.count {
            if bytes[idx] == 0 {
                let len = idx - tokenStart
                if len >= 3 && len <= 160 {
                    let str = String(decoding: bytes[tokenStart..<idx], as: UTF8.self)
                    if isPrintableIdentifierLike(str) && SafetyPolicyFilter.isPermittedIdentifier(str) {
                        let lower = str.lowercased()
                        if !filterKeywordsOnly || relevantKeywords.contains(where: { lower.contains($0) }) {
                            let va = sec.address &+ UInt64(tokenStart)
                            map[va] = str
                            if map.count >= 6000 { break }
                        }
                    }
                }
                tokenStart = idx + 1
            }
        }
        return map
    }

    private static func isPrintableIdentifierLike(_ s: String) -> Bool {
        for scalar in s.unicodeScalars {
            if scalar.value < 0x20 || scalar.value > 0x7E {
                return false
            }
        }
        return true
    }
}
