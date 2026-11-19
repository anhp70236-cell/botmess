#pragma once
#include <cstdint>
#include <vector>

class PatchEngine {
public:
    static uintptr_t GetImageBase(const char *imageName);
    static bool ApplyPatch(uintptr_t rva, const std::vector<uint8_t> &bytes);
    static bool RevertPatch(uintptr_t rva);
    static void RevertAll();

    // ARM64 machine code helpers
    static std::vector<uint8_t> Arm64Ret();          // ret
    static std::vector<uint8_t> Arm64ReturnTrue();   // mov w0, #1; ret
    static std::vector<uint8_t> Arm64ReturnFalse();  // mov w0, #0; ret
};
