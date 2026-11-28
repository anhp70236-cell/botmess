import SwiftUI
import UIKit

struct AnalyzerView: View {
    @EnvironmentObject var vm: DumpViewModel
    @State private var showOptionsSheet = false
    @State private var showOptionsDetails = false
    @State private var showLogs = false
    @State private var quickShareZipURL: URL?

    private var enabledOptionCount: Int {
        let flags = [
            vm.options.generateDumpCS,
            vm.options.generateStructAndJSON,
            vm.options.generateDummyDLLs,
            vm.options.includeMethods,
            vm.options.includeFields,
            vm.options.includeProperties,
            vm.options.restoreAttributes,
            vm.options.writeFieldOffsets,
            vm.options.writeMethodOffsets,
            vm.options.writeTypeDefIndexes,
            vm.options.restoreDummyDllTokens
        ]
        return flags.filter { $0 }.count
    }

    var body: some View {
        let theme = vm.selectedTheme
        NavigationView {
            ZStack {
                theme.background.ignoresSafeArea()

                ScrollView(showsIndicators: false) {
                    VStack(spacing: 14) {
                        headerHero(theme)
                        inputSelectorCard(theme)
                        if let info = vm.inspectionInfo {
                            ipaInfoCard(info, theme: theme)
                        }
                        optionsDisclosureCard(theme)
                        actionAndProgressCard(theme)
                        if let err = vm.errorMessage {
                            errorBanner(err, theme: theme)
                        }
                        if let session = vm.currentSession, !vm.isRunning {
                            completionSummaryCard(session, theme: theme)
                        }
                        if !vm.logs.isEmpty {
                            collapsibleLogCard(theme)
                        }
                    }
                    .padding(.horizontal, 16)
                    .padding(.vertical, 12)
                }
            }
            .navigationTitle("IPA Dumper Pro")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button {
                        showOptionsSheet = true
                    } label: {
                        Image(systemName: "checklist")
                            .foregroundColor(theme.accent)
                    }
                }
            }
            .sheet(isPresented: $showOptionsSheet) {
                OptionsSheetView()
                    .environmentObject(vm)
            }
            .sheet(item: Binding(
                get: { quickShareZipURL.map { AnalyzerShareURL(url: $0) } },
                set: { quickShareZipURL = $0?.url }
            )) { wrapper in
                AnalyzerActivitySheet(activityItems: [wrapper.url])
            }
            .onChange(of: vm.isRunning) { running in
                if running {
                    showLogs = true
                }
            }
            .onAppear {
                vm.refreshLocalDocumentsIPAs()
            }
        }
        .navigationViewStyle(.stack)
    }

    private func headerHero(_ theme: AppThemeStyle) -> some View {
        HStack(spacing: 12) {
            ZStack {
                RoundedRectangle(cornerRadius: 14, style: .continuous)
                    .fill(theme.accent.opacity(0.16))
                    .frame(width: 46, height: 46)
                Image(systemName: "cube.transparent.fill")
                    .font(.system(size: 22, weight: .semibold))
                    .foregroundColor(theme.accent)
            }
            VStack(alignment: .leading, spacing: 3) {
                Text("IPA Dumper Pro")
                    .font(.headline)
                    .foregroundColor(theme.primaryText)
                Text("IL2CPP & Mach-O Static Engine")
                    .font(.caption)
                    .foregroundColor(theme.secondaryText)
            }
            Spacer()
            BadgeChip(text: "≤ 10 GB · \(vm.options.workerThreads)W", color: theme.accent)
        }
        .dipCard(theme)
    }

    private func inputSelectorCard(_ theme: AppThemeStyle) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Picker("Chế độ nhập", selection: $vm.inputMode) {
                ForEach(DumpViewModel.InputMode.allCases) { mode in
                    Text(mode.rawValue).tag(mode)
                }
            }
            .pickerStyle(.segmented)

            if vm.inputMode == .ipa {
                HStack(spacing: 8) {
                    Button {
                        NativeDocumentPicker.shared.presentFilePicker(asCopy: !vm.useInPlacePicker) { url in
                            vm.setIPAFile(url)
                        }
                    } label: {
                        HStack(spacing: 10) {
                            Image(systemName: "doc.zipper")
                                .font(.title2)
                                .foregroundColor(theme.accent)
                            VStack(alignment: .leading, spacing: 3) {
                                Text(vm.selectedIPAURL?.lastPathComponent ?? "Chọn tệp .IPA / .TIPA (≤ 10 GB)")
                                    .font(.subheadline.weight(.semibold))
                                    .foregroundColor(theme.primaryText)
                                    .lineLimit(1)
                                Text("Mở từ Tệp / Tải về / iCloud")
                                    .font(.caption)
                                    .foregroundColor(theme.secondaryText)
                            }
                            Spacer()
                            Image(systemName: "chevron.right")
                                .foregroundColor(theme.secondaryText)
                        }
                        .padding(12)
                        .background(theme.elevatedBackground)
                        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }

                    Button {
                        NativeDocumentPicker.shared.presentFolderPicker { folderURL in
                            vm.loadIPAsFromFolder(folderURL)
                        }
                    } label: {
                        VStack(spacing: 4) {
                            Image(systemName: "folder.badge.plus")
                                .font(.title3)
                            Text("Thư mục")
                                .font(.caption2.weight(.semibold))
                        }
                        .foregroundColor(theme.accent)
                        .padding(.horizontal, 12)
                        .padding(.vertical, 10)
                        .background(theme.elevatedBackground)
                        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }
                }

                // Optional external plaintext global-metadata.dat override
                HStack(spacing: 8) {
                    Button {
                        NativeDocumentPicker.shared.presentFilePicker(asCopy: true) { url in
                            vm.setOverrideMetadataFile(url)
                        }
                    } label: {
                        HStack(spacing: 8) {
                            Image(systemName: "doc.badge.plus")
                                .foregroundColor(theme.accent)
                            Text(vm.overrideMetadataURL?.lastPathComponent ?? "Gắn global-metadata.dat rời (tuỳ chọn)")
                                .font(.caption.weight(.medium))
                                .foregroundColor(vm.overrideMetadataURL != nil ? theme.primaryText : theme.secondaryText)
                                .lineLimit(1)
                            Spacer()
                        }
                        .padding(10)
                        .background(theme.elevatedBackground)
                        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                    }
                    if vm.overrideMetadataURL != nil {
                        Button {
                            vm.clearOverrideMetadata()
                        } label: {
                            Image(systemName: "xmark.circle.fill")
                                .foregroundColor(theme.secondaryText)
                        }
                    }
                }

                if !vm.localDocumentsIPAs.isEmpty {
                    VStack(alignment: .leading, spacing: 6) {
                        HStack {
                            Text("Danh sách IPA đã quét (\(vm.localDocumentsIPAs.count))")
                                .font(.caption.weight(.semibold))
                                .foregroundColor(theme.secondaryText)
                            Spacer()
                            Button {
                                vm.refreshLocalDocumentsIPAs()
                            } label: {
                                Image(systemName: "arrow.clockwise")
                                    .font(.caption)
                                    .foregroundColor(theme.accent)
                            }
                        }
                        ForEach(vm.localDocumentsIPAs, id: \.self) { url in
                            Button {
                                vm.setIPAFile(url)
                            } label: {
                                HStack {
                                    Image(systemName: vm.selectedIPAURL == url ? "checkmark.circle.fill" : "doc.zipper")
                                        .foregroundColor(vm.selectedIPAURL == url ? theme.success : theme.accent)
                                    Text(url.lastPathComponent)
                                        .font(.caption.weight(.medium))
                                        .foregroundColor(theme.primaryText)
                                        .lineLimit(1)
                                    Spacer()
                                }
                                .padding(.horizontal, 10)
                                .padding(.vertical, 7)
                                .background(theme.elevatedBackground)
                                .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                            }
                        }
                    }
                }
            } else {
                VStack(spacing: 8) {
                    Button {
                        NativeDocumentPicker.shared.presentFilePicker(asCopy: !vm.useInPlacePicker) { url in
                            vm.setMachOFile(url)
                        }
                    } label: {
                        fileRowLabel(
                            icon: "terminal.fill",
                            title: vm.selectedMachOURL?.lastPathComponent ?? "Chọn Mach-O (UnityFramework / Binary)",
                            subtitle: "Bắt buộc · ARM64 Mach-O",
                            theme: theme
                        )
                    }
                    Button {
                        NativeDocumentPicker.shared.presentFilePicker(asCopy: true) { url in
                            vm.setMetadataFile(url)
                        }
                    } label: {
                        fileRowLabel(
                            icon: "doc.text.magnifyingglass",
                            title: vm.selectedMetadataURL?.lastPathComponent ?? "Chọn global-metadata.dat (tuỳ chọn)",
                            subtitle: "Hỗ trợ Unity IL2CPP v20–v31",
                            theme: theme
                        )
                    }
                }
            }
        }
        .dipCard(theme)
    }

    private func fileRowLabel(icon: String, title: String, subtitle: String, theme: AppThemeStyle) -> some View {
        HStack(spacing: 10) {
            Image(systemName: icon)
                .font(.title3)
                .foregroundColor(theme.accent)
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.subheadline.weight(.medium))
                    .foregroundColor(theme.primaryText)
                    .lineLimit(1)
                Text(subtitle)
                    .font(.caption)
                    .foregroundColor(theme.secondaryText)
            }
            Spacer()
            Image(systemName: "folder")
                .foregroundColor(theme.secondaryText)
        }
        .padding(10)
        .background(theme.elevatedBackground)
        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
    }

    private func ipaInfoCard(_ info: IPAInspectionInfo, theme: AppThemeStyle) -> some View {
        VStack(alignment: .leading, spacing: 7) {
            HStack {
                Text(info.appName)
                    .font(.subheadline.weight(.bold))
                    .foregroundColor(theme.primaryText)
                    .lineLimit(1)
                Spacer()
                Text(formatByteCount(info.fileSizeBytes))
                    .font(.caption.monospacedDigit())
                    .foregroundColor(theme.accent)
            }
            Text("\(info.bundleId.isEmpty ? "—" : info.bundleId) · v\(info.bundleVersion)")
                .font(.caption)
                .foregroundColor(theme.secondaryText)
            HStack(spacing: 6) {
                BadgeChip(text: URL(fileURLWithPath: info.primaryMachOPath).lastPathComponent, color: theme.accent)
                if vm.overrideMetadataURL != nil {
                    BadgeChip(text: "Metadata rời", color: theme.success)
                } else {
                    BadgeChip(text: info.metadataPath != nil ? "Metadata OK" : "Mach-O Only", color: info.metadataPath != nil ? theme.success : theme.warning)
                }
            }
        }
        .dipCard(theme)
    }

    private func optionsDisclosureCard(_ theme: AppThemeStyle) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Button {
                    withAnimation(.easeInOut(duration: 0.18)) {
                        showOptionsDetails.toggle()
                    }
                } label: {
                    HStack(spacing: 8) {
                        Image(systemName: "slider.horizontal.3")
                            .foregroundColor(theme.accent)
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Tuỳ chọn Dump")
                                .font(.subheadline.weight(.semibold))
                                .foregroundColor(theme.primaryText)
                            Text("\(enabledOptionCount)/11 mục bật · \(vm.options.workerThreads) Workers")
                                .font(.caption)
                                .foregroundColor(theme.secondaryText)
                        }
                        Spacer()
                        Image(systemName: showOptionsDetails ? "chevron.up" : "chevron.down")
                            .font(.caption.weight(.semibold))
                            .foregroundColor(theme.secondaryText)
                    }
                }

                Button("Sửa") {
                    showOptionsSheet = true
                }
                .font(.caption.weight(.semibold))
                .foregroundColor(theme.accent)
                .padding(.horizontal, 10)
                .padding(.vertical, 5)
                .background(theme.accent.opacity(0.14))
                .clipShape(Capsule())
            }

            if showOptionsDetails {
                LazyVGrid(columns: [GridItem(.adaptive(minimum: 112), spacing: 6)], spacing: 6) {
                    optionPill("dump.cs", active: vm.options.generateDumpCS, theme: theme)
                    optionPill("Struct & JSON", active: vm.options.generateStructAndJSON, theme: theme)
                    optionPill("Dummy DLLs", active: vm.options.generateDummyDLLs, theme: theme)
                    optionPill("Methods", active: vm.options.includeMethods, theme: theme)
                    optionPill("Fields", active: vm.options.includeFields, theme: theme)
                    optionPill("Properties", active: vm.options.includeProperties, theme: theme)
                    optionPill("Attributes", active: vm.options.restoreAttributes, theme: theme)
                    optionPill("Field Offsets", active: vm.options.writeFieldOffsets, theme: theme)
                    optionPill("RVA/VA/Offset", active: vm.options.writeMethodOffsets, theme: theme)
                    optionPill("TypeDef Index", active: vm.options.writeTypeDefIndexes, theme: theme)
                    optionPill("DLL Tokens", active: vm.options.restoreDummyDllTokens, theme: theme)
                }
            }
        }
        .dipCard(theme)
    }

    private func optionPill(_ label: String, active: Bool, theme: AppThemeStyle) -> some View {
        HStack(spacing: 4) {
            Image(systemName: active ? "checkmark.circle.fill" : "circle")
                .font(.system(size: 11))
                .foregroundColor(active ? theme.success : theme.secondaryText)
            Text(label)
                .font(.system(size: 11, weight: .medium))
                .foregroundColor(active ? theme.primaryText : theme.secondaryText)
                .lineLimit(1)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 5)
        .background(theme.elevatedBackground)
        .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
    }

    private func actionAndProgressCard(_ theme: AppThemeStyle) -> some View {
        let canStart = !vm.isRunning && ((vm.inputMode == .ipa && vm.selectedIPAURL != nil) || (vm.inputMode == .standalone && vm.selectedMachOURL != nil))
        return VStack(alignment: .leading, spacing: 10) {
            Button {
                if vm.isRunning { vm.cancelAnalysis() }
                else { vm.startAnalysis() }
            } label: {
                HStack {
                    Image(systemName: vm.isRunning ? "stop.circle.fill" : "play.fill")
                    Text(vm.isRunning ? "Huỷ phân tích" : "Bắt đầu Dump")
                        .fontWeight(.semibold)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 13)
                .foregroundColor(.white)
                .background(vm.isRunning ? theme.danger : (canStart ? theme.accent : theme.secondaryText.opacity(0.35)))
                .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            .disabled(!vm.isRunning && !canStart)

            if vm.isRunning || vm.progress > 0 {
                VStack(alignment: .leading, spacing: 6) {
                    HStack {
                        Text(vm.currentPhase.rawValue)
                            .font(.caption.weight(.semibold))
                            .foregroundColor(theme.accent)
                        Spacer()
                        Text("\(Int(vm.progress * 100))%")
                            .font(.caption.monospacedDigit())
                            .foregroundColor(theme.primaryText)
                    }
                    ProgressView(value: vm.progress)
                        .tint(theme.accent)
                    Text(vm.statusMessage)
                        .font(.caption2)
                        .foregroundColor(theme.secondaryText)
                        .lineLimit(2)
                }
            }
        }
        .dipCard(theme)
    }

    private func errorBanner(_ msg: String, theme: AppThemeStyle) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "exclamationmark.triangle.fill")
                .foregroundColor(theme.danger)
            Text(msg)
                .font(.caption)
                .foregroundColor(theme.primaryText)
            Spacer()
        }
        .padding(12)
        .background(theme.danger.opacity(0.14))
        .overlay(
            RoundedRectangle(cornerRadius: 12, style: .continuous)
                .stroke(theme.danger.opacity(0.4), lineWidth: 1)
        )
        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
    }

    private func completionSummaryCard(_ s: DumpSessionRecord, theme: AppThemeStyle) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Label("Hoàn tất trích xuất", systemImage: "checkmark.seal.fill")
                    .font(.subheadline.weight(.bold))
                    .foregroundColor(theme.success)
                Spacer()
                Text(String(format: "%.2fs", s.stats.elapsedSeconds))
                    .font(.caption.monospacedDigit())
                    .foregroundColor(theme.secondaryText)
            }

            Text(s.diagnostics.metadataStatus.summaryText)
                .font(.caption)
                .foregroundColor(s.diagnostics.metadataStatus.isValid ? theme.primaryText : theme.warning)

            LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible()), GridItem(.flexible())], spacing: 8) {
                statBox("Assemblies", "\(s.stats.assembliesCount)", theme: theme)
                statBox("Types", "\(s.stats.typesCount)", theme: theme)
                statBox("Methods", "\(s.stats.methodsCount)", theme: theme)
                statBox("Fields", "\(s.stats.fieldsCount)", theme: theme)
                statBox("Properties", "\(s.stats.propertiesCount)", theme: theme)
                statBox("Dummy DLLs", "\(s.stats.dummyDllsCount)", theme: theme)
            }

            HStack(spacing: 10) {
                Button {
                    vm.activeTab = 1
                } label: {
                    Label("Xem Offset", systemImage: "magnifyingglass")
                        .font(.caption.weight(.semibold))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 9)
                        .foregroundColor(.white)
                        .background(theme.accent)
                        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                }
                Button {
                    if let zip = vm.exportSessionZip(s) {
                        quickShareZipURL = zip
                    }
                } label: {
                    Label("Chia sẻ ZIP", systemImage: "square.and.arrow.up")
                        .font(.caption.weight(.semibold))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 9)
                        .foregroundColor(theme.accent)
                        .background(theme.elevatedBackground)
                        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                }
            }

            if s.stats.filteredRealMoneyCount > 0 {
                Text("Đã lọc \(s.stats.filteredRealMoneyCount) symbol liên quan đến thanh toán tiền thật.")
                    .font(.caption2)
                    .foregroundColor(theme.secondaryText)
            }
        }
        .dipCard(theme)
    }

    private func statBox(_ label: String, _ val: String, theme: AppThemeStyle) -> some View {
        VStack(spacing: 2) {
            Text(val)
                .font(.system(size: 16, weight: .bold, design: .monospaced))
                .foregroundColor(theme.accent)
            Text(label)
                .font(.caption2)
                .foregroundColor(theme.secondaryText)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 8)
        .background(theme.elevatedBackground)
        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
    }

    private func collapsibleLogCard(_ theme: AppThemeStyle) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Button {
                withAnimation(.easeInOut(duration: 0.18)) {
                    showLogs.toggle()
                }
            } label: {
                HStack {
                    Text("Nhật ký Worker (\(vm.logs.count))")
                        .font(.caption.weight(.bold))
                        .foregroundColor(theme.primaryText)
                    Spacer()
                    Image(systemName: showLogs ? "chevron.up" : "chevron.down")
                        .font(.caption2)
                        .foregroundColor(theme.secondaryText)
                }
            }
            if showLogs {
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 4) {
                        ForEach(Array(vm.logs.enumerated()), id: \.offset) { _, line in
                            Text(line)
                                .font(.system(size: 11, design: .monospaced))
                                .foregroundColor(theme.secondaryText)
                                .frame(maxWidth: .infinity, alignment: .leading)
                        }
                    }
                }
                .frame(maxHeight: 150)
            }
        }
        .dipCard(theme)
    }
}

private struct AnalyzerShareURL: Identifiable {
    let id = UUID()
    let url: URL
}

private struct AnalyzerActivitySheet: UIViewControllerRepresentable {
    let activityItems: [Any]
    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: activityItems, applicationActivities: nil)
    }
    func updateUIViewController(_ uiViewController: UIActivityViewController, context: Context) {}
}
