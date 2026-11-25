import SwiftUI

public struct HomeView: View {
    @EnvironmentObject private var container: AppContainer
    @EnvironmentObject private var pipeline: AnalysisPipelineCoordinator

    @State private var gamePendingDelete: GameRecord?
    @State private var gamePendingIPARemoval: GameRecord?

    public init() {}

    public var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 15) {
                    BrandHeaderView()
                        .thinCard(padding: 14, cornerRadius: 14, elevated: true)

                    // 3 Summary Tiles
                    HStack(spacing: 10) {
                        StatMetricTile(
                            title: "Tổng IPA",
                            value: "\(container.games.count)",
                            subtitle: "\(container.storageSnapshot.ipaFileCount) tệp lưu",
                            iconName: "shippingbox"
                        )
                        StatMetricTile(
                            title: "Tổng Offset",
                            value: "\(container.totalOffsetsAcrossLibrary)",
                            subtitle: "Máu, Vị trí, Camera...",
                            iconName: "function"
                        )
                        StatMetricTile(
                            title: "Bộ nhớ",
                            value: HexFormatters.formatBytes(container.storageSnapshot.totalUsedBytes),
                            subtitle: "Trống \(HexFormatters.formatBytes(container.storageSnapshot.deviceFreeBytes))",
                            iconName: "internaldrive"
                        )
                    }

                    // Primary Import IPA Card (Fixed UIDocumentPicker asCopy: true)
                    Button {
                        container.openIPAPicker()
                    } label: {
                        HStack(spacing: 14) {
                            ZStack {
                                RoundedRectangle(cornerRadius: 11, style: .continuous)
                                    .fill(AppTheme.accentSubtle)
                                    .frame(width: 46, height: 46)
                                Image(systemName: "doc.badge.plus")
                                    .font(.system(size: 20, weight: .semibold))
                                    .foregroundStyle(AppTheme.accent)
                            }

                            VStack(alignment: .leading, spacing: 3) {
                                Text("Chọn Tệp IPA Từ Ứng Dụng Tệp (Files)")
                                    .font(.system(size: 15, weight: .bold))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Text("Hỗ trợ IPA dung lượng lớn (3 GB+), quét sâu Mach-O ARM64, IL2CPP CodeGenModule & xuất 1 tệp hoặc tách tệp")
                                    .font(.system(size: 12))
                                    .foregroundStyle(AppTheme.textSecondary)
                                    .lineLimit(2)
                                    .multilineTextAlignment(.leading)
                            }

                            Spacer(minLength: 4)
                            Image(systemName: "chevron.right")
                                .font(.system(size: 13, weight: .semibold))
                                .foregroundStyle(AppTheme.accent)
                        }
                        .thinCard(padding: 14, cornerRadius: 14, elevated: true)
                    }
                    .buttonStyle(.plain)

                    // Secondary Direct Folder Scan Button (On My iPhone -> DEVNVIOS IPA ANALYZER)
                    Button {
                        container.scanDropInFolderIPAs()
                    } label: {
                        HStack(spacing: 12) {
                            Image(systemName: "folder.badge.gearshape")
                                .font(.system(size: 17, weight: .semibold))
                                .foregroundStyle(AppTheme.statusVerified)
                                .frame(width: 30)

                            VStack(alignment: .leading, spacing: 2) {
                                Text("Quét Nhanh IPA Trong Thư Mục Ứng Dụng")
                                    .font(.system(size: 13, weight: .semibold))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Text("Đường dẫn: Tệp -> Trên iPhone của tôi -> DEVNVIOS IPA ANALYZER -> Bo_File_IPA_Vao_Day")
                                    .font(.system(size: 11))
                                    .foregroundStyle(AppTheme.textSecondary)
                                    .lineLimit(2)
                                    .multilineTextAlignment(.leading)
                            }

                            Spacer(minLength: 4)
                            if container.dropInIPACount > 0 {
                                Text("\(container.dropInIPACount) tệp")
                                    .font(.system(size: 11, weight: .bold, design: .monospaced))
                                    .foregroundStyle(AppTheme.statusVerified)
                                    .padding(.horizontal, 8)
                                    .padding(.vertical, 4)
                                    .background(AppTheme.statusVerified.opacity(0.14))
                                    .clipShape(Capsule())
                            }
                        }
                        .thinCard(padding: 12, cornerRadius: 12)
                    }
                    .buttonStyle(.plain)

                    // Active Analysis Progress Card
                    if pipeline.isAnalyzing {
                        Button {
                            container.selectedTab = .analysis
                        } label: {
                            VStack(alignment: .leading, spacing: 8) {
                                HStack {
                                    ProgressView()
                                        .tint(AppTheme.accent)
                                        .scaleEffect(0.85)
                                    Text("Đang dò chuyên sâu: \(pipeline.currentGameName)")
                                        .font(.system(size: 13, weight: .semibold))
                                        .foregroundStyle(AppTheme.textPrimary)
                                        .lineLimit(1)
                                    Spacer()
                                    Text("\(Int(pipeline.progress * 100))%")
                                        .font(.system(size: 12, weight: .bold, design: .monospaced))
                                        .foregroundStyle(AppTheme.accent)
                                }
                                ProgressView(value: pipeline.progress)
                                    .tint(AppTheme.accent)
                                Text(pipeline.currentStageTitle)
                                    .font(.system(size: 11))
                                    .foregroundStyle(AppTheme.textSecondary)
                                    .lineLimit(1)
                            }
                            .thinCard(padding: 13, cornerRadius: 12)
                        }
                        .buttonStyle(.plain)
                    }

                    // Recent Analysis
                    if let recent = container.games.first {
                        VStack(alignment: .leading, spacing: 8) {
                            sectionTitle("PHÂN TÍCH GẦN NHẤT")
                            GameRowCard(
                                record: recent,
                                onOpen: { container.openGameDetails(recent) },
                                onReanalyze: {
                                    container.selectedTab = .analysis
                                    pipeline.reanalyze(record: recent)
                                },
                                onRemoveIPA: { gamePendingIPARemoval = recent },
                                onDeleteGame: { gamePendingDelete = recent }
                            )
                        }
                    }

                    // Game Library
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            sectionTitle("THƯ VIỆN GAME ĐÃ DÒ OFFSET")
                            Spacer()
                            if !container.games.isEmpty {
                                Text("\(container.games.count) game")
                                    .font(.system(size: 11, weight: .medium))
                                    .foregroundStyle(AppTheme.textMuted)
                            }
                        }

                        if container.games.isEmpty {
                            EmptyStateCardView(
                                icon: "cpu.fill",
                                title: "Chưa Có Tệp IPA Nào Được Phân Tích",
                                message: "Bấm 'Chọn Tệp IPA Từ Ứng Dụng Tệp' ở trên để bắt đầu dò chuyên sâu các offset Máu, Thể lực, Vị trí, Tốc độ, Góc xoay, Camera và xuất tệp ngay trên iPhone."
                            )
                        } else {
                            ForEach(container.games) { game in
                                GameRowCard(
                                    record: game,
                                    onOpen: { container.openGameDetails(game) },
                                    onReanalyze: {
                                        container.selectedTab = .analysis
                                        pipeline.reanalyze(record: game)
                                    },
                                    onRemoveIPA: { gamePendingIPARemoval = game },
                                    onDeleteGame: { gamePendingDelete = game }
                                )
                            }
                        }
                    }
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 12)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Trang chủ")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear { container.refreshAll() }
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button {
                        container.openIPAPicker()
                    } label: {
                        Image(systemName: "plus.circle.fill")
                            .foregroundStyle(AppTheme.accent)
                    }
                }
            }
            .confirmationDialog(
                "Xóa Tệp IPA Gốc (Giữ Nguyên Offset)?",
                isPresented: Binding(
                    get: { gamePendingIPARemoval != nil },
                    set: { if !$0 { gamePendingIPARemoval = nil } }
                ),
                titleVisibility: .visible
            ) {
                if let target = gamePendingIPARemoval {
                    Button("Xóa IPA • Giữ Lại Toàn Bộ Offset", role: .destructive) {
                        container.deleteStoredIPAOnly(for: target)
                        gamePendingIPARemoval = nil
                    }
                }
                Button("Hủy", role: .cancel) { gamePendingIPARemoval = nil }
            } message: {
                Text("Giải phóng dung lượng của tệp .ipa gốc nhưng vẫn giữ nguyên 100% cơ sở dữ liệu Offset và tệp đã xuất của game này.")
            }
            .confirmationDialog(
                "Xóa Hoàn Toàn Game Này?",
                isPresented: Binding(
                    get: { gamePendingDelete != nil },
                    set: { if !$0 { gamePendingDelete = nil } }
                ),
                titleVisibility: .visible
            ) {
                if let target = gamePendingDelete {
                    Button("Xóa Vĩnh Viễn", role: .destructive) {
                        container.deleteGameCompletely(target)
                        gamePendingDelete = nil
                    }
                }
                Button("Hủy", role: .cancel) { gamePendingDelete = nil }
            } message: {
                Text("Thao tác này sẽ xóa cả tệp IPA lẫn thư mục cơ sở dữ liệu Offset của game.")
            }
        }
    }

    private func sectionTitle(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold, design: .monospaced))
            .tracking(0.7)
            .foregroundStyle(AppTheme.textMuted)
    }
}

struct GameRowCard: View {
    let record: GameRecord
    let onOpen: () -> Void
    let onReanalyze: () -> Void
    let onRemoveIPA: () -> Void
    let onDeleteGame: () -> Void

    var body: some View {
        Button(action: onOpen) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .top, spacing: 10) {
                    ZStack {
                        RoundedRectangle(cornerRadius: 9, style: .continuous)
                            .fill(AppTheme.surfaceElevated)
                            .frame(width: 40, height: 40)
                        Image(systemName: record.isUnityIL2CPP ? "cube.transparent" : "cpu")
                            .font(.system(size: 17, weight: .medium))
                            .foregroundStyle(AppTheme.accent)
                    }

                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 6) {
                            Text(record.gameName)
                                .font(.system(size: 15, weight: .bold))
                                .foregroundStyle(AppTheme.textPrimary)
                                .lineLimit(1)
                            Spacer(minLength: 4)
                            Menu {
                                Button(action: onOpen) {
                                    Label("Xem Bảng Offset & Xuất Tệp", systemImage: "list.bullet.rectangle")
                                }
                                if record.hasStoredIPA {
                                    Button(action: onReanalyze) {
                                        Label("Dò Lại IPA", systemImage: "arrow.clockwise")
                                    }
                                    Button(role: .destructive, action: onRemoveIPA) {
                                        Label("Xóa IPA Gốc (Giữ Offset)", systemImage: "archivebox")
                                    }
                                }
                                Button(role: .destructive, action: onDeleteGame) {
                                    Label("Xóa Hoàn Toàn Game", systemImage: "trash")
                                }
                            } label: {
                                Image(systemName: "ellipsis.circle")
                                    .font(.system(size: 17))
                                    .foregroundStyle(AppTheme.textSecondary)
                            }
                        }

                        Text("\(record.bundleIdentifier) • v\(record.bundleVersion)")
                            .font(.system(size: 11, design: .monospaced))
                            .foregroundStyle(AppTheme.textSecondary)
                            .lineLimit(1)
                    }
                }

                Rectangle().fill(AppTheme.hairline).frame(height: 0.8)

                HStack(spacing: 7) {
                    tagPill(record.architectureSummary)
                    tagPill(record.isUnityIL2CPP ? "IL2CPP" : "Mach-O")
                    tagPill(record.hasStoredIPA ? HexFormatters.formatBytes(record.ipaFileSize) : "Đã lưu DB")
                    Spacer(minLength: 4)
                    Text("\(record.totalOffsets) offset (\(record.verifiedOffsets) chuẩn)")
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundStyle(AppTheme.statusVerified)
                        .lineLimit(1)
                }
            }
            .thinCard(padding: 13, cornerRadius: 13)
        }
        .buttonStyle(.plain)
    }

    private func tagPill(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 10, weight: .medium, design: .monospaced))
            .foregroundStyle(AppTheme.textSecondary)
            .padding(.horizontal, 7)
            .padding(.vertical, 3)
            .background(AppTheme.surfaceElevated)
            .clipShape(RoundedRectangle(cornerRadius: 5, style: .continuous))
    }
}
