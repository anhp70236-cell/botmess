import Foundation

public struct StorageUsageSnapshot: Equatable, Sendable {
    public static let minimumSupportedQuotaBytes: Int64 = 3 * 1_073_741_824 // 3.00 GB minimum design guarantee
    public static let defaultWorkspaceQuotaBytes: Int64 = 8 * 1_073_741_824 // 8.00 GB default workspace budget

    public let ipaBytes: Int64
    public let cacheBytes: Int64
    public let databaseBytes: Int64
    public let exportsBytes: Int64
    public let configuredQuotaBytes: Int64
    public let deviceFreeBytes: Int64
    public let ipaFileCount: Int
    public let cachedItemCount: Int
    public let gameDatabaseCount: Int

    public var totalUsedBytes: Int64 {
        ipaBytes + cacheBytes + databaseBytes + exportsBytes
    }

    public var effectiveAvailableForAnalyzerBytes: Int64 {
        min(deviceFreeBytes, max(0, configuredQuotaBytes - totalUsedBytes))
    }

    public var supportsThreeGigabyteTarget: Bool {
        configuredQuotaBytes >= Self.minimumSupportedQuotaBytes && (deviceFreeBytes + totalUsedBytes) >= Self.minimumSupportedQuotaBytes
    }

    public var quotaUtilizationFraction: Double {
        guard configuredQuotaBytes > 0 else { return 0 }
        return min(1.0, Double(totalUsedBytes) / Double(configuredQuotaBytes))
    }
}

/// Stores workspace directly inside the app's `Documents/` directory so that on any iPhone
/// (`UIFileSharingEnabled = YES`), all game folders, exported single/multi offset files, and
/// drop-in `ImportIPA/` files are immediately visible in **Tệp (Files) -> Trên iPhone của tôi -> DEVNVIOS IPA ANALYZER**.
public final class StorageManagerService: @unchecked Sendable {
    public let rootDirectoryURL: URL
    public let ipasDirectoryURL: URL
    public let dropInImportDirectoryURL: URL
    public let cacheDirectoryURL: URL
    public let gamesDirectoryURL: URL
    public let exportsDirectoryURL: URL

    private let userDefaultsKey = "com.devnvios.storageQuotaBytes"
    private let lock = NSLock()

    public init(rootDirectoryURL: URL? = nil) throws {
        if let rootDirectoryURL {
            self.rootDirectoryURL = rootDirectoryURL
        } else {
            // Store directly in Documents so iOS Files app displays everything clearly!
            let docs = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]
            self.rootDirectoryURL = docs
        }

        self.ipasDirectoryURL = self.rootDirectoryURL.appendingPathComponent("IPAs", isDirectory: true)
        self.dropInImportDirectoryURL = self.rootDirectoryURL.appendingPathComponent("Bo_File_IPA_Vao_Day", isDirectory: true)
        self.gamesDirectoryURL = self.rootDirectoryURL.appendingPathComponent("Games", isDirectory: true)
        self.exportsDirectoryURL = self.rootDirectoryURL.appendingPathComponent("KetQuaOffset", isDirectory: true)

        // Keep temporary extraction cache inside Library/Caches when running in default sandbox so iOS Files stays clean
        if rootDirectoryURL == nil,
           let sysCaches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first {
            self.cacheDirectoryURL = sysCaches.appendingPathComponent("DEVNVIOSExtractionCache", isDirectory: true)
        } else {
            self.cacheDirectoryURL = self.rootDirectoryURL.appendingPathComponent("Cache", isDirectory: true)
        }

        for dir in [
            self.rootDirectoryURL,
            ipasDirectoryURL,
            dropInImportDirectoryURL,
            cacheDirectoryURL,
            gamesDirectoryURL,
            exportsDirectoryURL
        ] {
            try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        }

        // Write a small Vietnamese README file so the folder is immediately visible in iOS Files even before the first scan
        let readmeURL = self.rootDirectoryURL.appendingPathComponent("HUONG_DAN_LUU_TRU.txt")
        if !FileManager.default.fileExists(atPath: readmeURL.path) {
            let guide = """
            DEVNVIOS IPA ANALYZER — THƯ MỤC DỮ LIỆU TRÊN IPHONE
            ==================================================
            1. KetQuaOffset/ : Chứa các tệp Offset xuất ra (Gom 1 tệp duy nhất hoặc tách nhiều tệp).
            2. Games/        : Cơ sở dữ liệu chi tiết từng Game (metadata, offsets, symbols, runtime, report, history).
            3. Bo_File_IPA_Vao_Day/ : Bạn có thể chép trực tiếp tệp .ipa vào thư mục này trong ứng dụng Tệp (Files), sau đó mở DEVNVIOS bấm "Quét IPA trong Thư mục Tệp".
            4. IPAs/         : Lưu bản sao IPA đã nhập (có thể xóa trong mục Bộ nhớ mà vẫn giữ nguyên toàn bộ Offset).
            """
            try? Data(guide.utf8).write(to: readmeURL, options: .atomic)
        }
    }

    public var configuredQuotaBytes: Int64 {
        get {
            let stored = Int64(UserDefaults.standard.integer(forKey: userDefaultsKey))
            if stored >= StorageUsageSnapshot.minimumSupportedQuotaBytes {
                return stored
            }
            return StorageUsageSnapshot.defaultWorkspaceQuotaBytes
        }
        set {
            let clamped = max(StorageUsageSnapshot.minimumSupportedQuotaBytes, newValue)
            UserDefaults.standard.set(Int(clamped), forKey: userDefaultsKey)
        }
    }

    /// Scans `Documents/`, `Bo_File_IPA_Vao_Day/`, and `Documents/Inbox/` for `.ipa` / `.tipa` files placed via iOS Files or Share Sheet.
    public func discoverDropInIPAFiles() -> [URL] {
        let candidateDirs = [
            dropInImportDirectoryURL,
            rootDirectoryURL,
            rootDirectoryURL.appendingPathComponent("Inbox", isDirectory: true)
        ]
        var found: [URL] = []
        for dir in candidateDirs {
            guard let items = try? FileManager.default.contentsOfDirectory(
                at: dir,
                includingPropertiesForKeys: [.isRegularFileKey],
                options: [.skipsHiddenFiles]
            ) else { continue }
            for url in items {
                let ext = url.pathExtension.lowercased()
                if ext == "ipa" || ext == "tipa" || ext == "zip" {
                    if !found.contains(where: { $0.standardizedFileURL == url.standardizedFileURL }) {
                        found.append(url)
                    }
                }
            }
        }
        return found
    }

    public func computeUsageSnapshot() -> StorageUsageSnapshot {
        lock.lock()
        defer { lock.unlock() }

        let (ipaSize, ipaCount) = Self.directorySizeAndCount(at: ipasDirectoryURL)
        let (cacheSize, cacheCount) = Self.directorySizeAndCount(at: cacheDirectoryURL)
        let (dbSize, _) = Self.directorySizeAndCount(at: gamesDirectoryURL)
        let (expSize, _) = Self.directorySizeAndCount(at: exportsDirectoryURL)

        let gameFolders = ((try? FileManager.default.contentsOfDirectory(
            at: gamesDirectoryURL,
            includingPropertiesForKeys: [.isDirectoryKey],
            options: [.skipsHiddenFiles]
        )) ?? []).filter { url in
            var isDir: ObjCBool = false
            return FileManager.default.fileExists(atPath: url.path, isDirectory: &isDir) && isDir.boolValue
        }

        let freeSpace = Self.queryDeviceAvailableBytes(for: rootDirectoryURL)

        return StorageUsageSnapshot(
            ipaBytes: ipaSize,
            cacheBytes: cacheSize,
            databaseBytes: dbSize,
            exportsBytes: expSize,
            configuredQuotaBytes: configuredQuotaBytes,
            deviceFreeBytes: freeSpace,
            ipaFileCount: ipaCount,
            cachedItemCount: cacheCount,
            gameDatabaseCount: gameFolders.count
        )
    }

    public func verifyCapacityForImport(fileSizeBytes: Int64) throws {
        let snapshot = computeUsageSnapshot()
        let needed = fileSizeBytes + (32 * 1_048_576)
        if snapshot.deviceFreeBytes > 0 && snapshot.deviceFreeBytes < needed {
            throw AnalysisError.insufficientStorage(
                requiredBytes: needed,
                availableBytes: snapshot.deviceFreeBytes
            )
        }
    }

    public func storedIPAURL(for fileName: String) -> URL {
        ipasDirectoryURL.appendingPathComponent(fileName)
    }

    public func makeTemporaryExtractionFolder(prefix: String = "job") throws -> URL {
        let folder = cacheDirectoryURL.appendingPathComponent("\(prefix)_\(UUID().uuidString)", isDirectory: true)
        try FileManager.default.createDirectory(at: folder, withIntermediateDirectories: true)
        return folder
    }

    /// Clears temporary extraction cache without deleting saved Offset files or Games database.
    public func clearCache() throws {
        lock.lock()
        defer { lock.unlock() }

        if FileManager.default.fileExists(atPath: cacheDirectoryURL.path) {
            try FileManager.default.removeItem(at: cacheDirectoryURL)
        }
        try FileManager.default.createDirectory(at: cacheDirectoryURL, withIntermediateDirectories: true)
    }

    /// Deletes only the raw `.ipa` file for a game while preserving `Games/<GameName>/*.json` and exported offset files.
    public func deleteStoredIPA(
        for record: GameRecord,
        database: OffsetDatabaseService
    ) throws -> GameRecord? {
        lock.lock()
        if let ipaName = record.storedIPAFileName {
            let ipaURL = ipasDirectoryURL.appendingPathComponent(ipaName)
            if FileManager.default.fileExists(atPath: ipaURL.path) {
                try FileManager.default.removeItem(at: ipaURL)
            }
        }
        lock.unlock()
        return try database.markIPARemoved(for: record.id)
    }

    public func deleteAllStoredIPAs(database: OffsetDatabaseService) throws {
        let records = database.loadAllRecords()
        for rec in records where rec.hasStoredIPA {
            _ = try deleteStoredIPA(for: rec, database: database)
        }
        lock.lock()
        defer { lock.unlock() }
        if FileManager.default.fileExists(atPath: ipasDirectoryURL.path) {
            try FileManager.default.removeItem(at: ipasDirectoryURL)
        }
        try FileManager.default.createDirectory(at: ipasDirectoryURL, withIntermediateDirectories: true)
    }

    public static func queryDeviceAvailableBytes(for url: URL) -> Int64 {
        if let values = try? url.resourceValues(forKeys: [.volumeAvailableCapacityForImportantUsageKey]),
           let important = values.volumeAvailableCapacityForImportantUsage,
           important > 0 {
            return important
        }
        if let attrs = try? FileManager.default.attributesOfFileSystem(forPath: url.path),
           let free = (attrs[.systemFreeSize] as? NSNumber)?.int64Value {
            return free
        }
        return 16 * 1_073_741_824
    }

    private static func directorySizeAndCount(at directoryURL: URL) -> (bytes: Int64, fileCount: Int) {
        guard let enumerator = FileManager.default.enumerator(
            at: directoryURL,
            includingPropertiesForKeys: [.isRegularFileKey, .fileSizeKey],
            options: [.skipsHiddenFiles]
        ) else {
            return (0, 0)
        }

        var totalBytes: Int64 = 0
        var count = 0
        for case let fileURL as URL in enumerator {
            guard let vals = try? fileURL.resourceValues(forKeys: [.isRegularFileKey, .fileSizeKey]),
                  vals.isRegularFile == true else {
                continue
            }
            totalBytes += Int64(vals.fileSize ?? 0)
            count += 1
        }
        return (totalBytes, count)
    }
}
