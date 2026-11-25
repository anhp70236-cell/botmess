import SwiftUI

/// Modal presented automatically when an IPA scan finishes (and on-demand from Game Details),
/// letting the user pick between combining ALL offsets into 1 file OR splitting into separate files per group.
public struct ExportModePromptSheet: View {
    @Environment(\.dismiss) private var dismiss
    @EnvironmentObject private var container: AppContainer

    public let bundle: GameAnalysisBundle
    public let onOpenDetails: () -> Void

    @State private var completedExport: ExportResultSummary?
    @State private var exportError: String?
    @State private var isSharingFile = false

    public init(bundle: GameAnalysisBundle, onOpenDetails: @escaping () -> Void) {
        self.bundle = bundle
        self.onOpenDetails = onOpenDetails
    }

    public var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    // Completion Header
                    VStack(alignment: .leading, spacing: 8) {
                        HStack(spacing: 10) {
                            Image(systemName: "checkmark.seal.fill")
                                .font(.system(size: 24))
                                .foregroundStyle(AppTheme.statusVerified)
                            VStack(alignment: .leading, spacing: 2) {
                                Text("Dò Offset Hoàn Tất: \(bundle.record.gameName)")
                                    .font(.system(size: 16, weight: .bold))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Text("Tìm thấy \(bundle.offsets.count) offset (\(bundle.report.verifiedCount) đã xác minh • \(bundle.report.probableCount) khả năng cao)")
                                    .font(.system(size: 12))
                                    .foregroundStyle(AppTheme.textSecondary)
                            }
                        }

                        Rectangle().fill(AppTheme.hairline).frame(height: 0.8)

                        Text("Hãy chọn định dạng xuất tệp để lưu trực tiếp vào ứng dụng Tệp (Files) trên iPhone:")
                            .font(.system(size: 12))
                            .foregroundStyle(AppTheme.textSecondary)
                    }
                    .thinCard(padding: 14, cornerRadius: 13, elevated: true)

                    // Option 1: Single Combined Master File
                    Button {
                        runExport(.singleCombinedFile)
                    } label: {
                        modeOptionCard(
                            icon: "doc.text.fill",
                            badge: "KHUYÊN DÙNG",
                            title: OffsetExportMode.singleCombinedFile.vietnameseTitle,
                            subtitle: OffsetExportMode.singleCombinedFile.vietnameseSubtitle,
                            isHighlighted: completedExport?.mode == .singleCombinedFile
                        )
                    }
                    .buttonStyle(.plain)

                    // Option 2: Split Multiple Files by Category
                    Button {
                        runExport(.splitMultipleFiles)
                    } label: {
                        modeOptionCard(
                            icon: "folder.fill.badge.gearshape",
                            badge: "CHI TIẾT TỪNG TỆP",
                            title: OffsetExportMode.splitMultipleFiles.vietnameseTitle,
                            subtitle: OffsetExportMode.splitMultipleFiles.vietnameseSubtitle,
                            isHighlighted: completedExport?.mode == .splitMultipleFiles
                        )
                    }
                    .buttonStyle(.plain)

                    if let exportError {
                        Text("Lỗi xuất tệp: \(exportError)")
                            .font(.system(size: 12))
                            .foregroundStyle(AppTheme.statusUndetermined)
                            .thinCard(padding: 12)
                    }

                    // Saved Result Confirmation + Instant Share Button
                    if let done = completedExport {
                        VStack(alignment: .leading, spacing: 10) {
                            HStack(spacing: 6) {
                                Image(systemName: "externaldrive.fill.badge.checkmark")
                                    .foregroundStyle(AppTheme.statusVerified)
                                Text("ĐÃ LƯU VÀO MÁY THÀNH CÔNG")
                                    .font(.system(size: 11, weight: .bold, design: .monospaced))
                                    .foregroundStyle(AppTheme.statusVerified)
                            }

                            Text("Vị trí trong ứng dụng Tệp (Files):\n\(done.relativeDisplayPath)")
                                .font(.system(size: 12, design: .monospaced))
                                .foregroundStyle(AppTheme.textPrimary)

                            HStack(spacing: 10) {
                                Button {
                                    isSharingFile = true
                                } label: {
                                    HStack {
                                        Image(systemName: "square.and.arrow.up")
                                        Text("Lưu / Chia sẻ tệp ngay")
                                    }
                                    .font(.system(size: 13, weight: .semibold))
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 10)
                                    .background(AppTheme.accent)
                                    .foregroundStyle(Color.white)
                                    .clipShape(RoundedRectangle(cornerRadius: 9, style: .continuous))
                                }
                                .buttonStyle(.plain)

                                Button {
                                    dismiss()
                                    onOpenDetails()
                                } label: {
                                    Text("Xem Bảng Offset")
                                        .font(.system(size: 13, weight: .semibold))
                                        .frame(maxWidth: .infinity)
                                        .padding(.vertical, 10)
                                        .background(AppTheme.surfaceElevated)
                                        .foregroundStyle(AppTheme.textPrimary)
                                        .clipShape(RoundedRectangle(cornerRadius: 9, style: .continuous))
                                        .overlay(
                                            RoundedRectangle(cornerRadius: 9, style: .continuous)
                                                .strokeBorder(AppTheme.hairline, lineWidth: 0.8)
                                        )
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .thinCard(padding: 14, cornerRadius: 13, elevated: true)
                    }
                }
                .padding(16)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Chọn Cách Xuất Offset")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Đóng") { dismiss() }
                }
            }
            .sheet(isPresented: $isSharingFile) {
                if let done = completedExport {
                    ActivityShareSheet(items: done.generatedFiles)
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private func runExport(_ mode: OffsetExportMode) {
        do {
            let res = try container.exportService.exportByMode(mode: mode, bundle: bundle)
            withAnimation(.easeInOut(duration: 0.2)) {
                completedExport = res
                exportError = nil
            }
            container.refreshAll()
        } catch {
            exportError = error.localizedDescription
        }
    }

    private func modeOptionCard(
        icon: String,
        badge: String,
        title: String,
        subtitle: String,
        isHighlighted: Bool
    ) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Image(systemName: icon)
                    .font(.system(size: 18, weight: .semibold))
                    .foregroundStyle(AppTheme.accent)
                Text(badge)
                    .font(.system(size: 9, weight: .bold, design: .monospaced))
                    .foregroundStyle(AppTheme.accent)
                    .padding(.horizontal, 7)
                    .padding(.vertical, 3)
                    .background(AppTheme.accentSubtle)
                    .clipShape(Capsule())
                Spacer()
                Image(systemName: isHighlighted ? "checkmark.circle.fill" : "arrow.right.circle")
                    .font(.system(size: 18))
                    .foregroundStyle(isHighlighted ? AppTheme.statusVerified : AppTheme.textMuted)
            }

            Text(title)
                .font(.system(size: 15, weight: .bold))
                .foregroundStyle(AppTheme.textPrimary)

            Text(subtitle)
                .font(.system(size: 12))
                .foregroundStyle(AppTheme.textSecondary)
                .multilineTextAlignment(.leading)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(isHighlighted ? AppTheme.surfaceElevated : AppTheme.surface)
        .clipShape(RoundedRectangle(cornerRadius: 13, style: .continuous))
        .overlay(
            RoundedRectangle(cornerRadius: 13, style: .continuous)
                .strokeBorder(isHighlighted ? AppTheme.statusVerified : AppTheme.hairline, lineWidth: isHighlighted ? 1.3 : 0.85)
        )
    }
}
