import SwiftUI

public struct MainTabView: View {
    @EnvironmentObject private var container: AppContainer
    @EnvironmentObject private var pipeline: AnalysisPipelineCoordinator

    public init() {}

    public var body: some View {
        TabView(selection: $container.selectedTab) {
            HomeView()
                .tabItem {
                    Label("Trang chủ", systemImage: "square.grid.2x2")
                }
                .tag(AppTab.home)

            AnalysisView()
                .tabItem {
                    Label("Phân tích", systemImage: "waveform.path.ecg.rectangle")
                }
                .tag(AppTab.analysis)

            GameDetailRootView()
                .tabItem {
                    Label("Kho Offset", systemImage: "cylinder.split.1x2")
                }
                .tag(AppTab.gameDetails)

            StorageView()
                .tabItem {
                    Label("Bộ nhớ", systemImage: "internaldrive")
                }
                .tag(AppTab.storage)

            SettingsView()
                .tabItem {
                    Label("Cài đặt", systemImage: "slider.horizontal.3")
                }
                .tag(AppTab.settings)
        }
        .tint(AppTheme.accent)
        .preferredColorScheme(container.themeMode.preferredColorScheme)
        .sheet(isPresented: $container.isShowingFileImporter) {
            IPADocumentPicker(
                onPickURLs: { pickedURLs in
                    container.isShowingFileImporter = false
                    DispatchQueue.main.asyncAfter(deadline: .now() + 0.15) {
                        container.handleImportedURLs(pickedURLs)
                    }
                },
                onCancel: {
                    container.isShowingFileImporter = false
                }
            )
            .ignoresSafeArea()
        }
        .sheet(
            isPresented: Binding(
                get: { pipeline.pendingExportPromptBundle != nil },
                set: { if !$0 { pipeline.pendingExportPromptBundle = nil } }
            )
        ) {
            if let bundle = pipeline.pendingExportPromptBundle {
                ExportModePromptSheet(bundle: bundle) {
                    container.openGameDetails(bundle.record)
                }
                .environmentObject(container)
            }
        }
        .alert(
            "Thông báo",
            isPresented: Binding(
                get: { container.alertMessage != nil },
                set: { if !$0 { container.alertMessage = nil } }
            )
        ) {
            Button("Đã hiểu", role: .cancel) { container.alertMessage = nil }
        } message: {
            Text(container.alertMessage ?? "")
        }
    }
}
