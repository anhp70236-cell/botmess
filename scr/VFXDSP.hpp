// VFXDSP.hpp v2 - bo xu ly giong thoi gian thuc (khong phu thuoc thu vien ngoai)
// Chuoi: HPF -> Noise gate -> TD-PSOLA (do cao do YIN + dich formant) -> HPF sau -> EQ ro giong -> Leveler -> Soft limiter
// TD-PSOLA giu nguyen tinh chat ca "mot chu ky giong" nen nghe tu nhien, khong bi "phasey/re" nhu phase vocoder.
#pragma once
#include <math.h>
#include <string.h>
#include <stdlib.h>
#include <stdint.h>
#include <atomic>

#ifndef VFX_PI
#define VFX_PI 3.14159265358979323846f
#endif

static inline float vfxClampf(float v, float lo, float hi) { return v < lo ? lo : (v > hi ? hi : v); }
static inline int vfxPow2Ge(int v) { int p = 1; while (p < v) p <<= 1; return p; }

// ---------- Tham so (UI ghi, audio thread doc) ----------
struct VFXParams {
    std::atomic<bool>  enabled{false};
    std::atomic<bool>  autoPitch{true};    // tu can cao do theo giong dich, bat ke giong that cao/thap
    std::atomic<bool>  clarity{true};      // EQ + leveler (to, ro)
    std::atomic<float> semitones{0.f};     // tinh chinh cao do (ban cung)
    std::atomic<float> formant{1.f};       // ti le am sac (1 = giu nguyen)
    std::atomic<float> gainDb{0.f};        // am luong cong them
    std::atomic<float> gate{0.3f};         // loc on 0..1
    std::atomic<float> targetF0{215.f};    // cao do dich (Hz)
    std::atomic<float> srcF0{125.f};       // cao do trung binh giong that (Hz) - khoi dau, se tu hoc
    std::atomic<float> fixedSemis{0.f};    // dich co dinh khi tat tu can
    std::atomic<float> intonation{0.35f};  // giu bao nhieu ngu dieu goc (0..1) - thap de bam chac vao giong dich
    std::atomic<float> postHpf{110.f};     // cat tram sau khi doi (Hz)
};

struct VFXCfg {
    bool shift = true, autoPitch = true, clarity = true;
    float st = 0.f, fm = 1.f, gainDb = 0.f, gate = 0.f, target = 215.f, srcF0 = 125.f, fixedSemis = 0.f, k = 0.75f, postHpf = 110.f;
};

// ---------- Biquad (RBJ) ----------
struct VFXBiquad {
    float b0 = 1, b1 = 0, b2 = 0, a1 = 0, a2 = 0, z1 = 0, z2 = 0;
    void reset() { z1 = z2 = 0.f; }
    inline float run(float x) { float y = b0 * x + z1; z1 = b1 * x - a1 * y + z2; z2 = b2 * x - a2 * y; return y; }
    void setLP(float fs, float fc, float q) {
        float w = 2.f * VFX_PI * fc / fs, c = cosf(w), al = sinf(w) / (2.f * q), a0 = 1.f + al;
        b0 = (1.f - c) * 0.5f / a0; b1 = (1.f - c) / a0; b2 = b0; a1 = -2.f * c / a0; a2 = (1.f - al) / a0;
    }
    void setHP(float fs, float fc, float q) {
        float w = 2.f * VFX_PI * fc / fs, c = cosf(w), al = sinf(w) / (2.f * q), a0 = 1.f + al;
        b0 = (1.f + c) * 0.5f / a0; b1 = -(1.f + c) / a0; b2 = b0; a1 = -2.f * c / a0; a2 = (1.f - al) / a0;
    }
    void setPeak(float fs, float f0, float q, float dB) {
        float A = powf(10.f, dB / 40.f), w = 2.f * VFX_PI * f0 / fs, c = cosf(w), al = sinf(w) / (2.f * q), a0 = 1.f + al / A;
        b0 = (1.f + al * A) / a0; b1 = -2.f * c / a0; b2 = (1.f - al * A) / a0; a1 = -2.f * c / a0; a2 = (1.f - al / A) / a0;
    }
};

// ---------- Do cao do (YIN o toc do thap + tinh chinh o toc do goc) ----------
class VFXPitch {
public:
    float sr = 48000.f;
    int D = 6, hopD = 40, W = 256, tauMin = 15, tauMax = 140, dMask = 0, nMask = 0;
    float *dbuf = nullptr, *nat = nullptr, *win = nullptr, *cm = nullptr;
    int64_t dCount = 0, nCount = 0; float accD = 0.f; int accN = 0;
    bool voiced = false; float period = 0.f; int unvCount = 0, outlier = 0;

    ~VFXPitch() { free(dbuf); free(nat); free(win); free(cm); }
    void init(float sampleRate) {
        sr = sampleRate;
        D = (int)floorf(sr / 8000.f + 0.5f); if (D < 1) D = 1;
        float fd = sr / D;
        tauMin = (int)floorf(fd / 600.f); if (tauMin < 2) tauMin = 2;
        tauMax = (int)ceilf(fd / 55.f) + 1;
        W = (int)(0.032f * fd); hopD = (int)(0.005f * fd); if (hopD < 8) hopD = 8;
        int dn = vfxPow2Ge(W + tauMax + hopD + 64); dMask = dn - 1;
        int nn = vfxPow2Ge((int)(0.12f * sr) + 64); nMask = nn - 1;
        dbuf = (float*)calloc(dn, 4); nat = (float*)calloc(nn, 4);
        win = (float*)calloc(W + tauMax + 8, 4); cm = (float*)calloc(tauMax + 8, 4);
        reset();
    }
    void reset() {
        memset(dbuf, 0, (dMask + 1) * 4); memset(nat, 0, (nMask + 1) * 4);
        dCount = nCount = 0; accD = 0.f; accN = 0; voiced = false; period = 0.f; unvCount = 0; outlier = 0;
    }
    inline void push(float x) {
        nat[nCount & nMask] = x; nCount++;
        accD += x;
        if (++accN >= D) {
            dbuf[dCount & dMask] = accD / (float)D; dCount++; accD = 0.f; accN = 0;
            if ((dCount % hopD) == 0 && dCount >= (int64_t)(W + tauMax + 2)) analyze();
        }
    }
private:
    void analyze() {
        int total = W + tauMax;
        int64_t t0 = dCount - total;
        for (int i = 0; i < total; i++) win[i] = dbuf[(t0 + i) & dMask];
        float e = 0.f; for (int i = total - W; i < total; i++) e += win[i] * win[i];
        float rms = sqrtf(e / (float)W);
        bool v = false; int tau = -1; float tf = 0.f;
        if (rms > 1.2e-3f) {
            float run = 0.f; cm[0] = 1.f;
            for (int t = 1; t <= tauMax; t++) {
                float d = 0.f;
                for (int j = 0; j < W; j++) { float df = win[j] - win[j + t]; d += df * df; }
                run += d; cm[t] = run > 1e-12f ? d * (float)t / run : 1.f;
            }
            const float thr = 0.20f;
            for (int t = tauMin; t < tauMax; t++) if (cm[t] < thr) { while (t + 1 < tauMax && cm[t + 1] < cm[t]) t++; tau = t; break; }
            if (tau < 0 && voiced && period > 0.f) {   // dang noi: cho phep dip kem hon nhung phai gan chu ky truoc
                float pd = period / (float)D; int lo = (int)(0.85f * pd), hi = (int)(1.15f * pd) + 1;
                if (lo < tauMin) lo = tauMin;
                if (hi > tauMax - 1) hi = tauMax - 1;
                int bt = -1; for (int t = lo; t <= hi; t++) if (cm[t] < cm[t - 1] && cm[t] <= cm[t + 1] && (bt < 0 || cm[t] < cm[bt])) bt = t;
                if (bt > 0 && cm[bt] < 0.38f) tau = bt;
            }
            if (tau > 0) {
                v = true;
                float a = cm[tau - 1], b = cm[tau], c = cm[tau + 1], den = a - 2.f * b + c;
                float sh = den > 1e-9f ? 0.5f * (a - c) / den : 0.f; sh = vfxClampf(sh, -1.f, 1.f);
                tf = ((float)tau + sh) * (float)D;
            }
        }
        if (v) {
            // chong nhay quang tam len: neu boi 2x/3x cua chu ky khop tot hon han (tuong quan goc) thi lay boi do
            { int L1 = (int)(tf + 0.5f);
              if (L1 >= 8) {
                  float n1 = ncc(L1, L1);
                  for (int m = 3; m >= 2; m--) {
                      if ((float)(L1 * m) > sr / 52.f) continue;
                      if (n1 < 0.985f && ncc(L1, L1 * m) > n1 + 0.05f) { tf *= (float)m; break; }
                  }
              } }
            float p = refine(tf);
            if (p > 0.f) {
                if (voiced && period > 0.f) {
                    float r = p / period;
                    if (r > 0.8f && r < 1.25f) { period += 0.5f * (p - period); outlier = 0; }
                    else if (++outlier >= 3) { period = p; outlier = 0; }
                } else { period = p; outlier = 0; }
                voiced = true; unvCount = 0;
            } else v = false;
        }
        if (!v) { if (++unvCount > 2) voiced = false; }
    }
    float ncc(int S, int L) {
        if (nCount < (int64_t)(S + L + 8) || (S + L + 8) > nMask) return 0.f;
        int64_t e0 = nCount; float ea = 0.f, eb = 0.f, cr = 0.f;
        for (int j = 0; j < S; j++) { float a = nat[(e0 - S + j) & nMask], b = nat[(e0 - S - L + j) & nMask]; ea += a * a; eb += b * b; cr += a * b; }
        return cr / sqrtf(ea * eb + 1e-12f);
    }
    // tinh chinh chu ky o toc do goc bang tuong quan chuan hoa
    float refine(float tf) {
        int L0 = (int)(tf + 0.5f), S = L0;
        if (S < 8 || nCount < (int64_t)(2 * S + 4 * D + 16)) return tf;
        int64_t e0 = nCount; float ea = 0.f;
        for (int j = 0; j < S; j++) { float a = nat[(e0 - S + j) & nMask]; ea += a * a; }
        int lo = L0 - 2 * D - 1, hi = L0 + 2 * D + 1; if (lo < 4) lo = 4;
        float best = -2.f, rv[96]; int bi = lo, cnt = 0;
        for (int L = lo; L <= hi && cnt < 96; L++, cnt++) {
            float eb = 0.f, cr = 0.f;
            for (int j = 0; j < S; j++) { float a = nat[(e0 - S + j) & nMask], b = nat[(e0 - S - L + j) & nMask]; eb += b * b; cr += a * b; }
            float r = cr / sqrtf(ea * eb + 1e-12f); rv[cnt] = r;
            if (r > best) { best = r; bi = L; }
        }
        float res = (float)bi; int k = bi - lo;
        if (k > 0 && k < cnt - 1) {
            float a = rv[k - 1], b = rv[k], c = rv[k + 1], den = a - 2.f * b + c;
            if (den < -1e-9f) res += vfxClampf(0.5f * (a - c) / den, -1.f, 1.f);
        }
        if (best < 0.35f) return -1.f;   // khong du tuan hoan => khong coi la giong
        return res;
    }
};

// ---------- TD-PSOLA thoi gian thuc ----------
struct VFXMark { int64_t pos; float per; bool voiced; };

class VFXPsola {
public:
    float sr = 48000.f;
    int inMask = 0, outMask = 0, Pu = 240, Pmin = 80, Pmax = 870, fixLat = 0;
    float *xin = nullptr, *acc = nullptr, *wac = nullptr;
    int64_t inCount = 0, readIdx = 0, emitB = 0, nMarks = 0, mi = 0;
    double tA = 0, sK = 0;
    VFXMark marks[128];
    VFXPitch pitch;
    VFXBiquad lp[2]; bool lpOn = false; float lpAlpha = -1.f;
    float centerLog = 0.f, voicedTime = 0.f, lastSrc = -1.f;
    bool started = false; int underruns = 0, fadeIn = 0;
    float lastOut = 0.f;

    ~VFXPsola() { free(xin); free(acc); free(wac); }
    void init(float sampleRate) {
        sr = sampleRate;
        int in = vfxPow2Ge((int)(0.30f * sr)); inMask = in - 1;
        int ou = vfxPow2Ge((int)(0.60f * sr)); outMask = ou - 1;
        xin = (float*)calloc(in, 4); acc = (float*)calloc(ou, 4); wac = (float*)calloc(ou, 4);
        Pu = (int)(0.005f * sr); Pmin = (int)(sr / 600.f); Pmax = (int)ceilf(sr / 55.f);
        pitch.init(sr);
        reset();
    }
    void reset() {
        memset(xin, 0, (inMask + 1) * 4); memset(acc, 0, (outMask + 1) * 4); memset(wac, 0, (outMask + 1) * 4);
        pitch.reset(); lp[0].reset(); lp[1].reset();
        inCount = 0; readIdx = 0; emitB = 0; nMarks = 0; mi = 0; started = false; underruns = 0; fadeIn = 0; lastOut = 0.f;
        voicedTime = 0.f; lastSrc = -1.f;
    }

    void process(const float* in, float* out, int n, const VFXCfg& c) {
        if (!started) begin(c);
        if (fabsf(c.srcF0 - lastSrc) > 0.5f) { lastSrc = c.srcF0; centerLog = logf(c.srcF0); voicedTime = 0.f; }
        updateLP(c.fm);
        for (int i = 0; i < n; i++) {
            float x = in[i];
            pitch.push(x);
            if (lpOn) x = lp[1].run(lp[0].run(x));
            xin[inCount & inMask] = x; inCount++;
        }
        plan(c);
        // lay mau ra (khong de thieu: neu thieu thi fade ve 0 de khong click)
        int i = 0;
        for (; i < n; i++) {
            if (readIdx >= emitB && readIdx >= 0) break;
            out[i] = popOne();
        }
        if (i < n) {
            underruns++;
            int fl = i < 48 ? i : 48;
            for (int k = 0; k < fl; k++) out[i - fl + k] *= 1.f - (float)(k + 1) / (float)(fl + 1);
            for (int k = i; k < n; k++) out[k] = 0.f;
            fadeIn = 48;
        }
        if (fadeIn > 0) { int m = fadeIn < n ? fadeIn : n, base = 48 - fadeIn; for (int k = 0; k < m; k++) { float g = (float)(base + k + 1) / 49.f; if (out[k] != 0.f) out[k] *= g; } fadeIn -= m; }
    }

private:
    void begin(const VFXCfg& c) {
        started = true;
        float Pref = sr / vfxClampf(c.srcF0, 60.f, 500.f);
        float dn = logf(vfxClampf(c.srcF0, 60.f, 500.f) / vfxClampf(c.target, 60.f, 600.f)); if (dn < 0.f) dn = 0.f;
        fixLat = (int)((3.2f + 1.6f * dn) * Pref + 0.004f * sr); int mn = (int)(0.012f * sr); if (fixLat < mn) fixLat = mn;
        readIdx = -fixLat; emitB = 0;
        marks[0] = { (int64_t)Pu, (float)Pu, false }; nMarks = 1; mi = 0;
        tA = (double)Pu; sK = (double)Pu;
        centerLog = logf(c.srcF0); lastSrc = c.srcF0; voicedTime = 0.f;
    }
    void updateLP(float alpha) {
        if (fabsf(alpha - lpAlpha) < 0.005f) return;
        lpAlpha = alpha;
        if (alpha > 1.03f) {
            float fc = 0.45f * sr / alpha; if (fc > 0.45f * sr) fc = 0.45f * sr;
            lp[0].setLP(sr, fc, 0.5412f); lp[1].setLP(sr, fc, 1.3066f); lpOn = true;
        } else lpOn = false;
    }
    inline float xAt(int64_t i) const { return i < 0 ? 0.f : xin[i & inMask]; }
    inline float interp(double pos) const {   // noi suy cubic (Catmull-Rom)
        int64_t i0 = (int64_t)floor(pos); float f = (float)(pos - (double)i0);
        float p0 = xAt(i0 - 1), p1 = xAt(i0), p2 = xAt(i0 + 1), p3 = xAt(i0 + 2);
        return p1 + 0.5f * f * (p2 - p0 + f * (2.f * p0 - 5.f * p1 + 4.f * p2 - p3 + f * (3.f * (p1 - p2) + p3 - p0)));
    }
    inline float popOne() {
        float v = 0.f;
        if (readIdx >= 0) {
            int64_t k = readIdx & outMask; float w = wac[k];
            v = acc[k] / (w > 0.25f ? w : 0.25f);
            acc[k] = 0.f; wac[k] = 0.f;
        }
        readIdx++; lastOut = v; return v;
    }
    const VFXMark& mk(int64_t i) const { return marks[i & 127]; }

    bool genMark(const VFXCfg& c) {
        const VFXMark last = mk(nMarks - 1);
        if (!pitch.voiced || pitch.period < 8.f) {
            int L = Pu;
            if (inCount < last.pos + 2 * L + 3) return false;
            marks[nMarks & 127] = { last.pos + L, (float)Pu, false }; nMarks++;
            return true;
        }
        float L0 = vfxClampf(pitch.period, (float)Pmin, (float)Pmax);
        int Lmin = (int)(0.88f * L0), Lmax = (int)ceilf(1.14f * L0); if (Lmin < Pmin) Lmin = Pmin; if (Lmax < Lmin) Lmax = Lmin;
        int S = (int)L0, h = S / 2;
        if (inCount < last.pos + Lmax + S - h + 3) return false;
        float ea = 0.f; int64_t a0 = last.pos - h;
        for (int j = 0; j < S; j++) { float a = xAt(a0 + j); ea += a * a; }
        float best = -1e9f; int bestL = (int)(L0 + 0.5f);
        for (int L = Lmin; L <= Lmax; L++) {
            float eb = 0.f, cr = 0.f; int64_t b0 = a0 + L;
            for (int j = 0; j < S; j++) { float a = xAt(a0 + j), b = xAt(b0 + j); eb += b * b; cr += a * b; }
            float r = cr / sqrtf(ea * eb + 1e-12f) - 0.15f * fabsf((float)L - L0) / L0;
            if (r > best) { best = r; bestL = L; }
        }
        marks[nMarks & 127] = { last.pos + bestL, L0, true }; nMarks++;
        // hoc cao do trung binh cua nguoi noi (cham, de giu duoc ngu dieu)
        float f0 = sr / L0, dl = vfxClampf(logf(f0) - centerLog, -0.5f, 0.5f);
        float tau = voicedTime < 2.f ? 0.6f : 4.f, wgt = (float)bestL / sr / tau;
        centerLog += dl * wgt; voicedTime += (float)bestL / sr;
        return true;
    }

    void plan(const VFXCfg& c) {
        const double twoPi = 6.283185307179586;
        for (int guard = 0; guard < 96; guard++) {
            while (mk(nMarks - 1).pos < tA) { if (!genMark(c)) break; }
            if (mk(nMarks - 1).pos < tA) break;
            if (mi < nMarks - 120) mi = nMarks - 120;
            while (mi + 1 < nMarks && fabs((double)mk(mi + 1).pos - tA) <= fabs((double)mk(mi).pos - tA)) mi++;
            const VFXMark m = mk(mi);
            float alpha = m.voiced ? c.fm : 1.f, Ps, LgA = m.per / alpha;
            if (m.voiced) {
                float f0in = sr / m.per, f0out;
                float tgt = c.target * powf(2.f, c.st / 12.f);
                if (c.autoPitch) { float dl = vfxClampf(logf(f0in) - centerLog, -0.45f, 0.45f); f0out = tgt * expf(c.k * dl); }
                else f0out = f0in * powf(2.f, (c.fixedSemis + c.st) / 12.f);
                float beta = vfxClampf(f0out / f0in, 0.4f, 3.8f); f0out = vfxClampf(f0in * beta, 50.f, 0.45f * sr);
                Ps = sr / f0out;
            } else Ps = (float)Pu;
            float lg = fmaxf(LgA, fminf(0.95f * Ps, 1.35f * LgA));
            int Lg = (int)ceilf(lg); if (Lg < 4) Lg = 4; if (Lg > (outMask + 1) / 8) Lg = (outMask + 1) / 8;
            if (inCount < m.pos + (int64_t)ceilf(alpha * (float)Lg) + 3) break;
            if ((int64_t)(sK + Lg) - readIdx > outMask - 16) break;
            // dat 1 grain Hann tai sK (chinh xac duoi mau)
            int64_t s0 = (int64_t)llround(sK); float frac = (float)(sK - (double)s0);
            float invL = 1.f / (float)Lg;
            for (int n = -Lg; n <= Lg; n++) {
                int64_t idx = s0 + n; if (idx < readIdx || idx < 0) continue;
                float nn = (float)n - frac, w = 0.5f + 0.5f * cosf(VFX_PI * nn * invL);
                if (nn <= -(float)Lg || nn >= (float)Lg) continue;
                float v = interp((double)m.pos + (double)alpha * (double)nn);
                int64_t k = idx & outMask; acc[k] += w * v; wac[k] += w;
            }
            sK += Ps; tA += Ps;
            int64_t eb = (int64_t)floor(sK - 1.2 * (double)Lg - 4.0); if (eb > emitB) emitB = eb;
        }
        (void)twoPi;
    }
};

// ---------- Bo xu ly cho 1 luong mic ----------
class VFXProcessor {
public:
    float sr = 48000.f; int cap = 4096; float* buf = nullptr;
    VFXPsola voice; bool voiceActive = false;
    VFXBiquad hpIn, hpPost, eq; float lastHp = -1.f;
    float gateGain = 1.f, gateHold = 0.f; bool gateOpen = true;
    float env = 0.f, lvGain = 1.f;

    ~VFXProcessor() { free(buf); }
    void init(float sampleRate, int maxFrames) {
        sr = sampleRate; cap = maxFrames; buf = (float*)calloc(cap, 4);
        voice.init(sr);
        hpIn.setHP(sr, 80.f, 0.7071f);
        eq.setPeak(sr, 2800.f, 0.9f, 3.0f);
        hpPost.setHP(sr, 110.f, 0.7071f); lastHp = 110.f;
    }
    void process(float* x, int n, const VFXCfg& c) {
        // 1) HPF bo tieng u/rung
        for (int i = 0; i < n; i++) x[i] = hpIn.run(x[i]);
        // 2) Noise gate muot
        if (c.gate > 0.01f) {
            float e = 0.f; for (int i = 0; i < n; i++) e += x[i] * x[i];
            float db = 20.f * log10f(sqrtf(e / (float)n) + 1e-9f), thr = -62.f + 24.f * c.gate;
            if (db > thr) { gateOpen = true; gateHold = 0.15f * sr; }
            else if (gateHold > 0.f) gateHold -= (float)n;
            else if (db < thr - 3.f) gateOpen = false;
            float tgt = gateOpen ? 1.f : 0.f;
            float up = 1.f - expf(-1.f / (0.004f * sr)), dn = 1.f - expf(-1.f / (0.06f * sr));
            for (int i = 0; i < n; i++) { gateGain += (tgt - gateGain) * (tgt > gateGain ? up : dn); x[i] *= gateGain; }
        } else { gateGain = 1.f; gateOpen = true; }
        // 3) Doi giong (TD-PSOLA)
        if (c.shift) {
            if (!voiceActive) { voice.reset(); voiceActive = true; }
            voice.process(x, x, n, c);
        } else voiceActive = false;
        // 4) Cat tram sau khi doi (giong nu/be bot "ong")
        if (fabsf(c.postHpf - lastHp) > 0.5f) { hpPost.setHP(sr, vfxClampf(c.postHpf, 40.f, 400.f), 0.7071f); lastHp = c.postHpf; }
        for (int i = 0; i < n; i++) x[i] = hpPost.run(x[i]);
        // 5) EQ do ro + leveler (to, deu)
        if (c.clarity) {
            for (int i = 0; i < n; i++) x[i] = eq.run(x[i]);
            float aa = 1.f - expf(-1.f / (0.004f * sr)), ar = 1.f - expf(-1.f / (0.15f * sr));
            float gdn = 1.f - expf(-1.f / (0.02f * sr)), gup = 1.f - expf(-1.f / (0.25f * sr));
            for (int i = 0; i < n; i++) {
                float p = x[i] * x[i]; env += (p - env) * (p > env ? aa : ar);
                float rms = sqrtf(env), tg = 1.f;
                if (rms > 0.004f) tg = vfxClampf(0.16f / rms, 0.7f, 4.f);
                if (tg > 2.5f) tg = 2.5f;
                lvGain += (tg - lvGain) * (tg < lvGain ? gdn : gup);
                float ax = fabsf(x[i]);
                if (ax * lvGain > 0.72f) lvGain = 0.72f / ax;   // giam gain ngay khi sap vot bien do (khong de limiter bop meo)
                x[i] *= lvGain;
            }
        }
        // 6) Gain + soft limiter (chong vo tieng/re)
        float g = powf(10.f, c.gainDb / 20.f);
        for (int i = 0; i < n; i++) {
            float v = x[i] * g;
            if (!(v == v)) v = 0.f;
            float a = fabsf(v);
            if (a > 0.7f) { float s = v < 0 ? -1.f : 1.f; v = s * (0.7f + 0.28f * tanhf((a - 0.7f) / 0.28f)); }
            x[i] = v;
        }
    }
};
