import Foundation
import CryptoKit

public enum HexFormatters {
    public static func hex(_ value: UInt64, padWidth: Int = 0) -> String {
        if padWidth > 0 {
            return String(format: "0x%0*llX", padWidth, value)
        }
        return String(format: "0x%llX", value)
    }

    public static func rvaHex(_ value: UInt64) -> String {
        String(format: "0x%08llX", value)
    }

    public static func vaHex(_ value: UInt64) -> String {
        String(format: "0x%09llX", value)
    }

    public static func parseHex(_ text: String) -> UInt64? {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return nil }
        let clean: String
        if trimmed.lowercased().hasPrefix("0x") {
            clean = String(trimmed.dropFirst(2))
        } else {
            clean = trimmed
        }
        return UInt64(clean, radix: 16)
    }

    public static func formatBytes(_ bytes: Int64) -> String {
        let clamped = max(0, bytes)
        let gb = Double(clamped) / 1_073_741_824.0
        if gb >= 1.0 {
            return String(format: "%.2f GB", gb)
        }
        let mb = Double(clamped) / 1_048_576.0
        if mb >= 1.0 {
            return String(format: "%.1f MB", mb)
        }
        let kb = Double(clamped) / 1024.0
        if kb >= 1.0 {
            return String(format: "%.1f KB", kb)
        }
        return "\(clamped) B"
    }

    public static func sanitizeDirectoryName(_ rawName: String) -> String {
        let forbidden = CharacterSet(charactersIn: "/\\:?%*|\"<>.\0")
        let components = rawName
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .components(separatedBy: forbidden)
            .joined(separator: "_")
            .replacingOccurrences(of: "  ", with: " ")
            .trimmingCharacters(in: CharacterSet(charactersIn: " _-"))
        if components.isEmpty {
            return "UnknownGame"
        }
        return String(components.prefix(64))
    }

    public static func sha256Hex(ofFile url: URL, chunkSize: Int = 1_048_576) throws -> String {
        let handle = try FileHandle(forReadingFrom: url)
        defer { try? handle.close() }

        var hasher = SHA256()
        while autoreleasepool(invoking: {
            guard let data = try? handle.read(upToCount: chunkSize), !data.isEmpty else {
                return false
            }
            hasher.update(data: data)
            return true
        }) {}

        let digest = hasher.finalize()
        return digest.map { String(format: "%02x", $0) }.joined()
    }

    public static func sha256Hex(of data: Data) -> String {
        let digest = SHA256.hash(data: data)
        return digest.map { String(format: "%02x", $0) }.joined()
    }
}
