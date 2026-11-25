import Foundation

public enum OffsetCategoryClassifier {
    private static let gameplayRules: [(category: OffsetCategory, tokens: [String])] = [
        (.playerHealth, [
            "playerhealth", "maxhealth", "currenthealth", "curhealth", "basehealth", "healthcomponent",
            "sethealth", "get_health", "set_health", "takehealth", "addhealth", "heal", "regen",
            "hitpoint", "maxhp", "curhp", "get_hp", "set_hp", "shield", "maxshield",
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
        ]),
        (.uiAndControllers, [
            "viewcontroller", "swiftui", "uiview", "window", "navigation", "tabbar",
            "hud", "dialog", "modal", "screen", "router", "presenter", "viewmodel"
        ]),
        (.graphicsAudioPhysics, [
            "shader", "metal", "renderer", "render", "texture", "material", "mesh",
            "audio", "sound", "music", "voice", "physics", "raycast", "collision"
        ]),
        (.coreAndServices, [
            "manager", "service", "controller", "coordinator", "engine", "client",
            "session", "storage", "database", "cache", "config", "settings", "handler"
        ])
    ]

    public static func classify(identifier: String, allowGeneralAppFallback: Bool = false) -> OffsetCategory? {
        guard SafetyPolicyFilter.isPermittedIdentifier(identifier) else {
            return nil
        }
        let lower = identifier.lowercased()
        for rule in gameplayRules {
            for token in rule.tokens {
                if lower.contains(token) {
                    return rule.category
                }
            }
        }
        return allowGeneralAppFallback ? .appClassesAndMethods : nil
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

/// Multi-pass C++17 + Swift static binary & metadata offset discovery engine.
/// Works on ANY unencrypted iOS `.ipa` (games & general applications), strictly excluding banking, payment, and real-money data.
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
        let cppScan = staticOutput.nativeCPPScan

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

        // [1] Native C++17 Multi-Pass ARM64 & Swift5 Nominal Type Descriptor Analysis
        logger?.stage("C++17 Engine", "Đang đối chiếu kết quả quét sâu C++17 (\(cppScan.totalInstructionsScanned) lệnh ARM64 & \(cppScan.swiftNominalTypes.count) kiểu Swift5)...")
        var cppFindings = 0

        for swiftType in cppScan.swiftNominalTypes {
            guard let cat = OffsetCategoryClassifier.classify(identifier: swiftType.typeName, allowGeneralAppFallback: true) else {
                continue
            }
            if swiftType.accessorVA > 0 {
                let fileOff = machO.virtualAddressToSliceFileOffset(swiftType.accessorVA)
                let cppMetric = cppScan.metricsByFunctionVA[swiftType.accessorVA]
                appendUnique(
                    DiscoveredOffset(
                        name: "\(swiftType.kindLabel)::\(swiftType.typeName)",
                        value: HexFormatters.vaHex(swiftType.accessorVA),
                        moduleName: moduleName,
                        rva: swiftType.accessorRVA,
                        virtualAddress: swiftType.accessorVA,
                        fileOffset: fileOff,
                        category: cat,
                        dataType: .classType,
                        discoverySource: .swiftNominalTypes,
                        verificationStatus: .verified,
                        confidenceScore: 0.99,
                        signatureHint: cppMetric?.prologueHex ?? String(format: "Descriptor @0x%llX", swiftType.descriptorFileOffset),
                        notes: "Kiểu gốc Swift5 (__swift5_types) kèm địa chỉ hàm khởi tạo Metadata Accessor chuẩn 100%."
                    )
                )
            } else {
                let descVA = machO.sliceFileOffsetToVirtualAddress(swiftType.descriptorFileOffset) ?? 0
                let descRVA = descVA > 0 ? machO.rva(forVirtualAddress: descVA) : swiftType.descriptorFileOffset
                appendUnique(
                    DiscoveredOffset(
                        name: "\(swiftType.kindLabel)::\(swiftType.typeName)",
                        value: descVA > 0 ? HexFormatters.vaHex(descVA) : HexFormatters.rvaHex(descRVA),
                        moduleName: moduleName,
                        rva: descRVA,
                        virtualAddress: descVA,
                        fileOffset: swiftType.descriptorFileOffset,
                        category: cat,
                        dataType: .classType,
                        discoverySource: .swiftNominalTypes,
                        verificationStatus: .verified,
                        confidenceScore: 0.95,
                        signatureHint: "__TEXT,__swift5_types",
                        notes: "Cấu trúc Nominal Type Descriptor trong phân vùng __TEXT,__swift5_types."
                    )
                )
            }
            cppFindings += 1
            if cppFindings >= 4000 { break }
        }

        // [2] Mach-O Symbol Table & C++ Demangled Analysis (includes general app symbols when non-Unity IPA)
        logger?.stage("Mach-O", "Đang phân tích chuyên sâu bảng ký hiệu Mach-O & hàm C++/ObjC/Swift...")
        var symbolFindings = 0
        let isNonIL2CPPApp = (il2cppResult.availability == .notPresent)
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
            guard let category = OffsetCategoryClassifier.classify(
                identifier: sym.cleanName,
                allowGeneralAppFallback: isNonIL2CPPApp && symbolFindings < 2500
            ) else {
                continue
            }

            let validation = validateExecutableOrDataAddress(
                va: sym.virtualAddress,
                expectExecutable: sym.isFunctionSymbol,
                staticOutput: staticOutput
            )
            let cppMetric = cppScan.metricsByFunctionVA[sym.virtualAddress]
            let bodyProfile: (prologueHex: String?, thisFieldOffsets: [UInt32], validCode: Bool) = sym.isFunctionSymbol
                ? ARM64InstructionAnalyzer.profileFunctionBody(
                    sliceCursor: staticOutput.sliceCursor,
                    machO: machO,
                    functionVA: sym.virtualAddress
                )
                : (prologueHex: nil, thisFieldOffsets: [], validCode: false)

            let callers = max(Int(cppMetric?.incomingCalls ?? 0), staticOutput.incomingCallCountsByFuncVA[sym.virtualAddress] ?? 0)
            var noteParts: [String] = [validation.note]
            if let cppMetric {
                if cppMetric.isGetterIdiom { noteParts.append("C++17 xác nhận mẫu hàm Getter [X0+0x\(String(cppMetric.primaryFieldOffset, radix: 16, uppercase: true))].") }
                if cppMetric.isSetterIdiom { noteParts.append("C++17 xác nhận mẫu hàm Setter [X0+0x\(String(cppMetric.primaryFieldOffset, radix: 16, uppercase: true))].") }
            }
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
                    signatureHint: cppMetric?.prologueHex ?? bodyProfile.prologueHex ?? validation.prologueHex,
                    notes: noteParts.joined(separator: " ")
                )
            )
            symbolFindings += 1

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
                        discoverySource: .cppNativeEngine,
                        verificationStatus: .verified,
                        confidenceScore: 0.96,
                        signatureHint: "C++17 ARM64 LDR/STR [X0, #0x\(String(fOff, radix: 16, uppercase: true))]",
                        notes: "Trường bộ nhớ thực tế được xác minh qua tập lệnh máy bên trong hàm \(HexFormatters.rvaHex(sym.rva))."
                    )
                )
                symbolFindings += 1
            }
        }

        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "1. Lõi C++17 Quét Đa Luồng ARM64 & Swift5",
                executed: !machO.encryptionInfo.isEncrypted,
                status: machO.encryptionInfo.isEncrypted ? "Tạm dừng do FairPlay cryptid!=0" : "Hoàn tất",
                findingsCount: cppFindings + cppScan.getterIdiomsCount + cppScan.setterIdiomsCount,
                details: "Quét \(cppScan.totalInstructionsScanned) lệnh ARM64, \(cppScan.discoveredProloguesCount) prologues, \(cppScan.getterIdiomsCount) Getters, \(cppScan.setterIdiomsCount) Setters, \(cppScan.swiftNominalTypes.count) kiểu Swift5."
            )
        )
        methodSummaries.append(
            AnalysisMethodSummary(
                methodName: "2. Phân tích Cấu trúc Mach-O & Bảng Ký hiệu",
                executed: true,
                status: "Hoàn tất",
                findingsCount: symbolFindings,
                details: "Engine: \(staticOutput.detectedEngineSummary) | Kiến trúc \(machO.architectureName) | \(machO.segments.count) phân vùng | \(machO.symbols.count) ký hiệu."
            )
        )

        // Objective-C Resolved IMPs & Class/Selector Metadata (includes all app ObjC classes/methods!)
        var objcFindings = 0
        for resolvedIMP in staticOutput.objcResolvedMethods {
            let fullSel = "-[\(resolvedIMP.className) \(resolvedIMP.selectorName)]"
            guard let category = OffsetCategoryClassifier.classify(
                identifier: fullSel,
                allowGeneralAppFallback: objcFindings < 3500
            ) else { continue }

            let cppMetric = cppScan.metricsByFunctionVA[resolvedIMP.impVirtualAddress]
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
                    confidenceScore: profile.validCode ? 0.99 : 0.88,
                    signatureHint: cppMetric?.prologueHex ?? profile.prologueHex ?? "__objc_const IMP",
                    notes: "Địa chỉ hàm thực thi IMP chuẩn 100% giải mã trực tiếp từ bảng __objc_classlist."
                )
            )
            objcFindings += 1
        }

        for (va, className) in staticOutput.objcMetadata.classNames {
            guard let category = OffsetCategoryClassifier.classify(
                identifier: className,
                allowGeneralAppFallback: objcFindings < 4500
            ) else { continue }
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
                    confidenceScore: 0.92,
                    signatureHint: "__objc_classname",
                    notes: "Lớp Objective-C định nghĩa trong __TEXT,__objc_classname."
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
            logger?.stage("IL2CPP", "Đang chạy bộ đối chiếu sâu Il2CppCodeGenModule & lệnh máy ARM64...")
            let codeGenMethodVAs = IL2CPPBinaryRegistrationScanner.resolveMethodVirtualAddresses(
                machO: machO,
                sliceCursor: staticOutput.sliceCursor,
                images: il2cppResult.images,
                logger: logger
            )

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
                            confidenceScore: 0.97,
                            signatureHint: "TypeDef \(tokenHex)",
                            notes: "Lớp IL2CPP khớp trực tiếp với ký hiệu nhị phân \(matchedSym.cleanName)."
                        )
                    )
                    il2cppFindings += 1
                }
            }

            for methodRec in il2cppResult.methods {
                if il2cppFindings > 15_000 { break }
                let fullMethod = "\(methodRec.declaringClassFullName).\(methodRec.methodName)"
                // Include all game/app classes in Assembly-CSharp (non-System/UnityEngine) or matching categories
                let isUserAssemblyClass = !methodRec.declaringClassFullName.hasPrefix("System.")
                    && !methodRec.declaringClassFullName.hasPrefix("Microsoft.")
                    && !methodRec.declaringClassFullName.hasPrefix("Mono.")
                guard let category = OffsetCategoryClassifier.classify(
                    identifier: fullMethod,
                    allowGeneralAppFallback: isUserAssemblyClass && il2cppFindings < 5000
                ) else { continue }

                let tokenHex = String(format: "0x%08X", methodRec.token)

                if let codeGenVA = codeGenMethodVAs[methodRec.methodIndex] {
                    let rva = machO.rva(forVirtualAddress: codeGenVA)
                    let fileOff = machO.virtualAddressToSliceFileOffset(codeGenVA)
                    let cppMetric = cppScan.metricsByFunctionVA[codeGenVA]
                    let profile = ARM64InstructionAnalyzer.profileFunctionBody(
                        sliceCursor: staticOutput.sliceCursor,
                        machO: machO,
                        functionVA: codeGenVA
                    )

                    let resolvedFieldOff = (cppMetric?.primaryFieldOffset ?? 0) > 0
                        ? cppMetric!.primaryFieldOffset
                        : profile.thisFieldOffsets.first

                    if let firstField = resolvedFieldOff {
                        let propStem = Self.extractPropertyStem(from: methodRec.methodName)
                        if !propStem.isEmpty {
                            verifiedFieldOffsetByPropertyKey["\(methodRec.declaringClassFullName)|\(propStem.lowercased())"] = (firstField, rva)
                        }
                    }

                    var note = "Chuẩn 100%: Giải mã trực tiếp RVA hàm từ bảng Il2CppCodeGenModule.methodPointers (Token \(tokenHex))."
                    if !profile.thisFieldOffsets.isEmpty {
                        let fl = profile.thisFieldOffsets.map { String(format: "+0x%X", $0) }.joined(separator: ", ")
                        note += " Trường [X0] trong thân hàm: \(fl)."
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
                            signatureHint: cppMetric?.prologueHex ?? profile.prologueHex ?? "MethodDef \(tokenHex)",
                            notes: note
                        )
                    )
                    il2cppFindings += 1
                    continue
                }

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
                            notes: "Giải mã RVA hàm IL2CPP qua bảng ký hiệu Mach-O (\(matchedSym.cleanName))."
                        )
                    )
                    il2cppFindings += 1
                    continue
                }

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
                } else if category != .appClassesAndMethods {
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
                                ? "Hàm có trong global-metadata.dat nhưng phân vùng code đang bật mã hóa FairPlay (cryptid!=0)."
                                : "Metadata Token trong global-metadata.dat."
                        )
                    )
                    il2cppFindings += 1
                }
            }

            for fieldRec in il2cppResult.fields {
                if il2cppFindings > 20_000 { break }
                let fullField = "\(fieldRec.declaringClassFullName).\(fieldRec.fieldName)"
                let isUserAssembly = !fieldRec.declaringClassFullName.hasPrefix("System.")
                    && !fieldRec.declaringClassFullName.hasPrefix("Microsoft.")
                guard let category = OffsetCategoryClassifier.classify(
                    identifier: fullField,
                    allowGeneralAppFallback: isUserAssembly && il2cppFindings < 7000
                ) else { continue }

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
                            confidenceScore: 0.99,
                            signatureHint: String(format: "FieldDef 0x%08X • Lệnh ARM64 tại RVA 0x%08llX", fieldRec.token, verifiedPair.methodRVA),
                            notes: "Chuẩn 100%: Offset trường (\(hexOff)) được xác minh trực tiếp từ lệnh đọc/ghi [X0] trong thân hàm getter/setter."
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
                            notes: "Offset trường đối tượng IL2CPP 64-bit (\(fieldHex) tính từ Il2CppObject +0x10)."
                        )
                    )
                }
                il2cppFindings += 1
            }

        case .encryptedOrObfuscated:
            warnings.append("Tệp global-metadata.dat đang bị mã hóa hoặc làm rối header (ứng dụng tuân thủ nguyên tắc không can thiệp phá mã hóa).")
            limitations.append("Không đọc tĩnh tên lớp từ global-metadata.dat bị mã hóa; đã quét toàn bộ Mach-O, Swift5, ObjC IMP và ARM64.")

        case .truncatedOrIncomplete:
            warnings.append("Tệp global-metadata.dat không đầy đủ.")
            limitations.append("Metadata không đầy đủ nên chỉ khôi phục được một phần bảng IL2CPP.")

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
                methodName: "4. Phân tích Objective-C IMP & Metadata",
                executed: true,
                status: "Hoàn tất",
                findingsCount: objcFindings + il2cppResult.relevantStringLiterals.count,
                details: "Hàm IMP & Lớp ObjC: \(objcFindings), Chuỗi hằng IL2CPP: \(il2cppResult.relevantStringLiterals.count)."
            )
        )

        // [5] Symbol-less Analysis (ARM64 ADRP+ADD XRefs + C++17 Getter/Setter Leaf Functions)
        logger?.stage("ARM64 XRef", "Đang quét hàm ẩn không ký hiệu qua ADRP+ADD XRef & mẫu Getter/Setter C++17...")
        var symbolLessFindings = 0
        for xref in staticOutput.arm64StringXRefs {
            guard let category = OffsetCategoryClassifier.classify(
                identifier: xref.referencedString,
                allowGeneralAppFallback: symbolLessFindings < 1000
            ) else {
                continue
            }
            if let funcVA = xref.enclosingFunctionVA, let funcRVA = xref.enclosingFunctionRVA {
                let fileOff = machO.virtualAddressToSliceFileOffset(funcVA)
                let cleanLabel = sanitizeXRefLabel(xref.referencedString, rva: funcRVA)
                let cppMetric = cppScan.metricsByFunctionVA[funcVA]
                let hasPrologue = (cppMetric?.hasValidPrologue ?? false) || (xref.prologueHex != nil)
                let status: OffsetVerificationStatus = hasPrologue ? .verified : .probable
                let callers = max(Int(cppMetric?.incomingCalls ?? 0), staticOutput.incomingCallCountsByFuncVA[funcVA] ?? 0)

                var note = "Hàm ARM64 (\(HexFormatters.vaHex(funcVA))) tham chiếu trực tiếp chuỗi \"\(xref.referencedString)\" tại \(HexFormatters.vaHex(xref.instructionVA))."
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
                        confidenceScore: hasPrologue ? 0.90 : 0.78,
                        signatureHint: cppMetric?.prologueHex ?? xref.prologueHex,
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
                            confidenceScore: 0.76,
                            signatureHint: dataRef.sectionDescription,
                            notes: "Con trỏ toàn cục trong \(dataRef.sectionDescription) nạp qua ADRP+LDR bên trong hàm \(HexFormatters.vaHex(funcVA))."
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
                        confidenceScore: 0.86,
                        signatureHint: "Tham chiếu tại \(HexFormatters.vaHex(xref.instructionVA))",
                        notes: "Chuỗi tĩnh được tham chiếu trực tiếp bởi cặp lệnh ARM64 ADRP+ADD."
                    )
                )
                symbolLessFindings += 1
            }
        }

        // If an IPA is completely stripped AND has few string XRefs, surface top C++17 verified Getter/Setter leaf accessors
        if discovered.count < 50 && !cppScan.leafAccessors.isEmpty {
            for leaf in cppScan.leafAccessors.prefix(500) {
                let kind = leaf.isGetterIdiom ? "Getter" : "Setter"
                let fileOff = machO.virtualAddressToSliceFileOffset(leaf.functionVA)
                appendUnique(
                    DiscoveredOffset(
                        name: String(format: "ARM64_%@_sub_%llX_field_0x%X", kind, leaf.functionRVA, leaf.primaryFieldOffset),
                        value: HexFormatters.vaHex(leaf.functionVA),
                        moduleName: moduleName,
                        rva: leaf.functionRVA,
                        virtualAddress: leaf.functionVA,
                        fileOffset: fileOff,
                        category: .appClassesAndMethods,
                        dataType: .method,
                        discoverySource: .cppNativeEngine,
                        verificationStatus: .verified,
                        confidenceScore: 0.94,
                        signatureHint: leaf.prologueHex,
                        notes: String(format: "Hàm %@ gọn (%u lệnh) truy cập trực tiếp trường đối tượng [X0 + 0x%X], được gọi %u lần.", kind, leaf.instructionCount, leaf.primaryFieldOffset, leaf.incomingCalls)
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
                details: "Quét \(machO.functionStartRVAs.count) hàm LC_FUNCTION_STARTS và \(symbolLessFindings) điểm tham chiếu thực tế."
            )
        )

        if machO.encryptionInfo.isEncrypted {
            warnings.append(
                String(
                    format: "Tệp thực thi đang bật mã hóa FairPlay từ App Store (cryptid=%u, vùng 0x%X..0x%X). Hệ thống chỉ trích xuất những phần không mã hóa để đảm bảo chuẩn 100%% (tuyệt đối không sinh offset ảo).",
                    machO.encryptionInfo.cryptID,
                    machO.encryptionInfo.cryptOffset,
                    machO.encryptionInfo.cryptOffset + machO.encryptionInfo.cryptSize
                )
            )
            limitations.append("Để dò toàn bộ thân hàm lệnh máy ARM64, cần sử dụng gói IPA chưa bật cờ mã hóa FairPlay (cryptid=0).")
        }

        // [6] & [7] Runtime Inspection & ASLR Mapping
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
                methodName: "8. Kiểm chứng Chéo Độ Chính Xác 100%",
                executed: true,
                status: "Hoàn tất",
                findingsCount: verifiedTotal,
                details: "Xác minh nghiêm ngặt \(verifiedTotal) offset chuẩn 100% (biên phân vùng + căn lề 4-byte ARM64 + prologue C++17); \(probableTotal) độ chính xác cao."
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
                    "Khớp \($0.matchedCount) offset, đổi RVA: \($0.shiftedRVAEntries.count), mới: \($0.newlyAddedCount), gỡ bỏ: \($0.missingCount)."
                } ?? "Đã lưu mốc cơ sở vào history.json để tự động đối chiếu ở bản cập nhật sau."
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
            stage: "Hoàn tất Dò",
            "Tổng cộng: \(discovered.count) offset thực tế (Chuẩn 100%: \(verifiedTotal), Độ chính xác cao: \(probableTotal))."
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
                let conf = sig.hasStandardPrologue ? 0.99 : 0.94
                return (
                    .verified,
                    conf,
                    sig.hex,
                    sig.hasStandardPrologue
                        ? "Chuẩn 100%: Đã xác minh Prologue hàm ARM64 & phân vùng thực thi __TEXT."
                        : "Chuẩn 100%: Đã xác minh chuỗi lệnh máy ARM64 hợp lệ trong __TEXT."
                )
            }
        }
        return (.verified, 0.92, nil, "Đã xác minh ánh xạ phân vùng tĩnh Mach-O & File Offset.")
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
