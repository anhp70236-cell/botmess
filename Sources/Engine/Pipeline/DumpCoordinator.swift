import Foundation

public struct DumpCoordinatorResult: Sendable {
    public let session: DumpSessionRecord
    public let explorerRows: [ExplorerRow]
    public let outputDirectoryURL: URL
}

public enum DumpCoordinatorError: Error, LocalizedError {
    case restrictedApp(String)
    case noMachOFound
    case cancelled

    public var errorDescription: String? {
        switch self {
        case let .restrictedApp(reason): return reason
        case .noMachOFound: return "Không tìm thấy tệp thực thi Mach-O ARM64 hợp lệ trong IPA."
        case .cancelled: return "Đã huỷ tác vụ phân tích."
        }
    }
}

public final class DumpCoordinator {
    public init() {}

    private static func normalizedZipPath(_ raw: String) -> String {
        var p = raw.replacingOccurrences(of: "\\", with: "/")
        while p.hasPrefix("./") {
            p.removeFirst(2)
        }
        return p
    }

    public static func inspectIPA(at url: URL) throws -> IPAInspectionInfo {
        let reader = try StreamingZipReader(url: url)
        let entries = reader.entries
        let infoPlistEntry = entries.first {
            normalizedZipPath($0.path).range(of: #"(?:^|/)Payload/[^/]+\.app/Info\.plist$"#, options: [.regularExpression, .caseInsensitive]) != nil
        }

        var appName = url.deletingPathExtension().lastPathComponent
        var bundleId = ""
        var bundleVer = "1.0"
        var execName = ""
        var urlSchemes: [String] = []

        if let pEntry = infoPlistEntry, let pData = try? reader.extractSmallEntryData(pEntry),
           let plist = try? PropertyListSerialization.propertyList(from: pData, options: [], format: nil) as? [String: Any] {
            if let dn = plist["CFBundleDisplayName"] as? String, !dn.isEmpty { appName = dn }
            else if let bn = plist["CFBundleName"] as? String, !bn.isEmpty { appName = bn }
            bundleId = (plist["CFBundleIdentifier"] as? String) ?? ""
            bundleVer = (plist["CFBundleShortVersionString"] as? String) ?? "1.0"
            execName = (plist["CFBundleExecutable"] as? String) ?? ""
            if let urlTypes = plist["CFBundleURLTypes"] as? [[String: Any]] {
                for ut in urlTypes {
                    if let sc = ut["CFBundleURLSchemes"] as? [String] { urlSchemes.append(contentsOf: sc) }
                }
            }
        }

        let safety = SafetyGuard.detectRestrictedApp(
            fileName: url.lastPathComponent,
            bundleId: bundleId,
            appName: appName,
            executableName: execName,
            urlSchemes: urlSchemes,
            entryPaths: entries.map { $0.path }
        )
        if safety.isBlocked {
            throw DumpCoordinatorError.restrictedApp(safety.reason)
        }

        let unityFw = entries.first {
            normalizedZipPath($0.path).range(of: #"(?:^|/)Payload/[^/]+\.app/Frameworks/UnityFramework\.framework/UnityFramework$"#, options: [.regularExpression, .caseInsensitive]) != nil
        }

        let ignoredExts: Set<String> = [
            "plist", "nib", "png", "jpg", "jpeg", "webp", "gif", "car", "strings",
            "mobileprovision", "json", "dat", "assets", "resource", "bank", "mp4",
            "wav", "mp3", "ogg", "pak", "bundle", "txt", "xml", "html", "css", "js",
            "ttf", "otf", "xcent", "entitlements", "metallib", "mom", "momd"
        ]

        let appExec: ZipEntry?
        if !execName.isEmpty {
            let escaped = NSRegularExpression.escapedPattern(for: execName)
            appExec = entries.first {
                normalizedZipPath($0.path).range(of: "(?:^|/)Payload/[^/]+\\.app/" + escaped + "$", options: [.regularExpression, .caseInsensitive]) != nil
            }
        } else {
            appExec = entries
                .filter { e in
                    let np = normalizedZipPath(e.path)
                    guard !e.isDirectory,
                          np.range(of: #"(?:^|/)Payload/[^/]+\.app/[^/]+$"#, options: [.regularExpression, .caseInsensitive]) != nil else {
                        return false
                    }
                    let ext = URL(fileURLWithPath: np).pathExtension.lowercased()
                    return ext.isEmpty || !ignoredExts.contains(ext)
                }
                .max { $0.uncompressedSize < $1.uncompressedSize }
        }

        let primaryMachO = unityFw ?? appExec
        guard let machoEntry = primaryMachO else {
            throw DumpCoordinatorError.noMachOFound
        }
        let secondaryPath: String? = (unityFw != nil && appExec != nil && appExec?.path != unityFw?.path) ? appExec?.path : nil

        let metaEntry = entries.first {
            normalizedZipPath($0.path).lowercased().hasSuffix("data/managed/metadata/global-metadata.dat")
        } ?? entries.first {
            normalizedZipPath($0.path).lowercased().hasSuffix("global-metadata.dat")
        }

        return IPAInspectionInfo(
            fileName: url.lastPathComponent,
            fileSizeBytes: reader.fileSize,
            appName: appName,
            bundleId: bundleId,
            bundleVersion: bundleVer,
            executableName: execName,
            primaryMachOPath: machoEntry.path,
            secondaryMachOPath: secondaryPath,
            unityFrameworkPath: unityFw?.path,
            metadataPath: metaEntry?.path,
            totalZipEntries: entries.count,
            isStandaloneMode: false
        )
    }

    public func runIPA(
        ipaURL: URL,
        overrideMetadataURL: URL? = nil,
        outputsRootURL: URL,
        options: DumpOptions,
        isCancelled: () -> Bool = { false },
        onProgress: @escaping (DumpProgressUpdate) -> Void
    ) throws -> DumpCoordinatorResult {
        let startTime = Date()
        onProgress(DumpProgressUpdate(phase: .inspecting, progress: 0.04, message: "Đọc cấu trúc ZIP64 của \(ipaURL.lastPathComponent)..."))

        let info = try Self.inspectIPA(at: ipaURL)
        if isCancelled() { throw DumpCoordinatorError.cancelled }

        let tempDir = FileManager.default.temporaryDirectory.appendingPathComponent("dip_work_\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: tempDir, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: tempDir) }

        let reader = try StreamingZipReader(url: ipaURL)
        guard let machoEntry = reader.entries.first(where: { $0.path == info.primaryMachOPath }) else {
            throw DumpCoordinatorError.noMachOFound
        }

        let localMachO = tempDir.appendingPathComponent("target_macho")
        onProgress(DumpProgressUpdate(phase: .inspecting, progress: 0.12, message: "Giải nén streaming \(URL(fileURLWithPath: info.primaryMachOPath).lastPathComponent) (\(formatSize(machoEntry.uncompressedSize)))..."))
        var lastExtractPct = -5
        try reader.extractEntry(machoEntry, to: localMachO) { frac in
            let pct = Int(frac * 100)
            if pct >= lastExtractPct + 5 || pct == 100 {
                lastExtractPct = pct
                onProgress(DumpProgressUpdate(phase: .inspecting, progress: 0.12 + frac * 0.12, message: "Giải nén Mach-O: \(pct)%"))
            }
        }
        if isCancelled() { throw DumpCoordinatorError.cancelled }

        var localSecondaryMachO: URL? = nil
        if let secPath = info.secondaryMachOPath,
           let secEntry = reader.entries.first(where: { $0.path == secPath }) {
            let secDest = tempDir.appendingPathComponent("secondary_macho")
            try? reader.extractEntry(secEntry, to: secDest, progress: nil)
            if FileManager.default.fileExists(atPath: secDest.path) {
                localSecondaryMachO = secDest
            }
        }

        onProgress(DumpProgressUpdate(phase: .inspecting, progress: 0.24, message: "Đang rà soát danh sách nhị phân Mach-O trong bundle..."))
        let normPrimary = Self.normalizedZipPath(info.primaryMachOPath)
        let normSecondary = info.secondaryMachOPath.map { Self.normalizedZipPath($0) }
        let appRootPrefix: String = {
            if let range = normPrimary.range(of: ".app/", options: .caseInsensitive) {
                return String(normPrimary[..<range.upperBound])
            }
            return ""
        }()
        let nonBinaryNames: Set<String> = ["pkginfo", "coderesources", "license", "readme", "notice", "authors", "copying"]
        let machoMagics: Set<UInt32> = [0xFEEDFACF, 0xCAFEBABE, 0xBEBAFECA, 0xCAFEBABF, 0xBFBAFECA]

        var extraBinaryEntries: [ZipEntry] = []
        if options.deepMachOScan && !appRootPrefix.isEmpty {
            for entry in reader.entries {
                if isCancelled() { throw DumpCoordinatorError.cancelled }
                guard !entry.isDirectory, entry.uncompressedSize >= 4096 else { continue }
                let np = Self.normalizedZipPath(entry.path)
                if np == normPrimary || np == normSecondary { continue }
                guard np.hasPrefix(appRootPrefix) else { continue }
                let rel = String(np.dropFirst(appRootPrefix.count))
                guard !rel.isEmpty else { continue }

                let urlPath = URL(fileURLWithPath: rel)
                let ext = urlPath.pathExtension.lowercased()
                let fileName = urlPath.lastPathComponent
                if nonBinaryNames.contains(fileName.lowercased()) { continue }

                var isCandidate = false
                if !rel.contains("/") {
                    if ext.isEmpty { isCandidate = true }
                } else if ext == "dylib" || ext == "so" {
                    isCandidate = true
                } else if rel.contains("Frameworks/") {
                    let parent = urlPath.deletingLastPathComponent()
                    if parent.pathExtension.lowercased() == "framework" && parent.deletingPathExtension().lastPathComponent == fileName {
                        isCandidate = true
                    }
                } else if rel.contains("PlugIns/") {
                    let parent = urlPath.deletingLastPathComponent()
                    if parent.pathExtension.lowercased() == "appex" && parent.deletingPathExtension().lastPathComponent == fileName {
                        isCandidate = true
                    }
                }
                guard isCandidate, !SafetyGuard.isRealMoneyBlocked(np) else { continue }

                if let prefix = reader.peekEntryPrefix(entry, maxBytes: 4), prefix.count >= 4 {
                    let magic = UInt32(prefix[0]) | (UInt32(prefix[1]) << 8) | (UInt32(prefix[2]) << 16) | (UInt32(prefix[3]) << 24)
                    let magicBE = (UInt32(prefix[0]) << 24) | (UInt32(prefix[1]) << 16) | (UInt32(prefix[2]) << 8) | UInt32(prefix[3])
                    if machoMagics.contains(magic) || machoMagics.contains(magicBE) {
                        extraBinaryEntries.append(entry)
                    }
                }
            }
        }

        var localMetadata: URL? = nil
        if let overrideMetadataURL = overrideMetadataURL {
            localMetadata = overrideMetadataURL
            onProgress(DumpProgressUpdate(phase: .inspecting, progress: 0.25, message: "[Metadata] Đang dùng tệp global-metadata.dat rời do người dùng gắn kèm."))
        } else if let metaPath = info.metadataPath,
                  let metaEntry = reader.entries.first(where: { $0.path == metaPath }) {
            let metaDest = tempDir.appendingPathComponent("global-metadata.dat")
            onProgress(DumpProgressUpdate(phase: .inspecting, progress: 0.25, message: "Giải nén streaming global-metadata.dat (\(formatSize(metaEntry.uncompressedSize)))..."))
            try reader.extractEntry(metaEntry, to: metaDest, progress: nil)
            localMetadata = metaDest
        }

        return try executeCorePipeline(
            info: info,
            machOURL: localMachO,
            secondaryMachOURL: localSecondaryMachO,
            extraMachOReader: reader,
            extraMachOEntries: extraBinaryEntries,
            tempDir: tempDir,
            metadataURL: localMetadata,
            outputsRootURL: outputsRootURL,
            options: options,
            startTime: startTime,
            isCancelled: isCancelled,
            onProgress: onProgress
        )
    }

    public func runStandalone(
        machOURL: URL,
        metadataURL: URL?,
        outputsRootURL: URL,
        options: DumpOptions,
        isCancelled: () -> Bool = { false },
        onProgress: @escaping (DumpProgressUpdate) -> Void
    ) throws -> DumpCoordinatorResult {
        let startTime = Date()
        let attrs = (try? FileManager.default.attributesOfItem(atPath: machOURL.path)) ?? [:]
        let sz = (attrs[.size] as? NSNumber)?.uint64Value ?? 0
        let appName = machOURL.deletingPathExtension().lastPathComponent
        let safety = SafetyGuard.detectRestrictedApp(
            fileName: machOURL.lastPathComponent,
            bundleId: "",
            appName: appName,
            executableName: appName,
            urlSchemes: [],
            entryPaths: []
        )
        if safety.isBlocked {
            throw DumpCoordinatorError.restrictedApp(safety.reason)
        }
        let info = IPAInspectionInfo(
            fileName: machOURL.lastPathComponent,
            fileSizeBytes: sz,
            appName: appName,
            bundleId: "standalone.binary",
            bundleVersion: "1.0",
            executableName: machOURL.lastPathComponent,
            primaryMachOPath: machOURL.path,
            secondaryMachOPath: nil,
            unityFrameworkPath: nil,
            metadataPath: metadataURL?.path,
            totalZipEntries: 0,
            isStandaloneMode: true
        )
        return try executeCorePipeline(
            info: info,
            machOURL: machOURL,
            secondaryMachOURL: nil,
            extraMachOReader: nil,
            extraMachOEntries: [],
            tempDir: nil,
            metadataURL: metadataURL,
            outputsRootURL: outputsRootURL,
            options: options,
            startTime: startTime,
            isCancelled: isCancelled,
            onProgress: onProgress
        )
    }

    private func executeCorePipeline(
        info: IPAInspectionInfo,
        machOURL: URL,
        secondaryMachOURL: URL?,
        extraMachOReader: StreamingZipReader?,
        extraMachOEntries: [ZipEntry],
        tempDir: URL?,
        metadataURL: URL?,
        outputsRootURL: URL,
        options: DumpOptions,
        startTime: Date,
        isCancelled: () -> Bool,
        onProgress: @escaping (DumpProgressUpdate) -> Void
    ) throws -> DumpCoordinatorResult {
        if isCancelled() { throw DumpCoordinatorError.cancelled }

        let workerCount = max(1, min(4, options.workerThreads))
        onProgress(DumpProgressUpdate(phase: .analyzing, progress: 0.30, message: "Khởi chạy \(workerCount) Worker(s) phân tích song song Mach-O & Metadata..."))

        let group = DispatchGroup()
        let sem = DispatchSemaphore(value: workerCount)
        let lock = NSLock()

        var peekResult: (
            status: MetadataProtectionStatus,
            version: Int32,
            imageNames: [String],
            typeFieldCounts: [UInt16],
            totalTypes: Int,
            totalMethods: Int,
            ctorPairsByImage: [String: [(typeDefIndex: Int, ridIndex: Int, globalIndex: Int)]]
        )?
        var metaPeekError: Error?
        var parsedMachO: MachOParser?
        var machoError: Error?

        if let metaURL = metadataURL {
            sem.wait()
            group.enter()
            DispatchQueue.global(qos: .userInitiated).async {
                defer { sem.signal(); group.leave() }
                do {
                    let p = try Il2CppMetadataParser.quickPeek(url: metaURL)
                    lock.lock(); peekResult = p; lock.unlock()
                } catch {
                    lock.lock(); metaPeekError = error; lock.unlock()
                }
            }
        }

        sem.wait()
        group.enter()
        DispatchQueue.global(qos: .userInitiated).async {
            defer { sem.signal(); group.leave() }
            autoreleasepool {
                do {
                    let m = try MachOParser(url: machOURL, sourceName: URL(fileURLWithPath: info.primaryMachOPath).lastPathComponent)
                    if options.deepMachOScan {
                        m.runDeepNativeScan(isCancelled: isCancelled)
                    }
                    lock.lock(); parsedMachO = m; lock.unlock()
                } catch {
                    lock.lock(); machoError = error; lock.unlock()
                }
            }
        }
        group.wait()

        if let err = machoError { throw err }
        if let err = metaPeekError { throw err }
        guard var macho = parsedMachO else { throw DumpCoordinatorError.noMachOFound }
        if isCancelled() { throw DumpCoordinatorError.cancelled }

        let metaStatus = peekResult?.status ?? .missing
        let diagnostics = ProtectionDiagnostics(
            fairPlayEncrypted: macho.fairPlayEncrypted,
            cryptId: macho.cryptId,
            cryptOffset: macho.cryptOffset,
            cryptSize: macho.cryptSize,
            metadataStatus: metaStatus
        )

        onProgress(DumpProgressUpdate(phase: .analyzing, progress: 0.48, message: "Metadata: \(metaStatus.summaryText)"))

        var fullMeta = ParsedMetadataResult(
            status: metaStatus,
            version: 0,
            images: [],
            types: [],
            stringLiterals: [],
            typeFieldCounts: [],
            ctorPairsByImage: [:],
            totalMethodsCount: 0,
            filteredRealMoneyCount: 0
        )

        var promotedExtraPath: String? = nil
        var secondaryConsumed = false
        if let metaURL = metadataURL, let peek = peekResult, peek.status.isValid {
            onProgress(DumpProgressUpdate(phase: .analyzing, progress: 0.54, message: "Định vị CodeRegistration, MetadataRegistration & FieldOffsets..."))
            var reg = Il2CppRegistrationFinder.find(
                in: macho,
                imageNames: peek.imageNames,
                typeFieldCounts: peek.typeFieldCounts,
                typeDefsCount: peek.totalTypes,
                totalMethodsCount: peek.totalMethods,
                metadataVersion: peek.version,
                ctorPairsByImage: peek.ctorPairsByImage,
                isCancelled: isCancelled
            ) { msg in
                onProgress(DumpProgressUpdate(phase: .analyzing, progress: 0.60, message: msg))
            }

            if reg.codeGenModules.isEmpty && reg.legacyMethodRVAs.isEmpty,
               let secURL = secondaryMachOURL,
               let secMacho = try? MachOParser(url: secURL, sourceName: URL(fileURLWithPath: info.secondaryMachOPath ?? "AppBinary").lastPathComponent) {
                if options.deepMachOScan { secMacho.runDeepNativeScan(isCancelled: isCancelled) }
                let secReg = Il2CppRegistrationFinder.find(
                    in: secMacho,
                    imageNames: peek.imageNames,
                    typeFieldCounts: peek.typeFieldCounts,
                    typeDefsCount: peek.totalTypes,
                    totalMethodsCount: peek.totalMethods,
                    metadataVersion: peek.version,
                    ctorPairsByImage: peek.ctorPairsByImage,
                    isCancelled: isCancelled
                ) { msg in
                    onProgress(DumpProgressUpdate(phase: .analyzing, progress: 0.62, message: msg))
                }
                if !secReg.codeGenModules.isEmpty || !secReg.legacyMethodRVAs.isEmpty {
                    reg = secReg
                    macho = secMacho
                    secondaryConsumed = true
                }
            }

            if reg.codeGenModules.isEmpty && reg.legacyMethodRVAs.isEmpty,
               let reader = extraMachOReader, let tDir = tempDir, !extraMachOEntries.isEmpty {
                let prioritized = extraMachOEntries.sorted { a, b in
                    let al = a.path.lowercased()
                    let bl = b.path.lowercased()
                    let aHint = al.contains("il2cpp") || al.contains("unity") || al.contains("game")
                    let bHint = bl.contains("il2cpp") || bl.contains("unity") || bl.contains("game")
                    if aHint != bHint { return aHint }
                    return a.uncompressedSize > b.uncompressedSize
                }
                for (cIdx, entry) in prioritized.prefix(3).enumerated() {
                    if isCancelled() { throw DumpCoordinatorError.cancelled }
                    let candDest = tDir.appendingPathComponent("cand_il2cpp_\(cIdx)")
                    if (try? reader.extractEntry(entry, to: candDest, progress: nil)) != nil,
                       let candMacho = try? MachOParser(url: candDest, sourceName: URL(fileURLWithPath: entry.path).lastPathComponent) {
                        let candReg = Il2CppRegistrationFinder.find(
                            in: candMacho,
                            imageNames: peek.imageNames,
                            typeFieldCounts: peek.typeFieldCounts,
                            typeDefsCount: peek.totalTypes,
                            totalMethodsCount: peek.totalMethods,
                            metadataVersion: peek.version,
                            ctorPairsByImage: peek.ctorPairsByImage,
                            isCancelled: isCancelled
                        ) { msg in
                            onProgress(DumpProgressUpdate(phase: .analyzing, progress: 0.63, message: msg))
                        }
                        if !candReg.codeGenModules.isEmpty || !candReg.legacyMethodRVAs.isEmpty {
                            if options.deepMachOScan { candMacho.runDeepNativeScan(isCancelled: isCancelled) }
                            reg = candReg
                            macho = candMacho
                            promotedExtraPath = entry.path
                            break
                        }
                    }
                    try? FileManager.default.removeItem(at: candDest)
                }
            }

            if isCancelled() { throw DumpCoordinatorError.cancelled }
            onProgress(DumpProgressUpdate(phase: .analyzing, progress: 0.65, message: "Giải mã toàn bộ Types, Fields, Properties, Events, Methods & Attributes..."))
            fullMeta = try autoreleasepool {
                try Il2CppMetadataParser.inspectAndParse(
                    url: metaURL,
                    registration: reg,
                    macho: macho,
                    options: options,
                    isCancelled: isCancelled
                ) { msg in
                    onProgress(DumpProgressUpdate(phase: .analyzing, progress: 0.72, message: msg))
                }
            }
        } else if metadataURL != nil {
            onProgress(DumpProgressUpdate(phase: .analyzing, progress: 0.62, message: "Chuyển sang chế độ Mach-O Multi-Binary (Obj-C: \(macho.objcClasses.count), Swift: \(macho.swiftTypes.count), Exports: \(macho.exportedSymbols.count))..."))
        }

        // Collect all Mach-O snapshots across primary, secondary, and embedded frameworks (one-by-one temp disk cleanup)
        var allNativeSnapshots: [NativeBinarySnapshot] = [macho.makeSnapshot()]
        if !secondaryConsumed, let secURL = secondaryMachOURL,
           let secMacho = try? MachOParser(url: secURL, sourceName: URL(fileURLWithPath: info.secondaryMachOPath ?? "AppBinary").lastPathComponent) {
            if options.deepMachOScan { secMacho.runDeepNativeScan(isCancelled: isCancelled) }
            if !allNativeSnapshots.contains(where: { $0.sourceName == secMacho.sourceName }) {
                allNativeSnapshots.append(secMacho.makeSnapshot())
            }
        }
        if options.deepMachOScan, let reader = extraMachOReader, let tDir = tempDir, !extraMachOEntries.isEmpty {
            let totalExtra = extraMachOEntries.count
            for (idx, entry) in extraMachOEntries.enumerated() {
                if entry.path == promotedExtraPath { continue }
                if isCancelled() { throw DumpCoordinatorError.cancelled }
                let binName = URL(fileURLWithPath: entry.path).lastPathComponent
                let frac = Double(idx + 1) / Double(max(1, totalExtra))
                onProgress(DumpProgressUpdate(phase: .analyzing, progress: 0.72 + frac * 0.04, message: "Quét binary phụ \(idx + 1)/\(totalExtra): \(binName)..."))
                autoreleasepool {
                    let tmpBin = tDir.appendingPathComponent("extra_bin_\(idx)")
                    defer { try? FileManager.default.removeItem(at: tmpBin) }
                    if (try? reader.extractEntry(entry, to: tmpBin, progress: nil)) != nil,
                       let fwMacho = try? MachOParser(url: tmpBin, sourceName: binName) {
                        fwMacho.runDeepNativeScan(isCancelled: isCancelled)
                        allNativeSnapshots.append(fwMacho.makeSnapshot())
                    }
                }
            }
        }

        if isCancelled() { throw DumpCoordinatorError.cancelled }

        let fm = FileManager.default
        if !fm.fileExists(atPath: outputsRootURL.path) {
            try fm.createDirectory(at: outputsRootURL, withIntermediateDirectories: true)
        }
        let safeBase = Il2CppTypeResolver.sanitizeCIdentifier(info.appName)
        let tsFmt = DateFormatter()
        tsFmt.dateFormat = "yyyyMMdd_HHmmss"
        let dirName = "\(safeBase)_\(tsFmt.string(from: Date()))"
        let outDir = outputsRootURL.appendingPathComponent(dirName)
        try fm.createDirectory(at: outDir, withIntermediateDirectories: true)

        onProgress(DumpProgressUpdate(phase: .generating, progress: 0.76, message: "Đang xuất tệp đầu ra bằng \(workerCount) Worker(s)..."))

        let genGroup = DispatchGroup()
        let genSem = DispatchSemaphore(value: workerCount)
        var genError: Error?
        var dummyDllCount = 0

        if options.generateDumpCS {
            genSem.wait(); genGroup.enter()
            DispatchQueue.global(qos: .userInitiated).async {
                defer { genSem.signal(); genGroup.leave() }
                autoreleasepool {
                    do {
                        try DumpCSGenerator.generate(
                            to: outDir.appendingPathComponent("dump.cs"),
                            images: fullMeta.images,
                            types: fullMeta.types,
                            macho: macho,
                            snapshots: allNativeSnapshots,
                            diagnostics: diagnostics,
                            options: options
                        )
                    } catch {
                        lock.lock(); if genError == nil { genError = error }; lock.unlock()
                    }
                }
            }
        }

        if options.generateStructAndJSON {
            genSem.wait(); genGroup.enter()
            DispatchQueue.global(qos: .userInitiated).async {
                defer { genSem.signal(); genGroup.leave() }
                autoreleasepool {
                    do {
                        try StructAndJSONGenerator.generateIl2CppHeader(
                            to: outDir.appendingPathComponent("il2cpp.h"),
                            types: fullMeta.types,
                            macho: macho,
                            snapshots: allNativeSnapshots
                        )
                        try StructAndJSONGenerator.generateScriptJSON(
                            to: outDir.appendingPathComponent("script.json"),
                            types: fullMeta.types,
                            stringLiterals: fullMeta.stringLiterals,
                            macho: macho,
                            snapshots: allNativeSnapshots
                        )
                        try StructAndJSONGenerator.generateStringLiteralJSON(
                            to: outDir.appendingPathComponent("stringliteral.json"),
                            stringLiterals: fullMeta.stringLiterals
                        )
                    } catch {
                        lock.lock(); if genError == nil { genError = error }; lock.unlock()
                    }
                }
            }
        }

        genGroup.wait()
        if let err = genError { throw err }
        if isCancelled() { throw DumpCoordinatorError.cancelled }

        onProgress(DumpProgressUpdate(phase: .generating, progress: 0.84, message: "Đang xuất offsets_summary.txt..."))
        try autoreleasepool {
            try StructAndJSONGenerator.generateOffsetsSummary(
                to: outDir.appendingPathComponent("offsets_summary.txt"),
                images: fullMeta.images,
                types: fullMeta.types,
                macho: macho,
                snapshots: allNativeSnapshots,
                diagnostics: diagnostics
            )
        }
        if isCancelled() { throw DumpCoordinatorError.cancelled }

        onProgress(DumpProgressUpdate(phase: .generating, progress: 0.87, message: "Đang xuất native_functions.txt..."))
        try autoreleasepool {
            try StructAndJSONGenerator.generateNativeFunctionsReport(
                to: outDir.appendingPathComponent("native_functions.txt"),
                snapshots: allNativeSnapshots
            )
        }
        if isCancelled() { throw DumpCoordinatorError.cancelled }

        if options.generateDummyDLLs && !fullMeta.images.isEmpty {
            onProgress(DumpProgressUpdate(phase: .generating, progress: 0.89, message: "Đang dựng Dummy DLLs cho dnSpy/ILSpy..."))
            let dllDir = outDir.appendingPathComponent("DummyDll")
            dummyDllCount = try autoreleasepool {
                try DummyDLLGenerator.generateAll(
                    to: dllDir,
                    images: fullMeta.images,
                    types: fullMeta.types,
                    options: options
                ) { msg in
                    onProgress(DumpProgressUpdate(phase: .generating, progress: 0.90, message: msg))
                }
            }
        }

        let summaryURL = outDir.appendingPathComponent("report.txt")
        try writeSummaryReport(to: summaryURL, info: info, diagnostics: diagnostics, macho: macho, meta: fullMeta, dummyDllCount: dummyDllCount, snapshots: allNativeSnapshots)

        if options.autoExportZip {
            onProgress(DumpProgressUpdate(phase: .packaging, progress: 0.93, message: "Đang nén gói kết quả ZIP..."))
            let zipURL = outDir.appendingPathComponent("\(safeBase)_dump.zip")
            try packageOutputDirectory(outDir, toZip: zipURL)
        }

        let explorerRows = buildExplorerRows(meta: fullMeta, snapshots: allNativeSnapshots)
        let explorerURL = outDir.appendingPathComponent(".explorer_index.json")
        if let expData = try? JSONEncoder().encode(explorerRows) {
            try? expData.write(to: explorerURL, options: .atomic)
        }

        let files = collectOutputItems(in: outDir, relativePrefix: "")
        let totalMethods = fullMeta.types.reduce(0) { $0 + $1.methods.count }
        let totalFields = fullMeta.types.reduce(0) { $0 + $1.fields.count }
        let totalProps = fullMeta.types.reduce(0) { $0 + $1.properties.count }
        let totalEvents = fullMeta.types.reduce(0) { $0 + $1.events.count }
        let extraFiltered = allNativeSnapshots.dropFirst().reduce(0) { $0 + $1.filteredRealMoneyCount }
        let totalFiltered = fullMeta.filteredRealMoneyCount + macho.filteredRealMoneyCount + extraFiltered
        let nativeCount = allNativeSnapshots.reduce(0) { acc, b in
            acc + b.objcClasses.reduce(0) { $0 + $1.methods.count } + b.swiftTypes.count + b.exportedSymbols.count + b.symtabSymbols.count + b.functionStartsRVA.count
        }

        let stats = DumpStats(
            assembliesCount: fullMeta.images.count,
            typesCount: fullMeta.types.count,
            methodsCount: totalMethods,
            fieldsCount: totalFields,
            propertiesCount: totalProps,
            eventsCount: totalEvents,
            stringLiteralsCount: fullMeta.stringLiterals.count,
            dummyDllsCount: dummyDllCount,
            nativeSymbolsCount: nativeCount,
            filteredRealMoneyCount: totalFiltered,
            elapsedSeconds: Date().timeIntervalSince(startTime)
        )

        let session = DumpSessionRecord(
            id: UUID().uuidString,
            title: info.appName,
            bundleId: info.bundleId,
            createdAt: Date(),
            outputDirectoryName: dirName,
            diagnostics: diagnostics,
            stats: stats,
            files: files
        )

        if let sessData = try? JSONEncoder().encode(session) {
            try? sessData.write(to: outDir.appendingPathComponent(".session.json"), options: .atomic)
        }

        onProgress(DumpProgressUpdate(phase: .completed, progress: 1.0, message: "Hoàn tất phân tích trong \(String(format: "%.2fs", stats.elapsedSeconds))!"))
        return DumpCoordinatorResult(session: session, explorerRows: explorerRows, outputDirectoryURL: outDir)
    }

    public func packageOutputDirectory(_ dirURL: URL, toZip zipURL: URL) throws {
        let writer = try StreamingZipWriter(destinationURL: zipURL)
        let fm = FileManager.default
        let resolvedDir = dirURL.resolvingSymlinksInPath()
        let resolvedZip = zipURL.resolvingSymlinksInPath()
        let basePrefix = resolvedDir.path.hasSuffix("/") ? resolvedDir.path : (resolvedDir.path + "/")
        if let enumerator = fm.enumerator(at: resolvedDir, includingPropertiesForKeys: [.isRegularFileKey]) {
            for case let fileURL as URL in enumerator {
                let name = fileURL.lastPathComponent
                let resFile = fileURL.resolvingSymlinksInPath()
                if name.hasPrefix(".") || resFile == resolvedZip { continue }
                let vals = try? resFile.resourceValues(forKeys: [.isRegularFileKey])
                if vals?.isRegularFile == true {
                    let rel = resFile.path.hasPrefix(basePrefix)
                        ? String(resFile.path.dropFirst(basePrefix.count))
                        : resFile.lastPathComponent
                    try writer.addFile(at: resFile, entryName: rel)
                }
            }
        }
        try writer.finalize()
    }

    private func writeSummaryReport(
        to url: URL,
        info: IPAInspectionInfo,
        diagnostics: ProtectionDiagnostics,
        macho: MachOParser,
        meta: ParsedMetadataResult,
        dummyDllCount: Int,
        snapshots: [NativeBinarySnapshot]
    ) throws {
        let writer = try BufferedFileWriter(url: url)
        defer { try? writer.close() }
        let totalMethods = meta.types.reduce(0) { $0 + $1.methods.count }
        let methodsWithRVA = meta.types.reduce(0) { $0 + $1.methods.filter { $0.rva > 0 }.count }
        let genericInstTotal = meta.types.reduce(0) { acc, t in acc + t.methods.reduce(0) { $0 + $1.genericInstRVAs.count } }
        let totalFields = meta.types.reduce(0) { $0 + $1.fields.count }
        let resolvedFields = meta.types.reduce(0) { $0 + $1.fields.filter { $0.offset >= 0 }.count }
        let totalFiltered = meta.filteredRealMoneyCount + snapshots.reduce(0) { $0 + $1.filteredRealMoneyCount }

        try writer.writeLine("IPA Dumper Pro v2.0 — Analysis Report")
        try writer.writeLine("=====================================")
        try writer.writeLine("App: \(info.appName) (\(info.bundleId)) v\(info.bundleVersion)")
        try writer.writeLine("Input Size: \(formatSize(info.fileSizeBytes))")
        try writer.writeLine("Primary Mach-O: \(info.primaryMachOPath) [\(macho.architecture)] (Total Binaries Scanned: \(snapshots.count))")
        try writer.writeLine("FairPlay DRM: \(diagnostics.fairPlayEncrypted ? "ENCRYPTED (cryptid=\(diagnostics.cryptId))" : "Decrypted")")
        try writer.writeLine("Chained Fixups: \(macho.hasChainedFixups ? "Yes (\(macho.chainedFixupPointers.count) pointers)" : "No")")
        try writer.writeLine("Metadata Status: \(diagnostics.metadataStatus.summaryText)")
        try writer.writeLine("Assemblies: \(meta.images.count) | Types: \(meta.types.count) | Dummy DLLs: \(dummyDllCount)")
        try writer.writeLine("IL2CPP Methods with RVA: \(methodsWithRVA)/\(totalMethods) (GenericInst RVAs: \(genericInstTotal)) | Field Offsets Resolved: \(resolvedFields)/\(totalFields)")
        try writer.writeLine("Filtered Real-Money/IAP Symbols: \(totalFiltered)")
        try writer.writeLine("")
        try writer.writeLine("--- Native Binary Breakdown ---")
        for bin in snapshots {
            let objcMethodCount = bin.objcClasses.reduce(0) { $0 + $1.methods.count }
            try writer.writeLine("[\(bin.sourceName)] (\(bin.architecture)) Exports=\(bin.exportedSymbols.count) Symtab=\(bin.symtabSymbols.count) ObjC=\(bin.objcClasses.count) ObjCMethods=\(objcMethodCount) Swift=\(bin.swiftTypes.count) FuncStarts=\(bin.functionStartsRVA.count) XRefs=\(bin.xrefFunctions.count)")
        }
        try writer.close()
    }

    private func buildExplorerRows(meta: ParsedMetadataResult, snapshots: [NativeBinarySnapshot]) -> [ExplorerRow] {
        var rows: [ExplorerRow] = []
        let rowCap = 50_000
        rows.reserveCapacity(min(rowCap, meta.types.count * 4))
        var nextId = 1

        for t in meta.types {
            if rows.count >= rowCap { break }
            rows.append(ExplorerRow(
                id: nextId,
                kind: .type,
                assembly: t.assemblyName,
                namespace: t.namespace,
                className: t.name,
                name: t.name,
                signature: "\(t.fullName) // TypeDefIndex: \(t.typeDefIndex)",
                dataType: t.isEnum ? "enum" : (t.isValueType ? "struct" : (t.isInterface ? "interface" : "class")),
                rvaHex: "—",
                vaHex: "—",
                fileOffsetHex: "—",
                fieldOffsetHex: "—",
                tokenHex: String(format: "0x%X", t.token),
                typeDefIndex: t.typeDefIndex,
                extraNote: t.parentName ?? ""
            ))
            nextId += 1

            for f in t.fields {
                if rows.count >= rowCap { break }
                rows.append(ExplorerRow(
                    id: nextId,
                    kind: .field,
                    assembly: t.assemblyName,
                    namespace: t.namespace,
                    className: t.fullName,
                    name: f.name,
                    signature: "\(f.typeName) \(f.name)",
                    dataType: f.typeName,
                    rvaHex: "—",
                    vaHex: "—",
                    fileOffsetHex: "—",
                    fieldOffsetHex: f.offset >= 0 ? String(format: "0x%X", f.offset) : "0x0",
                    tokenHex: String(format: "0x%X", f.token),
                    typeDefIndex: t.typeDefIndex,
                    extraNote: f.defaultValue ?? f.ctorInitValue ?? ""
                ))
                nextId += 1
            }

            for p in t.properties {
                if rows.count >= rowCap { break }
                rows.append(ExplorerRow(
                    id: nextId,
                    kind: .property,
                    assembly: t.assemblyName,
                    namespace: t.namespace,
                    className: t.fullName,
                    name: p.name,
                    signature: "\(p.typeName) \(p.name) { get; set; }",
                    dataType: p.typeName,
                    rvaHex: p.getRVA > 0 ? String(format: "0x%llX", p.getRVA) : "—",
                    vaHex: "—",
                    fileOffsetHex: "—",
                    fieldOffsetHex: "—",
                    tokenHex: String(format: "0x%X", p.token),
                    typeDefIndex: t.typeDefIndex,
                    extraNote: ""
                ))
                nextId += 1
            }

            for ev in t.events {
                if rows.count >= rowCap { break }
                rows.append(ExplorerRow(
                    id: nextId,
                    kind: .event,
                    assembly: t.assemblyName,
                    namespace: t.namespace,
                    className: t.fullName,
                    name: ev.name,
                    signature: "event \(ev.typeName) \(ev.name)",
                    dataType: ev.typeName,
                    rvaHex: "—",
                    vaHex: "—",
                    fileOffsetHex: "—",
                    fieldOffsetHex: "—",
                    tokenHex: String(format: "0x%X", ev.token),
                    typeDefIndex: t.typeDefIndex,
                    extraNote: ""
                ))
                nextId += 1
            }

            for m in t.methods {
                if rows.count >= rowCap { break }
                let paramText = m.parameters.map { "\($0.typeName) \($0.name)" }.joined(separator: ", ")
                rows.append(ExplorerRow(
                    id: nextId,
                    kind: .method,
                    assembly: t.assemblyName,
                    namespace: t.namespace,
                    className: t.fullName,
                    name: m.name,
                    signature: "\(m.returnTypeName) \(m.name)(\(paramText))",
                    dataType: m.returnTypeName,
                    rvaHex: m.rva > 0 ? String(format: "0x%llX", m.rva) : "0x0",
                    vaHex: m.va > 0 ? String(format: "0x%llX", m.va) : "0x0",
                    fileOffsetHex: m.fileOffset > 0 ? String(format: "0x%llX", m.fileOffset) : "0x0",
                    fieldOffsetHex: "—",
                    tokenHex: String(format: "0x%X", m.token),
                    typeDefIndex: t.typeDefIndex,
                    extraNote: m.arm64Semantics
                ))
                nextId += 1
            }
        }

        for macho in snapshots {
            for cls in macho.objcClasses {
                for m in cls.methods {
                    if rows.count >= rowCap { break }
                    rows.append(ExplorerRow(
                        id: nextId,
                        kind: .native,
                        assembly: macho.sourceName,
                        namespace: "Objective-C",
                        className: cls.name,
                        name: m.name,
                        signature: "\(m.isClassMethod ? "+" : "-")[\(cls.name) \(m.name)]",
                        dataType: m.types,
                        rvaHex: String(format: "0x%llX", m.rva),
                        vaHex: String(format: "0x%llX", m.va),
                        fileOffsetHex: String(format: "0x%llX", m.fileOffset),
                        fieldOffsetHex: "—",
                        tokenHex: "—",
                        typeDefIndex: -1,
                        extraNote: "Obj-C"
                    ))
                    nextId += 1
                }
            }

            for st in macho.swiftTypes {
                if rows.count >= rowCap { break }
                rows.append(ExplorerRow(
                    id: nextId,
                    kind: .native,
                    assembly: macho.sourceName,
                    namespace: "Swift",
                    className: st.name,
                    name: st.name,
                    signature: "\(st.kind) \(st.name)",
                    dataType: st.kind,
                    rvaHex: String(format: "0x%llX", st.descriptorRVA),
                    vaHex: "—",
                    fileOffsetHex: "—",
                    fieldOffsetHex: "—",
                    tokenHex: "—",
                    typeDefIndex: -1,
                    extraNote: "Swift 5"
                ))
                nextId += 1
            }

            for sym in macho.exportedSymbols {
                if rows.count >= rowCap { break }
                rows.append(ExplorerRow(
                    id: nextId,
                    kind: .native,
                    assembly: macho.sourceName,
                    namespace: "Exports",
                    className: macho.sourceName,
                    name: sym.name,
                    signature: sym.name,
                    dataType: sym.source,
                    rvaHex: String(format: "0x%llX", sym.rva),
                    vaHex: String(format: "0x%llX", sym.va),
                    fileOffsetHex: String(format: "0x%llX", sym.fileOffset),
                    fieldOffsetHex: "—",
                    tokenHex: "—",
                    typeDefIndex: -1,
                    extraNote: sym.source
                ))
                nextId += 1
            }

            for sym in macho.symtabSymbols {
                if rows.count >= rowCap { break }
                rows.append(ExplorerRow(
                    id: nextId,
                    kind: .native,
                    assembly: macho.sourceName,
                    namespace: "Symtab",
                    className: macho.sourceName,
                    name: sym.name,
                    signature: sym.name,
                    dataType: sym.source,
                    rvaHex: String(format: "0x%llX", sym.rva),
                    vaHex: String(format: "0x%llX", sym.va),
                    fileOffsetHex: String(format: "0x%llX", sym.fileOffset),
                    fieldOffsetHex: "—",
                    tokenHex: "—",
                    typeDefIndex: -1,
                    extraNote: sym.source
                ))
                nextId += 1
            }
        }

        return rows
    }

    private func collectOutputItems(in dirURL: URL, relativePrefix: String) -> [OutputFileItem] {
        let fm = FileManager.default
        guard let children = try? fm.contentsOfDirectory(at: dirURL, includingPropertiesForKeys: [.isDirectoryKey, .fileSizeKey]) else {
            return []
        }
        var items: [OutputFileItem] = []
        for url in children.sorted(by: { $0.lastPathComponent < $1.lastPathComponent }) {
            let name = url.lastPathComponent
            if name.hasPrefix(".") { continue }
            let rel = relativePrefix.isEmpty ? name : "\(relativePrefix)/\(name)"
            let vals = try? url.resourceValues(forKeys: [.isDirectoryKey, .fileSizeKey])
            let isDir = vals?.isDirectory ?? false
            let sz = UInt64(vals?.fileSize ?? 0)
            items.append(OutputFileItem(name: name, relativePath: rel, sizeBytes: sz, isDirectory: isDir))
        }
        return items
    }

    private func formatSize(_ bytes: UInt64) -> String {
        let mb = Double(bytes) / (1024.0 * 1024.0)
        if mb >= 1024.0 {
            return String(format: "%.2f GB", mb / 1024.0)
        }
        return String(format: "%.2f MB", mb)
    }
}
