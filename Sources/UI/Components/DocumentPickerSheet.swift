import UIKit
import UniformTypeIdentifiers

@MainActor
final class NativeDocumentPicker: NSObject, UIDocumentPickerDelegate {
    static let shared = NativeDocumentPicker()

    private var onPick: ((URL) -> Void)?
    private var onCancel: (() -> Void)?
    private var isFolderPick = false

    private override init() {
        super.init()
    }

    /// Synchronously relocates files picked into iOS `-Inbox` or root `tmp/` into a dedicated
    /// `tmp/IPADumperImports/<UUID>/` folder before `didPickDocumentsAt` returns, so iOS does
    /// not purge the `-Inbox` copy before async ZIP64 / Mach-O inspection opens it.
    nonisolated static func preservePickedFileIfNeeded(_ url: URL) -> URL {
        guard !url.hasDirectoryPath else { return url }

        let fm = FileManager.default
        let stdPath = url.standardizedFileURL.path
        let tmpRoot = fm.temporaryDirectory.standardizedFileURL.path
        let importsRoot = fm.temporaryDirectory
            .appendingPathComponent("IPADumperImports", isDirectory: true)
        let importsPath = importsRoot.standardizedFileURL.path

        if stdPath.hasPrefix(importsPath + "/") {
            return url
        }

        let isInbox = stdPath.contains("-Inbox/") || stdPath.contains("/Inbox/")
        let isInTmp = stdPath.hasPrefix(tmpRoot + "/") || stdPath.hasPrefix(tmpRoot)
        guard isInbox || isInTmp else {
            return url
        }

        let accessed = url.startAccessingSecurityScopedResource()
        defer {
            if accessed { url.stopAccessingSecurityScopedResource() }
        }

        let sessionDir = importsRoot.appendingPathComponent(UUID().uuidString, isDirectory: true)
        do {
            try fm.createDirectory(at: sessionDir, withIntermediateDirectories: true)
            let destURL = sessionDir.appendingPathComponent(url.lastPathComponent)
            if fm.fileExists(atPath: destURL.path) {
                try? fm.removeItem(at: destURL)
            }
            do {
                try fm.moveItem(at: url, to: destURL)
                return destURL
            } catch {
                try fm.copyItem(at: url, to: destURL)
                return destURL
            }
        } catch {
            return url
        }
    }

    func presentFilePicker(
        asCopy: Bool = true,
        onPick: @escaping (URL) -> Void,
        onCancel: @escaping () -> Void = {}
    ) {
        self.onPick = onPick
        self.onCancel = onCancel
        self.isFolderPick = false

        var types: [UTType] = []
        var seen = Set<String>()
        func appendType(_ t: UTType?) {
            guard let t = t, !seen.contains(t.identifier) else { return }
            seen.insert(t.identifier)
            types.append(t)
        }

        for id in ["com.apple.itunes.ipa", "com.botmess.ipadumper.ipa", "com.pkware.zip-archive"] {
            appendType(UTType(id))
        }
        for ext in ["ipa", "IPA", "tipa", "TIPA", "zip", "dat", "dylib"] {
            appendType(UTType(filenameExtension: ext))
        }
        for fallback: UTType in [.zip, .archive, .data, .content, .item] {
            appendType(fallback)
        }

        let picker = UIDocumentPickerViewController(forOpeningContentTypes: types, asCopy: asCopy)
        picker.delegate = self
        picker.allowsMultipleSelection = false
        picker.shouldShowFileExtensions = true
        presentTopViewController(picker)
    }

    func presentFolderPicker(
        onPick: @escaping (URL) -> Void,
        onCancel: @escaping () -> Void = {}
    ) {
        self.onPick = onPick
        self.onCancel = onCancel
        self.isFolderPick = true

        let picker = UIDocumentPickerViewController(forOpeningContentTypes: [.folder])
        picker.delegate = self
        picker.allowsMultipleSelection = false
        presentTopViewController(picker)
    }

    private func presentTopViewController(_ vc: UIViewController) {
        let scenes = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }
        let keyWindow = scenes.flatMap { $0.windows }.first(where: { $0.isKeyWindow })
            ?? scenes.flatMap { $0.windows }.first
        guard var top = keyWindow?.rootViewController else {
            onCancel?()
            self.onPick = nil
            self.onCancel = nil
            return
        }
        while let presented = top.presentedViewController {
            top = presented
        }
        top.present(vc, animated: true)
    }

    func documentPicker(_ controller: UIDocumentPickerViewController, didPickDocumentsAt urls: [URL]) {
        let pickHandler = onPick
        let cancelHandler = onCancel
        let folderPick = isFolderPick
        onPick = nil
        onCancel = nil
        isFolderPick = false
        if var url = urls.first {
            if !folderPick {
                url = Self.preservePickedFileIfNeeded(url)
            }
            pickHandler?(url)
        } else {
            cancelHandler?()
        }
    }

    func documentPickerWasCancelled(_ controller: UIDocumentPickerViewController) {
        let cancelHandler = onCancel
        onPick = nil
        onCancel = nil
        isFolderPick = false
        cancelHandler?()
    }
}
