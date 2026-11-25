# DEVNVIOS IPA ANALYZER

Advanced iOS Mach-O, ARM64 Instruction, and Unity IL2CPP Offset Analyzer built natively in Swift & SwiftUI.

## Architecture (`DEVNVIOS/`)

- `App/` — Application lifecycle, global Dark Mode appearance, URL/Files import routing, and dependency container.
- `Core/` — Streaming PKZIP/ZIP64 + `zlib` archive reader/writer, SHA-256 file hashing, structured logger, and research compliance filters.
- `MachO/` — Thin & Universal/Fat 64-bit Mach-O parser (`LC_SEGMENT_64`, `LC_SYMTAB`, `LC_FUNCTION_STARTS`, `LC_ENCRYPTION_INFO_64`, `LC_UUID`, `VA <-> RVA <-> FileOffset`).
- `BinaryAnalyzer/` — Bounds-checked `BinaryCursor`, ARM64 A64 instruction decoder (`ADRP`, `ADD`, `LDR`, function prologues), and static string/ObjC cross-reference analyzer.
- `IL2CPP/` — Unity `global-metadata.dat` (v24–v31+) parser for namespaces, classes, methods, fields, and string literals, plus encrypted/truncated metadata detection.
- `Services/` — Multi-IPA streaming importer, 9-stage `AdvancedOffsetDiscoveryEngine`, and `@MainActor` live `AnalysisPipelineCoordinator`.
- `RuntimeInspector/` — In-process `dyld` module probe and ASLR-aware dynamic runtime trace mapper.
- `OffsetDatabase/` — Per-game isolated JSON storage (`<GameName>/{metadata,offsets,symbols,runtime,report,history}.json`) with search, filtering, and disk index recovery.
- `StorageManager/` — Workspace storage quota manager (minimum 3 GB floor), cache cleaner, and selective IPA removal preserving discovered offsets.
- `Export/` — JSON & Markdown report exporter.
- `UI/` — Matte obsidian Dark Mode iOS interface (`Home`, `Analysis`, `Game Details`, `Storage`, `Settings`).
- `Resources/` — `Info.plist`, `LaunchScreen.storyboard`, and `Assets.xcassets` (`AppIcon` & `BrandLogo`).
- `Tests/` — Comprehensive `XCTest` binary & pipeline verification suite.
- `GitHub/` — Automated CI/CD verification, test runner, IPA packager, and report generator scripts.

## Compliance & Research Scope

- Designed strictly for analyzing applications and games the user is authorized to research.
- Does **not** bypass FairPlay DRM, encryption, anti-cheat, or third-party application protections.
- Excludes all financial, payment, billing, StoreKit receipt, and wallet-related identifiers.
