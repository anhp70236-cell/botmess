#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
patch_unityframework.py
Patch TĨNH file UnityFramework trong IPA (không cần JIT, không cần dylib).
Dùng đúng 14 offset voice trong Offsets.h (lấy từ dump.cs).

Cách dùng:
    python3 patch_unityframework.py game.ipa game_patched.ipa
    python3 patch_unityframework.py game.ipa game_patched.ipa --dry-run
    python3 patch_unityframework.py game.ipa game_patched.ipa --skip EosRouting_IsRouted,Eos_SetMuteMemberInChannel
    python3 patch_unityframework.py --list

Sau đó ký lại game_patched.ipa bằng ESign (KHÔNG tiêm VoiceMod.dylib nữa).
IPA phải là bản đã giải mã (cryptid = 0).
"""
import argparse
import struct
import sys
import zipfile

RET   = bytes([0xC0, 0x03, 0x5F, 0xD6])                                # ret
TRUE  = bytes([0x20, 0x00, 0x80, 0x52, 0xC0, 0x03, 0x5F, 0xD6])        # mov w0,#1 ; ret
FALSE = bytes([0x00, 0x00, 0x80, 0x52, 0xC0, 0x03, 0x5F, 0xD6])        # mov w0,#0 ; ret

# (tên, RVA, bytes ghi đè, mô tả) — giống hệt VoiceModManager.mm + Offsets.h
PATCHES = [
    ("EosRouting_IsRouted",              0x5972E58, TRUE,  "bool IsRouted(EosRouteIntent, EosRoomKind)"),
    ("BlockVoice_ApplyMute",             0x51D1F78, RET,   "void ApplyMute(string, bool)"),
    ("BlockVoice_TryStartSfuMute",       0x51D2BE0, FALSE, "bool TryStartSfuMute(string)"),
    ("GameVoice_Mute",                   0x55D1398, RET,   "void Mute(string, bool)"),
    ("GameVoice_MuteParticipant",        0x55D11E4, RET,   "void MuteParticipant(string, bool, Action, Action)"),
    ("MuteMic_MuteListWhenSwitchChanel", 0x5602018, RET,   "void MuteListWhenSwitchChanel()"),
    ("MuteMic_MutePlayer",               0x560267C, RET,   "void MutePlayer(string)"),
    ("VoiceMgr_SetMuteOutputDevice",     0x594B2AC, RET,   "void SetMuteOutputDevice(bool)"),
    ("VoiceMgr_SetMuteMemberInChannel",  0x594B980, RET,   "void SetMuteMemberInChannel(string, bool, Action, Action)"),
    ("Vivox_SetMuteOutputDevice",        0x5955A70, RET,   "void SetMuteOutputDevice(bool)"),
    ("Vivox_SetMuteMemberInChannel",     0x5954E88, FALSE, "int SetMuteMemberInChannel(string, bool)"),
    ("Janus_SetMuteMemberInChannel",     0x5962A78, FALSE, "int SetMuteMemberInChannel(string, bool)"),
    ("Eos_SetMuteOutputDevice",          0x5980F48, RET,   "void SetMuteOutputDevice(bool)"),
    ("Eos_SetMuteMemberInChannel",       0x5980FE0, FALSE, "int SetMuteMemberInChannel(string, bool)"),
]

MH_MAGIC_64 = 0xFEEDFACF
FAT_MAGIC = 0xCAFEBABE
CPU_TYPE_ARM64 = 0x0100000C
LC_SEGMENT_64 = 0x19
LC_ENCRYPTION_INFO_64 = 0x2C


def hx(b):
    return " ".join("%02X" % x for x in b)


def find_arm64_slice(data):
    """Trả về (offset_slice, size_slice) của slice arm64."""
    magic = struct.unpack_from("<I", data, 0)[0]
    if magic == MH_MAGIC_64:
        return 0, len(data)
    if struct.unpack_from(">I", data, 0)[0] == FAT_MAGIC:
        n = struct.unpack_from(">I", data, 4)[0]
        for i in range(n):
            cputype, _sub, off, size, _al = struct.unpack_from(">iiIII", data, 8 + i * 20)
            if cputype & 0xFFFFFFFF == CPU_TYPE_ARM64:
                return off, size
        raise SystemExit("Không thấy slice arm64 trong file fat.")
    raise SystemExit("Không phải Mach-O 64-bit arm64 hợp lệ.")


def parse_macho(data, base):
    """Đọc segments + encryption info của slice bắt đầu tại base."""
    magic, cputype, _sub, _ft, ncmds, _sz, _fl, _res = struct.unpack_from("<IiiIIIII", data, base)
    if magic != MH_MAGIC_64 or (cputype & 0xFFFFFFFF) != CPU_TYPE_ARM64:
        raise SystemExit("Slice không phải arm64 64-bit.")
    segs = []
    crypt = None
    off = base + 32
    for _ in range(ncmds):
        cmd, cmdsize = struct.unpack_from("<II", data, off)
        if cmd == LC_SEGMENT_64:
            segname = data[off + 8: off + 24].split(b"\0", 1)[0].decode("ascii", "replace")
            vmaddr, vmsize, fileoff, filesize = struct.unpack_from("<QQQQ", data, off + 24)
            segs.append((segname, vmaddr, vmsize, fileoff, filesize))
        elif cmd == LC_ENCRYPTION_INFO_64:
            cryptoff, cryptsize, cryptid = struct.unpack_from("<III", data, off + 8)
            crypt = (cryptoff, cryptsize, cryptid)
        off += cmdsize
    return segs, crypt


def rva_to_fileoff(rva, segs, base):
    text = next((s for s in segs if s[0] == "__TEXT"), None)
    if text is None:
        raise SystemExit("Không thấy segment __TEXT.")
    va = text[1] + rva
    for name, vmaddr, vmsize, fileoff, filesize in segs:
        if vmaddr <= va < vmaddr + min(vmsize, filesize if filesize else vmsize):
            return base + fileoff + (va - vmaddr), name
    return None, None


def patch_binary(data, patches, dry_run=False):
    """Patch trực tiếp bytearray. Trả về (số patch ok, log lines, lỗi)."""
    base, _size = find_arm64_slice(data)
    segs, crypt = parse_macho(data, base)
    if crypt and crypt[2] != 0:
        raise SystemExit("File đang bị mã hoá (cryptid=%d). Cần IPA đã giải mã." % crypt[2])

    log, ok, bad = [], 0, 0
    for name, rva, new, desc in patches:
        foff, seg = rva_to_fileoff(rva, segs, base)
        if foff is None:
            log.append("[LỖI ] %-34s RVA 0x%X không nằm trong segment nào" % (name, rva))
            bad += 1
            continue
        if seg != "__TEXT":
            log.append("[LỖI ] %-34s RVA 0x%X nằm ở %s, không phải code" % (name, rva, seg))
            bad += 1
            continue
        if rva % 4 != 0:
            log.append("[LỖI ] %-34s RVA 0x%X không căn 4 byte" % (name, rva))
            bad += 1
            continue
        old = bytes(data[foff: foff + len(new)])
        if len(old) != len(new) or old[:4] == b"\0\0\0\0":
            log.append("[LỖI ] %-34s byte gốc rỗng/sai (%s) -> offset có thể lệch bản game" % (name, hx(old)))
            bad += 1
            continue
        if old == new:
            log.append("[BỎ  ] %-34s đã patch từ trước" % name)
            continue
        log.append("[OK   ] %-34s file+0x%X  %s  ->  %s" % (name, foff, hx(old), hx(new)))
        if not dry_run:
            data[foff: foff + len(new)] = new
        ok += 1
    return ok, log, bad


def main():
    ap = argparse.ArgumentParser(description="Patch tĩnh UnityFramework trong IPA (voice)")
    ap.add_argument("input", nargs="?", help="IPA (hoặc file UnityFramework) gốc")
    ap.add_argument("output", nargs="?", help="File xuất ra")
    ap.add_argument("--skip", default="", help="Tên patch bỏ qua, cách nhau bằng dấu phẩy")
    ap.add_argument("--dry-run", action="store_true", help="Chỉ kiểm tra, không ghi file")
    ap.add_argument("--list", action="store_true", help="Liệt kê các patch")
    args = ap.parse_args()

    if args.list:
        for name, rva, new, desc in PATCHES:
            print("%-34s 0x%X  %-20s %s" % (name, rva, hx(new), desc))
        return
    if not args.input or (not args.output and not args.dry_run):
        ap.print_help()
        sys.exit(1)

    skip = {s.strip() for s in args.skip.split(",") if s.strip()}
    unknown = skip - {p[0] for p in PATCHES}
    if unknown:
        raise SystemExit("Tên --skip không tồn tại: " + ", ".join(sorted(unknown)))
    patches = [p for p in PATCHES if p[0] not in skip]

    is_zip = zipfile.is_zipfile(args.input)
    if is_zip:
        zin = zipfile.ZipFile(args.input)
        target = None
        for info in zin.infolist():
            n = info.filename
            if n.startswith("Payload/") and n.endswith("/Frameworks/UnityFramework.framework/UnityFramework"):
                target = info
                break
        if target is None:
            raise SystemExit("Không thấy Frameworks/UnityFramework.framework/UnityFramework trong IPA.")
        print("Đọc", target.filename, "(%.1f MB)..." % (target.file_size / 1048576.0))
        data = bytearray(zin.read(target))
    else:
        with open(args.input, "rb") as f:
            data = bytearray(f.read())

    ok, log, bad = patch_binary(data, patches, args.dry_run)
    print("\n".join(log))
    print("\nTổng: %d patch ok, %d lỗi%s" % (ok, bad, " (dry-run, chưa ghi file)" if args.dry_run else ""))
    if bad:
        raise SystemExit("Có patch lỗi -> KHÔNG ghi file. Kiểm tra lại bản game/offset hoặc dùng --skip.")
    if args.dry_run:
        return

    if is_zip:
        with zipfile.ZipFile(args.output, "w") as zout:
            for info in zin.infolist():
                if info.filename == target.filename:
                    zout.writestr(info, bytes(data), compress_type=info.compress_type)
                else:
                    zout.writestr(info, zin.read(info), compress_type=info.compress_type)
        zin.close()
    else:
        with open(args.output, "wb") as f:
            f.write(data)
    print("Đã ghi:", args.output)
    print("Bước tiếp: ký lại bằng ESign, KHÔNG tiêm VoiceMod.dylib.")


if __name__ == "__main__":
    main()
