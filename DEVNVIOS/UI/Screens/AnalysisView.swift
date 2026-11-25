import SwiftUI
import UIKit

public struct AnalysisView: View {
    @EnvironmentObject private var container: AppContainer
    @EnvironmentObject private var pipeline: AnalysisPipelineCoordinator
    @State private var copiedLogsToast = false

    public init() {}

    public var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    // Status & Progress Card
                    VStack(alignment: .leading, spacing: 10) {
                        HStack {
                            VStack(alignment: .leading, spacing: 3) {
                                Text(pipeline.currentGameName.isEmpty ? "Ready for IPA Analysis" : pipeline.currentGameName)
                                    .font(.system(size: 16, weight: .semibold))
                                    .foregroundStyle(AppTheme.textPrimary)
                                    .lineLimit(1)
                                Text(pipeline.currentStageTitle)
                                    .font(.system(size: 12))
                                    .foregroundStyle(AppTheme.textSecondary)
                                    .lineLimit(1)
                            }
                            Spacer()
                            Text("\(Int(pipeline.progress * 100))%")
                                .font(.system(size: 18, weight: .bold, design: .monospaced))
                                .foregroundStyle(AppTheme.accent)
                        }

                        ProgressView(value: pipeline.progress)
                            .tint(AppTheme.accent)

                        HStack(spacing: 10) {
                            if pipeline.queuedCount > 1 {
                                Text("Queue remaining: \(pipeline.queuedCount)")
                                    .font(.system(size: 11, design: .monospaced))
                                    .foregroundStyle(AppTheme.textMuted)
                            }
                            Spacer()
                            if pipeline.isAnalyzing {
                                Button(role: .destructive) {
                                    pipeline.cancelCurrentAnalysis()
                                } label: {
                                    Label("Cancel Analysis", systemImage: "xmark.circle")
                                        .font(.system(size: 12, weight: .semibold))
                                }
                                .buttonStyle(.bordered)
                                .tint(AppTheme.statusUndetermined)
                            } else {
                                Button {
                                    container.isShowingFileImporter = true
                                } label: {
                                    Label("Select IPA", systemImage: "folder.badge.plus")
                                        .font(.system(size: 12, weight: .semibold))
                                }
                                .buttonStyle(.borderedProminent)
                                .tint(AppTheme.accent)
                            }
                        }
                    }
                    .thinCard(padding: 14, cornerRadius: 12, elevated: true)

                    if let errorText = pipeline.lastErrorMessage {
                        HStack(alignment: .top, spacing: 10) {
                            Image(systemName: "exclamationmark.triangle")
                                .foregroundStyle(AppTheme.statusUndetermined)
                            Text(errorText)
                                .font(.system(size: 12))
                                .foregroundStyle(AppTheme.textPrimary)
                        }
                        .thinCard(padding: 12, cornerRadius: 10)
                    }

                    // Latest Real Output Summary
                    if let bundle = pipeline.latestCompletedBundle {
                        VStack(alignment: .leading, spacing: 10) {
                            HStack {
                                Text("REAL ANALYSIS OUTPUT")
                                    .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                    .foregroundStyle(AppTheme.textMuted)
                                Spacer()
                                Text("\(bundle.record.directoryName)/")
                                    .font(.system(size: 11, design: .monospaced))
                                    .foregroundStyle(AppTheme.accent)
                            }

                            HStack(spacing: 8) {
                                miniCountTile("Verified", count: bundle.report.verifiedCount, color: AppTheme.statusVerified)
                                miniCountTile("Probable", count: bundle.report.probableCount, color: AppTheme.statusProbable)
                                miniCountTile("Unverified", count: bundle.report.unverifiedCount, color: AppTheme.statusUnverified)
                                miniCountTile("Undetermined", count: bundle.report.undeterminedCount, color: AppTheme.statusUndetermined)
                            }

                            Text("Module: \(bundle.metadata.analysisModuleName) (\(bundle.metadata.cpuArchitecture)) • \(bundle.metadata.encryptionState.rawValue)")
                                .font(.system(size: 11, design: .monospaced))
                                .foregroundStyle(AppTheme.textSecondary)

                            Button {
                                container.openGameDetails(bundle.record)
                            } label: {
                                HStack {
                                    Text("Inspect Offset Database (\(bundle.offsets.count) entries)")
                                        .font(.system(size: 13, weight: .semibold))
                                    Spacer()
                                    Image(systemName: "arrow.right")
                                }
                                .foregroundStyle(AppTheme.accent)
                                .padding(.top, 4)
                            }
                            .buttonStyle(.plain)
                        }
                        .thinCard(padding: 14, cornerRadius: 12)
                    }

                    // Live Console Log
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            Text("ANALYSIS ENGINE LOG")
                                .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                .foregroundStyle(AppTheme.textMuted)
                            Spacer()
                            if !pipeline.logEntries.isEmpty {
                                Button {
                                    let text = pipeline.logEntries.map(\.formattedLine).joined(separator: "\n")
                                    UIPasteboard.general.string = text
                                    copiedLogsToast = true
                                } label: {
                                    Text(copiedLogsToast ? "Copied" : "Copy Log")
                                        .font(.system(size: 11, weight: .medium))
                                        .foregroundStyle(AppTheme.accent)
                                }
                            }
                        }

                        if pipeline.logEntries.isEmpty {
                            Text("Waiting for IPA import... Engine stages (ZIP64 stream, Mach-O load commands, ARM64 ADRP+ADD XRefs, IL2CPP metadata, ASLR validation) will stream here in real time.")
                                .font(.system(size: 12, design: .monospaced))
                                .foregroundStyle(AppTheme.textMuted)
                                .padding(.vertical, 14)
                        } else {
                            ScrollView(.vertical) {
                                LazyVStack(alignment: .leading, spacing: 5) {
                                    ForEach(pipeline.logEntries) { item in
                                        HStack(alignment: .top, spacing: 6) {
                                            Text(item.formattedTime)
                                                .foregroundStyle(AppTheme.textMuted)
                                            Text(item.level.rawValue)
                                                .foregroundStyle(color(for: item.level))
                                                .frame(width: 44, alignment: .leading)
                                            Text("[\(item.stage)]")
                                                .foregroundStyle(AppTheme.accent)
                                            Text(item.message)
                                                .foregroundStyle(AppTheme.textPrimary)
                                                .fixedSize(horizontal: false, vertical: true)
                                        }
                                        .font(.system(size: 11, design: .monospaced))
                                        .frame(maxWidth: .infinity, alignment: .leading)
                                    }
                                }
                            }
                            .frame(minHeight: 220, maxHeight: 340)
                        }
                    }
                    .thinCard(padding: 13, cornerRadius: 12)
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 12)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Analysis")
            .navigationBarTitleDisplayMode(.inline)
        }
    }

    private func miniCountTile(_ label: String, count: Int, color: Color) -> some View {
        VStack(spacing: 3) {
            Text("\(count)")
                .font(.system(size: 15, weight: .bold, design: .monospaced))
                .foregroundStyle(color)
            Text(label)
                .font(.system(size: 9, weight: .medium))
                .foregroundStyle(AppTheme.textSecondary)
                .lineLimit(1)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 8)
        .background(AppTheme.surfaceElevated)
        .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
    }

    private func color(for level: AnalysisLogLevel) -> Color {
        switch level {
        case .info: return AppTheme.textSecondary
        case .stage: return AppTheme.accent
        case .success: return AppTheme.statusVerified
        case .warning: return AppTheme.statusProbable
        case .error: return AppTheme.statusUndetermined
        }
    }
}
