import SwiftUI

@main
struct IPADumperApp: App {
    @StateObject private var viewModel = DumpViewModel()

    var body: some Scene {
        WindowGroup {
            MainTabView()
                .environmentObject(viewModel)
                .preferredColorScheme(viewModel.selectedTheme.preferredColorScheme)
                .onOpenURL { url in
                    viewModel.handleIncomingFileURL(url)
                }
        }
    }
}
