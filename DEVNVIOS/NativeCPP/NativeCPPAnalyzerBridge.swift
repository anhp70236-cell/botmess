import Foundation

public struct NativeCPPFunctionInsight: Sendable, Equatable {
    public let functionVA: UInt64
    public let functionRVA: UInt64
    public let instructionCount: UInt32
    public let incomingCalls: UInt32
    public let outgoingCalls: UInt32
    public let primaryFieldOffset: UInt32
    public let hasValidPrologue: Bool
    public let isGetterIdiom: Bool
    public let isSetterIdiom: Bool
    public let prologueHex: String
}

public struct NativeCPPSwiftTypeRecord: Sendable, Equatable {
    public let typeName: String
    public let kindLabel: String
    public let accessorVA: UInt64
    public let accessorRVA: UInt64
    public let descriptorFileOffset: UInt64
}

public struct NativeCPPDeepScanResult: Sendable {
    public let totalInstructionsScanned: Int
    public let discoveredProloguesCount: Int
    public let getterIdiomsCount: Int
    public let setterIdiomsCount: Int
    public let directBLCallsCount: Int
    public let metricsByFunctionVA: [UInt64: NativeCPPFunctionInsight]
    public let leafAccessors: [NativeCPPFunctionInsight]
    public let swiftNominalTypes: [NativeCPPSwiftTypeRecord]
}

/// Swift bridge over the C++17 `NativeBinaryScanner` engine (`dnv_analyze_arm64_text` & `dnv_scan_swift5_types`).
public enum NativeCPPAnalyzerBridge {
    public static func runDeepScan(
        sliceData: Data,
        machO: ParsedMachOImage
    ) -> NativeCPPDeepScanResult {
        guard !machO.encryptionInfo.isEncrypted else {
            return NativeCPPDeepScanResult(
                totalInstructionsScanned: 0,
                discoveredProloguesCount: 0,
                getterIdiomsCount: 0,
                setterIdiomsCount: 0,
                directBLCallsCount: 0,
                metricsByFunctionVA: [:],
                leafAccessors: [],
                swiftNominalTypes: []
            )
        }

        var summary = DNVScanSummary()
        var metricsByVA: [UInt64: NativeCPPFunctionInsight] = [:]
        var leafAccessors: [NativeCPPFunctionInsight] = []

        if machO.isARM64,
           let textSec = machO.section(forSectionName: "__text", inSegment: "__TEXT"),
           textSec.size >= 4,
           Int(textSec.fileOffset) + Int(textSec.size) <= sliceData.count {

            let maxMetrics = 35_000
            var rawMetrics = [DNVFunctionMetric](repeating: DNVFunctionMetric(), count: maxMetrics)
            let knownRVAs = machO.functionStartRVAs

            let count = sliceData.withUnsafeBytes { sliceRaw -> Int in
                guard let sliceBase = sliceRaw.bindMemory(to: UInt8.self).baseAddress else { return 0 }
                let textPtr = sliceBase.advanced(by: Int(textSec.fileOffset))
                return knownRVAs.withUnsafeBufferPointer { rvaBuf in
                    rawMetrics.withUnsafeMutableBufferPointer { outBuf in
                        dnv_analyze_arm64_text(
                            textPtr,
                            Int(min(textSec.size, 32 * 1024 * 1024)),
                            textSec.address,
                            machO.defaultBaseAddress,
                            rvaBuf.baseAddress,
                            rvaBuf.count,
                            outBuf.baseAddress,
                            maxMetrics,
                            &summary
                        )
                    }
                }
            }

            metricsByVA.reserveCapacity(count)
            for i in 0..<count {
                let m = rawMetrics[i]
                let pHex = withUnsafeBytes(of: m.prologueBytes) { bytes in
                    bytes.prefix(12).map { String(format: "%02X", $0) }.joined(separator: " ")
                }
                let insight = NativeCPPFunctionInsight(
                    functionVA: m.functionVA,
                    functionRVA: m.functionRVA,
                    instructionCount: m.instructionCount,
                    incomingCalls: m.incomingCalls,
                    outgoingCalls: m.outgoingCalls,
                    primaryFieldOffset: m.primaryFieldOffset,
                    hasValidPrologue: m.hasValidPrologue != 0,
                    isGetterIdiom: m.isGetterIdiom != 0,
                    isSetterIdiom: m.isSetterIdiom != 0,
                    prologueHex: pHex
                )
                metricsByVA[m.functionVA] = insight
                if insight.isGetterIdiom || insight.isSetterIdiom {
                    leafAccessors.append(insight)
                }
            }
        }

        // Scan Swift 5 Nominal Type Descriptors (__TEXT,__swift5_types) so every native Swift IPA yields real types & RVAs
        var swiftTypes: [NativeCPPSwiftTypeRecord] = []
        if let swiftSec = machO.section(forSectionName: "__swift5_types", inSegment: "__TEXT"),
           swiftSec.size >= 4 {
            let textSec = machO.section(forSectionName: "__text", inSegment: "__TEXT")
            let textStart = textSec?.address ?? machO.defaultBaseAddress
            let textEnd = textStart &+ (textSec?.size ?? 0x1000_0000)

            let maxTypes = 6000
            var rawTypes = [DNVSwiftNominalType](repeating: DNVSwiftNominalType(), count: maxTypes)

            let foundTypes = sliceData.withUnsafeBytes { sliceRaw -> Int in
                guard let sliceBase = sliceRaw.bindMemory(to: UInt8.self).baseAddress else { return 0 }
                return rawTypes.withUnsafeMutableBufferPointer { outBuf in
                    dnv_scan_swift5_types(
                        sliceBase,
                        sliceData.count,
                        UInt64(swiftSec.fileOffset),
                        swiftSec.size,
                        swiftSec.address,
                        textStart,
                        textEnd,
                        machO.defaultBaseAddress,
                        outBuf.baseAddress,
                        maxTypes
                    )
                }
            }

            for i in 0..<foundTypes {
                var rec = rawTypes[i]
                let name = withUnsafePointer(to: &rec.typeName) { ptr -> String in
                    ptr.withMemoryRebound(to: CChar.self, capacity: 160) { cPtr in
                        String(cString: cPtr)
                    }
                }
                guard !name.isEmpty, SafetyPolicyFilter.isPermittedIdentifier(name) else { continue }
                let kindLabel: String = {
                    switch rec.kind {
                    case 1: return "SwiftClass"
                    case 2: return "SwiftStruct"
                    case 3: return "SwiftEnum"
                    default: return "SwiftType"
                    }
                }()
                swiftTypes.append(
                    NativeCPPSwiftTypeRecord(
                        typeName: name,
                        kindLabel: kindLabel,
                        accessorVA: rec.accessorFunctionVA,
                        accessorRVA: rec.accessorFunctionRVA,
                        descriptorFileOffset: UInt64(rec.descriptorFileOffset)
                    )
                )
            }
        }

        return NativeCPPDeepScanResult(
            totalInstructionsScanned: Int(summary.totalInstructionsScanned),
            discoveredProloguesCount: Int(summary.discoveredProloguesCount),
            getterIdiomsCount: Int(summary.getterIdiomsCount),
            setterIdiomsCount: Int(summary.setterIdiomsCount),
            directBLCallsCount: Int(summary.directBLCallsCount),
            metricsByFunctionVA: metricsByVA,
            leafAccessors: leafAccessors,
            swiftNominalTypes: swiftTypes
        )
    }
}
