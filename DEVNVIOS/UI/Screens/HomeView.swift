import SwiftUI

public struct HomeView: View {
    @EnvironmentObject private var container: AppContainer
    @EnvironmentObject private var pipeline: AnalysisPipelineCoordinator

    @State private var gamePendingDelete: GameRecord?
    @State private var gamePendingIPARemoval: GameRecord?
    @State private var selectedSavedFile: SavedOffsetFileItem?

    public init() {}

    public var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 15) {
                    BrandHeaderView()
                        .thinCard(padding: 15, cornerRadius: 15, elevated: true)

                    // 3 Summary Tiles
                    HStack(spacing: 10) {
                        StatMetricTile(
                            title: "Tổng IPA",
                            value: "\(container.games.count)",
                            subtitle: "\(container.storageSnapshot.ipaFileCount) tệp lưu",
                            iconName: "shippingbox.fill"
                        )
                        StatMetricTile(
                            title: "Offset Thực",
                            value: "\(container.totalOffsetsAcrossLibrary)",
                            subtitle: "Chuẩn C++17",
                            iconName: "cpu.fill"
                        )
                        StatMetricTile(
                            title: "Bộ Nhớ",
                            value: HexFormatters.formatBytes(container.storageSnapshot.totalUsedBytes),
                            subtitle: "Trống \(HexFormatters.formatBytes(container.storageSnapshot.deviceFreeBytes))",
                            iconName: "internaldrive.fill"
                        )
                    }

                    // Primary Import IPA Card
                    Button {
                        container.openIPAPicker()
                    } label: {
                        HStack(spacing: 14) {
                            ZStack {
                                RoundedRectangle(cornerRadius: 12, style: .continuous)
                                    .fill(AppTheme.accentSubtle)
                                    .frame(width: 48, height: 48)
                                Image(systemName: "doc.badge.plus")
                                    .font(.system(size: 21, weight: .semibold))
                                    .foregroundStyle(AppTheme.accent)
                            }

                            VStack(alignment: .leading, spacing: 4) {
                                Text("Chọn Tệp IPA Để Dò Chuyên Sâu")
                                    .font(.system(size: 15, weight: .bold))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Text("Quét mọi IPA (Game & Ứng dụng) với lõi C++17 + Swift • Loại trừ hoàn toàn Ngân hàng & Tiền thật")
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
                        .thinCard(padding: 15, cornerRadius: 15, elevated: true)
                    }
                    .buttonStyle(.plain)

                    // Secondary Direct Folder Scan Card
                    Button {
                        container.scanDropInFolderIPAs()
                    } label: {
                        HStack(spacing: 12) {
                            Image(systemName: "folder.fill.badge.gearshape")
                                .font(.system(size: 17, weight: .semibold))
                                .foregroundStyle(AppTheme.statusVerified)
                                .frame(width: 30)

                            VStack(alignment: .leading, spacing: 2) {
                                Text("Quét IPA Chép Sẵn Trong Ứng Dụng Tệp")
                                    .font(.system(size: 13, weight: .semibold))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Text("Tệp -> Trên iPhone của tôi -> DEVNVIOS IPA ANALYZER -> Bo_File_IPA_Vao_Day")
                                    .font(.system(size: 11))
                                    .foregroundStyle(AppTheme.textSecondary)
                                    .lineLimit(1)
                            }

                            Spacer(minLength: 4)
                            if container.dropInIPACount > 0 {
                                Text("\(container.dropInIPACount) IPA")
                                    .font(.system(size: 11, weight: .bold, design: .monospaced))
                                    .foregroundStyle(AppTheme.statusVerified)
                                    .padding(.horizontal, 8)
                                    .padding(.vertical, 4)
                                    .background(AppTheme.statusVerified.opacity(0.14))
                                    .clipShape(Capsule())
                            } else {
                                Image(systemName: "arrow.right")
                                    .font(.system(size: 12))
                                    .foregroundStyle(AppTheme.textMuted)
                            }
                        }
                        .thinCard(padding: 12, cornerRadius: 13)
                    }
                    .buttonStyle(.plain)

                    // Live Progress Banner
                    if pipeline.isAnalyzing {
                        Button {
                            container.selectedTab = .analysis
                        } label: {
                            VStack(alignment: .leading, spacing: 8) {
                                HStack {
                                    ProgressView()
                                        .tint(AppTheme.accent)
                                        .scaleEffect(0.85)
                                    Text("Đang kiểm tra chuyên sâu: \(pipeline.currentGameName)")
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
                            .thinCard(padding: 13, cornerRadius: 13)
                        }
                        .buttonStyle(.plain)
                    }

                    // Saved Named Game Offset Files (Click to Open Directly!)
                    if !container.savedOffsetFiles.isEmpty {
                        VStack(alignment: .leading, spacing: 8) {
                            HStack {
                                sectionTitle("TỆP OFFSET ĐÃ LƯU TRÊN IPHONE (CHẠM ĐỂ XEM)")
                                Spacer()
                                Text("\(container.savedOffsetFiles.count) tệp")
                                    .font(.system(size: 11))
                                    .foregroundStyle(AppTheme.textMuted)
                            }

                            ForEach(container.savedOffsetFiles.prefix(8)) { fileItem in
                                Button {
                                    selectedSavedFile = fileItem
                                } label: {
                                    HStack(spacing: 12) {
                                        ZStack {
                                            RoundedRectangle(cornerRadius: 9, style: .continuous)
                                                .fill(AppTheme.surfaceElevated)
                                                .frame(width: 38, height: 38)
                                            Image(systemName: fileItem.isDirectory ? "folder.fill" : "doc.text.fill")
                                                .font(.system(size: 16, weight: .medium))
                                                .foregroundStyle(fileItem.isDirectory ? AppTheme.statusProbable : AppTheme.statusVerified)
                                        }

                                        VStack(alignment: .leading, spacing: 2) {
                                            Text(fileItem.displayTitle)
                                                .font(.system(size: 14, weight: .bold, design: .monospaced))
                                                .foregroundStyle(AppTheme.textPrimary)
                                                .lineLimit(1)
                                            Text(fileItem.subtitle)
                                                .font(.system(size: 11))
                                                .foregroundStyle(AppTheme.textSecondary)
                                                .lineLimit(1)
                                        }

                                        Spacer(minLength: 4)
                                        Text("Mở xem")
                                            .font(.system(size: 11, weight: .semibold))
                                            .foregroundStyle(AppTheme.accent)
                                            .padding(.horizontal, 9)
                                            .padding(.vertical, 5)
                                            .background(AppTheme.accentSubtle)
                                            .clipShape(Capsule())
                                    }
                                    .thinCard(padding: 12, cornerRadius: 13)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }

                    // Full Game & App Library
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            sectionTitle("THƯ VIỆN IPA ĐÃ PHÂN TÍCH")
                            Spacer()
                            if !container.games.isEmpty {
                                Text("\(container.games.count) mục")
                                    .font(.system(size: 11, weight: .medium))
                                    .foregroundStyle(AppTheme.textMuted)
                            }
                        }

                        if container.games.isEmpty {
                            EmptyStateCardView(
                                icon: "square.stack.3d.up",
                                title: "Sẵn Sàng Phân Tích Mọi Gói IPA",
                                message: "Chọn bất kỳ tệp .ipa nào (Game Unity IL2CPP, Unreal C++, hoặc Ứng dụng iOS Swift/ObjC). Hệ thống C++17 sẽ tự động dò chuẩn 100% và lưu tệp mang tên ứng dụng ngay trên iPhone."
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
            .sheet(item: $selectedSavedFile) { fileItem in
                SavedOffsetFileViewerSheet(item: fileItem)
            }
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
                Text("Giải phóng dung lượng của tệp .ipa gốc nhưng bảo toàn 100% cơ sở dữ liệu Offset và tệp đã lưu trên iPhone.")
            }
            .confirmationDialog(
                "Xóa Hoàn Toàn Mục Này?",
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
                Text("Thao tác này sẽ xóa cả tệp IPA lẫn thư mục cơ sở dữ liệu Offset tương ứng.")
            }
        }
    }

    private func sectionTitle(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold, design: .monospaced))
            .tracking(0.6)
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
                        RoundedRectangle(cornerRadius: 10, style: .continuous)
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
                                    Label("Mở Bảng Offset & Xuất Tệp", systemImage: "list.bullet.rectangle")
                                }
                                if record.hasStoredIPA {
                                    Button(action: onReanalyze) {
                                        Label("Quét Lại Chuyên Sâu", systemImage: "arrow.clockwise")
                                    }
                                    Button(role: .destructive, action: onRemoveIPA) {
                                        Label("Xóa IPA Gốc (Giữ Offset)", systemImage: "archivebox")
                                    }
                                }
                                Button(role: .destructive, action: onDeleteGame) {
                                    Label("Xóa Hoàn Toàn", systemImage: "trash")
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

                Rectangle().fill(AppTheme.hairline.opacity(0.8)).frame(height: 0.75)

                HStack(spacing: 7) {
                    tagPill(record.architectureSummary)
                    tagPill(record.isUnityIL2CPP ? "IL2CPP" : "Mach-O / Swift")
                    tagPill(record.hasStoredIPA ? HexFormatters.formatBytes(record.ipaFileSize) : "Đã lưu DB")
                    Spacer(minLength: 4)
                    Text("\(record.totalOffsets) offset (\(record.verifiedOffsets) chuẩn)")
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundStyle(AppTheme.statusVerified)
                        .lineLimit(1)
                }
            }
            .thinCard(padding: 13, cornerRadius: 14)
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
