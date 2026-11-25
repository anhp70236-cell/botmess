import SwiftUI
import UIKit
import UniformTypeIdentifiers

/// Native UIKit UIDocumentPickerViewController wrapper using `asCopy: true`.
/// On real sideloaded/signed iPhones (ESign, Feather, TrollStore, AltStore, SideStore, Xcode),
/// `asCopy: true` instructs iOS DocumentManager to copy selected `.ipa` / `.tipa` / `.zip` files
/// directly into the app's readable temporary sandbox before invoking the callback, avoiding all
/// FileProvider security-scope & open-in-place entitlement failures.
public struct IPADocumentPicker: UIViewControllerRepresentable {
    public let onPickURLs: ([URL]) -> Void
    public let onCancel: () -> Void

    public init(
        onPickURLs: @escaping ([URL]) -> Void,
        onCancel: @escaping () -> Void = {}
    ) {
        self.onPickURLs = onPickURLs
        self.onCancel = onCancel
    }

    public func makeCoordinator() -> Coordinator {
        Coordinator(onPickURLs: onPickURLs, onCancel: onCancel)
    }

    public func makeUIViewController(context: Context) -> UIDocumentPickerViewController {
        // Accept .item and .data so custom sideloaded UTI registrations on iOS never grey out .ipa files
        let picker = UIDocumentPickerViewController(forOpeningContentTypes: [.item, .data], asCopy: true)
        picker.allowsMultipleSelection = true
        picker.shouldShowFileExtensions = true
        picker.delegate = context.coordinator
        return picker
    }

    public func updateUIViewController(_ uiViewController: UIDocumentPickerViewController, context: Context) {}

    public final class Coordinator: NSObject, UIDocumentPickerDelegate {
        private let onPickURLs: ([URL]) -> Void
        private let onCancel: () -> Void

        init(onPickURLs: @escaping ([URL]) -> Void, onCancel: @escaping () -> Void) {
            self.onPickURLs = onPickURLs
            self.onCancel = onCancel
        }

        public func documentPicker(_ controller: UIDocumentPickerViewController, didPickDocumentsAt urls: [URL]) {
            guard !urls.isEmpty else {
                onCancel()
                return
            }
            // Immediately stage picked temporary copies into our persistent Staging directory
            // before UIDocumentPicker cleans up its tmp folder when dismissed.
            let stagingDir = FileManager.default.temporaryDirectory
                .appendingPathComponent("DEVNVIOS_PickedStaging", isDirectory: true)
            try? FileManager.default.createDirectory(at: stagingDir, withIntermediateDirectories: true)

            var stagedURLs: [URL] = []
            for url in urls {
                let scoped = url.startAccessingSecurityScopedResource()
                defer { if scoped { url.stopAccessingSecurityScopedResource() } }

                let safeName = "\(UUID().uuidString.prefix(6))_\(url.lastPathComponent)"
                let dest = stagingDir.appendingPathComponent(safeName)
                try? FileManager.default.removeItem(at: dest)

                do {
                    try FileManager.default.copyItem(at: url, to: dest)
                    stagedURLs.append(dest)
                } catch {
                    // Fallback: coordinate read if copyItem encountered a FileProvider lazy URL
                    var coordError: NSError?
                    let coordinator = NSFileCoordinator()
                    coordinator.coordinate(readingItemAt: url, options: .withoutChanges, error: &coordError) { coordinatedURL in
                        try? FileManager.default.copyItem(at: coordinatedURL, to: dest)
                    }
                    if FileManager.default.fileExists(atPath: dest.path) {
                        stagedURLs.append(dest)
                    } else if FileManager.default.fileExists(atPath: url.path) {
                        stagedURLs.append(url)
                    }
                }
            }

            onPickURLs(stagedURLs)
        }

        public func documentPickerWasCancelled(_ controller: UIDocumentPickerViewController) {
            onCancel()
        }
    }
}

/// Native UIActivityViewController share sheet for reliable saving/airdropping of exported files on iPhone.
public struct ActivityShareSheet: UIViewControllerRepresentable {
    public let items: [Any]

    public init(items: [Any]) {
        self.items = items
    }

    public func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: items, applicationActivities: nil)
    }

    public func updateUIViewController(_ uiViewController: UIActivityViewController, context: Context) {}
}
