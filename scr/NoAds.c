// NoAds.c - dylib khong menu, tu dong patch quang cao khi game khoi dong.
// Build: xem .github/workflows/build.yml (macOS runner, arm64 iOS).
//
// Offset (RVA) lay tu il2cpp dump cua ban build nay (SayKit / SayGames).
// Dia chi thuc = slide cua image + RVA. Chi dung dung cho ban build da dump.
//
// Cach patch: ghi truc tiep lenh ARM64 vao dau ham (khong can thu vien hook).

#include <mach-o/dyld.h>
#include <mach/mach.h>
#include <libkern/OSCacheControl.h>
#include <dispatch/dispatch.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>
#include <unistd.h>

// Ten image chua code il2cpp. Game Unity doi moi thuong la "UnityFramework".
// Neu game cua ban nam trong file thuc thi chinh, doi thanh ten app do.
#ifndef TARGET_IMAGE
#define TARGET_IMAGE "UnityFramework"
#endif

// ----- Lenh ARM64 -----
#define I_RET         0xD65F03C0u  // ret
#define I_MOV_W0_0    0x52800000u  // mov w0, #0   (return false / 0)
#define I_MOV_W0_1    0x52800020u  // mov w0, #1   (return true)
#define I_FMOV_S0_WZR 0x1E2703E0u  // fmov s0, wzr (return 0.0f)

typedef struct {
    uintptr_t   rva;
    uint32_t    ins[2];
    int         n;      // so lenh (1 hoac 2), toi da 8 byte
    const char *name;
} Patch;

static const Patch kPatches[] = {
    // ===== Co "da mua No Ads" =====
    { 0x3A7A434, { I_MOV_W0_1, I_RET }, 2, "Advertisement.IsPremium" },
    { 0x3A79510, { I_MOV_W0_1, I_RET }, 2, "Advertisement.IsPremiumBySdk" },
    { 0x5D79AA4, { I_MOV_W0_1, I_RET }, 2, "SKManager.get_IsPremium" },

    // ===== Interstitial: bao "khong co quang cao" =====
    { 0x3A79A08, { I_MOV_W0_0, I_RET }, 2, "Advertisement.IsInterstitialAvailable(InterPlacement,int)" },
    { 0x3A79B9C, { I_MOV_W0_0, I_RET }, 2, "Advertisement.IsInterstitialAvailable(string,int)" },
    { 0x5D2A528, { I_MOV_W0_0, I_RET }, 2, "AdsManager.IsInterstitialAvailable" },
    { 0x5D7A008, { I_MOV_W0_0, I_RET }, 2, "SKManager.IsInterstitialAvailable" },

    // ===== Banner: khong hien, khong chiem cho =====
    { 0x3A7A4F0, { I_RET },             1, "Advertisement.RequestShowBanner" },
    { 0x5D2A058, { I_RET },             1, "AdsManager.ShowBanner" },
    { 0x5D79CFC, { I_RET },             1, "SKManager.ShowBanner" },
    { 0x5D81A44, { I_MOV_W0_0, I_RET }, 2, "SKManager.IsBannerEnabled" },
    { 0x3A7A500, { I_FMOV_S0_WZR, I_RET }, 2, "Advertisement.GetBackgroundBannerSize" },
};

#define LOG(...) do { fprintf(stderr, "[NoAds] " __VA_ARGS__); fputc('\n', stderr); } while (0)

static int find_image(const char *needle, intptr_t *slide_out) {
    uint32_t count = _dyld_image_count();
    for (uint32_t i = 0; i < count; i++) {
        const char *name = _dyld_get_image_name(i);
        if (!name) continue;
        const char *base = strrchr(name, '/');
        base = base ? base + 1 : name;
        if (strcmp(base, needle) == 0) {
            *slide_out = _dyld_get_image_vmaddr_slide(i);
            return 1;
        }
    }
    return 0;
}

static int write_code(uintptr_t addr, const uint32_t *ins, int n) {
    size_t len = (size_t)n * 4;
    uintptr_t pg = (uintptr_t)vm_page_size;
    uintptr_t start = addr & ~(pg - 1);
    uintptr_t end = (addr + len + pg - 1) & ~(pg - 1);

    kern_return_t kr = vm_protect(mach_task_self(), (vm_address_t)start,
                                  (vm_size_t)(end - start), FALSE,
                                  VM_PROT_READ | VM_PROT_WRITE | VM_PROT_COPY);
    if (kr != KERN_SUCCESS) return 0;

    // Ghi lenh sau truoc, lenh dau sau cung de tranh chay nua chung.
    for (int i = n - 1; i >= 0; i--) {
        memcpy((void *)(addr + (size_t)i * 4), &ins[i], 4);
    }
    sys_icache_invalidate((void *)addr, len);

    vm_protect(mach_task_self(), (vm_address_t)start,
               (vm_size_t)(end - start), FALSE,
               VM_PROT_READ | VM_PROT_EXECUTE);
    return 1;
}

static void apply_patches(intptr_t slide) {
    int ok = 0, total = (int)(sizeof(kPatches) / sizeof(kPatches[0]));
    for (int i = 0; i < total; i++) {
        const Patch *p = &kPatches[i];
        uintptr_t addr = (uintptr_t)slide + p->rva;
        if (write_code(addr, p->ins, p->n)) {
            ok++;
        } else {
            LOG("FAIL  0x%lX %s", (unsigned long)p->rva, p->name);
        }
    }
    LOG("da patch %d/%d ham", ok, total);
}

static void worker(void) {
    // Cho UnityFramework duoc nap (toi da ~60 giay).
    for (int tries = 0; tries < 120; tries++) {
        intptr_t slide = 0;
        if (find_image(TARGET_IMAGE, &slide)) {
            usleep(500 * 1000);  // cho image on dinh truoc khi ghi
            apply_patches(slide);
            return;
        }
        usleep(500 * 1000);
    }
    LOG("khong tim thay image '%s'", TARGET_IMAGE);
}

__attribute__((constructor))
static void NoAds_init(void) {
    dispatch_async(dispatch_get_global_queue(QOS_CLASS_USER_INITIATED, 0), ^{
        worker();
    });
}
