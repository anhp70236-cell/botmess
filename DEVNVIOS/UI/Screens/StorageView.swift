import SwiftUI

public struct StorageView: View {
    @EnvironmentObject private var container: AppContainer

    @State private var confirmClearCache = false
    @State private var confirmClearHistory = false
    @State private var recordPendingIPADeletion: GameRecord?

    public init() {}

    private var snap: StorageUsageSnapshot {
        container.storageSnapshot
    }

    public var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    // Main Storage Capacity Card (>= 3 GB support)
                    VStack(alignment: .leading, spacing: 12) {
                        HStack {
                            VStack(alignment: .leading, spacing: 3) {
                                Text("STORAGE WORKSPACE")
                                    .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                    .foregroundStyle(AppTheme.textMuted)
                                Text(HexFormatters.formatBytes(snap.totalUsedBytes))
                                    .font(.system(size: 24, weight: .bold, design: .rounded))
                                    .foregroundStyle(AppTheme.textPrimary)
                            }
                            Spacer()
                            VStack(alignment: .trailing, spacing: 3) {
                                Text(snap.supportsThreeGigabyteTarget ? "3 GB+ READY" : "LOW DISK")
                                    .font(.system(size: 10, weight: .bold, design: .monospaced))
                                    .foregroundStyle(snap.supportsThreeGigabyteTarget ? AppTheme.statusVerified : AppTheme.statusProbable)
                                    .padding(.horizontal, 8)
                                    .padding(.vertical, 3)
                                    .background((snap.supportsThreeGigabyteTarget ? AppTheme.statusVerified : AppTheme.statusProbable).opacity(0.14))
                                    .clipShape(Capsule())

                                Text("Device Free: \(HexFormatters.formatBytes(snap.deviceFreeBytes))")
                                    .font(.system(size: 11, design: .monospaced))
                                    .foregroundStyle(AppTheme.textSecondary)
                            }
                        }

                        GeometryReader { geo in
                            let width = max(geo.size.width, 1)
                            let quota = Double(max(snap.configuredQuotaBytes, 1))
                            let ipaW = width * min(1.0, Double(snap.ipaBytes) / quota)
                            let dbW = width * min(1.0, Double(snap.databaseBytes) / quota)
                            let cacheW = width * min(1.0, Double(snap.cacheBytes + snap.exportsBytes) / quota)

                            ZStack(alignment: .leading) {
                                RoundedRectangle(cornerRadius: 5)
                                    .fill(AppTheme.surfaceElevated)
                                HStack(spacing: 1) {
                                    if ipaW > 0.5 {
                                        Rectangle().fill(AppTheme.accent).frame(width: ipaW)
                                    }
                                    if dbW > 0.5 {
                                        Rectangle().fill(AppTheme.statusVerified).frame(width: dbW)
                                    }
                                    if cacheW > 0.5 {
                                        Rectangle().fill(AppTheme.statusProbable).frame(width: cacheW)
                                    }
                                }
                                .clipShape(RoundedRectangle(cornerRadius: 5))
                            }
                        }
                        .frame(height: 10)

                        Text("Configured Workspace Quota: \(HexFormatters.formatBytes(snap.configuredQuotaBytes)) (Minimum 3.00 GB supported)")
                            .font(.system(size: 11))
                            .foregroundStyle(AppTheme.textSecondary)
                    }
                    .thinCard(padding: 15, cornerRadius: 13, elevated: true)

                    // Breakdown List
                    VStack(spacing: 10) {
                        usageRow("IPA Archives", bytes: snap.ipaBytes, subtitle: "\(snap.ipaFileCount) stored .ipa packages", color: AppTheme.accent, icon: "doc.zipper")
                        usageRow("Offset Databases", bytes: snap.databaseBytes, subtitle: "\(snap.gameDatabaseCount) game folders (JSON)", color: AppTheme.statusVerified, icon: "cylinder.split.1x2")
                        usageRow("Cache & Exports", bytes: snap.cacheBytes + snap.exportsBytes, subtitle: "\(snap.cachedItemCount) temporary items", color: AppTheme.statusProbable, icon: "clock.arrow.circlepath")
                    }

                    // Storage Maintenance Actions
                    VStack(alignment: .leading, spacing: 10) {
                        Text("MAINTENANCE ACTIONS")
                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textMuted)

                        Button {
                            confirmClearCache = true
                        } label: {
                            actionButtonRow(
                                title: "Clear Temporary Cache & Exports",
                                subtitle: "Frees extraction staging files without affecting IPAs or Offset Databases",
                                icon: "sparkles",
                                destructive: false
                            )
                        }
                        .buttonStyle(.plain)

                        Button {
                            confirmClearHistory = true
                        } label: {
                            actionButtonRow(
                                title: "Delete Analysis History Logs",
                                subtitle: "Clears history.json snapshots while keeping active offsets.json intact",
                                icon: "clock.badge.xmark",
                                destructive: true
                            )
                        }
                        .buttonStyle(.plain)
                    }

                    // Selective IPA Deletion (Keep Offsets)
                    let storedIPAGames = container.games.filter(\.hasStoredIPA)
                    if !storedIPAGames.isEmpty {
                        VStack(alignment: .leading, spacing: 8) {
                            Text("STORED IPA ARCHIVES (DELETE IPA • KEEP OFFSETS)")
                                .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                .foregroundStyle(AppTheme.textMuted)

                            ForEach(storedIPAGames) { item in
                                HStack {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(item.gameName)
                                            .font(.system(size: 14, weight: .semibold))
                                            .foregroundStyle(AppTheme.textPrimary)
                                        Text("\(HexFormatters.formatBytes(item.ipaFileSize)) • \(item.totalOffsets) offsets preserved on delete")
                                            .font(.system(size: 11, design: .monospaced))
                                            .foregroundStyle(AppTheme.textSecondary)
                                    }
                                    Spacer()
                                    Button(role: .destructive) {
                                        recordPendingIPADeletion = item
                                    } label: {
                                        Image(systemName: "trash")
                                            .font(.system(size: 14))
                                            .foregroundStyle(AppTheme.statusUndetermined)
                                            .padding(8)
                                            .background(AppTheme.surfaceElevated)
                                            .clipShape(RoundedRectangle(cornerRadius: 8))
                                    }
                                    .buttonStyle(.plain)
                                }
                                .thinCard(padding: 12)
                            }
                        }
                    }
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 12)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Storage Manager")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear { container.refreshAll() }
            .confirmationDialog("Clear Cache?", isPresented: $confirmClearCache, titleVisibility: .visible) {
                Button("Clear Cache", role: .destructive) { container.clearCache() }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("Temporary extraction buffers and exported staging files will be removed. All game offsets remain untouched.")
            }
            .confirmationDialog("Delete History?", isPresented: $confirmClearHistory, titleVisibility: .visible) {
                Button("Delete All History", role: .destructive) { container.clearAllHistory() }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("Resets historical run logs across games while preserving current metadata.json and offsets.json.")
            }
            .confirmationDialog(
                "Delete Stored IPA?",
                isPresented: Binding(
                    get: { recordPendingIPADeletion != nil },
                    set: { if !$0 { recordPendingIPADeletion = nil } }
                ),
                titleVisibility: .visible
            ) {
                if let target = recordPendingIPADeletion {
                    Button("Delete IPA (Keep Offsets)", role: .destructive) {
                        container.deleteStoredIPAOnly(for: target)
                        recordPendingIPADeletion = nil
                    }
                }
                Button("Cancel", role: .cancel) { recordPendingIPADeletion = nil }
            } message: {
                Text("Reclaims IPA file space while keeping every discovered offset in the game database.")
            }
        }
    }

    private func usageRow(_ title: String, bytes: Int64, subtitle: String, color: Color, icon: String) -> some View {
        HStack(spacing: 12) {
            Circle()
                .fill(color)
                .frame(width: 9, height: 9)
            Image(systemName: icon)
                .font(.system(size: 15))
                .foregroundStyle(color)
                .frame(width: 22)
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.system(size: 14, weight: .semibold))
                    .foregroundStyle(AppTheme.textPrimary)
                Text(subtitle)
                    .font(.system(size: 11))
                    .foregroundStyle(AppTheme.textSecondary)
            }
            Spacer()
            Text(HexFormatters.formatBytes(bytes))
                .font(.system(size: 13, weight: .bold, design: .monospaced))
                .foregroundStyle(AppTheme.textPrimary)
        }
        .thinCard(padding: 12)
    }

    private func actionButtonRow(title: String, subtitle: String, icon: String, destructive: Bool) -> some View {
        HStack(spacing: 12) {
            Image(systemName: icon)
                .font(.system(size: 16))
                .foregroundStyle(destructive ? AppTheme.statusUndetermined : AppTheme.accent)
                .frame(width: 24)
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.system(size: 14, weight: .semibold))
                    .foregroundStyle(destructive ? AppTheme.statusUndetermined : AppTheme.textPrimary)
                Text(subtitle)
                    .font(.system(size: 11))
                    .foregroundStyle(AppTheme.textSecondary)
            }
            Spacer()
            Image(systemName: "chevron.right")
                .font(.system(size: 12))
                .foregroundStyle(AppTheme.textMuted)
        }
        .thinCard(padding: 13)
    }
}
