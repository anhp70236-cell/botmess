#import "VoiceModManager.h"
#import "Offsets.h"
#import "PatchEngine.h"

static NSString *const kKeyHearWolfThird  = @"vmod_hear_wolf_third";
static NSString *const kKeyHearDeadNight  = @"vmod_hear_dead_night";
static NSString *const kKeyThirdSeeAll    = @"vmod_third_see_all";
static NSString *const kKeyVillagerSee3rd = @"vmod_villager_see_3rd";

@interface VoiceModManager ()
@property (nonatomic, assign, readwrite) BOOL isApplied;
@end

@implementation VoiceModManager

+ (instancetype)shared {
    static VoiceModManager *instance = nil;
    static dispatch_once_t onceToken;
    dispatch_once(&onceToken, ^{
        instance = [[VoiceModManager alloc] init];
    });
    return instance;
}

- (instancetype)init {
    if ((self = [super init])) {
        NSUserDefaults *ud = [NSUserDefaults standardUserDefaults];
        _hearWolfAndThirdParty = [ud boolForKey:kKeyHearWolfThird];
        _hearDeadAtNight       = [ud boolForKey:kKeyHearDeadNight];
        _thirdPartySeeAllRoles = [ud boolForKey:kKeyThirdSeeAll];
        _villagerSeeThirdParty = [ud boolForKey:kKeyVillagerSee3rd];
    }
    return self;
}

- (void)saveSettings {
    NSUserDefaults *ud = [NSUserDefaults standardUserDefaults];
    [ud setBool:self.hearWolfAndThirdParty forKey:kKeyHearWolfThird];
    [ud setBool:self.hearDeadAtNight       forKey:kKeyHearDeadNight];
    [ud setBool:self.thirdPartySeeAllRoles forKey:kKeyThirdSeeAll];
    [ud setBool:self.villagerSeeThirdParty forKey:kKeyVillagerSee3rd];
    [ud synchronize];
}

- (void)loadAndApplySavedSettings {
    if (self.hearWolfAndThirdParty || self.hearDeadAtNight ||
        self.thirdPartySeeAllRoles || self.villagerSeeThirdParty) {
        [self applyAllBeforeMatch];
    }
}

- (BOOL)applyAllBeforeMatch {
    [self saveSettings];

    BOOL anyVoice = (self.hearWolfAndThirdParty || self.hearDeadAtNight);
    BOOL ok = YES;

    if (anyVoice) {
        // Mở routing nghe toàn kênh trên EOS
        ok &= PatchEngine::ApplyPatch(Offsets::EosRouting_IsRouted, PatchEngine::Arm64ReturnTrue());

        // Chặn tự động mute loa & mute thành viên khi chuyển pha Ngày/Đêm/Kênh
        ok &= PatchEngine::ApplyPatch(Offsets::BlockVoice_ApplyMute, PatchEngine::Arm64Ret());
        ok &= PatchEngine::ApplyPatch(Offsets::BlockVoice_TryStartSfuMute, PatchEngine::Arm64ReturnFalse());
        ok &= PatchEngine::ApplyPatch(Offsets::GameVoice_Mute, PatchEngine::Arm64Ret());
        ok &= PatchEngine::ApplyPatch(Offsets::GameVoice_MuteParticipant, PatchEngine::Arm64Ret());
        ok &= PatchEngine::ApplyPatch(Offsets::MuteMic_MuteListWhenSwitchChanel, PatchEngine::Arm64Ret());
        ok &= PatchEngine::ApplyPatch(Offsets::MuteMic_MutePlayer, PatchEngine::Arm64Ret());

        ok &= PatchEngine::ApplyPatch(Offsets::VoiceMgr_SetMuteOutputDevice, PatchEngine::Arm64Ret());
        ok &= PatchEngine::ApplyPatch(Offsets::VoiceMgr_SetMuteMemberInChannel, PatchEngine::Arm64Ret());

        ok &= PatchEngine::ApplyPatch(Offsets::Vivox_SetMuteOutputDevice, PatchEngine::Arm64Ret());
        ok &= PatchEngine::ApplyPatch(Offsets::Vivox_SetMuteMemberInChannel, PatchEngine::Arm64ReturnFalse());

        ok &= PatchEngine::ApplyPatch(Offsets::Janus_SetMuteMemberInChannel, PatchEngine::Arm64ReturnFalse());

        ok &= PatchEngine::ApplyPatch(Offsets::Eos_SetMuteOutputDevice, PatchEngine::Arm64Ret());
        ok &= PatchEngine::ApplyPatch(Offsets::Eos_SetMuteMemberInChannel, PatchEngine::Arm64ReturnFalse());
    } else {
        PatchEngine::RevertPatch(Offsets::EosRouting_IsRouted);
        PatchEngine::RevertPatch(Offsets::BlockVoice_ApplyMute);
        PatchEngine::RevertPatch(Offsets::BlockVoice_TryStartSfuMute);
        PatchEngine::RevertPatch(Offsets::GameVoice_Mute);
        PatchEngine::RevertPatch(Offsets::GameVoice_MuteParticipant);
        PatchEngine::RevertPatch(Offsets::MuteMic_MuteListWhenSwitchChanel);
        PatchEngine::RevertPatch(Offsets::MuteMic_MutePlayer);
        PatchEngine::RevertPatch(Offsets::VoiceMgr_SetMuteOutputDevice);
        PatchEngine::RevertPatch(Offsets::VoiceMgr_SetMuteMemberInChannel);
        PatchEngine::RevertPatch(Offsets::Vivox_SetMuteOutputDevice);
        PatchEngine::RevertPatch(Offsets::Vivox_SetMuteMemberInChannel);
        PatchEngine::RevertPatch(Offsets::Janus_SetMuteMemberInChannel);
        PatchEngine::RevertPatch(Offsets::Eos_SetMuteOutputDevice);
        PatchEngine::RevertPatch(Offsets::Eos_SetMuteMemberInChannel);
    }

    // Phần Role: tự động kích hoạt ngay khi điền offset thật vào Offsets.h
    if (self.thirdPartySeeAllRoles && Offsets::Role_CanSeeAllRoles_ThirdParty != 0) {
        ok &= PatchEngine::ApplyPatch(Offsets::Role_CanSeeAllRoles_ThirdParty, PatchEngine::Arm64ReturnTrue());
    } else if (Offsets::Role_CanSeeAllRoles_ThirdParty != 0) {
        PatchEngine::RevertPatch(Offsets::Role_CanSeeAllRoles_ThirdParty);
    }

    if (self.villagerSeeThirdParty && Offsets::Role_CanSeeThirdParty_Villager != 0) {
        ok &= PatchEngine::ApplyPatch(Offsets::Role_CanSeeThirdParty_Villager, PatchEngine::Arm64ReturnTrue());
    } else if (Offsets::Role_CanSeeThirdParty_Villager != 0) {
        PatchEngine::RevertPatch(Offsets::Role_CanSeeThirdParty_Villager);
    }

    self.isApplied = (anyVoice || self.thirdPartySeeAllRoles || self.villagerSeeThirdParty) && ok;
    return ok;
}

- (void)resetAll {
    self.hearWolfAndThirdParty = NO;
    self.hearDeadAtNight       = NO;
    self.thirdPartySeeAllRoles = NO;
    self.villagerSeeThirdParty = NO;
    [self saveSettings];
    PatchEngine::RevertAll();
    self.isApplied = NO;
}

- (NSString *)statusSummary {
    uintptr_t base = PatchEngine::GetImageBase(Offsets::kTargetImage);
    if (base == 0) {
        return @"Chưa tìm thấy UnityFramework";
    }
    int count = 0;
    if (self.hearWolfAndThirdParty) count++;
    if (self.hearDeadAtNight) count++;
    if (self.thirdPartySeeAllRoles) count++;
    if (self.villagerSeeThirdParty) count++;

    if (count == 0) {
        return @"Đang tắt — Bật chức năng trước khi vào trận";
    }
    return [NSString stringWithFormat:@"Đang bật %d mục • Áp dụng ngoài sảnh", count];
}

@end
