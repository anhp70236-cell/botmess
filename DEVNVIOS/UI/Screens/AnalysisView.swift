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
                    // Progress & Controls Card
                    VStack(alignment: .leading, spacing: 10) {
                        HStack {
                            VStack(alignment: .leading, spacing: 3) {
                                Text(pipeline.currentGameName.isEmpty ? "Trình Dò Offset Chuyên Sâu" : pipeline.currentGameName)
                                    .font(.system(size: 16, weight: .bold))
                                    .foregroundStyle(AppTheme.textPrimary)
                                    .lineLimit(1)
                                Text(pipeline.currentStageTitle)
                                    .font(.system(size: 12))
                                    .foregroundStyle(AppTheme.textSecondary)
                                    .lineLimit(2)
                            }
                            Spacer()
                            Text("\(Int(pipeline.progress * 100))%")
                                .font(.system(size: 19, weight: .bold, design: .monospaced))
                                .foregroundStyle(AppTheme.accent)
                        }

                        ProgressView(value: pipeline.progress)
                            .tint(AppTheme.accent)

                        HStack(spacing: 10) {
                            if pipeline.queuedCount > 1 {
                                Text("Hàng đợi còn: \(pipeline.queuedCount) IPA")
                                    .font(.system(size: 11, design: .monospaced))
                                    .foregroundStyle(AppTheme.textMuted)
                            }
                            Spacer()
                            if pipeline.isAnalyzing {
                                Button(role: .destructive) {
                                    pipeline.cancelCurrentAnalysis()
                                } label: {
                                    Label("Hủy Phân Tích", systemImage: "xmark.circle")
                                        .font(.system(size: 12, weight: .semibold))
                                }
                                .buttonStyle(.bordered)
                                .tint(AppTheme.statusUndetermined)
                            } else {
                                Button {
                                    container.openIPAPicker()
                                } label: {
                                    Label("Chọn Tệp IPA", systemImage: "doc.badge.plus")
                                        .font(.system(size: 12, weight: .semibold))
                                }
                                .buttonStyle(.borderedProminent)
                                .tint(AppTheme.accent)
                            }
                        }
                    }
                    .thinCard(padding: 14, cornerRadius: 13, elevated: true)

                    if let errorText = pipeline.lastErrorMessage {
                        HStack(alignment: .top, spacing: 10) {
                            Image(systemName: "exclamationmark.triangle.fill")
                                .foregroundStyle(AppTheme.statusUndetermined)
                            Text(errorText)
                                .font(.system(size: 12))
                                .foregroundStyle(AppTheme.textPrimary)
                        }
                        .thinCard(padding: 12, cornerRadius: 11)
                    }

                    // Latest Completed Bundle Actions
                    if let bundle = pipeline.latestCompletedBundle {
                        VStack(alignment: .leading, spacing: 10) {
                            HStack {
                                Text("KẾT QUẢ DÒ CHUYÊN SÂU THỰC TẾ")
                                    .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                    .foregroundStyle(AppTheme.textMuted)
                                Spacer()
                                Text("Games/\(bundle.record.directoryName)/")
                                    .font(.system(size: 11, design: .monospaced))
                                    .foregroundStyle(AppTheme.accent)
                            }

                            HStack(spacing: 8) {
                                miniCountTile("Đã xác minh", count: bundle.report.verifiedCount, color: AppTheme.statusVerified)
                                miniCountTile("Khả năng cao", count: bundle.report.probableCount, color: AppTheme.statusProbable)
                                miniCountTile("Chưa rõ", count: bundle.report.unverifiedCount, color: AppTheme.statusUnverified)
                                miniCountTile("Token tĩnh", count: bundle.report.undeterminedCount, color: AppTheme.statusUndetermined)
                            }

                            HStack(spacing: 10) {
                                Button {
                                    pipeline.pendingExportPromptBundle = bundle
                                } label: {
                                    HStack(spacing: 5) {
                                        Image(systemName: "square.and.arrow.down.on.square")
                                        Text("Xuất 1 Tệp / Tách Tệp")
                                    }
                                    .font(.system(size: 12, weight: .semibold))
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 9)
                                    .background(AppTheme.accent)
                                    .foregroundStyle(Color.white)
                                    .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                                }
                                .buttonStyle(.plain)

                                Button {
                                    container.openGameDetails(bundle.record)
                                } label: {
                                    HStack(spacing: 5) {
                                        Text("Mở Bảng Offset (\(bundle.offsets.count))")
                                        Image(systemName: "arrow.right")
                                    }
                                    .font(.system(size: 12, weight: .semibold))
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 9)
                                    .background(AppTheme.surfaceElevated)
                                    .foregroundStyle(AppTheme.textPrimary)
                                    .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .thinCard(padding: 14, cornerRadius: 13)
                    }

                    // Live Console Log
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            Text("NHẬT KÝ PHÂN TÍCH THỜI GIAN THỰC")
                                .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                .foregroundStyle(AppTheme.textMuted)
                            Spacer()
                            if !pipeline.logEntries.isEmpty {
                                Button {
                                    let text = pipeline.logEntries.map(\.formattedLine).joined(separator: "\n")
                                    UIPasteboard.general.string = text
                                    copiedLogsToast = true
                                } label: {
                                    Text(copiedLogsToast ? "Đã chép Log" : "Sao chép Log")
                                        .font(.system(size: 11, weight: .medium))
                                        .foregroundStyle(AppTheme.accent)
                                }
                            }
                        }

                        if pipeline.logEntries.isEmpty {
                            Text("Đang chờ chọn tệp IPA... Hệ thống sẽ tự động giải nén luồng ZIP64, đọc Mach-O 64-bit, quét bảng Il2CppCodeGenModule, ObjC IMP, lệnh ARM64 ADRP/ADD/LDR và loại trừ hoàn toàn dữ liệu tiền thật.")
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
                    .thinCard(padding: 13, cornerRadius: 13)
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 12)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Phân tích")
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
                .minimumScaleFactor(0.75)
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
