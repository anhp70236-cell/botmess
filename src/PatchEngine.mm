#import "PatchEngine.h"
#import "Offsets.h"
#import <mach/mach.h>
#import <mach-o/dyld.h>
#import <libkern/OSCacheControl.h>
#import <cstring>
#import <unordered_map>
#import <mutex>

static std::unordered_map<uintptr_t, std::vector<uint8_t>> gOriginalBytes;
static std::mutex gPatchMutex;

uintptr_t PatchEngine::GetImageBase(const char *imageName) {
    uint32_t count = _dyld_image_count();
    for (uint32_t i = 0; i < count; i++) {
        const char *name = _dyld_get_image_name(i);
        if (name && strstr(name, imageName) != nullptr) {
            return (uintptr_t)_dyld_get_image_header(i);
        }
    }
    return 0;
}

std::vector<uint8_t> PatchEngine::Arm64Ret() {
    // RET = 0xD65F03C0
    return { 0xC0, 0x03, 0x5F, 0xD6 };
}

std::vector<uint8_t> PatchEngine::Arm64ReturnTrue() {
    // MOV W0, #1 (0x52800020) ; RET (0xD65F03C0)
    return { 0x20, 0x00, 0x80, 0x52, 0xC0, 0x03, 0x5F, 0xD6 };
}

std::vector<uint8_t> PatchEngine::Arm64ReturnFalse() {
    // MOV W0, #0 (0x52800000) ; RET (0xD65F03C0)
    return { 0x00, 0x00, 0x80, 0x52, 0xC0, 0x03, 0x5F, 0xD6 };
}

static bool WriteMemoryRaw(uintptr_t address, const uint8_t *data, size_t size) {
    if (address == 0 || data == nullptr || size == 0) return false;

    mach_port_t task = mach_task_self();
    kern_return_t kr = vm_protect(task, (vm_address_t)address, (vm_size_t)size, FALSE,
                                  VM_PROT_READ | VM_PROT_WRITE | VM_PROT_COPY);
    if (kr != KERN_SUCCESS) {
        return false;
    }

    memcpy((void *)address, data, size);

    vm_protect(task, (vm_address_t)address, (vm_size_t)size, FALSE,
               VM_PROT_READ | VM_PROT_EXECUTE);
    sys_icache_invalidate((void *)address, size);
    return true;
}

bool PatchEngine::ApplyPatch(uintptr_t rva, const std::vector<uint8_t> &bytes) {
    if (rva == 0 || bytes.empty()) return false;

    std::lock_guard<std::mutex> lock(gPatchMutex);
    uintptr_t base = GetImageBase(Offsets::kTargetImage);
    if (base == 0) return false;

    uintptr_t target = base + rva;

    if (gOriginalBytes.find(rva) == gOriginalBytes.end()) {
        std::vector<uint8_t> backup(bytes.size());
        memcpy(backup.data(), (const void *)target, bytes.size());
        gOriginalBytes[rva] = std::move(backup);
    }

    return WriteMemoryRaw(target, bytes.data(), bytes.size());
}

bool PatchEngine::RevertPatch(uintptr_t rva) {
    if (rva == 0) return false;

    std::lock_guard<std::mutex> lock(gPatchMutex);
    auto it = gOriginalBytes.find(rva);
    if (it == gOriginalBytes.end()) return false;

    uintptr_t base = GetImageBase(Offsets::kTargetImage);
    if (base == 0) return false;

    uintptr_t target = base + rva;
    bool ok = WriteMemoryRaw(target, it->second.data(), it->second.size());
    if (ok) {
        gOriginalBytes.erase(it);
    }
    return ok;
}

void PatchEngine::RevertAll() {
    std::lock_guard<std::mutex> lock(gPatchMutex);
    uintptr_t base = GetImageBase(Offsets::kTargetImage);
    if (base == 0) return;

    for (const auto &kv : gOriginalBytes) {
        WriteMemoryRaw(base + kv.first, kv.second.data(), kv.second.size());
    }
    gOriginalBytes.clear();
}
