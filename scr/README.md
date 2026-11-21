# VoiceFX v2 - dylib đổi giọng Nam / Nữ / Em bé (TD-PSOLA)

## Build trên GitHub
1. Upload toàn bộ thư mục này lên repo (nhớ cả thư mục `.github`, và giữ đúng tên thư mục `src`).
2. Tab **Actions** → *Build VoiceFX* → Run workflow.
3. Tải artifact **VoiceFX-dylib** → `VoiceFX.dylib`, tiêm vào IPA bằng công cụ ký/tiêm dylib của bạn.

## Dùng
- Chạm nút tròn hình sóng âm (kéo được) để mở menu.
- **Giọng thật của bạn** (Nam/Nữ) → **Giọng muốn có** (Nam / Nam trầm / Nữ / Nữ cao / Em bé) → bật **Đổi giọng**.
- Để **Tự căn theo giọng thật** bật: giọng ra luôn rơi đúng vùng cao độ của giọng đích
  (Nam ~120Hz, Nữ ~215Hz, Nữ cao ~270Hz, Em bé ~400Hz) dù giọng thật của bạn cao hay thấp; voice được "khoá" sát giọng đích để nghe rõ là loại giọng đã chọn, không lưng chừng.
- Tinh chỉnh: Tinh chỉnh cao độ, Âm sắc (formant), Âm lượng, Lọc ồn.
- **🔊 Nghe thử giọng vừa đổi**: bấm sau khi nói vài giây để tự nghe lại 4 giây gần nhất giọng ĐÃ qua xử lý —
  chỉ máy bạn nghe, không gửi cho ai. Đây là cách duy nhất để tự kiểm tra vì bình thường bạn không tự nghe
  được giọng mình qua loa khi đang voice chat (giống mọi app gọi thoại khác).
- Dòng *Mic: đã bắt …* cuối menu cho biết hook đã nhận luồng thu âm và đã xử lý bao nhiêu giây.

## Nếu mọi người báo không nghe thấy giọng đổi
- Trước tiên tự bấm "🔊 Nghe thử giọng vừa đổi" để xác nhận bộ xử lý CÓ chạy đúng.
  Nếu nghe ra đúng giọng đã chọn → bộ xử lý hoạt động tốt, vấn đề (nếu còn) nằm ở phía truyền tín hiệu của game, cần thêm log để tìm tiếp.
  Nếu không nghe thấy gì / báo "chưa có gì để nghe thử" → gửi lại dòng trạng thái "Mic: ..." để kiểm tra tiếp.
- Nói liên tục khoảng 1 phút rồi mở lại menu, so số giây ở "đã xử lý Xs" với thời gian bạn vừa nói — nếu chênh lệch nhiều, gửi tôi số đó.
