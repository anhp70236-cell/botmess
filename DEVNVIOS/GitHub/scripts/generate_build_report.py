#!/usr/bin/env python3
import hashlib
import json
import os
import re
import subprocess
from datetime import datetime, timezone

ipa_path = "build/Artifacts/DEVNVIOS-IPA-Analyzer.ipa"
test_log_path = "build/Reports/xcodebuild-test.log"
report_json_path = "build/Artifacts/build-report.json"
report_md_path = "build/Artifacts/build-report.md"
test_report_json_path = "build/Artifacts/test-report.json"

def sha256_file(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        while True:
            chunk = f.read(1024 * 1024)
            if not chunk:
                break
            h.update(chunk)
    return h.hexdigest()

xcode_ver = subprocess.check_output(["xcodebuild", "-version"], text=True).strip().splitlines()
swift_ver = subprocess.check_output(["swift", "--version"], text=True).strip().splitlines()[0]

ipa_exists = os.path.isfile(ipa_path)
ipa_size = os.path.getsize(ipa_path) if ipa_exists else 0
ipa_sha256 = sha256_file(ipa_path) if ipa_exists else ""

executed_tests = []
passed_count = 0
failed_count = 0

if os.path.isfile(test_log_path):
    with open(test_log_path, "r", encoding="utf-8", errors="replace") as f:
        for line in f:
            m = re.search(r"Test Case '-\[(\S+)\s+(\S+)\]' (passed|failed) \(([\d.]+) seconds\)", line)
            if m:
                suite, name, status, secs = m.group(1), m.group(2), m.group(3), float(m.group(4))
                executed_tests.append({
                    "suite": suite,
                    "test": name,
                    "status": status,
                    "durationSeconds": secs
                })
                if status == "passed":
                    passed_count += 1
                else:
                    failed_count += 1

now_iso = datetime.now(timezone.utc).isoformat()

test_report = {
    "generatedAt": now_iso,
    "totalExecuted": len(executed_tests),
    "passed": passed_count,
    "failed": failed_count,
    "allPassed": failed_count == 0 and passed_count > 0,
    "testCases": executed_tests
}

build_report = {
    "application": "DEVNVIOS IPA ANALYZER",
    "bundleIdentifier": "com.devnvios.ipaanalyzer",
    "version": "2.0.0",
    "generatedAt": now_iso,
    "gitCommit": os.environ.get("GITHUB_SHA", "local"),
    "gitRunId": os.environ.get("GITHUB_RUN_ID", "local"),
    "xcodeVersion": xcode_ver,
    "swiftVersion": swift_ver,
    "artifact": {
        "fileName": "DEVNVIOS-IPA-Analyzer.ipa",
        "sizeBytes": ipa_size,
        "sha256": ipa_sha256,
        "architecture": "arm64 (iphoneos)",
        "verified": ipa_exists and ipa_size > 0
    },
    "testSummary": {
        "total": len(executed_tests),
        "passed": passed_count,
        "failed": failed_count
    }
}

with open(test_report_json_path, "w", encoding="utf-8") as f:
    json.dump(test_report, f, indent=2)

with open(report_json_path, "w", encoding="utf-8") as f:
    json.dump(build_report, f, indent=2)

md_lines = [
    "# DEVNVIOS IPA ANALYZER — Build & Test Report",
    "",
    f"- **Commit**: `{build_report['gitCommit']}`",
    f"- **Xcode**: `{' / '.join(xcode_ver)}`",
    f"- **Swift**: `{swift_ver}`",
    f"- **IPA Artifact**: `DEVNVIOS-IPA-Analyzer.ipa` ({ipa_size:,} bytes)",
    f"- **SHA-256**: `{ipa_sha256}`",
    f"- **Unit & Integration Tests**: **{passed_count} passed**, **{failed_count} failed**",
    "",
    "## Executed Test Cases",
    "",
    "| Test Case | Status | Duration |",
    "| --- | --- | --- |"
]
for t in executed_tests:
    md_lines.append(f"| `{t['test']}` | {t['status'].upper()} | {t['durationSeconds']:.3f}s |")

md_content = "\n".join(md_lines) + "\n"
with open(report_md_path, "w", encoding="utf-8") as f:
    f.write(md_content)

summary_env = os.environ.get("GITHUB_STEP_SUMMARY")
if summary_env:
    with open(summary_env, "a", encoding="utf-8") as f:
        f.write(md_content)

print("Generated build-report.json, test-report.json, and build-report.md successfully.")
