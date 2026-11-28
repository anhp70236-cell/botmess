import UIKit
import UniformTypeIdentifiers

@MainActor
final class NativeDocumentPicker: NSObject, UIDocumentPickerDelegate {
    static let shared = NativeDocumentPicker()

    private var onPick: ((URL) -> Void)?
    private var onCancel: (() -> Void)?

    private override init() {
        super.init()
    }

    func presentFilePicker(
        asCopy: Bool = true,
        onPick: @escaping (URL) -> Void,
        onCancel: @escaping () -> Void = {}
    ) {
        self.onPick = onPick
        self.onCancel = onCancel

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
        onPick = nil
        onCancel = nil
        if let url = urls.first {
            pickHandler?(url)
        } else {
            cancelHandler?()
        }
    }

    func documentPickerWasCancelled(_ controller: UIDocumentPickerViewController) {
        let cancelHandler = onCancel
        onPick = nil
        onCancel = nil
        cancelHandler?()
    }
}
