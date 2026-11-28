import SwiftUI
import UIKit

struct OutputsView: View {
    @EnvironmentObject var vm: DumpViewModel
    @State private var shareURL: URL?
    @State private var previewURL: URL?

    var body: some View {
        let theme = vm.selectedTheme
        NavigationView {
            ZStack {
                theme.background.ignoresSafeArea()

                if vm.savedSessions.isEmpty {
                    VStack(spacing: 10) {
                        Image(systemName: "folder")
                            .font(.system(size: 42))
                            .foregroundColor(theme.secondaryText)
                        Text("Chưa có phiên xuất tệp nào.")
                            .font(.subheadline)
                            .foregroundColor(theme.secondaryText)
                    }
                } else {
                    List {
                        Section(header: Text("Phiên phân tích đã lưu")) {
                            ForEach(vm.savedSessions) { session in
                                Button {
                                    vm.selectSession(session)
                                } label: {
                                    HStack {
                                        VStack(alignment: .leading, spacing: 3) {
                                            Text(session.title)
                                                .font(.subheadline.weight(.semibold))
                                                .foregroundColor(theme.primaryText)
                                            Text("\(session.stats.typesCount) Types · \(session.stats.dummyDllsCount) DLLs")
                                                .font(.caption)
                                                .foregroundColor(theme.secondaryText)
                                        }
                                        Spacer()
                                        if vm.currentSession?.id == session.id {
                                            Image(systemName: "checkmark.circle.fill")
                                                .foregroundColor(theme.accent)
                                        }
                                    }
                                }
                                .swipeActions(edge: .trailing) {
                                    Button(role: .destructive) {
                                        vm.deleteSession(session)
                                    } label: {
                                        Label("Xoá", systemImage: "trash")
                                    }
                                }
                            }
                        }

                        if let session = vm.currentSession {
                            let sessionDir = vm.outputsRootURL.appendingPathComponent(session.outputDirectoryName)
                            Section(header: Text("Tệp đầu ra (\(session.title))")) {
                                Button {
                                    if let zip = vm.exportSessionZip(session) {
                                        shareURL = zip
                                    }
                                } label: {
                                    Label("Nén & Chia sẻ toàn bộ (.ZIP)", systemImage: "archivebox.fill")
                                        .foregroundColor(theme.accent)
                                }

                                ForEach(session.files) { item in
                                    let fileURL = sessionDir.appendingPathComponent(item.relativePath)
                                    if item.isDirectory {
                                        NavigationLink {
                                            DirectoryFilesView(dirURL: fileURL, title: item.name)
                                        } label: {
                                            Label(item.name, systemImage: "folder.fill")
                                        }
                                    } else {
                                        HStack {
                                            Button {
                                                previewURL = fileURL
                                            } label: {
                                                VStack(alignment: .leading, spacing: 2) {
                                                    Text(item.name)
                                                        .font(.system(size: 14, weight: .medium, design: .monospaced))
                                                        .foregroundColor(theme.primaryText)
                                                    Text(formatByteCount(item.sizeBytes))
                                                        .font(.caption2)
                                                        .foregroundColor(theme.secondaryText)
                                                }
                                            }
                                            Spacer()
                                            Button {
                                                shareURL = fileURL
                                            } label: {
                                                Image(systemName: "square.and.arrow.up")
                                                    .foregroundColor(theme.accent)
                                            }
                                            .buttonStyle(.borderless)
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            .navigationTitle("Tệp xuất")
            .navigationBarTitleDisplayMode(.inline)
            .sheet(item: Binding(
                get: { shareURL.map { IdentifiableURL(url: $0) } },
                set: { shareURL = $0?.url }
            )) { wrapper in
                ActivityShareSheet(activityItems: [wrapper.url])
            }
            .sheet(item: Binding(
                get: { previewURL.map { IdentifiableURL(url: $0) } },
                set: { previewURL = $0?.url }
            )) { wrapper in
                ChunkedFilePreviewSheet(fileURL: wrapper.url)
            }
        }
        .navigationViewStyle(.stack)
    }
}

private struct DirectoryFilesView: View {
    let dirURL: URL
    let title: String
    @State private var files: [URL] = []
    @State private var shareURL: URL?

    var body: some View {
        List {
            ForEach(files, id: \.self) { url in
                let sz = (try? url.resourceValues(forKeys: [.fileSizeKey]))?.fileSize ?? 0
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(url.lastPathComponent)
                            .font(.system(size: 13, weight: .medium, design: .monospaced))
                        Text(formatByteCount(UInt64(sz)))
                            .font(.caption2)
                            .foregroundColor(.secondary)
                    }
                    Spacer()
                    Button {
                        shareURL = url
                    } label: {
                        Image(systemName: "square.and.arrow.up")
                    }
                    .buttonStyle(.borderless)
                }
            }
        }
        .navigationTitle(title)
        .onAppear {
            let items = (try? FileManager.default.contentsOfDirectory(at: dirURL, includingPropertiesForKeys: [.fileSizeKey])) ?? []
            files = items.sorted { $0.lastPathComponent < $1.lastPathComponent }
        }
        .sheet(item: Binding(
            get: { shareURL.map { IdentifiableURL(url: $0) } },
            set: { shareURL = $0?.url }
        )) { wrapper in
            ActivityShareSheet(activityItems: [wrapper.url])
        }
    }
}

private struct ChunkedFilePreviewSheet: View {
    let fileURL: URL
    @Environment(\.dismiss) private var dismiss
    @State private var pageIndex = 0
    @State private var totalPages = 1
    @State private var textChunk = ""
    private let pageBytes = 65_536

    var body: some View {
        NavigationView {
            VStack(spacing: 8) {
                HStack {
                    Button("Trang trước") {
                        if pageIndex > 0 { pageIndex -= 1; loadPage() }
                    }
                    .disabled(pageIndex == 0)
                    Spacer()
                    Text("Trang \(pageIndex + 1) / \(totalPages)")
                        .font(.caption.monospacedDigit())
                    Spacer()
                    Button("Trang sau") {
                        if pageIndex + 1 < totalPages { pageIndex += 1; loadPage() }
                    }
                    .disabled(pageIndex + 1 >= totalPages)
                }
                .padding(.horizontal, 16)
                .padding(.top, 8)

                ScrollView {
                    Text(textChunk)
                        .font(.system(size: 11, design: .monospaced))
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(12)
                        .textSelection(.enabled)
                }
            }
            .navigationTitle(fileURL.lastPathComponent)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Đóng") { dismiss() }
                }
            }
            .onAppear { loadPage() }
        }
    }

    private func loadPage() {
        guard let handle = try? FileHandle(forReadingFrom: fileURL) else {
            textChunk = "Không thể đọc tệp."
            return
        }
        defer { try? handle.close() }
        let attrs = (try? FileManager.default.attributesOfItem(atPath: fileURL.path)) ?? [:]
        let totalSize = (attrs[.size] as? NSNumber)?.intValue ?? 0
        totalPages = max(1, (totalSize + pageBytes - 1) / pageBytes)
        let offset = UInt64(pageIndex * pageBytes)
        try? handle.seek(toOffset: offset)
        let data = (try? handle.read(upToCount: pageBytes)) ?? Data()
        textChunk = String(decoding: data, as: UTF8.self)
    }
}

private struct IdentifiableURL: Identifiable {
    let id = UUID()
    let url: URL
}

private struct ActivityShareSheet: UIViewControllerRepresentable {
    let activityItems: [Any]

    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: activityItems, applicationActivities: nil)
    }

    func updateUIViewController(_ uiViewController: UIActivityViewController, context: Context) {}
}
