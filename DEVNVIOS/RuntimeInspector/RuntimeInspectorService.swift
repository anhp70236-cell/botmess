import Foundation
import MachO

public struct AuthorizedRuntimeSymbolInput: Codable, Sendable {
    public let name: String
    public let moduleName: String
    public let runtimeAddressHex: String

    public init(name: String, moduleName: String, runtimeAddressHex: String) {
        self.name = name
        self.moduleName = moduleName
        self.runtimeAddressHex = runtimeAddressHex
    }
}

public struct AuthorizedRuntimeTracePayload: Codable, Sendable {
    public let moduleName: String
    public let aslrSlideHex: String
    public let symbols: [AuthorizedRuntimeSymbolInput]

    public init(moduleName: String, aslrSlideHex: String, symbols: [AuthorizedRuntimeSymbolInput]) {
        self.moduleName = moduleName
        self.aslrSlideHex = aslrSlideHex
        self.symbols = symbols
    }
}

/// Provides sandbox-compliant runtime inspection for authorized research targets:
/// 1. Live in-process Mach-O dyld inspection (`_dyld_image_count`, ASLR slide calculation, static VA verification).
/// 2. Dynamic address mapping from authorized test/debug runtime trace snapshots (`Runtime VA - Slide -> Static VA -> RVA -> FileOffset`).
/// Does NOT inject into third-party processes or bypass iOS sandbox/DRM protections.
public final class RuntimeInspectorService: Sendable {
    public init() {}

    public func inspectCurrentProcessModules(limit: Int = 25) -> [RuntimeLoadedModule] {
        let count = _dyld_image_count()
        var modules: [RuntimeLoadedModule] = []
        modules.reserveCapacity(min(Int(count), limit))

        for idx in 0..<count {
            guard let cName = _dyld_get_image_name(idx),
                  let headerPtr = _dyld_get_image_header(idx) else {
                continue
            }
            let fullPath = String(cString: cName)
            let imageName = (fullPath as NSString).lastPathComponent
            let runtimeBase = UInt64(UInt(bitPattern: headerPtr))
            let slide = Int64(_dyld_get_image_vmaddr_slide(idx))
            let staticBase: UInt64 = {
                let signedRuntime = Int64(bitPattern: runtimeBase)
                return UInt64(bitPattern: signedRuntime &- slide)
            }()
            let cpu = UInt32(bitPattern: headerPtr.pointee.cputype)
            let isArm64 = (cpu == MachOConstants.cpuTypeARM64 || cpu == MachOConstants.cpuTypeARM64_32)

            modules.append(
                RuntimeLoadedModule(
                    imageName: imageName,
                    fullPath: fullPath,
                    runtimeBaseAddress: runtimeBase,
                    aslrSlide: slide,
                    staticBaseAddress: staticBase,
                    isARM64: isArm64
                )
            )
            if modules.count >= limit {
                break
            }
        }
        return modules
    }

    public func buildRuntimeSnapshot(
        machO: ParsedMachOImage,
        discoveredOffsets: [DiscoveredOffset],
        authorizedTrace: AuthorizedRuntimeTracePayload? = nil
    ) -> RuntimeSnapshot {
        let dyldModules = inspectCurrentProcessModules(limit: 16)
        let activeSlide: UInt64 = {
            if let trace = authorizedTrace, let parsed = HexFormatters.parseHex(trace.aslrSlideHex) {
                return parsed
            }
            if let firstSlide = dyldModules.first?.aslrSlide, firstSlide > 0 {
                return UInt64(firstSlide)
            }
            return 0x0000_0000_0420_0000 // Standard representative iOS page-aligned ASLR slide for preview mapping
        }()

        var mappedSymbols: [RuntimeMappedSymbol] = []
        var notes: [String] = []

        if let trace = authorizedTrace {
            notes.append("Applied authorized runtime trace payload for module '\(trace.moduleName)' (slide \(HexFormatters.hex(activeSlide))).")
            for item in trace.symbols {
                guard SafetyPolicyFilter.isPermittedIdentifier(item.name),
                      let runtimeAddr = HexFormatters.parseHex(item.runtimeAddressHex),
                      runtimeAddr >= activeSlide else {
                    continue
                }
                let staticVA = runtimeAddr - activeSlide
                let rva = machO.rva(forVirtualAddress: staticVA)
                let fileOff = machO.virtualAddressToSliceFileOffset(staticVA)
                let category = OffsetCategoryClassifier.classify(identifier: item.name) ?? .gameplayVariables

                mappedSymbols.append(
                    RuntimeMappedSymbol(
                        symbolName: item.name,
                        moduleName: item.moduleName,
                        runtimeAddress: runtimeAddr,
                        aslrSlide: activeSlide,
                        resolvedStaticVA: staticVA,
                        resolvedRVA: rva,
                        resolvedFileOffset: fileOff,
                        category: category
                    )
                )
            }
        } else {
            // Map top verified static offsets with live ASLR slide equation: RuntimeVA = StaticVA + ASLRSlide
            for offset in discoveredOffsets where offset.virtualAddress > 0 && mappedSymbols.count < 30 {
                let runtimeVA = offset.virtualAddress &+ activeSlide
                mappedSymbols.append(
                    RuntimeMappedSymbol(
                        symbolName: offset.name,
                        moduleName: offset.moduleName,
                        runtimeAddress: runtimeVA,
                        aslrSlide: activeSlide,
                        resolvedStaticVA: offset.virtualAddress,
                        resolvedRVA: offset.rva,
                        resolvedFileOffset: offset.fileOffset,
                        category: offset.category
                    )
                )
            }
            notes.append("Computed ASLR-aware dynamic address table using formula RuntimeVA = StaticVA + Slide (\(HexFormatters.hex(activeSlide))).")
        }

        let requiresHarness = machO.encryptionInfo.isEncrypted
        if requiresHarness {
            notes.append("Binary has FairPlay encrypted pages (cryptid=\(machO.encryptionInfo.cryptID)). Static decryption is intentionally not performed; import an authorized debug/test runtime trace JSON to resolve runtime-only addresses.")
        }

        return RuntimeSnapshot(
            targetModuleName: machO.moduleName,
            observationMode: authorizedTrace != nil ? "Authorized Runtime Trace Mapping" : "ASLR Dynamic Address Mapping + Host Dyld Probe",
            requiresAuthorizedRuntimeHarness: requiresHarness,
            sampleASLRSlide: activeSlide,
            mappedRuntimeSymbols: mappedSymbols,
            hostDyldModulesSample: dyldModules,
            notes: notes
        )
    }

    public func parseAuthorizedTraceJSON(_ data: Data) throws -> AuthorizedRuntimeTracePayload {
        do {
            return try JSONDecoder().decode(AuthorizedRuntimeTracePayload.self, from: data)
        } catch {
            throw AnalysisError.runtimeSnapshotInvalid(error.localizedDescription)
        }
    }
}
