import SwiftUI

struct MainTabView: View {
    @EnvironmentObject var vm: DumpViewModel

    var body: some View {
        let theme = vm.selectedTheme
        TabView(selection: $vm.activeTab) {
            AnalyzerView()
                .tabItem {
                    Label("Phân tích", systemImage: "cpu")
                }
                .tag(0)
            ExplorerView()
                .tabItem {
                    Label("Trình duyệt", systemImage: "magnifyingglass.circle")
                }
                .tag(1)
            OutputsView()
                .tabItem {
                    Label("Tệp xuất", systemImage: "folder.badge.gearshape")
                }
                .tag(2)
            SettingsView()
                .tabItem {
                    Label("Cài đặt", systemImage: "slider.horizontal.3")
                }
                .tag(3)
        }
        .accentColor(theme.accent)
    }
}
