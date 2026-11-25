import Foundation
import zlib

public struct IPAZipEntry: Sendable, Equatable {
    public let path: String
    public let compressionMethod: UInt16
    public let crc32: UInt32
    public let compressedSize: UInt64
    public let uncompressedSize: UInt64
    public let localHeaderOffset: UInt64
    public let isDirectory: Bool

    public init(
        path: String,
        compressionMethod: UInt16,
        crc32: UInt32,
        compressedSize: UInt64,
        uncompressedSize: UInt64,
        localHeaderOffset: UInt64,
        isDirectory: Bool
    ) {
        self.path = path
        self.compressionMethod = compressionMethod
        self.crc32 = crc32
        self.compressedSize = compressedSize
        self.uncompressedSize = uncompressedSize
        self.localHeaderOffset = localHeaderOffset
        self.isDirectory = isDirectory
    }
}

/// Streaming PKZIP & ZIP64 archive reader optimized for large multi-gigabyte iOS .ipa files.
/// Reads Central Directory without loading the entire archive into memory and extracts individual
/// entries using chunked zlib streams with cancellation and progress support.
public final class IPAZipArchiveReader {
    private static let eocdSignature: UInt32 = 0x06054B50
    private static let zip64LocatorSignature: UInt32 = 0x07064B50
    private static let zip64EOCDSignature: UInt32 = 0x06064B50
    private static let centralFileHeaderSignature: UInt32 = 0x02014B50
    private static let localFileHeaderSignature: UInt32 = 0x04034B50

    public let archiveURL: URL
    public let archiveFileSize: UInt64

    public init(archiveURL: URL) throws {
        guard FileManager.default.fileExists(atPath: archiveURL.path) else {
            throw AnalysisError.fileNotFound(archiveURL.path)
        }
        let attrs = try FileManager.default.attributesOfItem(atPath: archiveURL.path)
        let size = (attrs[.size] as? NSNumber)?.uint64Value ?? 0
        guard size >= 22 else {
            throw AnalysisError.invalidIPAArchive("File is smaller than minimum PKZIP header (22 bytes).")
        }
        self.archiveURL = archiveURL
        self.archiveFileSize = size
    }

    public func listEntries() throws -> [IPAZipEntry] {
        let handle = try FileHandle(forReadingFrom: archiveURL)
        defer { try? handle.close() }

        let maxTailSearch = min(archiveFileSize, UInt64(65_557))
        let tailStart = archiveFileSize - maxTailSearch
        try handle.seek(toOffset: tailStart)
        guard let tailData = try handle.read(upToCount: Int(maxTailSearch)), tailData.count >= 22 else {
            throw AnalysisError.invalidIPAArchive("Unable to read End of Central Directory.")
        }

        var eocdOffsetInTail: Int?
        var idx = tailData.count - 22
        while idx >= 0 {
            if Self.readUInt32LE(tailData, at: idx) == Self.eocdSignature {
                eocdOffsetInTail = idx
                break
            }
            if idx == 0 { break }
            idx -= 1
        }

        guard let foundEOCD = eocdOffsetInTail else {
            throw AnalysisError.invalidIPAArchive("PKZIP End of Central Directory signature not found.")
        }

        var totalEntries = UInt64(Self.readUInt16LE(tailData, at: foundEOCD + 10))
        var centralDirSize = UInt64(Self.readUInt32LE(tailData, at: foundEOCD + 12))
        var centralDirOffset = UInt64(Self.readUInt32LE(tailData, at: foundEOCD + 16))

        // Check ZIP64 EOCD Locator immediately preceding EOCD (20 bytes)
        if foundEOCD >= 20 {
            let locatorPos = foundEOCD - 20
            if Self.readUInt32LE(tailData, at: locatorPos) == Self.zip64LocatorSignature {
                let zip64EOCDOffset = Self.readUInt64LE(tailData, at: locatorPos + 8)
                try handle.seek(toOffset: zip64EOCDOffset)
                if let zip64Header = try handle.read(upToCount: 56),
                   zip64Header.count >= 56,
                   Self.readUInt32LE(zip64Header, at: 0) == Self.zip64EOCDSignature {
                    totalEntries = Self.readUInt64LE(zip64Header, at: 32)
                    centralDirSize = Self.readUInt64LE(zip64Header, at: 40)
                    centralDirOffset = Self.readUInt64LE(zip64Header, at: 48)
                }
            }
        }

        guard centralDirOffset + centralDirSize <= archiveFileSize else {
            throw AnalysisError.corruptedArchive("Central Directory bounds exceed archive file size.")
        }

        try handle.seek(toOffset: centralDirOffset)
        guard let cdData = try handle.read(upToCount: Int(centralDirSize)) else {
            throw AnalysisError.corruptedArchive("Unable to read Central Directory entries.")
        }

        var entries: [IPAZipEntry] = []
        entries.reserveCapacity(Int(min(totalEntries, 16_384)))

        var pos = 0
        while pos + 46 <= cdData.count {
            if Task.isCancelled { throw AnalysisError.cancelled }
            let sig = Self.readUInt32LE(cdData, at: pos)
            guard sig == Self.centralFileHeaderSignature else {
                break
            }

            let method = Self.readUInt16LE(cdData, at: pos + 10)
            let crc = Self.readUInt32LE(cdData, at: pos + 16)
            var compSize = UInt64(Self.readUInt32LE(cdData, at: pos + 20))
            var uncompSize = UInt64(Self.readUInt32LE(cdData, at: pos + 24))
            let nameLen = Int(Self.readUInt16LE(cdData, at: pos + 28))
            let extraLen = Int(Self.readUInt16LE(cdData, at: pos + 30))
            let commentLen = Int(Self.readUInt16LE(cdData, at: pos + 32))
            var localOffset = UInt64(Self.readUInt32LE(cdData, at: pos + 42))

            let nameStart = pos + 46
            let extraStart = nameStart + nameLen
            let nextPos = extraStart + extraLen + commentLen
            guard nextPos <= cdData.count else {
                throw AnalysisError.corruptedArchive("Truncated Central Directory entry header.")
            }

            let nameSlice = cdData.subdata(in: nameStart..<extraStart)
            let path = String(data: nameSlice, encoding: .utf8)
                ?? String(decoding: nameSlice, as: UTF8.self)

            // Parse ZIP64 Extra Field (0x0001) if any field is 0xFFFFFFFF
            if compSize == 0xFFFF_FFFF || uncompSize == 0xFFFF_FFFF || localOffset == 0xFFFF_FFFF {
                var ePos = extraStart
                let eEnd = extraStart + extraLen
                while ePos + 4 <= eEnd {
                    let headerID = Self.readUInt16LE(cdData, at: ePos)
                    let dataSize = Int(Self.readUInt16LE(cdData, at: ePos + 2))
                    let fieldDataStart = ePos + 4
                    let fieldDataEnd = fieldDataStart + dataSize
                    guard fieldDataEnd <= eEnd else { break }

                    if headerID == 0x0001 {
                        var cursor = fieldDataStart
                        if uncompSize == 0xFFFF_FFFF && cursor + 8 <= fieldDataEnd {
                            uncompSize = Self.readUInt64LE(cdData, at: cursor)
                            cursor += 8
                        }
                        if compSize == 0xFFFF_FFFF && cursor + 8 <= fieldDataEnd {
                            compSize = Self.readUInt64LE(cdData, at: cursor)
                            cursor += 8
                        }
                        if localOffset == 0xFFFF_FFFF && cursor + 8 <= fieldDataEnd {
                            localOffset = Self.readUInt64LE(cdData, at: cursor)
                            cursor += 8
                        }
                        break
                    }
                    ePos = fieldDataEnd
                }
            }

            let isDir = path.hasSuffix("/")
            entries.append(
                IPAZipEntry(
                    path: path,
                    compressionMethod: method,
                    crc32: crc,
                    compressedSize: compSize,
                    uncompressedSize: uncompSize,
                    localHeaderOffset: localOffset,
                    isDirectory: isDir
                )
            )

            pos = nextPos
        }

        return entries
    }

    public func extractEntryToData(
        _ entry: IPAZipEntry,
        verifyCRC: Bool = true
    ) throws -> Data {
        var result = Data()
        if entry.uncompressedSize <= 256 * 1024 * 1024 {
            result.reserveCapacity(Int(entry.uncompressedSize))
        }
        try streamExtractEntry(entry, verifyCRC: verifyCRC) { chunk in
            result.append(chunk)
        }
        return result
    }

    public func extractEntryToFile(
        _ entry: IPAZipEntry,
        destinationURL: URL,
        verifyCRC: Bool = true,
        progress: ((Double) -> Void)? = nil
    ) throws {
        let dir = destinationURL.deletingLastPathComponent()
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        if FileManager.default.fileExists(atPath: destinationURL.path) {
            try FileManager.default.removeItem(at: destinationURL)
        }
        FileManager.default.createFile(atPath: destinationURL.path, contents: nil)
        let outHandle = try FileHandle(forWritingTo: destinationURL)
        defer { try? outHandle.close() }

        var written: UInt64 = 0
        let total = max(entry.uncompressedSize, 1)

        try streamExtractEntry(entry, verifyCRC: verifyCRC) { chunk in
            try outHandle.write(contentsOf: chunk)
            written += UInt64(chunk.count)
            progress?(min(1.0, Double(written) / Double(total)))
        }
    }

    public func streamExtractEntry(
        _ entry: IPAZipEntry,
        verifyCRC: Bool = true,
        chunkConsumer: (Data) throws -> Void
    ) throws {
        if entry.isDirectory { return }

        let handle = try FileHandle(forReadingFrom: archiveURL)
        defer { try? handle.close() }

        try handle.seek(toOffset: entry.localHeaderOffset)
        guard let localHeader = try handle.read(upToCount: 30),
              localHeader.count == 30,
              Self.readUInt32LE(localHeader, at: 0) == Self.localFileHeaderSignature else {
            throw AnalysisError.corruptedArchive("Invalid local file header for \(entry.path)")
        }

        let fileNameLen = UInt64(Self.readUInt16LE(localHeader, at: 26))
        let extraLen = UInt64(Self.readUInt16LE(localHeader, at: 28))
        let dataOffset = entry.localHeaderOffset + 30 + fileNameLen + extraLen
        guard dataOffset + entry.compressedSize <= archiveFileSize else {
            throw AnalysisError.corruptedArchive("Compressed payload exceeds file bounds for \(entry.path)")
        }

        try handle.seek(toOffset: dataOffset)
        var runningCRC: uLong = zlib.crc32(0, nil, 0)
        var remainingCompressed = entry.compressedSize
        let ioChunkSize = 262_144 // 256 KB streaming chunks

        switch entry.compressionMethod {
        case 0: // Stored (uncompressed)
            while remainingCompressed > 0 {
                if Task.isCancelled { throw AnalysisError.cancelled }
                let toRead = Int(min(remainingCompressed, UInt64(ioChunkSize)))
                guard let chunk = try handle.read(upToCount: toRead), !chunk.isEmpty else {
                    throw AnalysisError.corruptedArchive("Unexpected EOF while reading stored entry \(entry.path)")
                }
                if verifyCRC {
                    runningCRC = Self.updateCRC(runningCRC, with: chunk)
                }
                try chunkConsumer(chunk)
                remainingCompressed -= UInt64(chunk.count)
            }

        case 8: // Deflated (RFC 1951 raw deflate)
            var stream = z_stream()
            let initCode = inflateInit2_(
                &stream,
                -MAX_WBITS,
                zlibVersion(),
                Int32(MemoryLayout<z_stream>.size)
            )
            guard initCode == Z_OK else {
                throw AnalysisError.corruptedArchive("zlib initialization failed (\(initCode))")
            }
            defer { inflateEnd(&stream) }

            let outCapacity = 262_144
            var outBuffer = [UInt8](repeating: 0, count: outCapacity)
            var streamEnded = false

            while remainingCompressed > 0 && !streamEnded {
                if Task.isCancelled { throw AnalysisError.cancelled }
                let toRead = Int(min(remainingCompressed, UInt64(ioChunkSize)))
                guard var inChunk = try handle.read(upToCount: toRead), !inChunk.isEmpty else {
                    throw AnalysisError.corruptedArchive("Unexpected EOF in deflate stream for \(entry.path)")
                }
                remainingCompressed -= UInt64(inChunk.count)

                try inChunk.withUnsafeMutableBytes { inRaw in
                    guard let inBase = inRaw.bindMemory(to: Bytef.self).baseAddress else { return }
                    stream.next_in = inBase
                    stream.avail_in = uInt(inRaw.count)

                    repeat {
                        if Task.isCancelled { throw AnalysisError.cancelled }
                        var producedData: Data?
                        var inflateResult: Int32 = Z_OK

                        outBuffer.withUnsafeMutableBufferPointer { outPtr in
                            stream.next_out = outPtr.baseAddress
                            stream.avail_out = uInt(outCapacity)
                            inflateResult = inflate(&stream, Z_NO_FLUSH)
                            let have = outCapacity - Int(stream.avail_out)
                            if have > 0, let base = outPtr.baseAddress {
                                producedData = Data(bytes: base, count: have)
                            }
                        }

                        if inflateResult != Z_OK && inflateResult != Z_STREAM_END && inflateResult != Z_BUF_ERROR {
                            throw AnalysisError.corruptedArchive("Deflate decompression failed (\(inflateResult)) in \(entry.path)")
                        }

                        if let slice = producedData {
                            if verifyCRC {
                                runningCRC = Self.updateCRC(runningCRC, with: slice)
                            }
                            try chunkConsumer(slice)
                        }

                        if inflateResult == Z_STREAM_END {
                            streamEnded = true
                        }
                    } while stream.avail_in > 0 && !streamEnded
                }
            }

        default:
            throw AnalysisError.invalidIPAArchive("Unsupported ZIP compression method \(entry.compressionMethod) in \(entry.path)")
        }

        if verifyCRC && entry.crc32 != 0 {
            let computed = UInt32(truncatingIfNeeded: runningCRC)
            guard computed == entry.crc32 else {
                throw AnalysisError.corruptedArchive(
                    String(format: "CRC-32 mismatch for %@ (expected 0x%08X, got 0x%08X)", entry.path, entry.crc32, computed)
                )
            }
        }
    }

    // MARK: - Helpers

    static func updateCRC(_ current: uLong, with data: Data) -> uLong {
        data.withUnsafeBytes { rawBuffer in
            guard let base = rawBuffer.bindMemory(to: Bytef.self).baseAddress else {
                return current
            }
            return zlib.crc32(current, base, uInt(rawBuffer.count))
        }
    }

    static func readUInt16LE(_ data: Data, at offset: Int) -> UInt16 {
        let b0 = UInt16(data[data.startIndex + offset])
        let b1 = UInt16(data[data.startIndex + offset + 1]) << 8
        return b0 | b1
    }

    static func readUInt32LE(_ data: Data, at offset: Int) -> UInt32 {
        let b0 = UInt32(data[data.startIndex + offset])
        let b1 = UInt32(data[data.startIndex + offset + 1]) << 8
        let b2 = UInt32(data[data.startIndex + offset + 2]) << 16
        let b3 = UInt32(data[data.startIndex + offset + 3]) << 24
        return b0 | b1 | b2 | b3
    }

    static func readUInt64LE(_ data: Data, at offset: Int) -> UInt64 {
        let lo = UInt64(readUInt32LE(data, at: offset))
        let hi = UInt64(readUInt32LE(data, at: offset + 4)) << 32
        return lo | hi
    }
}

/// Lightweight PKZIP builder used for packaging exports and generating deterministic test IPA archives.
public enum IPAZipArchiveWriter {
    public struct ArchiveItem {
        public let path: String
        public let data: Data
        public let compress: Bool

        public init(path: String, data: Data, compress: Bool = true) {
            self.path = path
            self.data = data
            self.compress = compress
        }
    }

    public static func writeArchive(items: [ArchiveItem], to url: URL) throws {
        var archiveData = Data()
        struct CentralRecord {
            let pathData: Data
            let method: UInt16
            let crc: UInt32
            let compSize: UInt32
            let uncompSize: UInt32
            let localOffset: UInt32
        }
        var centralRecords: [CentralRecord] = []

        for item in items {
            let pathData = Data(item.path.utf8)
            let crcVal = UInt32(truncatingIfNeeded: IPAZipArchiveReader.updateCRC(zlib.crc32(0, nil, 0), with: item.data))
            let payload: Data
            let method: UInt16

            if item.compress && !item.data.isEmpty {
                payload = try rawDeflate(item.data)
                method = 8
            } else {
                payload = item.data
                method = 0
            }

            let localOffset = UInt32(archiveData.count)
            appendUInt32LE(&archiveData, 0x04034B50)
            appendUInt16LE(&archiveData, 20)
            appendUInt16LE(&archiveData, 0)
            appendUInt16LE(&archiveData, method)
            appendUInt16LE(&archiveData, 0)
            appendUInt16LE(&archiveData, 0)
            appendUInt32LE(&archiveData, crcVal)
            appendUInt32LE(&archiveData, UInt32(payload.count))
            appendUInt32LE(&archiveData, UInt32(item.data.count))
            appendUInt16LE(&archiveData, UInt16(pathData.count))
            appendUInt16LE(&archiveData, 0)
            archiveData.append(pathData)
            archiveData.append(payload)

            centralRecords.append(
                CentralRecord(
                    pathData: pathData,
                    method: method,
                    crc: crcVal,
                    compSize: UInt32(payload.count),
                    uncompSize: UInt32(item.data.count),
                    localOffset: localOffset
                )
            )
        }

        let cdOffset = UInt32(archiveData.count)
        for rec in centralRecords {
            appendUInt32LE(&archiveData, 0x02014B50)
            appendUInt16LE(&archiveData, 20)
            appendUInt16LE(&archiveData, 20)
            appendUInt16LE(&archiveData, 0)
            appendUInt16LE(&archiveData, rec.method)
            appendUInt16LE(&archiveData, 0)
            appendUInt16LE(&archiveData, 0)
            appendUInt32LE(&archiveData, rec.crc)
            appendUInt32LE(&archiveData, rec.compSize)
            appendUInt32LE(&archiveData, rec.uncompSize)
            appendUInt16LE(&archiveData, UInt16(rec.pathData.count))
            appendUInt16LE(&archiveData, 0)
            appendUInt16LE(&archiveData, 0)
            appendUInt16LE(&archiveData, 0)
            appendUInt16LE(&archiveData, 0)
            appendUInt32LE(&archiveData, 0)
            appendUInt32LE(&archiveData, rec.localOffset)
            archiveData.append(rec.pathData)
        }

        let cdSize = UInt32(archiveData.count) - cdOffset
        appendUInt32LE(&archiveData, 0x06054B50)
        appendUInt16LE(&archiveData, 0)
        appendUInt16LE(&archiveData, 0)
        appendUInt16LE(&archiveData, UInt16(centralRecords.count))
        appendUInt16LE(&archiveData, UInt16(centralRecords.count))
        appendUInt32LE(&archiveData, cdSize)
        appendUInt32LE(&archiveData, cdOffset)
        appendUInt16LE(&archiveData, 0)

        try FileManager.default.createDirectory(at: url.deletingLastPathComponent(), withIntermediateDirectories: true)
        try archiveData.write(to: url, options: .atomic)
    }

    private static func rawDeflate(_ input: Data) throws -> Data {
        var stream = z_stream()
        let rc = deflateInit2_(
            &stream,
            Z_DEFAULT_COMPRESSION,
            Z_DEFLATED,
            -MAX_WBITS,
            8,
            Z_DEFAULT_STRATEGY,
            zlibVersion(),
            Int32(MemoryLayout<z_stream>.size)
        )
        guard rc == Z_OK else {
            throw AnalysisError.corruptedArchive("deflateInit2 failed (\(rc))")
        }
        defer { deflateEnd(&stream) }

        var mutableInput = input
        var output = Data()
        let chunkCap = 65_536
        var outBuf = [UInt8](repeating: 0, count: chunkCap)

        mutableInput.withUnsafeMutableBytes { inRaw in
            stream.next_in = inRaw.bindMemory(to: Bytef.self).baseAddress
            stream.avail_in = uInt(inRaw.count)

            repeat {
                outBuf.withUnsafeMutableBufferPointer { outPtr in
                    stream.next_out = outPtr.baseAddress
                    stream.avail_out = uInt(chunkCap)
                    _ = deflate(&stream, Z_FINISH)
                    let have = chunkCap - Int(stream.avail_out)
                    if have > 0, let base = outPtr.baseAddress {
                        output.append(base, count: have)
                    }
                }
            } while stream.avail_out == 0
        }
        return output
    }

    private static func appendUInt16LE(_ data: inout Data, _ value: UInt16) {
        var le = value.littleEndian
        withUnsafeBytes(of: &le) { data.append(contentsOf: $0) }
    }

    private static func appendUInt32LE(_ data: inout Data, _ value: UInt32) {
        var le = value.littleEndian
        withUnsafeBytes(of: &le) { data.append(contentsOf: $0) }
    }
}
