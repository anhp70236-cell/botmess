import Foundation

public final class BufferedFileWriter {
    private let handle: FileHandle
    private var buffer: [UInt8]
    private let flushThreshold: Int
    private var closed = false

    public init(url: URL, bufferCapacity: Int = 262_144) throws {
        let fm = FileManager.default
        let dir = url.deletingLastPathComponent()
        if !fm.fileExists(atPath: dir.path) {
            try fm.createDirectory(at: dir, withIntermediateDirectories: true)
        }
        if !fm.fileExists(atPath: url.path) {
            fm.createFile(atPath: url.path, contents: nil)
        } else {
            try Data().write(to: url, options: .atomic)
        }
        self.handle = try FileHandle(forWritingTo: url)
        self.flushThreshold = max(4096, bufferCapacity)
        self.buffer = []
        self.buffer.reserveCapacity(self.flushThreshold + 4096)
    }

    deinit {
        if !closed {
            try? flush()
            try? handle.close()
        }
    }

    public func write(_ string: String) throws {
        if string.isEmpty { return }
        buffer.append(contentsOf: string.utf8)
        if buffer.count >= flushThreshold {
            try flush()
        }
    }

    public func writeLine(_ string: String = "") throws {
        try write(string)
        buffer.append(0x0A)
        if buffer.count >= flushThreshold {
            try flush()
        }
    }

    public func write(_ data: Data) throws {
        if data.isEmpty { return }
        buffer.append(contentsOf: data)
        if buffer.count >= flushThreshold {
            try flush()
        }
    }

    public func flush() throws {
        guard !closed, !buffer.isEmpty else { return }
        let data = Data(buffer)
        buffer.removeAll(keepingCapacity: true)
        try handle.write(contentsOf: data)
    }

    public func close() throws {
        guard !closed else { return }
        try flush()
        try handle.close()
        closed = true
    }
}
