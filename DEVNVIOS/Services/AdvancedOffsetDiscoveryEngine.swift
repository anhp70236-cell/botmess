import Foundation

public enum OffsetCategoryClassifier {
    private static let rules: [(category: OffsetCategory, tokens: [String])] = [
        (.playerHealth, [
            "playerhealth", "maxhealth", "currenthealth", "curhealth", "basehealth", "healthcomponent",
            "sethealth", "get_health", "set_health", "takehealth", "addhealth", "heal", "regen",
            "hitpoint", "maxhp", "curhp", "get_hp", "set_hp", "_hp", "shield", "maxshield",
            "armor", "maxarmor", "takedamage", "applydamage", "ondamage", "vitality", "lifepoint"
        ]),
        (.playerState, [
            "playerstate", "isalive", "isdead", "ondie", "respawn", "revive", "stamina", "maxstamina",
            "energy", "maxenergy", "mana", "maxmana", "get_mp", "set_mp", "invulnerable", "invincible",
            "immunity", "combatstate", "buff", "debuff", "statuscontroller", "stun", "freeze"
        ]),
        (.position, [
            "position", "localposition", "worldposition", "get_position", "set_position",
            "actorlocation", "teleport", "coordinates", "spawnpoint", "waypoint", "footposition", "headposition"
        ]),
        (.movement, [
            "movement", "locomotion", "velocity", "movespeed", "walkspeed", "runspeed", "sprintspeed",
            "sprint", "dash", "jump", "jumpforce", "jumpheight", "gravity", "acceleration",
            "charactercontroller", "charactermovement", "rigidbody", "navmesh"
        ]),
        (.rotation, [
            "rotation", "localrotation", "quaternion", "eulerangles", "get_yaw", "set_yaw",
            "get_pitch", "set_pitch", "rollangle", "get_roll", "set_roll",
            "aimrotation", "lookrotation", "lookat", "turnspeed", "aiming", "orientation"
        ]),
        (.camera, [
            "camera", "maincamera", "fieldofview", "get_fieldofview", "set_fieldofview",
            "get_fov", "set_fov", "camerafov", "camerafollow", "cameracontroller",
            "springarm", "viewport", "worldtoscreen", "screenpointtoray", "cinemachine", "camerazoom"
        ]),
        (.animation, [
            "animator", "animation", "animstate", "animinstance", "crossfade", "blendtree",
            "playspeed", "animspeed", "montage", "ragdoll", "rootmotion"
        ]),
        (.character, [
            "character", "avatar", "humanoid", "hero", "skinmesh", "skeleton", "headbone",
            "bonepos", "hitbox", "hurtbox", "capsulecollider", "characterstats", "attributes"
        ]),
        (.player, [
            "player", "localplayer", "mainplayer", "myplayer", "playercontroller",
            "playermanager", "playerinput", "playerdata", "localpawn"
        ]),
        (.gameObjects, [
            "gameobject", "transform", "entity", "actor", "enemy", "monster", "boss", "npc",
            "weapon", "guncontroller", "ammo", "projectile", "bullet", "skill", "ability", "spawner"
        ]),
        (.gameState, [
            "gamestate", "gamemanager", "matchstate", "roundmanager", "levelmanager",
            "timescale", "set_timescale", "sessionstate", "worldstate", "wavemanager", "mission"
        ]),
        (.gameplayVariables, [
            "cooldown", "firerate", "attackspeed", "attackrange", "weaponrange", "recoil", "bulletspread",
            "critrate", "critical", "defense", "difficulty", "experience", "get_exp", "set_exp", "gameplay"
        ])
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

/// Deep multi-source static & ASLR offset analysis engine (strictly excluding real-money/financial identifiers
/// and never bypassing FairPlay DRM or third-party protections).
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
            guard SafetyPolicyFilter.isPermittedIdentifier(offset.name) else { return }
            let dedupKey = "\(offset.moduleName)|\(offset.name)|\(offset.dataType.rawValue)|\(offset.rva)"
            guard !seenKeys.contains(dedupKey) else { return }
            seenKeys.insert(dedupKey)
            discovered.append(offset)
        }

        // [1] & [2] Mach-O Symbol Table & C++/Swift Demangled Analysis
        logger?.stage("OffsetDiscovery", "Đang quét bảng ký hiệu Mach-O & giải mã tên hàm C++/Swift...")
        var symbolFindings = 0
        let symbolLookupByCleanSuffix: [String: SymbolEntry] = {
            var map: [String: SymbolEntry] = [:]
            for sym in machO.symbols {
                map[sym.cleanName.lowercased()] = sym
                let rawTrim = sym.name.hasPrefix("_") ? String(sym.name.dropFirst()).lowercased() : sym.name.lowercased()
                map[rawTrim] = sym
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
            let bodyProfile: (prologueHex: String?, thisFieldOffsets: [UInt32], validCode: Bool) = sym.isFunctionSymbol
                ? ARM64InstructionAnalyzer.profileFunctionBody(
                    sliceCursor: staticOutput.sliceCursor,
                    machO: machO,
                    functionVA: sym.virtualAddress
                )
                : (prologueHex: nil, thisFieldOffsets: [], validCode: false)

            let callers = staticOutput.incomingCallCountsByFuncVA[sym.virtualAddress] ?? 0
            var noteParts: [String] = [validation.note]
            if callers > 0 { noteParts.append("Số lời gọi BL trực tiếp: \(callers).") }
            if !bodyProfile.thisFieldOffsets.isEmpty {
                let fList = bodyProfile.thisFieldOffsets.map { String(format: "+0x%X", $0) }.joined(separator: ", ")
                noteParts.append("Truy cập trường [X0]: \(fList).")
            }

            appendUnique(
                DiscoveredOffset(
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
                    signatureHint: bodyProfile.prologueHex ?? validation.prologueHex,
                    notes: noteParts.joined(separator: " ")
                )
            )
            symbolFindings += 1

            // Emit machine-code verified X0 field offsets discovered inside getter/setter functions
            for fOff in bodyProfile.thisFieldOffsets.prefix(2) {
                appendUnique(
                    DiscoveredOffset(
                        name: "\(sym.cleanName) -> [X0 + 0x\(String(fOff, radix: 16, uppercase: true))]",
                        value: String(format: "0x%X", fOff),
                        moduleName: moduleName,
                        rva: UInt64(fOff),
                        virtualAddress: sym.virtualAddress,
                        fileOffset: sym.fileOffset,
                        category: category,
                        dataType: .field,
                        discoverySource: .arm64PatternAnalysis,
                        verificationStatus: .verified,
                        confidenceScore: 0.93,
                        signatureHint: "ARM64 LDR/STR [X0, #0x\(String(fOff, radix: 16, uppercase: true))]",
                        notes: "Trường bộ nhớ thực tế đọc/ghi trực tiếp qua con trỏ đối tượng X0 bên trong hàm \(HexFormatters.rvaHex(sym.rva))."
                    )
                )
                symbolFindings += 1
            }
        }

        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "1. Phân tích Nhị phân Tĩnh (Static Binary)",
                executed: true,
                status: machO.encryptionInfo.isEncrypted ? "Một phần (Bỏ qua trang mã hóa)" : "Hoàn tất",
                findingsCount: staticOutput.cStringsByVA.count + staticOutput.arm64StringXRefs.count,
                details: "Engine: \(staticOutput.detectedEngineSummary) | Chuỗi: \(staticOutput.cStringsByVA.count) | ARM64 XRefs: \(staticOutput.arm64StringXRefs.count)."
            )
        )
        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "2. Phân tích Cấu trúc Mach-O 64-bit",
                executed: true,
                status: "Hoàn tất",
                findingsCount: symbolFindings,
                details: "Kiến trúc \(machO.architectureName), \(machO.segments.count) phân vùng, \(machO.symbols.count) ký hiệu (\(staticOutput.symbolStripStatus.rawValue))."
            )
        )

        // Objective-C Resolved IMPs & Class/Selector Metadata
        var objcFindings = 0
        for resolvedIMP in staticOutput.objcResolvedMethods {
            let fullSel = "-[\(resolvedIMP.className) \(resolvedIMP.selectorName)]"
            guard let category = OffsetCategoryClassifier.classify(identifier: fullSel) else { continue }
            let profile = ARM64InstructionAnalyzer.profileFunctionBody(
                sliceCursor: staticOutput.sliceCursor,
                machO: machO,
                functionVA: resolvedIMP.impVirtualAddress
            )
            appendUnique(
                DiscoveredOffset(
                    name: fullSel,
                    value: HexFormatters.vaHex(resolvedIMP.impVirtualAddress),
                    moduleName: moduleName,
                    rva: resolvedIMP.impRVA,
                    virtualAddress: resolvedIMP.impVirtualAddress,
                    fileOffset: resolvedIMP.impFileOffset,
                    category: category,
                    dataType: .method,
                    discoverySource: .objcRuntimeMetadata,
                    verificationStatus: profile.validCode ? .verified : .probable,
                    confidenceScore: profile.validCode ? 0.97 : 0.85,
                    signatureHint: profile.prologueHex ?? "__objc_const IMP",
                    notes: "Địa chỉ hàm thực thi IMP giải mã trực tiếp từ cấu trúc __objc_classlist."
                )
            )
            objcFindings += 1
        }

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
                    notes: "Tên lớp Objective-C trong __TEXT,__objc_classname."
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
                    notes: "Selector Objective-C trong __TEXT,__objc_methname."
                )
            )
            objcFindings += 1
        }

        // [3] & [4] Deep IL2CPP CodeGenModule RVA + Metadata Analysis
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
            logger?.stage("OffsetDiscovery", "Đang chạy quét sâu IL2CPP CodeGenModule & đối chiếu bảng MethodPointers...")
            let codeGenMethodVAs = IL2CPPBinaryRegistrationScanner.resolveMethodVirtualAddresses(
                machO: machO,
                sliceCursor: staticOutput.sliceCursor,
                images: il2cppResult.images,
                logger: logger
            )

            // Track machine-code verified getter/setter field offsets by ClassName + Property stem!
            var verifiedFieldOffsetByPropertyKey: [String: (offset: UInt32, methodRVA: UInt64)] = [:]

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
                            notes: "Lớp IL2CPP khớp trực tiếp với ký hiệu nhị phân \(matchedSym.cleanName)."
                        )
                    )
                    il2cppFindings += 1
                }
            }

            for methodRec in il2cppResult.methods {
                if il2cppFindings > 12_000 { break }
                let fullMethod = "\(methodRec.declaringClassFullName).\(methodRec.methodName)"
                guard let category = OffsetCategoryClassifier.classify(identifier: fullMethod) else { continue }
                let tokenHex = String(format: "0x%08X", methodRec.token)

                // Priority 1: Exact RVA from Il2CppCodeGenModule.methodPointers!
                if let codeGenVA = codeGenMethodVAs[methodRec.methodIndex] {
                    let rva = machO.rva(forVirtualAddress: codeGenVA)
                    let fileOff = machO.virtualAddressToSliceFileOffset(codeGenVA)
                    let profile = ARM64InstructionAnalyzer.profileFunctionBody(
                        sliceCursor: staticOutput.sliceCursor,
                        machO: machO,
                        functionVA: codeGenVA
                    )

                    if let firstField = profile.thisFieldOffsets.first {
                        let propStem = Self.extractPropertyStem(from: methodRec.methodName)
                        if !propStem.isEmpty {
                            verifiedFieldOffsetByPropertyKey["\(methodRec.declaringClassFullName)|\(propStem.lowercased())"] = (firstField, rva)
                        }
                    }

                    var note = "Giải mã chính xác RVA hàm từ bảng Il2CppCodeGenModule.methodPointers (Token \(tokenHex))."
                    if !profile.thisFieldOffsets.isEmpty {
                        let fl = profile.thisFieldOffsets.map { String(format: "+0x%X", $0) }.joined(separator: ", ")
                        note += " Offset trường [X0] trong thân hàm: \(fl)."
                    }

                    appendUnique(
                        DiscoveredOffset(
                            name: fullMethod,
                            value: HexFormatters.vaHex(codeGenVA),
                            moduleName: moduleName,
                            rva: rva,
                            virtualAddress: codeGenVA,
                            fileOffset: fileOff,
                            category: category,
                            dataType: .method,
                            discoverySource: .il2cppCodeGen,
                            verificationStatus: profile.validCode ? .verified : .probable,
                            confidenceScore: profile.validCode ? 0.99 : 0.90,
                            signatureHint: profile.prologueHex ?? "MethodDef \(tokenHex)",
                            notes: note
                        )
                    )
                    il2cppFindings += 1
                    continue
                }

                // Priority 2: Exact RVA from Mach-O symbol table
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
                    let profile = ARM64InstructionAnalyzer.profileFunctionBody(
                        sliceCursor: staticOutput.sliceCursor,
                        machO: machO,
                        functionVA: matchedSym.virtualAddress
                    )
                    if let firstField = profile.thisFieldOffsets.first {
                        let propStem = Self.extractPropertyStem(from: methodRec.methodName)
                        if !propStem.isEmpty {
                            verifiedFieldOffsetByPropertyKey["\(methodRec.declaringClassFullName)|\(propStem.lowercased())"] = (firstField, matchedSym.rva)
                        }
                    }

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
                            signatureHint: profile.prologueHex ?? valCheck.prologueHex ?? "MethodDef \(tokenHex)",
                            notes: "Giải mã RVA hàm IL2CPP thông qua liên kết ký hiệu Mach-O (\(matchedSym.cleanName))."
                        )
                    )
                    il2cppFindings += 1
                    continue
                }

                // Priority 3: ARM64 ADRP+ADD XRef correlation
                if let xref = xrefByString[methodRec.methodName] ?? xrefByString[methodRec.declaringClassFullName],
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
                            notes: "Tương quan địa chỉ hàm ARM64 thông qua tham chiếu chuỗi ADRP+ADD tới '\(xref.referencedString)'."
                        )
                    )
                    il2cppFindings += 1
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
                            signatureHint: "MethodDef \(tokenHex) (\(methodRec.parameterCount) tham số)",
                            notes: machO.encryptionInfo.isEncrypted
                                ? "Hàm tồn tại trong global-metadata.dat nhưng phân vùng code đang mã hóa FairPlay (cryptid!=0)."
                                : "Metadata Token hợp lệ trong global-metadata.dat."
                        )
                    )
                    il2cppFindings += 1
                }
            }

            // Fields — upgrade with machine-code verified [X0 + #offset] whenever getter/setter was disassembled!
            for fieldRec in il2cppResult.fields {
                if il2cppFindings > 16_000 { break }
                let fullField = "\(fieldRec.declaringClassFullName).\(fieldRec.fieldName)"
                guard let category = OffsetCategoryClassifier.classify(identifier: fullField) else { continue }

                let normalizedFieldStem = Self.normalizeFieldStem(fieldRec.fieldName)
                let lookupKey = "\(fieldRec.declaringClassFullName)|\(normalizedFieldStem)"

                if let verifiedPair = verifiedFieldOffsetByPropertyKey[lookupKey] {
                    let hexOff = String(format: "0x%X", verifiedPair.offset)
                    appendUnique(
                        DiscoveredOffset(
                            name: fullField,
                            value: hexOff,
                            moduleName: moduleName,
                            rva: UInt64(verifiedPair.offset),
                            virtualAddress: 0,
                            fileOffset: nil,
                            category: category,
                            dataType: .field,
                            discoverySource: .il2cppCodeGen,
                            verificationStatus: .verified,
                            confidenceScore: 0.98,
                            signatureHint: String(format: "FieldDef 0x%08X • Lệnh ARM64 tại RVA 0x%08llX", fieldRec.token, verifiedPair.methodRVA),
                            notes: "Offset trường dữ liệu (\(hexOff)) được xác minh trực tiếp từ lệnh đọc/ghi thanh ghi [X0] trong hàm getter/setter!"
                        )
                    )
                } else {
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
                            signatureHint: String(format: "FieldDef 0x%08X (thứ tự #%d)", fieldRec.token, fieldRec.fieldOrdinalInClass),
                            notes: "Offset trường đối tượng IL2CPP 64-bit (\(fieldHex) tính từ header Il2CppObject +0x10)."
                        )
                    )
                }
                il2cppFindings += 1
            }

        case .encryptedOrObfuscated:
            warnings.append("Tệp global-metadata.dat đã bị mã hóa hoặc làm rối header (không vượt cơ chế bảo vệ).")
            limitations.append("Không đọc tĩnh tên lớp/hàm từ global-metadata.dat bị mã hóa; vẫn thực hiện quét Mach-O, ObjC và ARM64.")

        case .truncatedOrIncomplete:
            warnings.append("Tệp global-metadata.dat bị cắt cụt hoặc không đầy đủ.")
            limitations.append("Metadata không đầy đủ nên không thể tái tạo trọn vẹn bảng lớp IL2CPP.")

        case .notPresent:
            break
        }

        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "3. Phân tích Chuyên sâu IL2CPP & CodeGenModule",
                executed: il2cppResult.availability != .notPresent,
                status: il2cppResult.availability.rawValue,
                findingsCount: il2cppFindings,
                details: il2cppResult.diagnostics.joined(separator: " ")
            )
        )
        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "4. Phân tích Metadata & Objective-C IMP",
                executed: true,
                status: "Hoàn tất",
                findingsCount: objcFindings + il2cppResult.relevantStringLiterals.count,
                details: "Hàm/Lớp ObjC: \(objcFindings), Chuỗi hằng IL2CPP: \(il2cppResult.relevantStringLiterals.count)."
            )
        )

        // [5] Symbol-less Analysis (LC_FUNCTION_STARTS + ARM64 ADRP+ADD / ADRP+LDR XRefs)
        logger?.stage("OffsetDiscovery", "Đang quét hàm không ký hiệu (LC_FUNCTION_STARTS + ARM64 ADRP/ADD/LDR)...")
        var symbolLessFindings = 0
        for xref in staticOutput.arm64StringXRefs {
            guard let category = OffsetCategoryClassifier.classify(identifier: xref.referencedString) else {
                continue
            }
            if let funcVA = xref.enclosingFunctionVA, let funcRVA = xref.enclosingFunctionRVA {
                let fileOff = machO.virtualAddressToSliceFileOffset(funcVA)
                let cleanLabel = sanitizeXRefLabel(xref.referencedString, rva: funcRVA)
                let status: OffsetVerificationStatus = (xref.prologueHex != nil) ? .probable : .unverified
                let callers = staticOutput.incomingCallCountsByFuncVA[funcVA] ?? 0
                var note = "Hàm ARM64 không ký hiệu (\(HexFormatters.vaHex(funcVA))) tham chiếu chuỗi \"\(xref.referencedString)\" tại \(HexFormatters.vaHex(xref.instructionVA))."
                if !xref.structFieldOffsets.isEmpty {
                    let formattedFields = xref.structFieldOffsets.prefix(4).map { String(format: "+0x%X", $0) }.joined(separator: ", ")
                    note += " Truy cập trường cấu trúc: [\(formattedFields)]."
                }
                if callers > 0 {
                    note += " Số nơi gọi BL tới hàm này: \(callers)."
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
                        confidenceScore: 0.78,
                        signatureHint: xref.prologueHex,
                        notes: note
                    )
                )
                symbolLessFindings += 1

                for dataRef in staticOutput.arm64DataXRefs where dataRef.enclosingFunctionVA == funcVA {
                    let dataFileOff = machO.virtualAddressToSliceFileOffset(dataRef.targetDataVA)
                    appendUnique(
                        DiscoveredOffset(
                            name: String(format: "GlobalPtr_%llX_in_%@", dataRef.targetDataRVA, sanitizeIdentifierToken(xref.referencedString)),
                            value: HexFormatters.vaHex(dataRef.targetDataVA),
                            moduleName: moduleName,
                            rva: dataRef.targetDataRVA,
                            virtualAddress: dataRef.targetDataVA,
                            fileOffset: dataFileOff,
                            category: category,
                            dataType: .staticData,
                            discoverySource: .arm64PatternAnalysis,
                            verificationStatus: .probable,
                            confidenceScore: 0.75,
                            signatureHint: dataRef.sectionDescription,
                            notes: "Con trỏ toàn cục trong \(dataRef.sectionDescription) được nạp qua ADRP+LDR bên trong hàm \(HexFormatters.vaHex(funcVA))."
                        )
                    )
                    symbolLessFindings += 1
                }
            } else {
                let strRVA = machO.rva(forVirtualAddress: xref.targetStringVA)
                let strFileOff = machO.virtualAddressToSliceFileOffset(xref.targetStringVA)
                appendUnique(
                    DiscoveredOffset(
                        name: "StringRef::\(sanitizeIdentifierToken(xref.referencedString))",
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
                        signatureHint: "Tham chiếu tại \(HexFormatters.vaHex(xref.instructionVA))",
                        notes: "Chuỗi tĩnh được tham chiếu trực tiếp bởi cặp lệnh ARM64 ADRP+ADD."
                    )
                )
                symbolLessFindings += 1
            }
        }

        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "5. Phân tích Binary Đã Strip Symbol (ARM64 XRef)",
                executed: machO.isARM64 && !machO.encryptionInfo.isEncrypted,
                status: machO.encryptionInfo.isEncrypted ? "Tạm dừng do FairPlay cryptid!=0" : "Hoàn tất",
                findingsCount: symbolLessFindings,
                details: "Quét \(machO.functionStartRVAs.count) hàm LC_FUNCTION_STARTS và \(symbolLessFindings) điểm tham chiếu gameplay."
            )
        )

        if machO.encryptionInfo.isEncrypted {
            warnings.append(
                String(
                    format: "Tệp thực thi đang bật mã hóa FairPlay từ App Store (cryptid=%u, vùng 0x%X..0x%X). Để dò RVA lệnh máy ARM64 đầy đủ, hãy dùng bản IPA chưa mã hóa (cryptid=0).",
                    machO.encryptionInfo.cryptID,
                    machO.encryptionInfo.cryptOffset,
                    machO.encryptionInfo.cryptOffset + machO.encryptionInfo.cryptSize
                )
            )
            limitations.append("Các trang mã máy đang mã hóa FairPlay không được giải mã giả lập nhằm đảm bảo tính chính xác 100% (không tạo offset ảo).")
        }

        // [6] & [7] Runtime Inspection & Dynamic ASLR Mapping
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
                        notes: "Ánh xạ động từ bản ghi Runtime được cấp quyền."
                    )
                )
                runtimeAddedCount += 1
            }
        }

        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "6. Quan sát Runtime & Dyld Slide",
                executed: true,
                status: runtimeSnapshot.observationMode,
                findingsCount: runtimeAddedCount + runtimeSnapshot.hostDyldModulesSample.count,
                details: runtimeSnapshot.notes.joined(separator: " ")
            )
        )
        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "7. Ánh xạ Địa chỉ Động ASLR (VA/RVA/FileOffset)",
                executed: true,
                status: "Hoàn tất",
                findingsCount: runtimeSnapshot.mappedRuntimeSymbols.count,
                details: "Ánh xạ \(runtimeSnapshot.mappedRuntimeSymbols.count) địa chỉ với ASLR Base \(HexFormatters.vaHex(machO.defaultBaseAddress))."
            )
        )

        // [8] Offset Validation
        let verifiedTotal = discovered.filter { $0.verificationStatus == .verified }.count
        let probableTotal = discovered.filter { $0.verificationStatus == .probable }.count
        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "8. Kiểm chứng Độ chính xác Offset",
                executed: true,
                status: "Hoàn tất",
                findingsCount: verifiedTotal,
                details: "Đã xác minh \(verifiedTotal) offset (kiểm tra biên phân vùng + căn lề 4-byte ARM64 + prologue); \(probableTotal) khả năng cao."
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
                methodName: "9. Đối chiếu Chênh lệch Xuyên Phiên bản",
                executed: crossDelta != nil,
                status: crossDelta != nil ? "Đã so sánh với v\(crossDelta!.previousVersion)" : "Đã lưu mốc phiên bản đầu",
                findingsCount: crossDelta?.shiftedRVAEntries.count ?? 0,
                details: crossDelta.map {
                    "Khớp \($0.matchedCount) offset, đổi địa chỉ RVA: \($0.shiftedRVAEntries.count), mới: \($0.newlyAddedCount), mất: \($0.missingCount)."
                } ?? "Đã ghi nhận mốc cơ sở vào history.json để tự động so sánh khi cập nhật phiên bản mới."
            )
        )

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
            "Hoàn tất dò chuyên sâu: \(discovered.count) offset (Đã xác minh: \(verifiedTotal), Khả năng cao: \(probableTotal))."
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

    // MARK: - Helpers

    private static func extractPropertyStem(from methodName: String) -> String {
        let lower = methodName.lowercased()
        for prefix in ["get_", "set_"] where lower.hasPrefix(prefix) {
            return String(methodName.dropFirst(prefix.count))
        }
        return ""
    }

    private static func normalizeFieldStem(_ fieldName: String) -> String {
        var s = fieldName
        if s.hasPrefix("<"), let closeIdx = s.firstIndex(of: ">") {
            return String(s[s.index(after: s.startIndex)..<closeIdx]).lowercased()
        }
        for p in ["m_", "_", "s_"] where s.hasPrefix(p) {
            s = String(s.dropFirst(p.count))
            break
        }
        return s.lowercased()
    }

    private func validateExecutableOrDataAddress(
        va: UInt64,
        expectExecutable: Bool,
        staticOutput: StaticBinaryAnalysisOutput
    ) -> (status: OffsetVerificationStatus, confidence: Double, prologueHex: String?, note: String) {
        let machO = staticOutput.machO
        guard va > 0, machO.isVirtualAddressValid(va, requireExecutable: expectExecutable) else {
            return (.unverified, 0.40, nil, "Địa chỉ ảo nằm ngoài phân vùng Mach-O mong đợi.")
        }
        guard let fileOff = machO.virtualAddressToSliceFileOffset(va) else {
            return (.probable, 0.70, nil, "Biến bộ nhớ ảo khởi tạo bằng 0 (__bss / zero-fill).")
        }
        if machO.isSliceRangeEncrypted(offset: fileOff, length: 4) {
            return (
                .unverified,
                0.60,
                nil,
                "Ký hiệu trỏ vào phân vùng mã hóa FairPlay; không thể xác minh byte lệnh máy tĩnh."
            )
        }
        if expectExecutable && machO.isARM64 {
            if (va & 0x3) != 0 {
                return (.unverified, 0.35, nil, "Địa chỉ không căn lề 4-byte của tập lệnh ARM64.")
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
                        ? "Đã xác minh Prologue hàm ARM64 chuẩn & phân vùng __TEXT."
                        : "Đã xác minh chuỗi lệnh thực thi ARM64 hợp lệ trong __TEXT."
                )
            }
        }
        return (.verified, 0.90, nil, "Đã xác minh ánh xạ phân vùng tĩnh Mach-O & File Offset.")
    }

    private func matchIL2CPPSymbol(
        className: String,
        methodName: String?,
        symbolMap: [String: SymbolEntry]
    ) -> SymbolEntry? {
        let shortClass = className.split(separator: ".").last.map(String.init) ?? className
        if let methodName {
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
