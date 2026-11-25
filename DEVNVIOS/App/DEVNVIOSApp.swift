import SwiftUI

@main
struct DEVNVIOSApp: App {
    @StateObject private var container = AppContainer()

    init() {
        AppTheme.configureGlobalAppearance()
    }

    var body: some Scene {
        WindowGroup {
            MainTabView()
                .environmentObject(container)
                .environmentObject(container.pipeline)
                .onOpenURL { url in
                    container.handleImportedURLs([url])
                }
        }
    }
}
