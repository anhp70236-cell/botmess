import Foundation
import Darwin

public enum MappedFileError: Error, LocalizedError {
    case openFailed(String, Int32)
    case statFailed(String, Int32)
    case mmapFailed(String, Int32)
    case outOfBounds(offset: Int, length: Int, total: Int)

    public var errorDescription: String? {
        switch self {
        case let .openFailed(path, err):
            return "Không thể mở tệp \(path) (errno \(err))"
        case let .statFailed(path, err):
            return "Không thể đọc kích thước tệp \(path) (errno \(err))"
        case let .mmapFailed(path, err):
            return "Không thể ánh xạ bộ nhớ (mmap) tệp \(path) (errno \(err))"
        case let .outOfBounds(offset, length, total):
            return "Đọc vượt quá giới hạn tệp: offset=\(offset), len=\(length), total=\(total)"
        }
    }
}

public final class MappedFile {
    public let url: URL
    public let count: Int
    public let baseAddress: UnsafePointer<UInt8>
    private let fd: Int32
    private let mappedRaw: UnsafeMutableRawPointer?

    public init(url: URL) throws {
        self.url = url
        let path = url.path
        let fd = Darwin.open(path, O_RDONLY)
        if fd < 0 {
            throw MappedFileError.openFailed(path, errno)
        }
        var st = stat()
        if fstat(fd, &st) != 0 {
            let err = errno
            Darwin.close(fd)
            throw MappedFileError.statFailed(path, err)
        }
        let size = Int(st.st_size)
        self.fd = fd
        self.count = max(0, size)
        if size == 0 {
            self.mappedRaw = nil
            // Safe dummy pointer for 0-byte file
            let empty = UnsafeMutablePointer<UInt8>.allocate(capacity: 1)
            empty.initialize(to: 0)
            self.baseAddress = UnsafePointer(empty)
            return
        }
        let ptr = Darwin.mmap(nil, size, PROT_READ, MAP_PRIVATE, fd, 0)
        if ptr == MAP_FAILED || ptr == nil {
            let err = errno
            Darwin.close(fd)
            throw MappedFileError.mmapFailed(path, err)
        }
        self.mappedRaw = ptr
        self.baseAddress = UnsafePointer(ptr!.assumingMemoryBound(to: UInt8.self))
    }

    deinit {
        if let raw = mappedRaw, count > 0 {
            Darwin.munmap(raw, count)
        } else if count == 0 {
            UnsafeMutablePointer(mutating: baseAddress).deallocate()
        }
        if fd >= 0 {
            Darwin.close(fd)
        }
    }

    @inline(__always)
    public func inRange(_ offset: Int, _ length: Int) -> Bool {
        return offset >= 0 && length >= 0 && offset <= count && length <= (count - offset)
    }

    @inline(__always)
    public func readUInt8(at offset: Int) -> UInt8 {
        guard inRange(offset, 1) else { return 0 }
        return baseAddress[offset]
    }

    @inline(__always)
    public func readInt8(at offset: Int) -> Int8 {
        return Int8(bitPattern: readUInt8(at: offset))
    }

    @inline(__always)
    public func readUInt16(at offset: Int) -> UInt16 {
        guard inRange(offset, 2) else { return 0 }
        var v: UInt16 = 0
        memcpy(&v, baseAddress.advanced(by: offset), 2)
        return UInt16(littleEndian: v)
    }

    @inline(__always)
    public func readInt16(at offset: Int) -> Int16 {
        return Int16(bitPattern: readUInt16(at: offset))
    }

    @inline(__always)
    public func readUInt32(at offset: Int) -> UInt32 {
        guard inRange(offset, 4) else { return 0 }
        var v: UInt32 = 0
        memcpy(&v, baseAddress.advanced(by: offset), 4)
        return UInt32(littleEndian: v)
    }

    @inline(__always)
    public func readUInt32BE(at offset: Int) -> UInt32 {
        guard inRange(offset, 4) else { return 0 }
        var v: UInt32 = 0
        memcpy(&v, baseAddress.advanced(by: offset), 4)
        return UInt32(bigEndian: v)
    }

    @inline(__always)
    public func readInt32(at offset: Int) -> Int32 {
        return Int32(bitPattern: readUInt32(at: offset))
    }

    @inline(__always)
    public func readUInt64(at offset: Int) -> UInt64 {
        guard inRange(offset, 8) else { return 0 }
        var v: UInt64 = 0
        memcpy(&v, baseAddress.advanced(by: offset), 8)
        return UInt64(littleEndian: v)
    }

    @inline(__always)
    public func readInt64(at offset: Int) -> Int64 {
        return Int64(bitPattern: readUInt64(at: offset))
    }

    @inline(__always)
    public func readFloat(at offset: Int) -> Float {
        return Float(bitPattern: readUInt32(at: offset))
    }

    @inline(__always)
    public func readDouble(at offset: Int) -> Double {
        return Double(bitPattern: readUInt64(at: offset))
    }

    public func readData(at offset: Int, length: Int) -> Data {
        guard length > 0, inRange(offset, length) else { return Data() }
        return Data(bytes: baseAddress.advanced(by: offset), count: length)
    }

    public func readCString(at offset: Int, maxLength: Int = 512) -> String {
        guard offset >= 0 && offset < count else { return "" }
        let limit = min(count - offset, maxLength)
        var len = 0
        while len < limit {
            if baseAddress[offset + len] == 0 {
                break
            }
            len += 1
        }
        guard len > 0 else { return "" }
        let buf = UnsafeBufferPointer(start: baseAddress.advanced(by: offset), count: len)
        return String(decoding: buf, as: UTF8.self)
    }

    public func readUTF8(at offset: Int, length: Int) -> String {
        guard length > 0, inRange(offset, length) else { return "" }
        let buf = UnsafeBufferPointer(start: baseAddress.advanced(by: offset), count: length)
        return String(decoding: buf, as: UTF8.self)
    }

    public func readULEB128(at offset: inout Int) -> UInt64 {
        var result: UInt64 = 0
        var shift: UInt64 = 0
        while offset < count {
            let byte = baseAddress[offset]
            offset += 1
            result |= UInt64(byte & 0x7F) << shift
            if (byte & 0x80) == 0 {
                break
            }
            shift += 7
            if shift >= 64 { break }
        }
        return result
    }

    public func readSLEB128(at offset: inout Int) -> Int64 {
        var result: Int64 = 0
        var shift: Int = 0
        var byte: UInt8 = 0
        while offset < count {
            byte = baseAddress[offset]
            offset += 1
            result |= Int64(byte & 0x7F) << shift
            shift += 7
            if (byte & 0x80) == 0 {
                break
            }
            if shift >= 64 { break }
        }
        if shift < 64 && (byte & 0x40) != 0 {
            result |= (~0 << shift)
        }
        return result
    }

    /// IL2CPP v29+ ReadCompressedUInt32
    public func readCompressedUInt32(at offset: inout Int) -> UInt32 {
        guard offset < count else { return 0 }
        let b0 = baseAddress[offset]
        if (b0 & 0x80) == 0 {
            offset += 1
            return UInt32(b0)
        } else if (b0 & 0xC0) == 0x80 {
            guard offset + 1 < count else { offset = count; return 0 }
            let val = (UInt32(b0 & 0x3F) << 8) | UInt32(baseAddress[offset + 1])
            offset += 2
            return val
        } else if (b0 & 0xE0) == 0xC0 {
            guard offset + 3 < count else { offset = count; return 0 }
            let val = (UInt32(b0 & 0x1F) << 24)
                | (UInt32(baseAddress[offset + 1]) << 16)
                | (UInt32(baseAddress[offset + 2]) << 8)
                | UInt32(baseAddress[offset + 3])
            offset += 4
            return val
        } else if b0 == 0xF0 {
            guard offset + 4 < count else { offset = count; return 0 }
            let val = readUInt32(at: offset + 1)
            offset += 5
            return val
        } else if b0 == 0xFE {
            offset += 1
            return UInt32.max - 1
        } else if b0 == 0xFF {
            offset += 1
            return UInt32.max
        }
        offset += 1
        return UInt32(b0)
    }

    /// IL2CPP v29+ ReadCompressedInt32
    public func readCompressedInt32(at offset: inout Int) -> Int32 {
        let encoded = readCompressedUInt32(at: &offset)
        if encoded == UInt32.max {
            return Int32.min
        }
        let isNegative = (encoded & 1) != 0
        let magnitude = Int32(bitPattern: encoded >> 1)
        return isNegative ? -(magnitude + 1) : magnitude
    }

    public func shannonEntropy(offset: Int = 0, sampleLimit: Int = 262_144) -> Double {
        guard offset >= 0 && offset < count else { return 0 }
        let len = min(count - offset, sampleLimit)
        guard len > 0 else { return 0 }
        var hist = [Int](repeating: 0, count: 256)
        for i in 0..<len {
            hist[Int(baseAddress[offset + i])] += 1
        }
        let total = Double(len)
        var entropy = 0.0
        for c in hist where c > 0 {
            let p = Double(c) / total
            entropy -= p * log2(p)
        }
        return entropy
    }

    public func hexPreview(at offset: Int = 0, length: Int = 8) -> String {
        let n = min(max(0, count - offset), length)
        guard n > 0 else { return "" }
        return (0..<n).map { String(format: "%02X", baseAddress[offset + $0]) }.joined()
    }
}
