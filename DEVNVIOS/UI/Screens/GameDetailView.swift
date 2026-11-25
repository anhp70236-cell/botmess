import SwiftUI
import UIKit
import UniformTypeIdentifiers

public struct GameDetailRootView: View {
    @EnvironmentObject private var container: AppContainer

    public init() {}

    public var body: some View {
        NavigationStack {
            Group {
                if container.games.isEmpty {
                    ScrollView {
                        VStack(spacing: 16) {
                            EmptyStateCardView(
                                icon: "cylinder.split.1x2",
                                title: "No Offset Databases Saved",
                                message: "Import an IPA from Home or Analysis to automatically create a per-game folder with metadata.json, offsets.json, symbols.json, runtime.json, report.json, and history.json."
                            )
                            Button {
                                container.isShowingFileImporter = true
                            } label: {
                                Label("Import IPA Archive", systemImage: "square.and.arrow.down")
                                    .font(.system(size: 14, weight: .semibold))
                                    .frame(maxWidth: .infinity)
                                    .padding(.vertical, 10)
                            }
                            .buttonStyle(.borderedProminent)
                            .tint(AppTheme.accent)
                        }
                        .padding(16)
                    }
                } else if let selected = container.activeGameForDetails ?? container.games.first {
                    GameDetailContentView(record: selected)
                }
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle(container.activeGameForDetails?.gameName ?? "Game Details")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if container.games.count > 1 {
                    ToolbarItem(placement: .navigationBarLeading) {
                        Menu {
                            ForEach(container.games) { item in
                                Button {
                                    container.activeGameForDetails = item
                                } label: {
                                    Label(item.gameName, systemImage: item.id == container.activeGameForDetails?.id ? "checkmark.circle.fill" : "circle")
                                }
                            }
                        } label: {
                            HStack(spacing: 4) {
                                Image(systemName: "square.stack.3d.up")
                                Text("Switch")
                                    .font(.system(size: 13, weight: .medium))
                            }
                            .foregroundStyle(AppTheme.accent)
                        }
                    }
                }
            }
        }
    }
}

struct GameDetailContentView: View {
    @EnvironmentObject private var container: AppContainer
    @EnvironmentObject private var pipeline: AnalysisPipelineCoordinator

    let record: GameRecord

    @State private var bundle: GameAnalysisBundle?
    @State private var loadError: String?

    @State private var searchText: String = ""
    @State private var selectedCategory: OffsetCategory?
    @State private var selectedStatus: OffsetVerificationStatus?
    @State private var selectedDataType: OffsetDataType?

    @State private var inspectedOffset: DiscoveredOffset?
    @State private var isShowingReportSheet = false
    @State private var isShowingRuntimeSheet = false
    @State private var isShowingTraceImporter = false
    @State private var confirmDeleteGame = false
    @State private var confirmDeleteIPA = false
    @State private var exportedFileURL: URL?
    @State private var copiedAddressLabel: String?

    private var filteredOffsets: [DiscoveredOffset] {
        guard let bundle else { return [] }
        return container.databaseService.filterOffsets(
            bundle.offsets,
            searchQuery: searchText,
            category: selectedCategory,
            verificationStatus: selectedStatus,
            dataType: selectedDataType
        )
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                if let bundle {
                    binarySummaryCard(bundle: bundle)
                    actionToolbarCard(bundle: bundle)
                    searchAndFilterSection(totalCount: bundle.offsets.count)

                    if filteredOffsets.isEmpty {
                        EmptyStateCardView(
                            icon: "magnifyingglass",
                            title: "No Matching Offsets",
                            message: "Adjust search keywords or clear active category/verification filters."
                        )
                    } else {
                        LazyVStack(spacing: 10) {
                            ForEach(filteredOffsets) { offset in
                                OffsetRowCard(
                                    offset: offset,
                                    isCopied: copiedAddressLabel == offset.id.uuidString,
                                    onCopy: {
                                        let copyTarget = offset.rva > 0 ? offset.rvaFormatted : offset.value
                                        UIPasteboard.general.string = copyTarget
                                        withAnimation(.easeInOut(duration: 0.18)) {
                                            copiedAddressLabel = offset.id.uuidString
                                        }
                                    },
                                    onInspect: {
                                        inspectedOffset = offset
                                    }
                                )
                            }
                        }
                    }
                } else if let loadError {
                    EmptyStateCardView(icon: "exclamationmark.octagon", title: "Database Read Error", message: loadError)
                } else {
                    ProgressView("Loading \(record.directoryName)/ database...")
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 40)
                }
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
        }
        .onAppear { reloadBundle() }
        .onChange(of: record.id) { _ in reloadBundle() }
        .onChange(of: record.lastAnalyzedAt) { _ in reloadBundle() }
        .sheet(item: $inspectedOffset) { item in
            OffsetInspectorSheet(offset: item, defaultBase: bundle?.metadata.defaultBaseAddress ?? 0x1_0000_0000)
        }
        .sheet(isPresented: $isShowingReportSheet) {
            if let bundle {
                AnalysisReportSheet(bundle: bundle)
            }
        }
        .sheet(isPresented: $isShowingRuntimeSheet) {
            if let bundle {
                RuntimeInspectorSheet(
                    bundle: bundle,
                    onImportTraceTapped: {
                        isShowingRuntimeSheet = false
                        DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                            isShowingTraceImporter = true
                        }
                    }
                )
            }
        }
        .fileImporter(
            isPresented: $isShowingTraceImporter,
            allowedContentTypes: [.json],
            allowsMultipleSelection: false
        ) { result in
            if case .success(let urls) = result, let url = urls.first {
                applyAuthorizedRuntimeTrace(from: url)
            }
        }
        .confirmationDialog("Delete Original IPA?", isPresented: $confirmDeleteIPA, titleVisibility: .visible) {
            Button("Delete IPA (Keep Offsets)", role: .destructive) {
                container.deleteStoredIPAOnly(for: record)
                reloadBundle()
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Removes the large IPA archive while keeping \(record.directoryName)/{metadata,offsets,symbols,runtime,report,history}.json intact.")
        }
        .confirmationDialog("Delete \(record.gameName)?", isPresented: $confirmDeleteGame, titleVisibility: .visible) {
            Button("Delete Game & All Offsets", role: .destructive) {
                container.deleteGameCompletely(record)
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Permanently removes \(record.directoryName)/ and all discovered offsets.")
        }
    }

    private func reloadBundle() {
        do {
            let loaded = try container.databaseService.loadGameBundle(for: record)
            self.bundle = loaded
            self.loadError = nil
            self.exportedFileURL = try? container.exportService.exportFullAnalysisPackageJSON(bundle: loaded)
        } catch {
            self.bundle = nil
            self.loadError = error.localizedDescription
        }
    }

    private func applyAuthorizedRuntimeTrace(from url: URL) {
        let scoped = url.startAccessingSecurityScopedResource()
        defer { if scoped { url.stopAccessingSecurityScopedResource() } }
        do {
            let data = try Data(contentsOf: url)
            let trace = try container.runtimeInspector.parseAuthorizedTraceJSON(data)
            container.selectedTab = .analysis
            pipeline.reanalyze(record: record, authorizedTrace: trace)
        } catch {
            container.alertMessage = error.localizedDescription
        }
    }

    @ViewBuilder
    private func binarySummaryCard(bundle: GameAnalysisBundle) -> some View {
        let meta = bundle.metadata
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                VStack(alignment: .leading, spacing: 3) {
                    Text(meta.gameName)
                        .font(.system(size: 17, weight: .bold))
                        .foregroundStyle(AppTheme.textPrimary)
                    Text("\(meta.bundleIdentifier) • v\(meta.bundleShortVersion) (\(meta.bundleBuildVersion))")
                        .font(.system(size: 11, design: .monospaced))
                        .foregroundStyle(AppTheme.textSecondary)
                }
                Spacer()
                Text("\(record.directoryName)/")
                    .font(.system(size: 11, weight: .semibold, design: .monospaced))
                    .foregroundStyle(AppTheme.accent)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .background(AppTheme.accentSubtle)
                    .clipShape(RoundedRectangle(cornerRadius: 6, style: .continuous))
            }

            Divider().overlay(AppTheme.hairline)

            LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], alignment: .leading, spacing: 8) {
                metaKeyValue("Module", meta.analysisModuleName)
                metaKeyValue("Architecture", meta.cpuArchitecture.uppercased())
                metaKeyValue("Encryption", meta.encryptionState == .unencrypted ? "None (cryptid=0)" : "FairPlay (cryptid=\(meta.cryptID))")
                metaKeyValue("Symbols", "\(meta.symbolStripStatus.rawValue) (\(meta.totalSymbolsCount))")
                metaKeyValue("IL2CPP", meta.isUnityIL2CPP ? (meta.il2cppMetadataVersion.map { "v\($0)" } ?? meta.il2cppMetadataStatus) : "Not Present")
                metaKeyValue("ASLR Base", HexFormatters.vaHex(meta.defaultBaseAddress))
            }

            Text("Persisted files: metadata.json • offsets.json • symbols.json • runtime.json • report.json • history.json")
                .font(.system(size: 10, design: .monospaced))
                .foregroundStyle(AppTheme.textMuted)
                .lineLimit(2)
        }
        .thinCard(padding: 14, cornerRadius: 12, elevated: true)
    }

    private func metaKeyValue(_ key: String, _ val: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(key.uppercased())
                .font(.system(size: 9, weight: .semibold, design: .monospaced))
                .foregroundStyle(AppTheme.textMuted)
            Text(val)
                .font(.system(size: 12, weight: .medium, design: .monospaced))
                .foregroundStyle(AppTheme.textPrimary)
                .lineLimit(1)
        }
    }

    @ViewBuilder
    private func actionToolbarCard(bundle: GameAnalysisBundle) -> some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                Button {
                    isShowingReportSheet = true
                } label: {
                    actionChipLabel("Report & History", icon: "doc.text.magnifyingglass")
                }

                Button {
                    isShowingRuntimeSheet = true
                } label: {
                    actionChipLabel("Runtime & ASLR", icon: "memorychip")
                }

                if let exportURL = exportedFileURL {
                    ShareLink(item: exportURL) {
                        actionChipLabel("Export JSON", icon: "square.and.arrow.up")
                    }
                }

                if record.hasStoredIPA {
                    Button {
                        container.selectedTab = .analysis
                        pipeline.reanalyze(record: record)
                    } label: {
                        actionChipLabel("Re-analyze", icon: "arrow.clockwise")
                    }

                    Button {
                        confirmDeleteIPA = true
                    } label: {
                        actionChipLabel("Delete IPA Only", icon: "archivebox")
                    }
                }

                Button {
                    confirmDeleteGame = true
                } label: {
                    HStack(spacing: 5) {
                        Image(systemName: "trash")
                        Text("Delete Game")
                    }
                    .font(.system(size: 12, weight: .medium))
                    .foregroundStyle(AppTheme.statusUndetermined)
                    .padding(.horizontal, 11)
                    .padding(.vertical, 8)
                    .background(AppTheme.surface)
                    .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                    .overlay(
                        RoundedRectangle(cornerRadius: 8, style: .continuous)
                            .strokeBorder(AppTheme.statusUndetermined.opacity(0.35), lineWidth: 0.8)
                    )
                }
            }
        }
        .buttonStyle(.plain)
    }

    private func actionChipLabel(_ title: String, icon: String) -> some View {
        HStack(spacing: 5) {
            Image(systemName: icon)
                .foregroundStyle(AppTheme.accent)
            Text(title)
                .foregroundStyle(AppTheme.textPrimary)
        }
        .font(.system(size: 12, weight: .medium))
        .padding(.horizontal, 11)
        .padding(.vertical, 8)
        .background(AppTheme.surface)
        .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
        .overlay(
            RoundedRectangle(cornerRadius: 8, style: .continuous)
                .strokeBorder(AppTheme.hairline, lineWidth: 0.8)
        )
    }

    @ViewBuilder
    private func searchAndFilterSection(totalCount: Int) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 8) {
                Image(systemName: "magnifyingglass")
                    .foregroundStyle(AppTheme.textMuted)
                TextField("Search class, method, field, RVA (0x...)", text: $searchText)
                    .font(.system(size: 13, design: .monospaced))
                    .foregroundStyle(AppTheme.textPrimary)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                if !searchText.isEmpty {
                    Button {
                        searchText = ""
                    } label: {
                        Image(systemName: "xmark.circle.fill")
                            .foregroundStyle(AppTheme.textMuted)
                    }
                }
            }
            .thinCard(padding: 10, cornerRadius: 10)

            // Verification Status Filter Chips
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    filterChip(title: "All (\(totalCount))", isSelected: selectedStatus == nil && selectedCategory == nil && selectedDataType == nil) {
                        selectedStatus = nil
                        selectedCategory = nil
                        selectedDataType = nil
                    }
                    ForEach(OffsetVerificationStatus.allCases) { st in
                        filterChip(title: st.vietnameseLabel, isSelected: selectedStatus == st) {
                            selectedStatus = (selectedStatus == st) ? nil : st
                        }
                    }
                }
            }

            // Category Filter Chips
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    ForEach(OffsetCategory.allCases) { cat in
                        filterChip(title: cat.rawValue, isSelected: selectedCategory == cat) {
                            selectedCategory = (selectedCategory == cat) ? nil : cat
                        }
                    }
                }
            }

            Text("Showing \(filteredOffsets.count) of \(totalCount) offsets")
                .font(.system(size: 11, design: .monospaced))
                .foregroundStyle(AppTheme.textMuted)
        }
    }

    private func filterChip(title: String, isSelected: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(title)
                .font(.system(size: 11, weight: isSelected ? .semibold : .regular))
                .foregroundStyle(isSelected ? AppTheme.accent : AppTheme.textSecondary)
                .padding(.horizontal, 9)
                .padding(.vertical, 5)
                .background(isSelected ? AppTheme.accentSubtle : AppTheme.surface)
                .clipShape(Capsule())
                .overlay(
                    Capsule()
                        .strokeBorder(isSelected ? AppTheme.accent.opacity(0.6) : AppTheme.hairline, lineWidth: 0.8)
                )
        }
        .buttonStyle(.plain)
    }
}

struct OffsetRowCard: View {
    let offset: DiscoveredOffset
    let isCopied: Bool
    let onCopy: () -> Void
    let onInspect: () -> Void

    var body: some View {
        Button(action: onInspect) {
            VStack(alignment: .leading, spacing: 8) {
                HStack(alignment: .top, spacing: 8) {
                    Image(systemName: offset.category.safeSymbolName)
                        .font(.system(size: 13))
                        .foregroundStyle(AppTheme.accent)
                        .frame(width: 20, height: 20)

                    VStack(alignment: .leading, spacing: 3) {
                        Text(offset.name)
                            .font(.system(size: 13, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textPrimary)
                            .lineLimit(2)
                            .multilineTextAlignment(.leading)

                        Text("\(offset.category.rawValue) • \(offset.dataType.rawValue) • \(offset.discoverySource.rawValue)")
                            .font(.system(size: 10))
                            .foregroundStyle(AppTheme.textSecondary)
                            .lineLimit(1)
                    }

                    Spacer(minLength: 4)
                    VerificationBadge(status: offset.verificationStatus)
                }

                HStack(spacing: 10) {
                    addressPair("RVA", offset.rvaFormatted)
                    addressPair("VA", offset.value)
                    addressPair("FILE", offset.fileOffsetFormatted)
                    Spacer(minLength: 4)

                    Button(action: onCopy) {
                        HStack(spacing: 4) {
                            Image(systemName: isCopied ? "checkmark" : "doc.on.doc")
                            Text(isCopied ? "Copied" : "Copy")
                        }
                        .font(.system(size: 10, weight: .semibold))
                        .foregroundStyle(isCopied ? AppTheme.statusVerified : AppTheme.accent)
                        .padding(.horizontal, 8)
                        .padding(.vertical, 4)
                        .background(AppTheme.surfaceElevated)
                        .clipShape(RoundedRectangle(cornerRadius: 6, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
            }
            .thinCard(padding: 12, cornerRadius: 11)
        }
        .buttonStyle(.plain)
    }

    private func addressPair(_ label: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            Text(label)
                .font(.system(size: 8, weight: .semibold, design: .monospaced))
                .foregroundStyle(AppTheme.textMuted)
            Text(value)
                .font(.system(size: 11, weight: .medium, design: .monospaced))
                .foregroundStyle(AppTheme.textPrimary)
                .lineLimit(1)
        }
    }
}

struct OffsetInspectorSheet: View {
    @Environment(\.dismiss) private var dismiss
    let offset: DiscoveredOffset
    let defaultBase: UInt64
    @State private var customSlideHex: String = "0x04200000"

    private var computedRuntimeHex: String {
        guard let slide = HexFormatters.parseHex(customSlideHex),
              let rt = offset.runtimeAddress(withASLRSlide: slide) else {
            return "N/A"
        }
        return HexFormatters.vaHex(rt)
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    VStack(alignment: .leading, spacing: 8) {
                        VerificationBadge(status: offset.verificationStatus)
                        Text(offset.name)
                            .font(.system(size: 15, weight: .bold, design: .monospaced))
                            .foregroundStyle(AppTheme.textPrimary)
                        Text(offset.notes)
                            .font(.system(size: 12))
                            .foregroundStyle(AppTheme.textSecondary)
                    }
                    .thinCard()

                    VStack(alignment: .leading, spacing: 8) {
                        detailRow("Module", offset.moduleName)
                        detailRow("Category", offset.category.rawValue)
                        detailRow("Data Type", offset.dataType.rawValue)
                        detailRow("Value / Static VA", offset.value)
                        detailRow("RVA", offset.rvaFormatted)
                        detailRow("File Offset", offset.fileOffsetFormatted)
                        detailRow("Discovery Source", offset.discoverySource.rawValue)
                        detailRow("Confidence", String(format: "%.0f%%", offset.confidenceScore * 100))
                        if let sig = offset.signatureHint {
                            detailRow("Signature / Prologue", sig)
                        }
                        detailRow("Detected At", ISO8601DateFormatter().string(from: offset.detectedAt))
                    }
                    .thinCard()

                    // Interactive ASLR Calculator
                    VStack(alignment: .leading, spacing: 8) {
                        Text("ASLR-AWARE ADDRESS CALCULATOR")
                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textMuted)
                        Text("Formula: RuntimeVA = StaticVA (\(HexFormatters.vaHex(defaultBase)) + RVA) + ASLRSlide")
                            .font(.system(size: 11, design: .monospaced))
                            .foregroundStyle(AppTheme.textSecondary)

                        HStack {
                            Text("ASLR Slide:")
                                .font(.system(size: 12, design: .monospaced))
                                .foregroundStyle(AppTheme.textSecondary)
                            TextField("0x04200000", text: $customSlideHex)
                                .font(.system(size: 13, design: .monospaced))
                                .foregroundStyle(AppTheme.accent)
                                .textInputAutocapitalization(.never)
                                .autocorrectionDisabled()
                        }

                        HStack {
                            Text("Runtime VA:")
                                .font(.system(size: 12, design: .monospaced))
                                .foregroundStyle(AppTheme.textSecondary)
                            Text(computedRuntimeHex)
                                .font(.system(size: 14, weight: .bold, design: .monospaced))
                                .foregroundStyle(AppTheme.statusVerified)
                            Spacer()
                            Button("Copy") {
                                UIPasteboard.general.string = computedRuntimeHex
                            }
                            .font(.system(size: 12, weight: .semibold))
                            .foregroundStyle(AppTheme.accent)
                        }
                    }
                    .thinCard()
                }
                .padding(16)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Offset Details")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Done") { dismiss() }
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private func detailRow(_ title: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(title)
                .font(.system(size: 10, weight: .semibold))
                .foregroundStyle(AppTheme.textMuted)
            Text(value)
                .font(.system(size: 12, design: .monospaced))
                .foregroundStyle(AppTheme.textPrimary)
                .textSelection(.enabled)
        }
    }
}

struct AnalysisReportSheet: View {
    @Environment(\.dismiss) private var dismiss
    let bundle: GameAnalysisBundle

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    VStack(alignment: .leading, spacing: 6) {
                        Text("9-STAGE PIPELINE REPORT")
                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textMuted)
                        Text("Duration: \(String(format: "%.2f", bundle.report.durationSeconds))s • SHA-256: \(bundle.metadata.binarySHA256.prefix(16))...")
                            .font(.system(size: 11, design: .monospaced))
                            .foregroundStyle(AppTheme.textSecondary)
                    }
                    .thinCard()

                    if !bundle.report.warnings.isEmpty {
                        VStack(alignment: .leading, spacing: 6) {
                            Text("WARNINGS & LIMITATIONS")
                                .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                .foregroundStyle(AppTheme.statusProbable)
                            ForEach(bundle.report.warnings + bundle.report.limitations, id: \.self) { w in
                                Text("• \(w)")
                                    .font(.system(size: 12))
                                    .foregroundStyle(AppTheme.textPrimary)
                            }
                        }
                        .thinCard()
                    }

                    ForEach(bundle.report.methodSummaries) { method in
                        VStack(alignment: .leading, spacing: 4) {
                            HStack {
                                Text(method.methodName)
                                    .font(.system(size: 13, weight: .semibold))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Spacer()
                                Text(method.status)
                                    .font(.system(size: 10, weight: .semibold, design: .monospaced))
                                    .foregroundStyle(AppTheme.accent)
                            }
                            Text(method.details)
                                .font(.system(size: 11))
                                .foregroundStyle(AppTheme.textSecondary)
                        }
                        .thinCard(padding: 12)
                    }

                    if let cross = bundle.report.crossVersionDelta {
                        VStack(alignment: .leading, spacing: 6) {
                            Text("CROSS-VERSION COMPARISON (v\(cross.previousVersion) -> v\(cross.currentVersion))")
                                .font(.system(size: 11, weight: .semibold, design: .monospaced))
                                .foregroundStyle(AppTheme.accent)
                            Text("Matched: \(cross.matchedCount) • Shifted RVAs: \(cross.shiftedRVAEntries.count) • Added: \(cross.newlyAddedCount) • Missing: \(cross.missingCount)")
                                .font(.system(size: 12))
                                .foregroundStyle(AppTheme.textPrimary)
                            ForEach(cross.shiftedRVAEntries.prefix(15), id: \.self) { line in
                                Text(line)
                                    .font(.system(size: 11, design: .monospaced))
                                    .foregroundStyle(AppTheme.statusProbable)
                            }
                        }
                        .thinCard()
                    }

                    VStack(alignment: .leading, spacing: 6) {
                        Text("ANALYSIS HISTORY (\(bundle.history.count) RUNS)")
                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textMuted)
                        ForEach(bundle.history) { h in
                            HStack {
                                Text("v\(h.bundleVersion)")
                                    .font(.system(size: 12, weight: .semibold, design: .monospaced))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Spacer()
                                Text("\(h.totalOffsets) offsets (\(h.verifiedCount) verified)")
                                    .font(.system(size: 11, design: .monospaced))
                                    .foregroundStyle(AppTheme.textSecondary)
                            }
                        }
                    }
                    .thinCard()
                }
                .padding(16)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Analysis Report")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Close") { dismiss() }
                }
            }
        }
        .preferredColorScheme(.dark)
    }
}

struct RuntimeInspectorSheet: View {
    @Environment(\.dismiss) private var dismiss
    let bundle: GameAnalysisBundle
    let onImportTraceTapped: () -> Void

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    VStack(alignment: .leading, spacing: 8) {
                        Text("RUNTIME & ASLR MAPPING")
                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textMuted)
                        Text("Mode: \(bundle.runtime.observationMode)")
                            .font(.system(size: 13, weight: .semibold))
                            .foregroundStyle(AppTheme.textPrimary)
                        ForEach(bundle.runtime.notes, id: \.self) { note in
                            Text("• \(note)")
                                .font(.system(size: 12))
                                .foregroundStyle(AppTheme.textSecondary)
                        }
                        Button(action: onImportTraceTapped) {
                            Label("Import Authorized Runtime Trace (.json)", systemImage: "tray.and.arrow.down")
                                .font(.system(size: 12, weight: .semibold))
                        }
                        .buttonStyle(.borderedProminent)
                        .tint(AppTheme.accent)
                        .padding(.top, 4)
                    }
                    .thinCard()

                    VStack(alignment: .leading, spacing: 8) {
                        Text("DYNAMIC ASLR TABLE (SLIDE \(HexFormatters.hex(bundle.runtime.sampleASLRSlide)))")
                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textMuted)
                        ForEach(bundle.runtime.mappedRuntimeSymbols.prefix(25)) { sym in
                            VStack(alignment: .leading, spacing: 2) {
                                Text(sym.symbolName)
                                    .font(.system(size: 12, weight: .semibold, design: .monospaced))
                                    .foregroundStyle(AppTheme.textPrimary)
                                Text("Runtime: \(HexFormatters.vaHex(sym.runtimeAddress)) -> Static VA: \(HexFormatters.vaHex(sym.resolvedStaticVA)) (RVA \(HexFormatters.rvaHex(sym.resolvedRVA)))")
                                    .font(.system(size: 10, design: .monospaced))
                                    .foregroundStyle(AppTheme.accent)
                            }
                            Divider().overlay(AppTheme.hairline)
                        }
                    }
                    .thinCard()

                    VStack(alignment: .leading, spacing: 8) {
                        Text("AUTHORIZED IN-PROCESS DYLD MODULES")
                            .font(.system(size: 11, weight: .semibold, design: .monospaced))
                            .foregroundStyle(AppTheme.textMuted)
                        ForEach(bundle.runtime.hostDyldModulesSample.prefix(12)) { mod in
                            HStack {
                                Text(mod.imageName)
                                    .font(.system(size: 11, weight: .medium, design: .monospaced))
                                    .foregroundStyle(AppTheme.textPrimary)
                                    .lineLimit(1)
                                Spacer()
                                Text("Base \(HexFormatters.hex(mod.runtimeBaseAddress))")
                                    .font(.system(size: 10, design: .monospaced))
                                    .foregroundStyle(AppTheme.textSecondary)
                            }
                        }
                    }
                    .thinCard()
                }
                .padding(16)
            }
            .background(AppTheme.canvas.ignoresSafeArea())
            .navigationTitle("Runtime Inspector")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button("Close") { dismiss() }
                }
            }
        }
        .preferredColorScheme(.dark)
    }
}
