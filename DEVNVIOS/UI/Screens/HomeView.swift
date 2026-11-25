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
                VStack(alignment: .leading, spacing: 16) {
                    BrandHeaderView()
                        .thinCard(padding: 14, cornerRadius: 14, elevated: true)

                    // Summary Metrics
                    HStack(spacing: 10) {
                        StatMetricTile(
                            title: "Total IPAs",
                            value: "\(container.games.count)",
                            subtitle: "\(container.storageSnapshot.ipaFileCount) on disk",
                            iconName: "shippingbox"
                        )
                        StatMetricTile(
                            title: "Offsets",
                            value: "\(container.totalOffsetsAcrossLibrary)",
                            subtitle: "Indexed DB",
                            iconName: "function"
                        )
                        StatMetricTile(
                            title: "Storage",
                            value: HexFormatters.formatBytes(container.storageSnapshot.totalUsedBytes),
                            subtitle: "Free \(HexFormatters.formatBytes(container.storageSnapshot.deviceFreeBytes))",
                            iconName: "internaldrive"
                        )
                    }

                    // Import IPA Action Card
                    Button {
                        container.isShowingFileImporter = true
                    } label: {
                        HStack(spacing: 14) {
                            ZStack {
                                RoundedRectangle(cornerRadius: 10, style: .continuous)
                                    .fill(AppTheme.accentSubtle)
                                    .frame(width: 44, height: 44)
                                Image(systemName: "square.and.arrow.down.on.square")
                                    .font(.system(size: 18, weight: .semibold))
                                    .foregroundStyle(AppTheme.accent)
                            }

                            VStack(alignment: .leading, spacing: 3) {
                                Text("Import IPA from iOS Files")
                                    .font(.system(size: 15, weight: .semibold))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Text("Supports multi-IPA batch import, large 3 GB+ archives, Mach-O ARM64 & Unity IL2CPP")
                                    .font(.system(size: 12))
                                    .foregroundStyle(AppTheme.textSecondary)
                                    .lineLimit(2)
                                    .multilineTextAlignment(.leading)
                            }

                            Spacer(minLength: 4)
                            Image(systemName: "chevron.right")
                                .font(.system(size: 13, weight: .semibold))
                                .foregroundStyle(AppTheme.textMuted)
                        }
                        .thinCard(padding: 14, cornerRadius: 13, elevated: true)
                    }
                    .buttonStyle(.plain)

                    // Live Active Pipeline Banner
                    if pipeline.isAnalyzing {
                        Button {
                            container.selectedTab = .analysis
                        } label: {
                            VStack(alignment: .leading, spacing: 8) {
                                HStack {
                                    ProgressView()
                                        .tint(AppTheme.accent)
                                        .scaleEffect(0.85)
                                    Text("Analyzing \(pipeline.currentGameName)")
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
                            .thinCard(padding: 12, cornerRadius: 12)
                        }
                        .buttonStyle(.plain)
                    }

                    // Recent Analysis Section
                    if let recent = container.games.first {
                        VStack(alignment: .leading, spacing: 8) {
                            sectionTitle("RECENT ANALYSIS")
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

                    // Full Game Library Section
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            sectionTitle("GAME LIBRARY")
                            Spacer()
                            if !container.games.isEmpty {
                                Text("\(container.games.count) items")
                                    .font(.system(size: 11, weight: .medium))
                                    .foregroundStyle(AppTheme.textMuted)
                            }
                        }

                        if container.games.isEmpty {
                            EmptyStateCardView(
                                icon: "doc.zipper",
                                title: "No IPA Archives Analyzed Yet",
                                message: "Tap 'Import IPA from iOS Files' above to inspect Mach-O headers, ARM64 instructions, symbols, and IL2CPP offsets."
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
            .navigationTitle("Home")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button {
                        container.isShowingFileImporter = true
                    } label: {
                        Image(systemName: "plus.circle")
                            .foregroundStyle(AppTheme.accent)
                    }
                }
            }
            .confirmationDialog(
                "Delete Original IPA File?",
                isPresented: Binding(
                    get: { gamePendingIPARemoval != nil },
                    set: { if !$0 { gamePendingIPARemoval = nil } }
                ),
                titleVisibility: .visible
            ) {
                if let target = gamePendingIPARemoval {
                    Button("Delete IPA (Keep Discovered Offsets)", role: .destructive) {
                        container.deleteStoredIPAOnly(for: target)
                        gamePendingIPARemoval = nil
                    }
                }
                Button("Cancel", role: .cancel) { gamePendingIPARemoval = nil }
            } message: {
                Text("This frees storage occupied by the raw .ipa file while preserving all saved JSON offset databases for this game.")
            }
            .confirmationDialog(
                "Delete Game & Offset Database?",
                isPresented: Binding(
                    get: { gamePendingDelete != nil },
                    set: { if !$0 { gamePendingDelete = nil } }
                ),
                titleVisibility: .visible
            ) {
                if let target = gamePendingDelete {
                    Button("Delete Permanently", role: .destructive) {
                        container.deleteGameCompletely(target)
                        gamePendingDelete = nil
                    }
                }
                Button("Cancel", role: .cancel) { gamePendingDelete = nil }
            } message: {
                Text("This permanently deletes both the IPA archive and all saved offsets, metadata, and history for this game.")
            }
        }
    }

    private func sectionTitle(_ text: String) -> some View {
        Text(text)
            .font(.system(size: 11, weight: .semibold, design: .monospaced))
            .tracking(0.8)
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
                            .frame(width: 38, height: 38)
                        Image(systemName: record.isUnityIL2CPP ? "cube.box" : "cpu")
                            .font(.system(size: 16, weight: .medium))
                            .foregroundStyle(AppTheme.accent)
                    }

                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 6) {
                            Text(record.gameName)
                                .font(.system(size: 15, weight: .semibold))
                                .foregroundStyle(AppTheme.textPrimary)
                                .lineLimit(1)
                            Spacer(minLength: 4)
                            Menu {
                                Button(action: onOpen) {
                                    Label("Open Offset Database", systemImage: "list.bullet.rectangle")
                                }
                                if record.hasStoredIPA {
                                    Button(action: onReanalyze) {
                                        Label("Re-Analyze IPA", systemImage: "arrow.clockwise")
                                    }
                                    Button(role: .destructive, action: onRemoveIPA) {
                                        Label("Delete IPA (Keep Offsets)", systemImage: "archivebox")
                                    }
                                }
                                Button(role: .destructive, action: onDeleteGame) {
                                    Label("Delete Game Completely", systemImage: "trash")
                                }
                            } label: {
                                Image(systemName: "ellipsis.circle")
                                    .font(.system(size: 16))
                                    .foregroundStyle(AppTheme.textSecondary)
                            }
                        }

                        Text("\(record.bundleIdentifier) • v\(record.bundleVersion)")
                            .font(.system(size: 11, design: .monospaced))
                            .foregroundStyle(AppTheme.textSecondary)
                            .lineLimit(1)
                    }
                }

                Divider().overlay(AppTheme.hairline)

                HStack(spacing: 8) {
                    tagPill(record.architectureSummary)
                    tagPill(record.isUnityIL2CPP ? "IL2CPP" : "Native Mach-O")
                    tagPill(record.hasStoredIPA ? HexFormatters.formatBytes(record.ipaFileSize) : "DB Only")
                    Spacer(minLength: 4)
                    Text("\(record.totalOffsets) offsets (\(record.verifiedOffsets) verified)")
                        .font(.system(size: 11, weight: .medium))
                        .foregroundStyle(AppTheme.statusVerified)
                        .lineLimit(1)
                }
            }
            .thinCard(padding: 13, cornerRadius: 12)
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
