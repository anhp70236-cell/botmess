import Foundation
import Darwin
import Compression

public struct ZipEntry: Equatable, Sendable {
    public var path: String
    public var compressionMethod: UInt16
    public var crc32: UInt32
    public var compressedSize: UInt64
    public var uncompressedSize: UInt64
    public var localHeaderOffset: UInt64
    public var isDirectory: Bool
}

public enum ZipReaderError: Error, LocalizedError {
    case openFailed(String, Int32)
    case invalidArchive(String)
    case unsupportedCompression(UInt16, String)
    case decompressFailed(Int32, String)
    case ioFailed(String)

    public var errorDescription: String? {
        switch self {
        case let .openFailed(path, err):
            return "Không thể mở IPA \(path) (errno \(err))"
        case let .invalidArchive(msg):
            return "Tệp ZIP/IPA không hợp lệ: \(msg)"
        case let .unsupportedCompression(method, entry):
            return "Phương thức nén #\(method) không được hỗ trợ cho \(entry)"
        case let .decompressFailed(code, entry):
            return "Lỗi giải nén Compression (\(code)) cho \(entry)"
        case let .ioFailed(msg):
            return "Lỗi đọc/ghi tệp: \(msg)"
        }
    }
}

public final class StreamingZipReader {
    public let url: URL
    public let fileSize: UInt64
    public private(set) var entries: [ZipEntry] = []
    private let fd: Int32

    public init(url: URL) throws {
        self.url = url
        let fd = Darwin.open(url.path, O_RDONLY)
        if fd < 0 {
            throw ZipReaderError.openFailed(url.path, errno)
        }
        var st = stat()
        if fstat(fd, &st) != 0 {
            let err = errno
            Darwin.close(fd)
            throw ZipReaderError.openFailed(url.path, err)
        }
        self.fd = fd
        self.fileSize = UInt64(max(0, st.st_size))
        do {
            try parseCentralDirectory()
        } catch {
            Darwin.close(fd)
            throw error
        }
    }

    deinit {
        if fd >= 0 {
            Darwin.close(fd)
        }
    }

    private func preadExact(offset: UInt64, length: Int) throws -> [UInt8] {
        guard length >= 0, offset <= fileSize, UInt64(length) <= fileSize - offset else {
            throw ZipReaderError.invalidArchive("Đọc vượt quá kích thước tệp (\(offset) + \(length) > \(fileSize))")
        }
        if length == 0 { return [] }
        var buf = [UInt8](repeating: 0, count: length)
        var totalRead = 0
        while totalRead < length {
            let n = buf.withUnsafeMutableBytes { raw -> Int in
                let ptr = raw.baseAddress!.advanced(by: totalRead)
                return Darwin.pread(fd, ptr, length - totalRead, off_t(offset + UInt64(totalRead)))
            }
            if n < 0 {
                if errno == EINTR { continue }
                throw ZipReaderError.ioFailed("pread lỗi errno=\(errno)")
            }
            if n == 0 { break }
            totalRead += n
        }
        if totalRead != length {
            throw ZipReaderError.invalidArchive("Đọc thiếu dữ liệu tại offset \(offset)")
        }
        return buf
    }

    private static func u16(_ b: [UInt8], _ i: Int) -> UInt16 {
        return UInt16(b[i]) | (UInt16(b[i + 1]) << 8)
    }

    private static func u32(_ b: [UInt8], _ i: Int) -> UInt32 {
        return UInt32(b[i])
            | (UInt32(b[i + 1]) << 8)
            | (UInt32(b[i + 2]) << 16)
            | (UInt32(b[i + 3]) << 24)
    }

    private static func u64(_ b: [UInt8], _ i: Int) -> UInt64 {
        let lo = UInt64(u32(b, i))
        let hi = UInt64(u32(b, i + 4))
        return lo | (hi << 32)
    }

    private func parseCentralDirectory() throws {
        guard fileSize >= 22 else {
            throw ZipReaderError.invalidArchive("Tệp quá nhỏ (< 22 bytes)")
        }
        let tailLen = Int(min(fileSize, 66_000))
        let tailOffset = fileSize - UInt64(tailLen)
        let tail = try preadExact(offset: tailOffset, length: tailLen)

        var eocdPos = -1
        for i in stride(from: tail.count - 22, through: 0, by: -1) {
            if tail[i] == 0x50 && tail[i + 1] == 0x4B && tail[i + 2] == 0x05 && tail[i + 3] == 0x06 {
                let commentLen = Int(Self.u16(tail, i + 20))
                if i + 22 + commentLen <= tail.count {
                    eocdPos = i
                    break
                }
            }
        }
        guard eocdPos >= 0 else {
            throw ZipReaderError.invalidArchive("Không tìm thấy End of Central Directory (EOCD)")
        }

        var cdEntriesCount = UInt64(Self.u16(tail, eocdPos + 10))
        var cdSize = UInt64(Self.u32(tail, eocdPos + 12))
        var cdOffset = UInt64(Self.u32(tail, eocdPos + 16))

        // Check ZIP64 EOCD Locator right before EOCD
        if eocdPos >= 20,
           tail[eocdPos - 20] == 0x50, tail[eocdPos - 19] == 0x4B,
           tail[eocdPos - 18] == 0x06, tail[eocdPos - 17] == 0x07 {
            let zip64EocdOffset = Self.u64(tail, eocdPos - 12)
            if zip64EocdOffset + 56 <= fileSize {
                let z64 = try preadExact(offset: zip64EocdOffset, length: 56)
                if Self.u32(z64, 0) == 0x06064B50 {
                    cdEntriesCount = Self.u64(z64, 32)
                    cdSize = Self.u64(z64, 40)
                    cdOffset = Self.u64(z64, 48)
                }
            }
        }

        guard cdSize > 0, cdOffset + cdSize <= fileSize else {
            if cdEntriesCount == 0 {
                entries = []
                return
            }
            throw ZipReaderError.invalidArchive("Central Directory vượt ngoài kích thước tệp")
        }
        guard cdSize <= 512 * 1024 * 1024 else {
            throw ZipReaderError.invalidArchive("Central Directory quá lớn (> 512 MB)")
        }

        let cdData = try preadExact(offset: cdOffset, length: Int(cdSize))
        var parsed: [ZipEntry] = []
        parsed.reserveCapacity(Int(min(cdEntriesCount, 200_000)))

        var pos = 0
        while pos + 46 <= cdData.count {
            let sig = Self.u32(cdData, pos)
            if sig != 0x02014B50 { break }

            let method = Self.u16(cdData, pos + 10)
            let crc = Self.u32(cdData, pos + 16)
            var compSize = UInt64(Self.u32(cdData, pos + 20))
            var uncompSize = UInt64(Self.u32(cdData, pos + 24))
            let nameLen = Int(Self.u16(cdData, pos + 28))
            let extraLen = Int(Self.u16(cdData, pos + 30))
            let commentLen = Int(Self.u16(cdData, pos + 32))
            var localOff = UInt64(Self.u32(cdData, pos + 42))

            let nameStart = pos + 46
            let extraStart = nameStart + nameLen
            let nextPos = extraStart + extraLen + commentLen
            if nextPos > cdData.count { break }

            let nameBytes = cdData[nameStart..<extraStart]
            let entryPath = String(decoding: nameBytes, as: UTF8.self)

            if (compSize == 0xFFFF_FFFF || uncompSize == 0xFFFF_FFFF || localOff == 0xFFFF_FFFF), extraLen >= 4 {
                var ep = extraStart
                let extraEnd = extraStart + extraLen
                while ep + 4 <= extraEnd {
                    let tag = Self.u16(cdData, ep)
                    let sz = Int(Self.u16(cdData, ep + 2))
                    let valStart = ep + 4
                    if valStart + sz > extraEnd { break }
                    if tag == 0x0001 {
                        var vp = valStart
                        if uncompSize == 0xFFFF_FFFF && vp + 8 <= valStart + sz {
                            uncompSize = Self.u64(cdData, vp)
                            vp += 8
                        }
                        if compSize == 0xFFFF_FFFF && vp + 8 <= valStart + sz {
                            compSize = Self.u64(cdData, vp)
                            vp += 8
                        }
                        if localOff == 0xFFFF_FFFF && vp + 8 <= valStart + sz {
                            localOff = Self.u64(cdData, vp)
                        }
                        break
                    }
                    ep = valStart + sz
                }
            }

            parsed.append(ZipEntry(
                path: entryPath,
                compressionMethod: method,
                crc32: crc,
                compressedSize: compSize,
                uncompressedSize: uncompSize,
                localHeaderOffset: localOff,
                isDirectory: entryPath.hasSuffix("/")
            ))
            pos = nextPos
        }

        self.entries = parsed
    }

    private func dataOffset(for entry: ZipEntry) throws -> UInt64 {
        let lfh = try preadExact(offset: entry.localHeaderOffset, length: 30)
        guard Self.u32(lfh, 0) == 0x04034B50 else {
            throw ZipReaderError.invalidArchive("Local File Header hỏng tại \(entry.path)")
        }
        let nameLen = UInt64(Self.u16(lfh, 26))
        let extraLen = UInt64(Self.u16(lfh, 28))
        return entry.localHeaderOffset + 30 + nameLen + extraLen
    }

    public func peekEntryPrefix(_ entry: ZipEntry, maxBytes: Int = 4) -> [UInt8]? {
        guard maxBytes > 0, entry.compressedSize > 0, entry.uncompressedSize >= UInt64(maxBytes) else {
            return nil
        }
        guard let startOffset = try? dataOffset(for: entry) else {
            return nil
        }

        if entry.compressionMethod == 0 {
            return try? preadExact(offset: startOffset, length: maxBytes)
        }

        guard entry.compressionMethod == 8 else {
            return nil
        }

        let toRead = Int(min(UInt64(512), entry.compressedSize))
        guard toRead > 0, let inBuf = try? preadExact(offset: startOffset, length: toRead) else {
            return nil
        }

        let streamPtr = UnsafeMutablePointer<compression_stream>.allocate(capacity: 1)
        memset(streamPtr, 0, MemoryLayout<compression_stream>.size)
        guard compression_stream_init(streamPtr, COMPRESSION_STREAM_DECODE, COMPRESSION_ZLIB) != COMPRESSION_STATUS_ERROR else {
            streamPtr.deallocate()
            return nil
        }
        defer {
            compression_stream_destroy(streamPtr)
            streamPtr.deallocate()
        }

        let dstCap = max(64, maxBytes)
        var outBuf = [UInt8](repeating: 0, count: dstCap)
        let flags: Int32 = (UInt64(toRead) == entry.compressedSize) ? Int32(COMPRESSION_STREAM_FINALIZE.rawValue) : 0
        var produced = 0

        inBuf.withUnsafeBytes { inRaw in
            guard let srcBase = inRaw.bindMemory(to: UInt8.self).baseAddress else { return }
            streamPtr.pointee.src_ptr = srcBase
            streamPtr.pointee.src_size = toRead
            outBuf.withUnsafeMutableBytes { outRaw in
                guard let dstBase = outRaw.bindMemory(to: UInt8.self).baseAddress else { return }
                streamPtr.pointee.dst_ptr = dstBase
                streamPtr.pointee.dst_size = dstCap
                let status = compression_stream_process(streamPtr, flags)
                if status != COMPRESSION_STATUS_ERROR {
                    produced = dstCap - streamPtr.pointee.dst_size
                }
            }
        }

        guard produced >= maxBytes else { return nil }
        return Array(outBuf.prefix(maxBytes))
    }

    public func extractSmallEntryData(_ entry: ZipEntry, maxBytes: Int = 8 * 1024 * 1024) throws -> Data {
        guard entry.uncompressedSize <= UInt64(maxBytes) else {
            throw ZipReaderError.ioFailed("Tệp \(entry.path) vượt quá giới hạn đọc nhanh")
        }
        let tempURL = FileManager.default.temporaryDirectory
            .appendingPathComponent("zip_small_\(UUID().uuidString).bin")
        defer { try? FileManager.default.removeItem(at: tempURL) }
        try extractEntry(entry, to: tempURL, progress: nil)
        return try Data(contentsOf: tempURL)
    }

    public func extractEntry(_ entry: ZipEntry, to destinationURL: URL, progress: ((Double) -> Void)? = nil) throws {
        let fm = FileManager.default
        let parent = destinationURL.deletingLastPathComponent()
        if !fm.fileExists(atPath: parent.path) {
            try fm.createDirectory(at: parent, withIntermediateDirectories: true)
        }
        if fm.fileExists(atPath: destinationURL.path) {
            try fm.removeItem(at: destinationURL)
        }
        fm.createFile(atPath: destinationURL.path, contents: nil)
        let outHandle = try FileHandle(forWritingTo: destinationURL)
        defer { try? outHandle.close() }

        let startOffset = try dataOffset(for: entry)
        let compTotal = entry.compressedSize
        if compTotal == 0 {
            progress?(1.0)
            return
        }

        let chunkSize = 524_288

        if entry.compressionMethod == 0 {
            var remaining = compTotal
            var cursor = startOffset
            while remaining > 0 {
                let toRead = Int(min(UInt64(chunkSize), remaining))
                let chunk = try preadExact(offset: cursor, length: toRead)
                try outHandle.write(contentsOf: Data(chunk))
                cursor += UInt64(toRead)
                remaining -= UInt64(toRead)
                if let progress = progress, compTotal > 0 {
                    progress(Double(compTotal - remaining) / Double(compTotal))
                }
            }
            progress?(1.0)
            return
        }

        guard entry.compressionMethod == 8 else {
            throw ZipReaderError.unsupportedCompression(entry.compressionMethod, entry.path)
        }

        let streamPtr = UnsafeMutablePointer<compression_stream>.allocate(capacity: 1)
        memset(streamPtr, 0, MemoryLayout<compression_stream>.size)
        let initStatus = compression_stream_init(streamPtr, COMPRESSION_STREAM_DECODE, COMPRESSION_ZLIB)
        guard initStatus != COMPRESSION_STATUS_ERROR else {
            streamPtr.deallocate()
            throw ZipReaderError.decompressFailed(-1, entry.path)
        }
        defer {
            compression_stream_destroy(streamPtr)
            streamPtr.deallocate()
        }

        let dstBuffer = UnsafeMutablePointer<UInt8>.allocate(capacity: chunkSize)
        defer { dstBuffer.deallocate() }
        var dummySrc: UInt8 = 0

        var remaining = compTotal
        var cursor = startOffset
        var done = false

        while !done {
            let toRead = Int(min(UInt64(chunkSize), remaining))
            let inBuf = toRead > 0 ? try preadExact(offset: cursor, length: toRead) : []
            cursor += UInt64(toRead)
            remaining -= UInt64(toRead)
            let flags: Int32 = (toRead == 0) ? Int32(COMPRESSION_STREAM_FINALIZE.rawValue) : 0

            try inBuf.withUnsafeBytes { inRaw in
                if toRead > 0, let srcBase = inRaw.bindMemory(to: UInt8.self).baseAddress {
                    streamPtr.pointee.src_ptr = srcBase
                    streamPtr.pointee.src_size = toRead
                } else {
                    streamPtr.pointee.src_ptr = withUnsafePointer(to: &dummySrc) { $0 }
                    streamPtr.pointee.src_size = 0
                }

                while !done {
                    streamPtr.pointee.dst_ptr = dstBuffer
                    streamPtr.pointee.dst_size = chunkSize

                    let status = compression_stream_process(streamPtr, flags)
                    let produced = chunkSize - streamPtr.pointee.dst_size
                    if produced > 0 {
                        try outHandle.write(contentsOf: Data(bytes: dstBuffer, count: produced))
                    }

                    if status == COMPRESSION_STATUS_END {
                        done = true
                        break
                    }
                    if status == COMPRESSION_STATUS_ERROR {
                        throw ZipReaderError.decompressFailed(-2, entry.path)
                    }
                    if toRead > 0 {
                        if streamPtr.pointee.src_size == 0 && produced == 0 {
                            break
                        }
                    } else {
                        if produced == 0 {
                            done = true
                            break
                        }
                    }
                }
            }

            if let progress = progress, compTotal > 0 {
                progress(Double(compTotal - remaining) / Double(compTotal))
            }
        }
        progress?(1.0)
    }
}
