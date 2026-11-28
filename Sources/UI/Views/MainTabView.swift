import SwiftUI

struct MainTabView: View {
    @EnvironmentObject var vm: DumpViewModel

    var body: some View {
        let theme = vm.selectedTheme
        TabView {
            AnalyzerView()
                .tabItem {
                    Label("Phân tích", systemImage: "cpu")
                }
            ExplorerView()
                .tabItem {
                    Label("Trình duyệt", systemImage: "magnifyingglass.circle")
                }
            OutputsView()
                .tabItem {
                    Label("Tệp xuất", systemImage: "folder.badge.gearshape")
                }
            SettingsView()
                .tabItem {
                    Label("Cài đặt", systemImage: "slider.horizontal.3")
                }
        }
        .accentColor(theme.accent)
    }
}
