#pragma once
#include <cstdint>

namespace Offsets {
    // Tên binary chứa code IL2CPP của Unity trên iOS
    static constexpr const char *kTargetImage = "UnityFramework";

    // ================= VOICE / MIC (từ dump.cs) =================
    // BlockVoiceMuteController
    static constexpr uintptr_t BlockVoice_ApplyMute             = 0x51D1F78; // void (string, bool)
    static constexpr uintptr_t BlockVoice_TryStartSfuMute       = 0x51D2BE0; // bool (string)

    // GameVoiceController
    static constexpr uintptr_t GameVoice_MuteParticipant        = 0x55D11E4; // void (string, bool, Action, Action)
    static constexpr uintptr_t GameVoice_Mute                   = 0x55D1398; // void (string, bool)

    // MuteMicController
    static constexpr uintptr_t MuteMic_MuteListWhenSwitchChanel = 0x5602018; // void ()
    static constexpr uintptr_t MuteMic_MutePlayer               = 0x560267C; // void (string)

    // VoiceServiceManager
    static constexpr uintptr_t VoiceMgr_SetMuteOutputDevice     = 0x594B2AC; // void (bool)
    static constexpr uintptr_t VoiceMgr_SetMuteMemberInChannel  = 0x594B980; // void (string, bool, Action, Action)

    // VivoxVoiceService
    static constexpr uintptr_t Vivox_SetMuteMemberInChannel     = 0x5954E88; // int (string, bool)
    static constexpr uintptr_t Vivox_SetMuteOutputDevice        = 0x5955A70; // void (bool)

    // JanusVoiceService
    static constexpr uintptr_t Janus_SetMuteMemberInChannel     = 0x5962A78; // int (string, bool)

    // EosRouting & EosVoiceService
    static constexpr uintptr_t EosRouting_IsRouted              = 0x5972E58; // bool (EosRouteIntent, EosRoomKind)
    static constexpr uintptr_t Eos_SetMuteMemberInChannel       = 0x5980FE0; // int (string, bool)
    static constexpr uintptr_t Eos_SetMuteOutputDevice          = 0x5980F48; // void (bool)

    // ================= ROLE / CHỨC NĂNG (Chờ bổ sung từ dump.cs) =================
    // Thay 0x0 bằng RVA thật sau khi lọc PlayerController / Role / InGameDataAdmin
    static constexpr uintptr_t Role_CanSeeAllRoles_ThirdParty   = 0x0;
    static constexpr uintptr_t Role_CanSeeThirdParty_Villager   = 0x0;
}
