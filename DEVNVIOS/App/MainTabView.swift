import SwiftUI
import UniformTypeIdentifiers

public struct MainTabView: View {
    @EnvironmentObject private var container: AppContainer

    public init() {}

    private var supportedImportTypes: [UTType] {
        var types: [UTType] = []
        if let ipaType = UTType(filenameExtension: "ipa") {
            types.append(ipaType)
        }
        types.append(.zip)
        types.append(.archive)
        types.append(.data)
        return types
    }

    public var body: some View {
        TabView(selection: $container.selectedTab) {
            HomeView()
                .tabItem {
                    Label("Home", systemImage: "square.grid.2x2")
                }
                .tag(AppTab.home)

            AnalysisView()
                .tabItem {
                    Label("Analysis", systemImage: "waveform.path.ecg.rectangle")
                }
                .tag(AppTab.analysis)

            GameDetailRootView()
                .tabItem {
                    Label("Games", systemImage: "cylinder.split.1x2")
                }
                .tag(AppTab.gameDetails)

            StorageView()
                .tabItem {
                    Label("Storage", systemImage: "internaldrive")
                }
                .tag(AppTab.storage)

            SettingsView()
                .tabItem {
                    Label("Settings", systemImage: "slider.horizontal.3")
                }
                .tag(AppTab.settings)
        }
        .tint(AppTheme.accent)
        .preferredColorScheme(container.themeMode.preferredColorScheme)
        .fileImporter(
            isPresented: $container.isShowingFileImporter,
            allowedContentTypes: supportedImportTypes,
            allowsMultipleSelection: true
        ) { result in
            switch result {
            case .success(let urls):
                container.handleImportedURLs(urls)
            case .failure(let error):
                container.alertMessage = error.localizedDescription
            }
        }
        .alert(
            "Notice",
            isPresented: Binding(
                get: { container.alertMessage != nil },
                set: { if !$0 { container.alertMessage = nil } }
            )
        ) {
            Button("OK", role: .cancel) { container.alertMessage = nil }
        } message: {
            Text(container.alertMessage ?? "")
        }
    }
}
