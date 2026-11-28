import Foundation
import zlib

public final class StreamingZipWriter {
    private struct CentralRecord {
        var nameBytes: [UInt8]
        var method: UInt16
        var crc32: UInt32
        var compressedSize: UInt64
        var uncompressedSize: UInt64
        var localHeaderOffset: UInt64
    }

    private let handle: FileHandle
    private var bytesWritten: UInt64 = 0
    private var records: [CentralRecord] = []
    private var finalized = false

    public init(destinationURL: URL) throws {
        let fm = FileManager.default
        let dir = destinationURL.deletingLastPathComponent()
        if !fm.fileExists(atPath: dir.path) {
            try fm.createDirectory(at: dir, withIntermediateDirectories: true)
        }
        if fm.fileExists(atPath: destinationURL.path) {
            try fm.removeItem(at: destinationURL)
        }
        fm.createFile(atPath: destinationURL.path, contents: nil)
        self.handle = try FileHandle(forWritingTo: destinationURL)
    }

    deinit {
        if !finalized {
            try? finalize()
        }
    }

    private func writeBytes(_ bytes: [UInt8]) throws {
        if bytes.isEmpty { return }
        try handle.write(contentsOf: Data(bytes))
        bytesWritten += UInt64(bytes.count)
    }

    private func writeData(_ data: Data) throws {
        if data.isEmpty { return }
        try handle.write(contentsOf: data)
        bytesWritten += UInt64(data.count)
    }

    private static func appendU16(_ out: inout [UInt8], _ v: UInt16) {
        out.append(UInt8(v & 0xFF))
        out.append(UInt8((v >> 8) & 0xFF))
    }

    private static func appendU32(_ out: inout [UInt8], _ v: UInt32) {
        out.append(UInt8(v & 0xFF))
        out.append(UInt8((v >> 8) & 0xFF))
        out.append(UInt8((v >> 16) & 0xFF))
        out.append(UInt8((v >> 24) & 0xFF))
    }

    private static func appendU64(_ out: inout [UInt8], _ v: UInt64) {
        appendU32(&out, UInt32(v & 0xFFFF_FFFF))
        appendU32(&out, UInt32((v >> 32) & 0xFFFF_FFFF))
    }

    public func addFile(at fileURL: URL, entryName: String) throws {
        let nameBytes = Array(entryName.utf8)
        let localOffset = bytesWritten

        // Stream compress into a temporary file or direct? Using local data descriptor bit (bit 3 = 0x0008) avoids seeking!
        var lfh: [UInt8] = []
        lfh.reserveCapacity(30 + nameBytes.count)
        Self.appendU32(&lfh, 0x04034B50)
        Self.appendU16(&lfh, 20)         // version needed
        Self.appendU16(&lfh, 0x0808)     // flags: UTF-8 (bit 11) + data descriptor (bit 3)
        Self.appendU16(&lfh, 8)          // DEFLATE
        Self.appendU16(&lfh, 0)          // mod time
        Self.appendU16(&lfh, 0x5421)     // mod date
        Self.appendU32(&lfh, 0)          // crc32 (in data descriptor)
        Self.appendU32(&lfh, 0)          // compSize (in data descriptor)
        Self.appendU32(&lfh, 0)          // uncompSize (in data descriptor)
        Self.appendU16(&lfh, UInt16(nameBytes.count))
        Self.appendU16(&lfh, 0)          // extra len
        lfh.append(contentsOf: nameBytes)
        try writeBytes(lfh)

        let inHandle = try FileHandle(forReadingFrom: fileURL)
        defer { try? inHandle.close() }

        var stream = z_stream()
        let initRet = deflateInit2_(
            &stream,
            Z_DEFAULT_COMPRESSION,
            Z_DEFLATED,
            -15,
            8,
            Z_DEFAULT_STRATEGY,
            zlibVersion(),
            Int32(MemoryLayout<z_stream>.size)
        )
        guard initRet == Z_OK else {
            throw ZipReaderError.ioFailed("deflateInit2_ failed: \(initRet)")
        }
        defer { deflateEnd(&stream) }

        let chunkSize = 262_144
        var outBuf = [UInt8](repeating: 0, count: chunkSize)
        var crcVal: uLong = zlib.crc32(0, nil, 0)
        var uncompTotal: UInt64 = 0
        var compTotal: UInt64 = 0

        while true {
            let chunkData = try inHandle.read(upToCount: chunkSize) ?? Data()
            let isEOF = chunkData.isEmpty
            if !isEOF {
                uncompTotal += UInt64(chunkData.count)
                chunkData.withUnsafeBytes { raw in
                    if let ptr = raw.bindMemory(to: Bytef.self).baseAddress {
                        crcVal = zlib.crc32(crcVal, ptr, uInt(chunkData.count))
                    }
                }
            }

            var inBytes = Array(chunkData)
            try inBytes.withUnsafeMutableBytes { inRaw in
                stream.next_in = isEOF ? nil : inRaw.bindMemory(to: Bytef.self).baseAddress
                stream.avail_in = uInt(chunkData.count)
                let flushMode = isEOF ? Z_FINISH : Z_NO_FLUSH

                repeat {
                    let outCount = outBuf.count
                    var written = 0
                    outBuf.withUnsafeMutableBytes { outRaw in
                        stream.next_out = outRaw.bindMemory(to: Bytef.self).baseAddress
                        stream.avail_out = uInt(outCount)
                        _ = deflate(&stream, flushMode)
                        written = outCount - Int(stream.avail_out)
                    }
                    if written > 0 {
                        compTotal += UInt64(written)
                        try writeData(Data(outBuf[0..<written]))
                    }
                } while stream.avail_out == 0
            }

            if isEOF { break }
        }

        let finalCRC = UInt32(crcVal & 0xFFFF_FFFF)
        var dd: [UInt8] = []
        Self.appendU32(&dd, 0x08074B50)
        Self.appendU32(&dd, finalCRC)
        Self.appendU32(&dd, UInt32(min(compTotal, UInt64(UInt32.max))))
        Self.appendU32(&dd, UInt32(min(uncompTotal, UInt64(UInt32.max))))
        try writeBytes(dd)

        records.append(CentralRecord(
            nameBytes: nameBytes,
            method: 8,
            crc32: finalCRC,
            compressedSize: compTotal,
            uncompressedSize: uncompTotal,
            localHeaderOffset: localOffset
        ))
    }

    public func finalize() throws {
        guard !finalized else { return }
        finalized = true

        let cdOffset = bytesWritten
        var cdBytes: [UInt8] = []
        cdBytes.reserveCapacity(records.count * 64)

        for rec in records {
            let needsZip64 = rec.compressedSize >= UInt64(UInt32.max)
                || rec.uncompressedSize >= UInt64(UInt32.max)
                || rec.localHeaderOffset >= UInt64(UInt32.max)
            var extra: [UInt8] = []
            if needsZip64 {
                Self.appendU16(&extra, 0x0001)
                Self.appendU16(&extra, 24)
                Self.appendU64(&extra, rec.uncompressedSize)
                Self.appendU64(&extra, rec.compressedSize)
                Self.appendU64(&extra, rec.localHeaderOffset)
            }

            Self.appendU32(&cdBytes, 0x02014B50)
            Self.appendU16(&cdBytes, 45)
            Self.appendU16(&cdBytes, needsZip64 ? 45 : 20)
            Self.appendU16(&cdBytes, 0x0808)
            Self.appendU16(&cdBytes, rec.method)
            Self.appendU16(&cdBytes, 0)
            Self.appendU16(&cdBytes, 0x5421)
            Self.appendU32(&cdBytes, rec.crc32)
            Self.appendU32(&cdBytes, rec.compressedSize >= UInt64(UInt32.max) ? UInt32.max : UInt32(rec.compressedSize))
            Self.appendU32(&cdBytes, rec.uncompressedSize >= UInt64(UInt32.max) ? UInt32.max : UInt32(rec.uncompressedSize))
            Self.appendU16(&cdBytes, UInt16(rec.nameBytes.count))
            Self.appendU16(&cdBytes, UInt16(extra.count))
            Self.appendU16(&cdBytes, 0)
            Self.appendU16(&cdBytes, 0)
            Self.appendU16(&cdBytes, 0)
            Self.appendU32(&cdBytes, 0)
            Self.appendU32(&cdBytes, rec.localHeaderOffset >= UInt64(UInt32.max) ? UInt32.max : UInt32(rec.localHeaderOffset))
            cdBytes.append(contentsOf: rec.nameBytes)
            cdBytes.append(contentsOf: extra)
        }

        let cdSize = UInt64(cdBytes.count)
        try writeBytes(cdBytes)

        if cdOffset >= UInt64(UInt32.max) || cdSize >= UInt64(UInt32.max) || records.count >= 0xFFFF {
            let z64Offset = bytesWritten
            var z64: [UInt8] = []
            Self.appendU32(&z64, 0x06064B50)
            Self.appendU64(&z64, 44)
            Self.appendU16(&z64, 45)
            Self.appendU16(&z64, 45)
            Self.appendU32(&z64, 0)
            Self.appendU32(&z64, 0)
            Self.appendU64(&z64, UInt64(records.count))
            Self.appendU64(&z64, UInt64(records.count))
            Self.appendU64(&z64, cdSize)
            Self.appendU64(&z64, cdOffset)
            try writeBytes(z64)

            var loc: [UInt8] = []
            Self.appendU32(&loc, 0x07064B50)
            Self.appendU32(&loc, 0)
            Self.appendU64(&loc, z64Offset)
            Self.appendU32(&loc, 1)
            try writeBytes(loc)
        }

        var eocd: [UInt8] = []
        let cnt16 = UInt16(min(records.count, 0xFFFF))
        Self.appendU32(&eocd, 0x06054B50)
        Self.appendU16(&eocd, 0)
        Self.appendU16(&eocd, 0)
        Self.appendU16(&eocd, cnt16)
        Self.appendU16(&eocd, cnt16)
        Self.appendU32(&eocd, cdSize >= UInt64(UInt32.max) ? UInt32.max : UInt32(cdSize))
        Self.appendU32(&eocd, cdOffset >= UInt64(UInt32.max) ? UInt32.max : UInt32(cdOffset))
        Self.appendU16(&eocd, 0)
        try writeBytes(eocd)
        try handle.close()
    }
}
