import Foundation

public enum CRC32 {
    private static let table: [UInt32] = {
        var t = [UInt32](repeating: 0, count: 256)
        for i in 0..<256 {
            var c = UInt32(i)
            for _ in 0..<8 {
                if (c & 1) != 0 {
                    c = 0xEDB8_8320 ^ (c >> 1)
                } else {
                    c >>= 1
                }
            }
            t[i] = c
        }
        return t
    }()

    @inline(__always)
    public static func update(crc: UInt32, bytes: UnsafePointer<UInt8>, count: Int) -> UInt32 {
        var c = crc ^ 0xFFFF_FFFF
        for i in 0..<count {
            let idx = Int((c ^ UInt32(bytes[i])) & 0xFF)
            c = table[idx] ^ (c >> 8)
        }
        return c ^ 0xFFFF_FFFF
    }

    public static func compute(_ data: Data) -> UInt32 {
        data.withUnsafeBytes { raw in
            guard let ptr = raw.bindMemory(to: UInt8.self).baseAddress else { return 0 }
            return update(crc: 0, bytes: ptr, count: data.count)
        }
    }
}
