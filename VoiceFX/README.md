# VoiceFX v2 - dylib đổi giọng Nam / Nữ / Em bé (TD-PSOLA)

## Build trên GitHub
1. Upload toàn bộ thư mục này lên repo (nhớ cả thư mục `.github`).
2. Tab **Actions** → *Build VoiceFX* → Run workflow.
3. Tải artifact **VoiceFX-dylib** → `VoiceFX.dylib`, tiêm vào IPA bằng công cụ ký/tiêm dylib của bạn.

## Dùng
- Chạm nút tròn hình sóng âm (kéo được) để mở menu.
- **Giọng thật của bạn** (Nam/Nữ) → **Giọng muốn có** (Nam / Nam trầm / Nữ / Nữ cao / Em bé) → bật **Đổi giọng**.
- Để **Tự căn theo giọng thật** bật: giọng ra luôn rơi đúng vùng cao độ của giọng đích
  (Nam ~120Hz, Nữ ~215Hz, Nữ cao ~260Hz, Em bé ~380Hz) dù giọng thật của bạn cao hay thấp; ngữ điệu vẫn được giữ.
- Tinh chỉnh: Tinh chỉnh cao độ (±6 bán cung quanh giọng đích), Âm sắc (formant), Âm lượng, Lọc ồn.
- Dòng *Mic: đã bắt …* ở cuối menu cho biết hook đã nhận luồng thu âm.

## Nếu có vấn đề
- *"Mic: chưa thấy luồng thu âm"* sau khi bật mic trong trận → gửi lại nguyên dòng trạng thái đó.
- Giọng nữ/bé chưa đủ "mỏng": tăng Âm sắc thêm 5–10%. Giọng nam chưa đủ dày: giảm Âm sắc 5–10%.
- Giọng nghe lạ khi nói rất nhanh/thì thầm: tăng Lọc ồn nhẹ, nói rõ vừa phải.
