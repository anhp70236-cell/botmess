import Foundation

public enum OffsetCategoryClassifier {
    private static let rules: [(category: OffsetCategory, tokens: [String])] = [
        (.playerHealth, ["playerhealth", "maxhealth", "currenthealth", "healthcomponent", "sethealth", "get_health", "set_health", "takehealth", "hp", "hitpoint", "shield", "armor"]),
        (.playerState, ["playerstate", "isalive", "isdead", "respawn", "stamina", "invulnerable", "combatstate", "buffstate", "statuscontroller"]),
        (.position, ["position", "localposition", "worldposition", "get_position", "set_position", "teleport", "coordinates", "spawnpoint", "waypoint"]),
        (.movement, ["movement", "locomotion", "velocity", "movespeed", "walkspeed", "runspeed", "sprint", "jump", "gravity", "charactercontroller", "rigidbody"]),
        (.rotation, ["rotation", "localrotation", "quaternion", "eulerangles", "yaw", "pitch", "aimrotation", "lookrotation", "turnspeed"]),
        (.camera, ["camera", "maincamera", "fieldofview", "fov", "camerafollow", "cameracontroller", "viewport", "worldtoscreen", "cinemachine"]),
        (.animation, ["animator", "animation", "animstate", "crossfade", "blendtree", "skeleton", "ragdoll", "rootmotion"]),
        (.character, ["character", "avatar", "humanoid", "hero", "skinmesh", "bone", "hitbox", "characterstats"]),
        (.player, ["player", "localplayer", "playercontroller", "playermanager", "playerinput", "playerdata"]),
        (.gameObjects, ["gameobject", "transform", "component", "instantiate", "entity", "actor", "prefab", "objectpool", "spawner"]),
        (.gameState, ["gamestate", "gamemanager", "matchstate", "roundmanager", "levelmanager", "timescale", "sessionstate", "worldstate"]),
        (.gameplayVariables, ["cooldown", "firerate", "range", "recoil", "difficulty", "experience", "score", "gameplay", "config", "settings"])
    ]

    public static func classify(identifier: String) -> OffsetCategory? {
        guard SafetyPolicyFilter.isPermittedIdentifier(identifier) else {
            return nil
        }
        let lower = identifier.lowercased()
        for rule in rules {
            for token in rule.tokens {
                if lower.contains(token) {
                    return rule.category
                }
            }
        }
        return nil
    }
}

public struct OffsetDiscoveryBundle: Sendable {
    public let offsets: [DiscoveredOffset]
    public let runtimeSnapshot: RuntimeSnapshot
    public let methodSummaries: [AnalysisMethodSummary]
    public let crossVersionDelta: CrossVersionDelta?
    public let warnings: [String]
    public let limitations: [String]
}

/// Executes the 9-stage multi-source offset analysis pipeline:
/// 1. Static Binary Analysis
/// 2. Mach-O Analysis
/// 3. IL2CPP Analysis
/// 4. Metadata Analysis
/// 5. Symbol-less Analysis (LC_FUNCTION_STARTS + ARM64 ADRP/ADD XRefs)
/// 6. Runtime Inspection
/// 7. Dynamic Address Mapping (ASLR VA <-> RVA <-> FileOffset)
/// 8. Offset Validation
/// 9. Cross-version Comparison
public final class AdvancedOffsetDiscoveryEngine: Sendable {
    private let runtimeInspector: RuntimeInspectorService

    public init(runtimeInspector: RuntimeInspectorService = RuntimeInspectorService()) {
        self.runtimeInspector = runtimeInspector
    }

    public func discoverOffsets(
        staticOutput: StaticBinaryAnalysisOutput,
        il2cppResult: IL2CPPAnalysisResult,
        bundleIdentifier: String,
        bundleVersion: String,
        previousHistory: [AnalysisHistoryEntry],
        authorizedTrace: AuthorizedRuntimeTracePayload? = nil,
        logger: AnalysisLogger? = nil
    ) throws -> OffsetDiscoveryBundle {
        let machO = staticOutput.machO
        let moduleName = machO.moduleName

        var discovered: [DiscoveredOffset] = []
        var seenKeys = Set<String>()
        var warnings: [String] = []
        var limitations: [String] = []
        var methodSummaries: [AnalysisMethodSummary] = []

        func appendUnique(_ offset: DiscoveredOffset) {
            let dedupKey = "\(offset.moduleName)|\(offset.name)|\(offset.dataType.rawValue)|\(offset.rva)"
            guard !seenKeys.contains(dedupKey) else { return }
            seenKeys.insert(dedupKey)
            discovered.append(offset)
        }

        // [1] & [2] Mach-O & Symbol Table Analysis
        logger?.stage("OffsetDiscovery", "Running Mach-O symbol table discovery...")
        var symbolFindings = 0
        let symbolLookupByCleanSuffix: [String: SymbolEntry] = {
            var map: [String: SymbolEntry] = [:]
            for sym in machO.symbols {
                map[sym.cleanName.lowercased()] = sym
            }
            return map
        }()

        for sym in machO.symbols {
            if Task.isCancelled { throw AnalysisError.cancelled }
            guard let category = OffsetCategoryClassifier.classify(identifier: sym.cleanName) else {
                continue
            }
            let validation = validateExecutableOrDataAddress(
                va: sym.virtualAddress,
                expectExecutable: sym.isFunctionSymbol,
                staticOutput: staticOutput
            )
            let off = DiscoveredOffset(
                name: sym.cleanName,
                value: HexFormatters.vaHex(sym.virtualAddress),
                moduleName: moduleName,
                rva: sym.rva,
                virtualAddress: sym.virtualAddress,
                fileOffset: sym.fileOffset,
                category: category,
                dataType: sym.isFunctionSymbol ? .symbolFunction : .staticData,
                discoverySource: .machOSymbolTable,
                verificationStatus: validation.status,
                confidenceScore: validation.confidence,
                signatureHint: validation.prologueHex,
                notes: validation.note
            )
            appendUnique(off)
            symbolFindings += 1
        }

        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "1. Static Binary Analysis",
                executed: true,
                status: machO.encryptionInfo.isEncrypted ? "Partial (Encrypted Pages Skipped)" : "Completed",
                findingsCount: staticOutput.cStringsByVA.count + staticOutput.arm64StringXRefs.count,
                details: "Indexed \(staticOutput.cStringsByVA.count) strings and \(staticOutput.arm64StringXRefs.count) ARM64 cross-references."
            )
        )
        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "2. Mach-O Analysis",
                executed: true,
                status: "Completed",
                findingsCount: symbolFindings,
                details: "Architecture \(machO.architectureName), \(machO.segments.count) segments, \(machO.symbols.count) symbols (\(staticOutput.symbolStripStatus.rawValue))."
            )
        )

        // Objective-C Runtime Metadata inside Mach-O
        var objcFindings = 0
        for (va, className) in staticOutput.objcMetadata.classNames {
            guard let category = OffsetCategoryClassifier.classify(identifier: className) else { continue }
            let rva = machO.rva(forVirtualAddress: va)
            let fileOff = machO.virtualAddressToSliceFileOffset(va)
            appendUnique(
                DiscoveredOffset(
                    name: "ObjCClass::\(className)",
                    value: HexFormatters.vaHex(va),
                    moduleName: moduleName,
                    rva: rva,
                    virtualAddress: va,
                    fileOffset: fileOff,
                    category: category,
                    dataType: .classType,
                    discoverySource: .objcRuntimeMetadata,
                    verificationStatus: fileOff != nil ? .verified : .probable,
                    confidenceScore: 0.90,
                    signatureHint: "__objc_classname",
                    notes: "Objective-C class metadata entry in __TEXT,__objc_classname."
                )
            )
            objcFindings += 1
        }
        for (va, selector) in staticOutput.objcMetadata.selectorNames {
            guard let category = OffsetCategoryClassifier.classify(identifier: selector) else { continue }
            let rva = machO.rva(forVirtualAddress: va)
            let fileOff = machO.virtualAddressToSliceFileOffset(va)
            appendUnique(
                DiscoveredOffset(
                    name: "ObjCSelector::\(selector)",
                    value: HexFormatters.vaHex(va),
                    moduleName: moduleName,
                    rva: rva,
                    virtualAddress: va,
                    fileOffset: fileOff,
                    category: category,
                    dataType: .objcSelector,
                    discoverySource: .objcRuntimeMetadata,
                    verificationStatus: .probable,
                    confidenceScore: 0.80,
                    signatureHint: "__objc_methname",
                    notes: "Objective-C selector name entry."
                )
            )
            objcFindings += 1
        }

        // [3] & [4] IL2CPP & Metadata Analysis
        var il2cppFindings = 0
        let xrefByString: [String: ARM64StringXRef] = {
            var map: [String: ARM64StringXRef] = [:]
            for xref in staticOutput.arm64StringXRefs {
                if map[xref.referencedString] == nil {
                    map[xref.referencedString] = xref
                }
            }
            return map
        }()

        switch il2cppResult.availability {
        case .validStandard:
            logger?.stage("OffsetDiscovery", "Correlating IL2CPP classes, methods, and fields with binary addresses...")

            for typeRec in il2cppResult.types {
                guard let category = OffsetCategoryClassifier.classify(identifier: typeRec.fullName) else { continue }
                let tokenHex = String(format: "0x%08X", typeRec.token)
                if let matchedSym = matchIL2CPPSymbol(
                    className: typeRec.className,
                    methodName: nil,
                    symbolMap: symbolLookupByCleanSuffix
                ) {
                    appendUnique(
                        DiscoveredOffset(
                            name: typeRec.fullName,
                            value: HexFormatters.vaHex(matchedSym.virtualAddress),
                            moduleName: moduleName,
                            rva: matchedSym.rva,
                            virtualAddress: matchedSym.virtualAddress,
                            fileOffset: matchedSym.fileOffset,
                            category: category,
                            dataType: .classType,
                            discoverySource: .il2cppMetadata,
                            verificationStatus: .verified,
                            confidenceScore: 0.96,
                            signatureHint: "TypeDef \(tokenHex)",
                            notes: "IL2CPP TypeDefinition matched to Mach-O symbol \(matchedSym.cleanName)."
                        )
                    )
                } else {
                    appendUnique(
                        DiscoveredOffset(
                            name: typeRec.fullName,
                            value: tokenHex,
                            moduleName: moduleName,
                            rva: 0,
                            virtualAddress: 0,
                            fileOffset: nil,
                            category: category,
                            dataType: .classType,
                            discoverySource: .il2cppMetadata,
                            verificationStatus: machO.encryptionInfo.isEncrypted ? .undetermined : .unverified,
                            confidenceScore: 0.65,
                            signatureHint: "TypeDef \(tokenHex) (Methods: \(typeRec.methodCount), Fields: \(typeRec.fieldCount))",
                            notes: machO.encryptionInfo.isEncrypted
                                ? "Class verified in global-metadata.dat; code RVA requires unencrypted binary or authorized runtime trace."
                                : "IL2CPP TypeDefinition metadata token (stripped code pointer table)."
                        )
                    )
                }
                il2cppFindings += 1
            }

            for methodRec in il2cppResult.methods {
                let fullMethod = "\(methodRec.declaringClassFullName).\(methodRec.methodName)"
                guard let category = OffsetCategoryClassifier.classify(identifier: fullMethod) else { continue }
                let tokenHex = String(format: "0x%08X", methodRec.token)

                if let matchedSym = matchIL2CPPSymbol(
                    className: methodRec.declaringClassFullName,
                    methodName: methodRec.methodName,
                    symbolMap: symbolLookupByCleanSuffix
                ) {
                    let valCheck = validateExecutableOrDataAddress(
                        va: matchedSym.virtualAddress,
                        expectExecutable: true,
                        staticOutput: staticOutput
                    )
                    appendUnique(
                        DiscoveredOffset(
                            name: fullMethod,
                            value: HexFormatters.vaHex(matchedSym.virtualAddress),
                            moduleName: moduleName,
                            rva: matchedSym.rva,
                            virtualAddress: matchedSym.virtualAddress,
                            fileOffset: matchedSym.fileOffset,
                            category: category,
                            dataType: .method,
                            discoverySource: .il2cppMetadata,
                            verificationStatus: valCheck.status,
                            confidenceScore: valCheck.confidence,
                            signatureHint: valCheck.prologueHex ?? "MethodDef \(tokenHex)",
                            notes: "Resolved IL2CPP method RVA via Mach-O symbol linkage (\(matchedSym.cleanName))."
                        )
                    )
                } else if let xref = xrefByString[methodRec.methodName] ?? xrefByString[methodRec.declaringClassFullName],
                          let funcVA = xref.enclosingFunctionVA,
                          let funcRVA = xref.enclosingFunctionRVA {
                    let fileOff = machO.virtualAddressToSliceFileOffset(funcVA)
                    appendUnique(
                        DiscoveredOffset(
                            name: fullMethod,
                            value: HexFormatters.vaHex(funcVA),
                            moduleName: moduleName,
                            rva: funcRVA,
                            virtualAddress: funcVA,
                            fileOffset: fileOff,
                            category: category,
                            dataType: .method,
                            discoverySource: .il2cppMetadata,
                            verificationStatus: .probable,
                            confidenceScore: 0.78,
                            signatureHint: xref.prologueHex ?? "MethodDef \(tokenHex)",
                            notes: "Probable ARM64 function start correlated via ADRP+ADD reference to '\(xref.referencedString)'."
                        )
                    )
                } else {
                    appendUnique(
                        DiscoveredOffset(
                            name: fullMethod,
                            value: tokenHex,
                            moduleName: moduleName,
                            rva: 0,
                            virtualAddress: 0,
                            fileOffset: nil,
                            category: category,
                            dataType: .method,
                            discoverySource: .il2cppMetadata,
                            verificationStatus: .undetermined,
                            confidenceScore: 0.45,
                            signatureHint: "MethodDef \(tokenHex) (\(methodRec.parameterCount) params)",
                            notes: "Method definition exists in global-metadata.dat, but binary RVA is stripped/encrypted and cannot be statically proven."
                        )
                    )
                }
                il2cppFindings += 1
            }

            for fieldRec in il2cppResult.fields {
                let fullField = "\(fieldRec.declaringClassFullName).\(fieldRec.fieldName)"
                guard let category = OffsetCategoryClassifier.classify(identifier: fullField) else { continue }
                let fieldHex = String(format: "0x%X", fieldRec.estimatedInstanceOffset)
                appendUnique(
                    DiscoveredOffset(
                        name: fullField,
                        value: fieldHex,
                        moduleName: moduleName,
                        rva: UInt64(fieldRec.estimatedInstanceOffset),
                        virtualAddress: 0,
                        fileOffset: nil,
                        category: category,
                        dataType: .field,
                        discoverySource: .il2cppMetadata,
                        verificationStatus: .probable,
                        confidenceScore: 0.82,
                        signatureHint: String(format: "FieldDef 0x%08X (ordinal #%d)", fieldRec.token, fieldRec.fieldOrdinalInClass),
                        notes: "ARM64 64-bit IL2CPP instance field layout offset (\(fieldHex) from Il2CppObject +0x10 header)."
                    )
                )
                il2cppFindings += 1
            }

        case .encryptedOrObfuscated:
            warnings.append("IL2CPP global-metadata.dat header is encrypted or obfuscated. Per compliance rules, protection was not bypassed.")
            limitations.append("IL2CPP class/method names cannot be statically read from encrypted global-metadata.dat; use an authorized debug build or runtime trace.")

        case .truncatedOrIncomplete:
            warnings.append("IL2CPP global-metadata.dat is incomplete or truncated.")
            limitations.append("Incomplete metadata prevented full IL2CPP table reconstruction.")

        case .notPresent:
            break
        }

        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "3. IL2CPP Analysis",
                executed: il2cppResult.availability != .notPresent,
                status: il2cppResult.availability.rawValue,
                findingsCount: il2cppFindings,
                details: il2cppResult.diagnostics.joined(separator: " ")
            )
        )
        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "4. Metadata Analysis",
                executed: true,
                status: "Completed",
                findingsCount: objcFindings + il2cppResult.relevantStringLiterals.count,
                details: "ObjC metadata entries: \(objcFindings), IL2CPP string literals: \(il2cppResult.relevantStringLiterals.count)."
            )
        )

        // [5] Symbol-less Analysis (LC_FUNCTION_STARTS + ARM64 ADRP+ADD XRefs)
        logger?.stage("OffsetDiscovery", "Running symbol-less ARM64 instruction & string cross-reference discovery...")
        var symbolLessFindings = 0
        for xref in staticOutput.arm64StringXRefs {
            guard let category = OffsetCategoryClassifier.classify(identifier: xref.referencedString) else {
                continue
            }
            if let funcVA = xref.enclosingFunctionVA, let funcRVA = xref.enclosingFunctionRVA {
                let fileOff = machO.virtualAddressToSliceFileOffset(funcVA)
                let cleanLabel = sanitizeXRefLabel(xref.referencedString, rva: funcRVA)
                let status: OffsetVerificationStatus = (xref.prologueHex != nil) ? .probable : .unverified
                var note = "Symbol-less ARM64 function (\(HexFormatters.vaHex(funcVA))) referencing string \"\(xref.referencedString)\" at \(HexFormatters.vaHex(xref.instructionVA))."
                if let fieldHint = xref.nearbyFieldOffsetHint {
                    note += String(format: " Nearby ARM64 LDR field offset access: +0x%X.", fieldHint)
                }

                appendUnique(
                    DiscoveredOffset(
                        name: cleanLabel,
                        value: HexFormatters.vaHex(funcVA),
                        moduleName: moduleName,
                        rva: funcRVA,
                        virtualAddress: funcVA,
                        fileOffset: fileOff,
                        category: category,
                        dataType: .method,
                        discoverySource: .arm64PatternAnalysis,
                        verificationStatus: status,
                        confidenceScore: 0.76,
                        signatureHint: xref.prologueHex,
                        notes: note
                    )
                )
                symbolLessFindings += 1
            } else {
                let strRVA = machO.rva(forVirtualAddress: xref.targetStringVA)
                let strFileOff = machO.virtualAddressToSliceFileOffset(xref.targetStringVA)
                appendUnique(
                    DiscoveredOffset(
                        name: "StringRef::\( sanitizeIdentifierToken(xref.referencedString) )",
                        value: HexFormatters.vaHex(xref.targetStringVA),
                        moduleName: moduleName,
                        rva: strRVA,
                        virtualAddress: xref.targetStringVA,
                        fileOffset: strFileOff,
                        category: category,
                        dataType: .staticData,
                        discoverySource: .stringCrossReference,
                        verificationStatus: .verified,
                        confidenceScore: 0.85,
                        signatureHint: "Referenced at \(HexFormatters.vaHex(xref.instructionVA))",
                        notes: "Verified read-only string literal cross-referenced by ARM64 ADRP+ADD."
                    )
                )
                symbolLessFindings += 1
            }
        }

        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "5. Symbol-less Analysis",
                executed: machO.isARM64 && !machO.encryptionInfo.isEncrypted,
                status: machO.encryptionInfo.isEncrypted ? "Blocked by FairPlay Encryption" : "Completed",
                findingsCount: symbolLessFindings,
                details: "Decoded \(machO.functionStartRVAs.count) LC_FUNCTION_STARTS entries and \(symbolLessFindings) gameplay ARM64 XRefs."
            )
        )

        if machO.encryptionInfo.isEncrypted {
            warnings.append(
                String(
                    format: "Mach-O segment encryption active (cryptid=%u, range 0x%X..0x%X). Encrypted code pages were NOT parsed as instructions to prevent false offsets.",
                    machO.encryptionInfo.cryptID,
                    machO.encryptionInfo.cryptOffset,
                    machO.encryptionInfo.cryptOffset + machO.encryptionInfo.cryptSize
                )
            )
            limitations.append("Encrypted FairPlay code segments cannot be statically disassembled without an unencrypted development IPA or authorized runtime trace.")
        }

        // [6] & [7] Runtime Inspection & Dynamic ASLR Address Mapping
        logger?.stage("RuntimeInspector", "Computing ASLR-aware dynamic address mappings...")
        let runtimeSnapshot = runtimeInspector.buildRuntimeSnapshot(
            machO: machO,
            discoveredOffsets: discovered,
            authorizedTrace: authorizedTrace
        )

        var runtimeAddedCount = 0
        if authorizedTrace != nil {
            for rtSym in runtimeSnapshot.mappedRuntimeSymbols {
                let status: OffsetVerificationStatus = machO.isVirtualAddressValid(rtSym.resolvedStaticVA) ? .verified : .probable
                appendUnique(
                    DiscoveredOffset(
                        name: rtSym.symbolName,
                        value: HexFormatters.vaHex(rtSym.resolvedStaticVA),
                        moduleName: rtSym.moduleName,
                        rva: rtSym.resolvedRVA,
                        virtualAddress: rtSym.resolvedStaticVA,
                        fileOffset: rtSym.resolvedFileOffset,
                        category: rtSym.category,
                        dataType: .runtimeData,
                        discoverySource: .runtimeInspection,
                        verificationStatus: status,
                        confidenceScore: status == .verified ? 0.95 : 0.75,
                        signatureHint: "Runtime \(HexFormatters.vaHex(rtSym.runtimeAddress)) - Slide \(HexFormatters.hex(rtSym.aslrSlide))",
                        notes: "Resolved dynamically from authorized runtime trace."
                    )
                )
                runtimeAddedCount += 1
            }
        }

        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "6. Runtime Inspection",
                executed: true,
                status: runtimeSnapshot.observationMode,
                findingsCount: runtimeAddedCount + runtimeSnapshot.hostDyldModulesSample.count,
                details: runtimeSnapshot.notes.joined(separator: " ")
            )
        )
        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "7. Dynamic Address Mapping",
                executed: true,
                status: "Completed",
                findingsCount: runtimeSnapshot.mappedRuntimeSymbols.count,
                details: "Mapped \(runtimeSnapshot.mappedRuntimeSymbols.count) entries with ASLR base \(HexFormatters.vaHex(machO.defaultBaseAddress)) + slide \(HexFormatters.hex(runtimeSnapshot.sampleASLRSlide))."
            )
        )

        // [8] Offset Validation Summary
        let verifiedTotal = discovered.filter { $0.verificationStatus == .verified }.count
        let probableTotal = discovered.filter { $0.verificationStatus == .probable }.count
        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "8. Offset Validation",
                executed: true,
                status: "Completed",
                findingsCount: verifiedTotal,
                details: "Verified \(verifiedTotal) offsets via segment bounds + ARM64 alignment/prologue checks; \(probableTotal) probable."
            )
        )

        // [9] Cross-version Comparison
        var crossDelta: CrossVersionDelta?
        if let latestPrev = previousHistory.first(where: { $0.bundleIdentifier == bundleIdentifier }) ?? previousHistory.first {
            var matched = 0
            var shifted: [String] = []
            var newlyAdded = 0

            var currentMap: [String: UInt64] = [:]
            for item in discovered where item.rva > 0 {
                currentMap[item.name] = item.rva
            }

            for (name, curRVA) in currentMap {
                if let prevRVA = latestPrev.offsetSnapshotByName[name] {
                    matched += 1
                    if prevRVA != curRVA {
                        shifted.append("\(name): \(HexFormatters.rvaHex(prevRVA)) -> \(HexFormatters.rvaHex(curRVA))")
                    }
                } else {
                    newlyAdded += 1
                }
            }
            let missing = latestPrev.offsetSnapshotByName.keys.filter { currentMap[$0] == nil }.count

            crossDelta = CrossVersionDelta(
                previousVersion: latestPrev.bundleVersion,
                currentVersion: bundleVersion,
                matchedCount: matched,
                shiftedRVAEntries: Array(shifted.prefix(50)),
                newlyAddedCount: newlyAdded,
                missingCount: missing
            )
        }

        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "9. Cross-version Comparison",
                executed: crossDelta != nil,
                status: crossDelta != nil ? "Compared vs v\(crossDelta!.previousVersion)" : "Baseline Version Recorded",
                findingsCount: crossDelta?.shiftedRVAEntries.count ?? 0,
                details: crossDelta.map {
                    "Matched \($0.matchedCount) offsets, \($0.shiftedRVAEntries.count) shifted RVAs, \($0.newlyAddedCount) added, \($0.missingCount) removed."
                } ?? "Initial baseline snapshot saved to history.json for future cross-version diffing."
            )
        )

        // Sort deterministically: Verified first, then Probable, Unverified, Undetermined; then by category & name
        let rank: [OffsetVerificationStatus: Int] = [
            .verified: 0,
            .probable: 1,
            .unverified: 2,
            .undetermined: 3
        ]
        discovered.sort { lhs, rhs in
            let r0 = rank[lhs.verificationStatus] ?? 4
            let r1 = rank[rhs.verificationStatus] ?? 4
            if r0 != r1 { return r0 < r1 }
            if lhs.category != rhs.category { return lhs.category.rawValue < rhs.category.rawValue }
            return lhs.name < rhs.name
        }

        logger?.success(
            stage: "OffsetDiscovery",
            "Completed discovery: \(discovered.count) total offsets (Verified: \(verifiedTotal), Probable: \(probableTotal))."
        )

        return OffsetDiscoveryBundle(
            offsets: discovered,
            runtimeSnapshot: runtimeSnapshot,
            methodSummaries: methodSummaries,
            crossVersionDelta: crossDelta,
            warnings: warnings,
            limitations: limitations
        )
    }

    // MARK: - Internal Validation & Matching Helpers

    private func validateExecutableOrDataAddress(
        va: UInt64,
        expectExecutable: Bool,
        staticOutput: StaticBinaryAnalysisOutput
    ) -> (status: OffsetVerificationStatus, confidence: Double, prologueHex: String?, note: String) {
        let machO = staticOutput.machO
        guard va > 0, machO.isVirtualAddressValid(va, requireExecutable: expectExecutable) else {
            return (.unverified, 0.40, nil, "Virtual address falls outside expected Mach-O segment bounds.")
        }
        guard let fileOff = machO.virtualAddressToSliceFileOffset(va) else {
            return (.probable, 0.70, nil, "Zero-fill VM address (valid in virtual memory, no static file backing).")
        }
        if machO.isSliceRangeEncrypted(offset: fileOff, length: 4) {
            return (
                .unverified,
                0.60,
                nil,
                "Symbol table entry points into FairPlay encrypted region; instruction bytes cannot be statically verified."
            )
        }
        if expectExecutable && machO.isARM64 {
            if (va & 0x3) != 0 {
                return (.unverified, 0.35, nil, "Address is not 4-byte aligned for ARM64 instruction execution.")
            }
            if let sig = ARM64InstructionAnalyzer.readPrologueSignature(
                sliceCursor: staticOutput.sliceCursor,
                machO: machO,
                virtualAddress: va
            ), sig.validEncoding {
                let conf = sig.hasStandardPrologue ? 0.98 : 0.92
                return (
                    .verified,
                    conf,
                    sig.hex,
                    sig.hasStandardPrologue
                        ? "Verified ARM64 function prologue & Mach-O __TEXT mapping."
                        : "Verified executable ARM64 instruction sequence in __TEXT."
                )
            }
        }
        return (.verified, 0.90, nil, "Verified static Mach-O segment & file offset mapping.")
    }

    private func matchIL2CPPSymbol(
        className: String,
        methodName: String?,
        symbolMap: [String: SymbolEntry]
    ) -> SymbolEntry? {
        let shortClass = className.split(separator: ".").last.map(String.init) ?? className
        if let methodName {
            // Standard IL2CPP symbol naming convention: ClassName_MethodName_m... or ClassName_MethodName
            let candidates = [
                "\(shortClass)_\(methodName)".lowercased(),
                "\(className.replacingOccurrences(of: ".", with: "_"))_\(methodName)".lowercased()
            ]
            for c in candidates {
                if let exact = symbolMap[c] { return exact }
            }
            for (symKey, entry) in symbolMap where symKey.hasPrefix(candidates[0] + "_") {
                return entry
            }
        } else {
            let key = "\(shortClass)_il2cpp_TypeInfo".lowercased()
            if let exact = symbolMap[key] { return exact }
        }
        return nil
    }

    private func sanitizeIdentifierToken(_ raw: String) -> String {
        let allowed = CharacterSet.alphanumerics.union(CharacterSet(charactersIn: "_.:"))
        let cleaned = raw.unicodeScalars.map { allowed.contains($0) ? Character($0) : "_" }
        return String(cleaned.prefix(56))
    }

    private func sanitizeXRefLabel(_ raw: String, rva: UInt64) -> String {
        let token = sanitizeIdentifierToken(raw)
        return String(format: "sub_%llX_ref_%@", rva, token)
    }
}
