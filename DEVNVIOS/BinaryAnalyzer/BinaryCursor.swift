import Foundation

/// Bounds-checked binary cursor for parsing Mach-O, Universal Fat headers, ULEB128 tables, and IL2CPP metadata.
public struct BinaryCursor: Sendable {
    public let data: Data
    public var count: Int { data.count }

    public init(data: Data) {
        self.data = data
    }

    @inline(__always)
    public func hasBytes(at offset: Int, count length: Int) -> Bool {
        offset >= 0 && length >= 0 && offset <= data.count - length
    }

    public func readUInt8(at offset: Int) -> UInt8? {
        guard hasBytes(at: offset, count: 1) else { return nil }
        return data[data.startIndex + offset]
    }

    public func readUInt16LE(at offset: Int) -> UInt16? {
        guard hasBytes(at: offset, count: 2) else { return nil }
        let s = data.startIndex + offset
        return UInt16(data[s]) | (UInt16(data[s + 1]) << 8)
    }

    public func readUInt32LE(at offset: Int) -> UInt32? {
        guard hasBytes(at: offset, count: 4) else { return nil }
        let s = data.startIndex + offset
        return UInt32(data[s])
            | (UInt32(data[s + 1]) << 8)
            | (UInt32(data[s + 2]) << 16)
            | (UInt32(data[s + 3]) << 24)
    }

    public func readInt32LE(at offset: Int) -> Int32? {
        guard let raw = readUInt32LE(at: offset) else { return nil }
        return Int32(bitPattern: raw)
    }

    public func readUInt32BE(at offset: Int) -> UInt32? {
        guard hasBytes(at: offset, count: 4) else { return nil }
        let s = data.startIndex + offset
        return (UInt32(data[s]) << 24)
            | (UInt32(data[s + 1]) << 16)
            | (UInt32(data[s + 2]) << 8)
            | UInt32(data[s + 3])
    }

    public func readUInt64LE(at offset: Int) -> UInt64? {
        guard let lo = readUInt32LE(at: offset),
              let hi = readUInt32LE(at: offset + 4) else {
            return nil
        }
        return UInt64(lo) | (UInt64(hi) << 32)
    }

    public func readUInt64BE(at offset: Int) -> UInt64? {
        guard let hi = readUInt32BE(at: offset),
              let lo = readUInt32BE(at: offset + 4) else {
            return nil
        }
        return (UInt64(hi) << 32) | UInt64(lo)
    }

    public func slice(offset: Int, length: Int) -> Data? {
        guard hasBytes(at: offset, count: length) else { return nil }
        let start = data.startIndex + offset
        return data.subdata(in: start..<(start + length))
    }

    public func readFixedString(at offset: Int, length: Int) -> String {
        guard let sub = slice(offset: offset, length: length) else { return "" }
        if let zeroIdx = sub.firstIndex(of: 0) {
            return String(decoding: sub[sub.startIndex..<zeroIdx], as: UTF8.self)
        }
        return String(decoding: sub, as: UTF8.self)
    }

    public func readCString(at offset: Int, maxLength: Int = 2048) -> String? {
        guard offset >= 0 && offset < data.count else { return nil }
        let start = data.startIndex + offset
        let limit = min(data.endIndex, start + maxLength)
        var end = start
        while end < limit && data[end] != 0 {
            end += 1
        }
        guard end <= limit else { return nil }
        return String(decoding: data[start..<end], as: UTF8.self)
    }

    public func readULEB128(at offset: inout Int, endOffset: Int) -> UInt64? {
        var result: UInt64 = 0
        var shift: UInt64 = 0
        while offset < endOffset && offset < data.count {
            let byte = data[data.startIndex + offset]
            offset += 1
            result |= UInt64(byte & 0x7F) << shift
            if (byte & 0x80) == 0 {
                return result
            }
            shift += 7
            if shift >= 64 {
                return nil
            }
        }
        return nil
    }
}
