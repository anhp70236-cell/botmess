import SwiftUI
import UIKit

/// Built-in Offset File Reader allowing the user to tap any saved game offset file (`<TenGame>_OFFSET.txt` or `<TenGame>_TachTungFile/`)
/// directly inside the app to read, filter, copy, or share all discovered offsets immediately.
public struct SavedOffsetFileViewerSheet: View {
    @Environment(\.dismiss) private var dismiss

    public let item: SavedOffsetFileItem

    @State private var selectedFileURL: URL
    @State private var rawContent: String = ""
    @State private var searchKeyword: String = ""
    @State private var copiedFeedback = false
    @State private var isSharing = false

    public init(item: SavedOffsetFileItem) {
        self.item = item
        let initial = item.isDirectory ? (item.childFiles.first ?? item.fileURL) : item.fileURL
        _selectedFileURL = State(initialValue: initial)
    }

    private var filteredLines: [String] {
        let allLines = rawContent.components(separatedBy: "\n")
        let trimmed = searchKeyword.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        guard !trimmed.isEmpty else { return allLines }
        return allLines.filter { $0.lowercased().contains(trimmed) }
    }

    public var body: some View {
        NavigationStack {
            VStack(spacing: 10) {
                // Multi-file switcher if viewing a split folder
                if item.isDirectory && !item.childFiles.isEmpty {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 7) {
                            ForEach(item.childFiles, id: \.path) { child in
                                let active = child.path == selectedFileURL.path
                                Button {
                                    selectedFileURL = child
                                    loadSelectedFile()
                                } label: {
                                    Text(child.lastPathComponent)
                                        .font(.system(size: 11, weight: active ? .bold : .medium, design: .monospaced))
                                        .foregroundStyle(active ? AppTheme.accent : AppTheme.textSecondary)
                                        .padding(.horizontal, 10)
                                        .padding(.vertical, 6)
                                        .background(active ? AppTheme.accentSubtle : AppTheme.surface)
                                        .clipShape(Capsule())
                                        .overlay(
                                            Capsule()
                                                .strokeBorder(active ? AppTheme.accent : AppTheme.hairline, lineWidth: 0.8)
                                        )
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .padding(.horizontal, 16)
                    }
                }

                // Search & Actions Bar
                HStack(spacing: 8) {
                    Image(systemName: "magnifyingglass")
                        .foregroundStyle(AppTheme.textMuted)
                    TextField("Tìm nhanh offset trong tệp (0x..., Health, Camera...)", text: $searchKeyword)
                        .font(.system(size: 12, design: .monospaced))
                        .foregroundStyle(AppTheme.textPrimary)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)
                    if !searchKeyword.isEmpty {
                        Button {
                            searchKeyword = ""
                        } label: {
                            Image(systemName: "xmark.circle.fill")
                                .foregroundStyle(AppTheme.textMuted)
                        }
                    }
                }
                .thinCard(padding: 10, cornerRadius: 10)
                .padding(.horizontal, 16)

                HStack(spacing: 10) {
                    Button {
                        UIPasteboard.general.string = rawContent
                        copiedFeedback = true
                    } label: {
                        Label(copiedFeedback ? "Đã sao chép toàn bộ" : "Sao chép toàn bộ", systemImage: copiedFeedback ? "checkmark" : "doc.on.doc")
                            .font(.system(size: 12, weight: .semibold))
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 8)
                            .background(AppTheme.surfaceElevated)
                            .foregroundStyle(copiedFeedback ? AppTheme.statusVerified : AppTheme.textPrimary)
                            .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                    }
                    .buttonStyle(.plain)

                    Button {
                        isSharing = true
                    } label: {
                        Label("Chia sẻ / Lưu Tệp", systemImage: "square.and.arrow.up")
                            .font(.system(size: 12, weight: .semibold))
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 8)
                            .background(AppTheme.accent)
                            .foregroundStyle(Color.white)
                            .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
                .padding(.horizontal, 16)

                // Scrollable Monospace File Content
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 3) {
                        ForEach(Array(filteredLines.prefix(2500).enumerated()), id: \.offset) { _, line in
                            Text(line.isEmpty ? " " : line)
                                .font(.system(size: 11, design: .monospaced))
                                .foregroundStyle(lineColor(for: line))
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .textSelection(.enabled)
                        }
                    }
                    .padding(12)
                }
                .background(AppTheme.surface)
                .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                .overlay(
                    RoundedRectangle(cornerRadius: 12, style: .continuous)
                        .strokeBorder(AppTheme.hairline, lineWidth: 0.8)
                )
                .padding(.horizontal, 16)
                .padding(.bottom, 12)
            }
            .padding(.top, 10)
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle(selectedFileURL.lastPathComponent)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Đóng") { dismiss() }
                }
            }
            .onAppear { loadSelectedFile() }
            .sheet(isPresented: $isSharing) {
                ActivityShareSheet(items: [selectedFileURL])
            }
        }
        .preferredColorScheme(.dark)
    }

    private func loadSelectedFile() {
        copiedFeedback = false
        if let data = try? Data(contentsOf: selectedFileURL),
           let str = String(data: data, encoding: .utf8) {
            rawContent = str
        } else {
            rawContent = "Không thể đọc nội dung tệp tại \(selectedFileURL.lastPathComponent)."
        }
    }

    private func lineColor(for line: String) -> Color {
        if line.hasPrefix("===") || line.hasPrefix("[") {
            return AppTheme.accent
        }
        if line.hasPrefix("•") {
            return AppTheme.textPrimary
        }
        if line.contains("RVA / Offset") || line.contains("RVA/Offset") {
            return AppTheme.statusVerified
        }
        return AppTheme.textSecondary
    }
}
