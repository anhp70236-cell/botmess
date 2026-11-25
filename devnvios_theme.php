<?php
/* Devnvios theme v43 (patch_v25): giao dien kieu app, KHONG phat sang.
   v43 (patch_v25 moi) - khong xoa gi cua v42:
   - FIX: thong bao o tab Keys hien 2 lan (ban goc cua index.php chen vao 1-2 khung hinh roi bien mat, ban sao hien lai) => nay chi hien 1 lan.
   - Tools: bo khung quanh cai co le (con co le lac + chu).
   - FIX: bam View bi toi roi toi them (trang bi lam toi 40% them 1 lan nua len cac dong da mo) => nay vao trang san pham khong con toi.
       Ve Home: dong tool bi khoa (mo 50%) khong con sang len roi toi lai.
   - Logo lap loa NHIEU hon: 2 dai sang chay lien tuc, dai rong hon, nhanh hon (1.6 giay).
   - Anh xem truoc: nhan them link tuong doi (/uploads/anh.jpg, uploads/anh.png) ngoai link https:// (de admin up anh len host).
   Giu nguyen moi thu khac. */
/* Devnvios theme v37 (BAN CUOI): giao dien kieu app, KHONG phat sang.
   Cac phien ban cu (v1 -> v36) giu nguyen toan bo chuc nang: trang chu + trang chi tiet tool, trang goi (?package=N),
   link chia se (?devnvios=ID-ten-tool), tab Keys / Tools / History / Me, menu avatar, keo xuong de tai lai, giao dien sang/toi.

   v40 (patch_v25 moi) - khong xoa gi cua v39:
   - FIX: thong bao hien 2 cai cung luc (chi con 1 thong bao tren man hinh), het nhay ve tab Keys khi thong bao tat.
   - FIX: doi man / load web bi khung (bo hieu ung mo xuong den roi moi hien lai).
   - History: kieu don hang (icon tui mua hang, khung trong bo goc) giong shopdsgm.vn. Trang Me gon, phang, khong phat sang.

   v39 (patch_v25) - khong xoa gi cua v38:
   - FIX: cuon len bi load lai web (bo cuon bang body cua v38, chi con khung cuon #ds-scroll).
   - Khoang trong duoi cung: "Made with ... @2024" sat thanh tab. Khoi gioi thieu DEVNVIOS nhich len.
   - Icon 56px, ten 14px, mo ta 12.5px, tieu de Package 18px, nut View 66x28 theo anh DS.

   v37 (BAN CUOI MOI NHAT) - khong xoa gi cua v36:
   - Het loi khi cuon xuong cuoi trang (video): khoang trong duoi cung nay CO DINH (khong con do lai theo thanh tab / thanh Safari),
       dong "Made with ... @2024" luon nam tren thanh tab, het bi che roi nhay len xuong. Van giu khung kinh mo cua thanh tab Home.
   - San pham to len mot xiu (icon 56px, chu 16px).
   - Nut GET trong trang san pham nho lai (cao 32px) + nut chia se nho theo.
   - Package (Package 1 / 2 / 3) to len (tieu de 24px, mui ten 21px).
   - Avatar tren header: BO KHUNG (khong nen, khong vien, khong bo goc).
   - Anh banner devnvios.io.vn: het mat chu 2 ben (khong con cat anh), ti le khung lay theo anh that.

   v36 (BAN CUOI MOI NHAT) - khong xoa gi cua v35:
   - Cuon muot hon + het "cuon 2 lan o day trang":
       (1) bo cai chan keo nay v35 (preventDefault tren touchmove lam iOS khung khung khuc khi cuon sat day) => dung CSS overscroll-behavior.
       (2) cuoi trang: khoang trong duoi co gia tri du phong dung ngay tu dau (khong con nhay chieu cao khi do thanh tab xong).
       (3) cuoi trang khong con tu them / bot khoang trong khi bi "ds-typing" kich nham luc thanh Safari truot (kbOpen khat khe hon: > 150px,
           va khong doi trang thai ban phim trong luc dang cuon).
       (4) anh xem truoc nap ngay (khong lazy) + giu cho san (aspect-ratio) => khong lam trang dai ra khi cuon toi.
       (5) lam mo (blur) cua thanh tab nhe hon (10px, bo saturate) + logo lap loa tach lop ve rieng => it ve lai luc cuon.
   - Bam View xem san pham roi bam Home / Keys / Tools / History / Me: noi dung mo dan roi hien len TU TU (khong nhay nhanh nua).
   - Khung thanh vien o trang ME: bo khung (khong nen, khong vien), con avatar + ten + huy hieu.
   - Danh sach san pham to len mot chut (icon 52px, chu 15px, nut View 30px). Package (Package 1 / 2 / 3) to len mot chut (tieu de 20px, trang goi 28px).
   - KHOA CUNG 2 chieu: key VIP chi mo san pham VIP (bam san pham Free => hien thong bao), key Free chi mo san pham Free (bam san pham VIP => hien thong bao).
       Chan o moi duong mo tool (nut GET va ca link trong menu an), nut GET mo di khi sai loai key.
       (Chan that van phai nam o server: ds_open_gate_* trong db.php, file nay chi la lop chan o giao dien.)
   - Co key ma bam GET mo duoc la LUU NGAY vao History (khong cho dem luot nua). Neu 3 giay sau van hien thong bao loi thi tu go dong do ra.
       Neu luot giam nhieu hon 1 thi cap nhat dung so luot.
   - Anh xem truoc kieu shopdsgm.vn / App Store: anh doc to, xep hang ngang, luot ngang de xem tiep, anh ke tiep lo ra mot phan, bam khop tung anh.
   - Logo lap loa chay NHANH hon (mac dinh 2.6 giay 1 luot, truoc la 5). Chinh: define('DS_SHINE_SECONDS', 2.6);
       LUU Y: neu index.php dang co dong define('DS_SHINE_SECONDS', ...) thi sua / xoa dong do di, khong thi no se de len so nay.

   v35 - khong xoa gi cua v34:
   - Logo devnvios.io.vn: tu dong DUNG THANG lai (anh logo goc bi nghieng nen bu bang skewX). Chinh goc: define('DS_LOGO_STRAIGHT', 10);
       (do; 0 = tat; neu thay no nghieng NGUOC lai thi doi thanh so am, vd -10)
   - Logo co LAP LOA thuong hieu chay lien tuc, CHAM (dai sang THANG, khong nghieng).
       Doi toc do: define('DS_SHINE_SECONDS', 5); (3 = nhanh hon, 8 = cham hon). Tat han: define('DS_SHINE', false);
       LUU Y: neu index.php dang co dong define('DS_SHINE', false) thi xoa dong do di.
   - Nut Dang xuat do: bo nen do nhat / vang sang quanh icon, khong hieu ung, khong bong => phang, chi con chu + icon do.
   - Het "khung chay len chay xuong" khi cuon cham day trang 2 lan:
       (1) canh khung IPA - TOOLS bang cach DO BO CUC (offsetTop) thay vi toa do man hinh => luc bat nay o day trang khong con do sai roi giat khung len xuong;
           khong do lai luc dang cuon.
       (2) chan keo nay (rubber band) o day trang: da o day trang ma vuot len tiep thi khong cho nay.
       (3) lop kinh cua thanh tab keo dai xuong duoi day nen khong lo khe khi thanh Safari truot.
   - Bam GET o san pham chuyen sang Keys het khoang trong cuc lon: nguyen nhan la web tu focus vao o nhap key bang code => iPhone khong bat ban phim
       nhung trang van chua san khoang trong 46vh cho ban phim va an thanh tab (ds-typing bi ket lai). Da sua:
       khong tu focus nua, chi chua cho khi ban phim THAT SU mo (do bang visualViewport), tu go khi ban phim dong / doi man.

   v34 (BAN CUOI MOI NHAT) - khong xoa gi cua v33:
   - Cuon muot nhu shopdsgm.vn: hieu ung bong logo (chay lien tuc duoi header co dinh => nang khi cuon) nay MAC DINH TAT.
       Muon bat lai: define('DS_SHINE', true);
   - Thanh tab duoi (Home / Keys / Tools...): kinh mo MONG, nhin xuyen mo mo (lop v33 truoc day bi 1 luat cu de len nen thanh tab van dac, da sua).
       Chinh do trong: define('DS_NAV_SEE_ALPHA', 46); (20-90, thap = trong hon). Tat: define('DS_NAV_SEE', false);
   - Trang san pham CO anh xem truoc: bo cuc giong shopdsgm.vn (tieu de to, anh xem truoc lon keo ngang, thong so can giua, mo ta to).
       San pham KHONG co anh xem truoc: giu nguyen nhu cu.
   - Khoi DEVNVIOS o cuoi trang cach xa san pham them mot chut.
   - Bam View xem san pham roi bam Home / Keys / Tools / History / Me: tro ve DUNG cho dang cuon truoc do cua man do, hien len nhe nhang (cham hon).
       Bam lai tab dang o => cuon len dau trang mem mai.
   - Key Free KHONG mo duoc san pham VIP (hien thong bao ngay, khong goi server). Chi key VIP moi mo san pham VIP.
       (Chan that van phai nam o server: ds_open_gate_* trong db.php, file nay chi la lop chan o giao dien.)
   - Logo devnvios.io.vn tren header to hon mot chut (chieu cao 47px, header 54px).

   v33 - khong xoa gi cua v32:
   - Khung "IPA - TOOLS" xich XUONG them mot chut (mac dinh 22px tu header). Doi: define('DS_HERO_TOP', 22); (0-40)
   - Dong "JOIN THE TELEGRAM GROUP" thang hang, can giua, co khoang tho tren duoi (het bi de bep sat hang the).
   - Hang the nho (IPA, C++, Python, Mod Menu...) KHONG tu chay nua: luc vao web hien the dau tien (IPA), nguoi dung tu keo ngang bang tay.
   - Anh banner: bo cham tron (dau cham) o goc phai. Banner van tu chuyen anh va keo tay qua lai duoc.
   - Het loi de len / khung o dau trang khi cuon len xuong lien tuc: header dac hoan toan + them lop che phia tren,
       bo cac goi y ve lai thua cua header (contain / will-change), KHONG con bat tat class tren header moi lan cuon (nguyen nhan khung khung khuc).
   - Thanh tab duoi (Home / Keys / Tools / History / Me): kinh mo nhin xuyen nhe (blur manh nen chu phia sau khong ro => khong con cam giac bi de).
       Tat: define('DS_NAV_SEE', false);  Chinh do mo: define('DS_NAV_SEE_ALPHA', 58); (phan tram, 30-90; cao = dac hon)
   - Khoi gioi thieu (DEVNVIOS / Legal disclaimer / Documents): tach khoi danh sach san pham (het bi dinh), giong shopdsgm.vn,
       ap dung cho moi man (Home, Keys, Tools...).
   - Cuoi trang: khoang trong duoi duoc tinh theo chieu cao that cua thanh tab => cuon den day la het, khong con khoang trong thua.

   v32 - khong xoa gi cua v31:
   - Trang chu: khung "IPA - TOOLS" + banner + the nho nhich LEN sat header (het khoang trong thua o tren).
       Doi khoang cach tu header toi chu IPA - TOOLS: define('DS_HERO_TOP', 6); (px, 0-40)
   - Banner anh LOAD NGAY: anh dau tien duoc tai san ngay trong <head> (truoc day chi bat dau tai khi trinh duyet doc toi CUOI trang
       nen rat cham), man hinh nap (vong tron) cho anh hien xong moi dong (toi da ~2.3 giay), anh thu 2, 3 tai sau anh dau.
       Anh van nang / cham: define('DS_HERO_OPT', true); => nen anh sang WebP nho (qua wsrv.nl), loi thi tu dung anh goc.
       Chinh do rong anh khi nen: define('DS_HERO_WIDTH', 960);
   - Hang the nho duoi banner: chi con 9 the kieu code (IPA, C++, Python, Mod Menu, Lua, Swift, Unity, Il2Cpp, Dylib),
       TU CHAY ngang lien tuc (chay bang CSS, khong phu thuoc JS), KHONG con keo qua keo lai.
       Doi toc do (so giay cho 1 vong): define('DS_CHIPS_SECONDS', 24); Doi the: sua mang $dsChips trong ham devnvios_theme_body.
   - Dong "JOIN THE TELEGRAM GROUP" co khoang tho voi hang the (het bi de sat).
   - Het giat khi cuon xuong roi cuon len 1 chut: tat content-visibility cua tung dong tool (nguyen nhan lam trang nhay vi tri
       khi cuon nguoc), bo lop ve rieng cua tung icon, header + thanh tab DAC (khong con trong suot nen het bi
       chu phia sau "xuyen" len / de len dong "Made with ... @2024").
   - Cuoi trang: bo bot khoang trong thua (giua danh sach tool va khoi DEVNVIOS, giua Terms of Service va dong Made with,
       va duoi @2024), dong "Made with ... @2024" nam gon sat tren thanh tab.

   v31 - khong xoa gi cua v30:
   - Trang chu: BO khung banner DEVNVIOS, thay bang khung giong shopdsgm.vn:
       tieu de "IPA - TOOLS" + mo ta ngan, banner anh tu chuyen sau 5 giay (luot qua / luot lai de chuyen nhanh, co cham tron o goc phai),
       duoi la hang the nho (Unity, IPA Files, TrollStore, Sideload...) tu chay ngang, cham vao la keo qua keo lai duoc.
       Anh dau tien hien ra khi vua vao web = anh thu 3 ban gui (rmWwBgXw). Doi anh: define('DS_HERO_IMAGES', 'link1,link2,link3');
       Doi thoi gian chuyen: define('DS_HERO_SECONDS', 5); Doi chu: define('DS_HERO_TITLE', 'IPA - TOOLS'); define('DS_HERO_DESC', '...');
       Muon doi danh sach the: sua mang $dsChips trong ham devnvios_theme_body.
   - Hai khung VIP key / Free key nhich ra them mot chut (khoang cach 18px).
   - Cac man khac (Keys, Tools, History, Me, chi tiet, goi) giu nguyen.

   v30 - khong xoa gi cua v29:
   - Thong bao dung key / sai key / chua dang nhap / canh bao: ep CANH LE TRAI cung hang voi chu "Activate a key"
       (X do + chu, khong khung, giong thong bao khi bam GET ma het luot). Khong con bi day ra ngoai le phai.
       Co them 1 doan JS tu do vi tri: neu van lech thi tu keo ve dung le trai.
   - Thong bao "tool da dong, nhap key" (bam GET): giu dang X do + chu, khong khung (nhu v29).
   - Danh sach san pham nho lai them 1 chut (icon 45px, chu 14px, nut View nho hon).
   - Cuon muot hon: hieu ung bong logo luc cuon chi tat/bat tren header (khong con doi class cua ca trang),
       dung opacity + pause thay vi display:none (het giat luc bat dau / het cuon), moi dong tool chi ve khi vao man hinh
       (content-visibility), bo lop cuon cu cua body, bo will-change thua. Neu may van lag: define('DS_SHINE', false);
       (tat han bong logo, nhe nhat). Muon quay lai cach cu: khong can, cac luat v29 van con nguyen.

   v29 - khong xoa gi cua v28:
   - Thong bao (dung key / sai key / chua dang nhap / canh bao) KHONG con khung, nho gon lai, canh sat le trai cung hang voi chu "Activate a key".
   - Thong bao "tool da dong, nhap key" (bam GET ma het luot) doi sang kieu giong thong bao sai key: chi dau X do + chu, khong khung.
   - Danh sach san pham nho lai mot chut (icon 48px, chu 14.5px).

   v28 - khong xoa gi cua v27:
   - Man hinh nap (vong tron xoay) hien o MOI lan vao web / tai lai / keo xuong de tai lai, tren trang chu, dang nhap, dang ky...
       Toc do vua phai (~0.8 giay). Doi: define('DS_BOOT_MS', 800); (200-3000, don vi mili giay).
       KHONG hien khi vua nhap key hoac vua co thong bao (giu nhu cu).
       Trang dang nhap / dang ky khong dung theme nay: chi can goi them devnvios_theme_boot(); trong <head> cua trang do.
   - Thong bao (dung key / sai key / canh bao...) nay nam TREN o nhap VIP key, DUOI dong chu "used up" (giong thong bao khi bam GET ma het luot).
       Thong bao hien o bat ky man nao thi web tu chuyen sang tab Keys de thay. Tat chuyen tab: define('DS_TOAST_KEYS', false);
   - Cuon muot hon (giong shopdsgm.vn): bo lam mo (blur) o header + thanh tab (nang nhat khi cuon), tam an vet lang bong logo luc dang cuon,
       icon tool nap san. Muon lai kinh mo nhu v27: define('DS_GLASS_BLUR', true); (hoac rieng: DS_HEADER_GLASS / DS_NAV_GLASS).
   - Khung menu avatar nho gon lai.

   v27 - khong xoa gi cua v26, KHONG doi kich thuoc chu / san pham / header:
   - Thanh tab duoi day: dang thanh phang dinh sat day kieu shopdsgm.vn, NHO GON, kinh mo nhin xuyen mo mo (blur).
       Muon thanh noi dang vien thuoc nhu v26: define('DS_NAV_FLOAT', true);
   - Header van kinh mo nhe nhu v26 (tat: define('DS_HEADER_GLASS', false);). Nen dac thanh tab: define('DS_NAV_GLASS', false);
   - Thong bao (dung key / sai key / canh bao): hien o GIUA man hinh, icon o tren, chu o duoi, hieu ung noi len nhe.
       Doi do cao: define('DS_TOAST_TOP', 40); (phan tram tu tren xuong, 10-80)
   - Thong bao "chua co key": icon dau X do tu ve + rung nhe, thanh dem nguoc mau do, van TU BIEN MAT.
   - Menu avatar lam gon va dep hon (the nguoi dung nho, muc co o icon bo tron, hieu ung mo ra). Id cu van nguyen.

   v26: Thanh tab duoi day dang vien thuoc noi, header kinh mo nhe, load nhanh hon, thong bao key tu bien mat (DS_NEED_SECONDS),
       toast bo khung, Home / Keys nho lai, History lam lai (id cu ds-hs-in / ds-hs-out / ds-hs-open / ds-hlist ... van nguyen).

   v25 / v24 / v23 / v22 / v21: xem lai cac khoi <style> ben duoi (da giu nguyen).

   v21 (cu): khoa cung khung, bo lien ket Gmail + SDT (bat lai: define('DS_SECURITY_LINKS', true);), nut Back
       (bat lai: define('DS_SHOW_BACK', true);), trang chi tiet kieu shopdsgm.vn (thong so cuon ngang, ngay up, PREVIEWS),
       khoi tai lieu (doi duong dan: define('DS_DOCS_URL', '/docs.php');).
       Anh preview lay tu cot cua bang tools: previews | preview | screenshots | screenshot | gallery | images
       (nhieu anh: moi link mot dong, hoac ngan bang dau phay / | / khoang trang, hoac mang JSON).
       Ngay up lay tu: created_at | date_added | added_at | upload_date | uploaded_at | ngay_up | created | date | time ...

   v20 (cu): Package 3 = tool iOS (Myren iOS, GTA5 by iosvn, Pocket Poster, Filza iOS...). Package 1 (VIP), Package 2 (Free).
       Nhan dien theo ten (mac dinh): define('DS_IOS_KEYWORDS', 'myren,gta5,gta 5,iosvn,pocket poster,filza');
       Hoac theo cot DB cua bang tools: package / pkg / package_id = 1|2|3, hoac category / platform / os = ios.
       Trang chu hien HET tool cua moi goi (mac dinh DS_HOME_PREVIEW = 0). Muon gon lai: define('DS_HOME_PREVIEW', 6);
       Mo tool: mo lai trong DS_REOPEN_SECONDS giay (mac dinh 20) thi khong mat luot. Sau do bam GET se BAT BUOC nhap key lai.
       (giao dien chan o trinh duyet; chan that o server dung ham ds_open_gate_* trong db.php)
       Header GHIM CUNG: position fixed + body chua cho. Tat vet lang bong neu may van giat: define('DS_SHINE', false);
       Avatar dung anh (DS_AVATAR_URL). Moi nguoi mot anh: $GLOBALS['ds_user']['avatar'] = 'https://...';
       Muc Security (trang security.php): doi mat khau. Doi duong dan: define('DS_SECURITY_URL', '/security.php');

   Tuy chon cu van dung duoc: DS_HEADER_STICKY, DS_NAV_GLASS, DS_NAV_REGISTER, DS_TOOLS_PAGE_LIST, DS_USERBAR_SHOW, DS_PRIVACY_URL, DS_TERMS_URL,
   $GLOBALS['ds_user'], $GLOBALS['ds_history'] (xem ham devnvios_theme_body).
   Chi ghi de bang CSS/JS: khong xoa gi cua index.php. Muon quay lai giao dien cu: xoa 2 dong include trong index.php.
   LUU Y: luu file o dang UTF-8 (khong BOM) de khong bi loi chu / dau "?". */

/** Chuoi cua giao dien */
/*DS-ADMIN-V25-BEGIN*/
/* admin_cp.php luu moi tuy chinh trong site_config (khoa ds_*). Khoi nay doc chung va bien thanh hang so DS_* (neu index.php chua tu define). */
function dsx_cfg_all(): ?array
{
    static $all = null;
    if ($all !== null) { return $all; }
    $c = isset($GLOBALS['conn']) ? $GLOBALS['conn'] : null;
    if (!($c instanceof mysqli)) { return null; }
    $all = [];
    try {
        $q = mysqli_query($c, 'SELECT config_key, config_value FROM site_config');
        while ($q && ($r = mysqli_fetch_assoc($q))) {
            if (strncmp((string) $r['config_key'], 'ds_', 3) === 0) { $all[(string) $r['config_key']] = (string) $r['config_value']; }
        }
    } catch (Throwable $x) {
    }
    return $all;
}

function dsx_cfg(string $k, string $d = ''): string
{
    $a = dsx_cfg_all();
    return ($a !== null && isset($a[$k]) && trim($a[$k]) !== '') ? trim($a[$k]) : $d;
}

function dsx_js(string $s): string
{
    return json_encode($s, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function dsx_css_color(string $v): string
{
    $v = trim($v);
    if ($v === '') { return ''; }
    if (preg_match('/^#[0-9a-f]{3,8}$/i', $v) || preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.\s,%\/-]+\)$/i', $v) || preg_match('/^[a-z]{3,20}$/i', $v)) { return $v; }
    return '';
}

function dsx_css_num(string $k, int $min, int $max): string
{
    $v = dsx_cfg($k);
    return ($v !== '' && preg_match('/^\d{1,4}$/', $v) && (int) $v >= $min && (int) $v <= $max) ? (string) (int) $v : '';
}

function dsx_boot(): void
{
    static $done = false;
    if ($done) { return; }
    if (dsx_cfg_all() === null) { return; }
    $done = true;
    foreach ([
        'ds_hero_title' => 'DS_HERO_TITLE', 'ds_hero_desc' => 'DS_HERO_DESC', 'ds_ios_keywords' => 'DS_IOS_KEYWORDS',
        'ds_privacy_url' => 'DS_PRIVACY_URL', 'ds_terms_url' => 'DS_TERMS_URL', 'ds_security_url' => 'DS_SECURITY_URL', 'ds_docs_url' => 'DS_DOCS_URL',
        'ds_hero_images' => 'DS_HERO_IMAGES', 'ds_avatar_url' => 'DS_AVATAR_URL',
    ] as $k => $cn) {
        $v = dsx_cfg($k);
        if ($v !== '' && !defined($cn)) { define($cn, $v); }
    }
    foreach ([
        'ds_hero_top' => 'DS_HERO_TOP', 'ds_hero_width' => 'DS_HERO_WIDTH', 'ds_nav_see_alpha' => 'DS_NAV_SEE_ALPHA', 'ds_boot_ms' => 'DS_BOOT_MS',
        'ds_toast_top' => 'DS_TOAST_TOP', 'ds_reopen_seconds' => 'DS_REOPEN_SECONDS', 'ds_home_preview' => 'DS_HOME_PREVIEW',
    ] as $k => $cn) {
        $v = dsx_cfg($k);
        if ($v !== '' && preg_match('/^-?\d{1,6}$/', $v) && !defined($cn)) { define($cn, (int) $v); }
    }
    foreach ([
        'ds_hero_seconds' => 'DS_HERO_SECONDS', 'ds_shine_seconds' => 'DS_SHINE_SECONDS', 'ds_logo_skew' => 'DS_LOGO_STRAIGHT', 'ds_need_seconds' => 'DS_NEED_SECONDS',
    ] as $k => $cn) {
        $v = str_replace(',', '.', dsx_cfg($k));
        if ($v !== '' && is_numeric($v) && !defined($cn)) { define($cn, (float) $v); }
    }
    foreach ([
        'ds_hero_opt' => 'DS_HERO_OPT', 'ds_shine' => 'DS_SHINE', 'ds_snow' => 'DS_SNOW', 'ds_nav_see' => 'DS_NAV_SEE', 'ds_nav_float' => 'DS_NAV_FLOAT', 'ds_glass_blur' => 'DS_GLASS_BLUR',
        'ds_toast_keys' => 'DS_TOAST_KEYS', 'ds_scroll_shell' => 'DS_SCROLL_SHELL', 'ds_show_back' => 'DS_SHOW_BACK', 'ds_nav_register' => 'DS_NAV_REGISTER',
        'ds_tools_list' => 'DS_TOOLS_PAGE_LIST', 'ds_security_links' => 'DS_SECURITY_LINKS', 'ds_userbar' => 'DS_USERBAR_SHOW',
    ] as $k => $cn) {
        $v = dsx_cfg($k);
        if (($v === '1' || $v === '0') && !defined($cn)) { define($cn, $v === '1'); }
    }
}
dsx_boot();

/* CSS + JS do admin dieu khien: mau, co chu, kich thuoc, bao tri tool, CSS rieng. In ngay truoc </body> nen thang moi khoi cu. */
function dsx_out(): void
{
    dsx_boot();
    $S = 'html:root:root:root:root:root:root';
    $css = '';
    $vm = [
        'bg' => ['--ds-bg'], 'card' => ['--ds-card'], 'field' => ['--ds-field'], 'line' => ['--ds-line'], 'line2' => ['--ds-line2'],
        'text' => ['--ds-h'], 'muted' => ['--ds-p'], 'accent' => ['--blue'], 'link' => ['--ds-link'], 'vip' => ['--ds-red'],
        'ok' => ['--ds-ok'], 'warn' => ['--ds-warn'], 'header' => ['--ds-hd-bg', '--ds-hd-soft'], 'nav' => ['--ds-nav', '--ds-nav-soft'],
    ];
    $sm = ['link' => ['--ds-soft:16', '--ds-pill:20'], 'ok' => ['--ds-ok-soft:14'], 'vip' => ['--ds-red-soft:14'], 'warn' => ['--ds-warn-soft:15']];
    $acc = false;
    foreach ([['ds_c_', ':not([data-theme="light"])'], ['ds_l_', '[data-theme="light"]']] as $g) {
        $d = '';
        $d2 = '';
        foreach ($vm as $ck => $vars) {
            $c = dsx_css_color(dsx_cfg($g[0] . $ck));
            if ($c === '') { continue; }
            if ($ck === 'accent') { $acc = true; }
            foreach ($vars as $vn) { $d .= $vn . ':' . $c . ';'; }
            if (isset($sm[$ck])) {
                foreach ($sm[$ck] as $sp) {
                    $pp = explode(':', $sp);
                    $d2 .= $pp[0] . ':color-mix(in srgb,' . $c . ' ' . $pp[1] . '%,transparent);';
                }
            }
        }
        if ($d !== '') { $css .= 'html:root:root:root:root' . $g[1] . '{' . $d . '}'; }
        if ($d2 !== '') { $css .= '@supports (color:color-mix(in srgb,red 10%,transparent)){html:root:root:root:root' . $g[1] . '{' . $d2 . '}}'; }
    }
    if ($acc) { $css .= $S . ' body .ds-det .ds-get:not(.is-mismatch):not(.is-mt):not([disabled]){background:var(--blue)!important}'; }

    $hd = dsx_css_num('ds_hd', 40, 80);
    $lh = dsx_css_num('ds_logo_h', 24, 70);
    if ($hd !== '' || $lh !== '') {
        $css .= 'html:root:root:root:root{' . ($hd !== '' ? '--hd:' . $hd . 'px;' : '') . ($lh !== '' ? '--logo-h:' . $lh . 'px;' : '') . '}';
    }
    if (($v = dsx_css_num('ds_row_icon', 36, 90)) !== '') { $css .= $S . ' .ds-row img{width:' . $v . 'px!important;height:' . $v . 'px!important}'; }
    if (($v = dsx_css_num('ds_row_name_size', 11, 24)) !== '') { $css .= $S . ' .ds-row__info strong{font-size:' . $v . 'px!important}'; }
    if (($v = dsx_css_num('ds_pkg_size', 12, 40)) !== '') { $css .= $S . ' .ds-products .ds-sec__title{font-size:' . $v . 'px!important}'; }
    if (($v = dsx_css_num('ds_hero_title_size', 16, 60)) !== '') { $css .= $S . ' .ds-hero__title{font-size:' . $v . 'px!important}'; }
    if (($v = dsx_css_num('ds_slider_radius', 0, 40)) !== '') { $css .= $S . ' .ds-slider{border-radius:' . $v . 'px!important}'; }

    $ss = str_replace(',', '.', dsx_cfg('ds_shine_seconds'));
    if ($ss !== '' && is_numeric($ss)) {
        $f = max(0.8, min(20, (float) $ss));
        $fs = rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
        $hs = rtrim(rtrim(number_format($f / 2, 2, '.', ''), '0'), '.');
        $css .= $S . ' .ds-logo .ds-sw,' . $S . ' .ds-logo .ds-swi{animation-duration:' . $fs . 's!important}'
            . $S . ' .ds-logo .ds-sw2,' . $S . ' .ds-logo .ds-sw2 .ds-swi{animation-delay:-' . $hs . 's!important}';
    }
    if (defined('DS_SHINE') && !DS_SHINE) { $css .= $S . ' .ds-logo .ds-sw{display:none!important}'; }
    if (defined('DS_SNOW') && !DS_SNOW) { $css .= $S . ' #ds-snow{display:none!important}'; }

    /* tool bao tri */
    $css .= '.ds-stat b.is-warn,.ds-info b.is-warn{color:var(--ds-warn)}'
        . '.ds-mtnote{margin:14px 0 0;padding:11px 14px;font-size:13px;font-weight:600;line-height:1.45;color:var(--ds-warn);background:var(--ds-warn-soft);border-radius:14px}'
        . '.ds-row__mt{font-style:normal;color:var(--ds-warn)}'
        . $S . ' body .ds-det .ds-get.is-mt{color:#8e8e93!important;background:#2c2c2e!important;pointer-events:none!important}'
        . 'html:root:root:root:root:root[data-theme="light"] body .ds-det .ds-get.is-mt{color:#6b7280!important;background:#e5e7eb!important}';

    echo '<style id="ds-admin-v25">' . $css . '</style>' . "\n";
    dsx_extra_out();
    $cc = dsx_cfg('ds_custom_css');
    if ($cc !== '') { echo '<style id="ds-admin-custom">' . str_ireplace('</style', '<\\/style', $cc) . '</style>' . "\n"; }
    ?>
<script id="ds-admin-v25-js">
(function () {
    try {
        var G = <?= dsx_js(ds_t('get')) ?>;
        if (G !== 'GET') {
            document.querySelectorAll('.ds-get').forEach(function (b) {
                var n = b.lastChild;
                if (n && n.nodeType === 3) n.nodeValue = G;
            });
        }
        document.addEventListener('click', function (e) {
            var g = e.target && e.target.closest ? e.target.closest('.ds-det[data-mt="1"] .ds-get') : null;
            if (!g) return;
            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) e.stopImmediatePropagation();
        }, true);
    } catch (e) {}
})();
</script>
<?php
}
/*DS-ADMIN-V26-BEGIN*/
/* Them ban v26: font, nut GET / View / Chia se, avatar, an / hien, thay chu tu do. Cau hinh do admin_cp.php luu. */
function dsx_extra_out(): void
{
    $fu = dsx_cfg('ds_font_url');
    if ($fu !== '' && preg_match('#^https://fonts\.googleapis\.com/[A-Za-z0-9_\-./?=&:+,@;%]*$#', $fu)) {
        echo '<link rel="stylesheet" href="' . htmlspecialchars($fu, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
    echo '<style id="ds-admin-v26">' . dsx_extra_css() . '</style>' . "\n";
}

function dsx_extra_css(): string
{
    $S = 'html:root:root:root:root:root:root';
    /* dong mo ta rong cua Package thi an di */
    $o = '.ds-sec__sub:empty,.ds-pkgsub:empty{display:none}';

    /* phong chu */
    $f = (string) preg_replace('/[^A-Za-z0-9 ,\'_-]/', '', dsx_cfg('ds_font'));
    if ($f !== '') {
        $fl = [];
        foreach (explode(',', $f) as $fp) {
            $fp = trim($fp);
            if ($fp === '') { continue; }
            $fl[] = (strpos($fp, ' ') !== false) ? "'" . $fp . "'" : $fp;
        }
        if ($fl) {
            $o .= 'html:root:root:root:root:root{--ds-font:' . implode(',', $fl) . ',-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}';
        }
    }

    /* nut GET */
    $g = '';
    if (($v = dsx_css_color(dsx_cfg('ds_get_bg'))) !== '') { $g .= 'background:' . $v . '!important;'; }
    if (($v = dsx_css_color(dsx_cfg('ds_get_fg'))) !== '') { $g .= 'color:' . $v . '!important;'; }
    if (($v = dsx_css_num('ds_get_radius', 0, 30)) !== '') { $g .= 'border-radius:' . $v . 'px!important;'; }
    if ($g !== '') { $o .= $S . ' body .ds-det .ds-get:not(.is-mismatch):not(.is-mt):not([disabled]){' . $g . '}'; }

    /* nut View trong danh sach */
    $g = '';
    if (($v = dsx_css_color(dsx_cfg('ds_view_bg'))) !== '') { $g .= 'background:' . $v . '!important;'; }
    if (($v = dsx_css_color(dsx_cfg('ds_view_fg'))) !== '') { $g .= 'color:' . $v . '!important;'; }
    if ($g !== '') { $o .= $S . ' .ds-open{' . $g . '}'; }

    /* nut chia se */
    $g = '';
    if (($v = dsx_css_color(dsx_cfg('ds_share_bg'))) !== '') { $g .= 'background:' . $v . '!important;'; }
    if (($v = dsx_css_color(dsx_cfg('ds_share_fg'))) !== '') { $g .= 'color:' . $v . '!important;'; }
    if ($g !== '') { $o .= $S . ' body .ds-det .ds-share{' . $g . '}'; }

    /* hinh dang avatar (mac dinh la tron) */
    $sh = dsx_cfg('ds_avatar_shape');
    if ($sh === 'rounded' || $sh === 'square') {
        $r = ($sh === 'rounded') ? '12px' : '0';
        $o .= 'html:root:root body #ds-avatar,html:root:root body #ds-avatar .ds-av__img{border-radius:' . $r . '!important;-webkit-clip-path:none!important;clip-path:none!important}';
    }

    /* an / hien thanh phan: gia tri 0 = an */
    $hide = [
        'ds_show_title'     => ['.ds-hero__title', '.ds-hero__desc'],
        'ds_show_slider'    => ['.ds-slider'],
        'ds_show_chips'     => ['.ds-chips2'],
        'ds_show_join'      => ['.group-link'],
        'ds_show_pkgsub'    => ['.ds-products .ds-sec__sub'],
        'ds_show_docs'      => ['.ds-docs'],
        'ds_tab_keys'       => ['.ds-nav #ds-keys'],
        'ds_tab_tools'      => ['.ds-nav #ds-tools'],
        'ds_tab_hist'       => ['.ds-nav #ds-hist'],
        'ds_show_theme_btn' => ['#ds-theme-btn'],
        'ds_show_lang'      => ['#ds-lang-btn'],
    ];
    foreach ($hide as $hk => $sels) {
        if (dsx_cfg($hk) !== '0') { continue; }
        $parts = [];
        foreach ($sels as $sl) { $parts[] = $S . ' ' . $sl; }
        $o .= implode(',', $parts) . '{display:none!important}';
    }
    return $o;
}

/* Thay chu tu do: ds_replace = "Chu goc|Chu moi" moi dong mot cap */
function dsx_text_map(): array
{
    static $m = null;
    if ($m !== null) { return $m; }
    $m = [];
    foreach (preg_split('/\r\n|\r|\n/', dsx_cfg('ds_replace')) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#') { continue; }
        $p = strpos($ln, '|');
        if ($p === false || $p < 1) { continue; }
        $a = trim(substr($ln, 0, $p));
        $b = trim(substr($ln, $p + 1));
        if ($a !== '' && count($m) < 300) { $m[$a] = $b; }
    }
    return $m;
}

function dsx_text_start(): void
{
    if (!empty($GLOBALS['__dsx_ob']) || !dsx_text_map()) { return; }
    ob_start();
    $GLOBALS['__dsx_ob'] = ob_get_level();
}

function dsx_text_apply(string $seg, array $map): string
{
    $r = preg_replace_callback('/>([^<>]+)</', function ($m) use ($map) {
        if (!preg_match('/^(\s*)(.*?)(\s*)$/s', $m[1], $w)) { return $m[0]; }
        $k = html_entity_decode($w[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($k === '' || !isset($map[$k])) { return $m[0]; }
        return '>' . $w[1] . htmlspecialchars($map[$k], ENT_QUOTES, 'UTF-8') . $w[3] . '<';
    }, $seg);
    if ($r !== null) { $seg = $r; }
    $r = preg_replace_callback('/\b(placeholder|aria-label|title)="([^"]*)"/', function ($m) use ($map) {
        $k = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return isset($map[$k]) ? $m[1] . '="' . htmlspecialchars($map[$k], ENT_QUOTES, 'UTF-8') . '"' : $m[0];
    }, $seg);
    return $r !== null ? $r : $seg;
}

function dsx_text_flush(): void
{
    $lvl = isset($GLOBALS['__dsx_ob']) ? (int) $GLOBALS['__dsx_ob'] : 0;
    if ($lvl < 1) { return; }
    $GLOBALS['__dsx_ob'] = 0;
    while (ob_get_level() > $lvl) { ob_end_flush(); }
    if (ob_get_level() < $lvl) { return; }
    $html = (string) ob_get_clean();
    $map = dsx_text_map();
    $out = '';
    $pos = 0;
    $n = strlen($html);
    while ($pos < $n) {
        /* khong dong vao noi dung <script> / <style> */
        if (!preg_match('/<(script|style)\b/i', $html, $mm, PREG_OFFSET_CAPTURE, $pos)) {
            $out .= dsx_text_apply(substr($html, $pos), $map);
            break;
        }
        $st = $mm[0][1];
        $out .= dsx_text_apply(substr($html, $pos, $st - $pos), $map);
        $end = stripos($html, '</' . $mm[1][0] . '>', $st);
        if ($end === false) { $out .= substr($html, $st); break; }
        $end += strlen($mm[1][0]) + 3;
        $out .= substr($html, $st, $end - $st);
        $pos = $end;
    }
    echo $out;
}
/*DS-ADMIN-V26-END*/
/*DS-ADMIN-V25-END*/

/*DS-DAILY-IP-KEY-GUARD-BEGIN*/
/* patch: v25-theme-fix -- the IP now comes only from trusted sources: the same function the site uses when a key is activated.
   (X-Forwarded-For / Client-IP are headers the visitor can fake, so they are no longer read.) */
function ds_real_client_ip(): string
{
    if (function_exists('daily_ip')) {
        return daily_ip();
    }
    if (function_exists('client_ip')) {
        return client_ip();
    }
    return isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '0.0.0.0';
}

function ds_daily_key_store_path(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'ds_key_daily_lock.php';
}

function ds_daily_key_load(): array
{
    $f = ds_daily_key_store_path();
    if (!is_file($f)) {
        return [];
    }
    $raw = @file_get_contents($f);
    if (is_string($raw) && strncmp($raw, '<?php', 5) === 0) { // patch: v25-theme-fix -- skip the guard line at the top of the file
        $nlPos = strpos($raw, "\n");
        $raw   = $nlPos === false ? '' : substr($raw, $nlPos + 1);
    }
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function ds_daily_key_hours(): int
{
    /*FKX-HOURS*/ /* same cycle as get_key.php / key.php: FK_COOLDOWN seconds (default 28800 = 8 hours). fk_config.php is only read, never included. */
    static $h = null;
    if ($h !== null) {
        return $h;
    }
    $sec = defined('FK_COOLDOWN') ? (int) FK_COOLDOWN : 0;
    if ($sec <= 0) {
        $cf = __DIR__ . DIRECTORY_SEPARATOR . 'fk_config.php';
        if (is_file($cf)) {
            $src = @file_get_contents($cf);
            if (is_string($src) && preg_match('/define\s*\(\s*[\'"]FK_COOLDOWN[\'"]\s*,\s*(\d+)/', $src, $m)) {
                $sec = (int) $m[1];
            }
        }
    }
    if ($sec <= 0) {
        $sec = 28800;
    }
    $h = max(1, (int) round($sec / 3600));
    return $h;
}

function ds_daily_key_save(array $data): void
{
    /* rolling lock by hours, not by calendar day: keep only entries newer than the lock window */
    $lim = time() - ds_daily_key_hours() * 3600;
    $out = ['roll' => ['ips' => [], 'users' => [], 'keys' => []]];
    if (!empty($data['roll']) && is_array($data['roll'])) {
        foreach (['ips', 'users', 'keys'] as $g) {
            if (!empty($data['roll'][$g]) && is_array($data['roll'][$g])) {
                foreach ($data['roll'][$g] as $k => $ts) {
                    if ((int) $ts > $lim) { $out['roll'][$g][$k] = (int) $ts; }
                }
            }
        }
    }
    @file_put_contents(ds_daily_key_store_path(), "<?php http_response_code(404); exit; ?>\n" . json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function ds_current_uid_key(): string
{
    if (!empty($_SESSION['user_id'])) return 'u_' . $_SESSION['user_id'];
    if (!empty($_SESSION['id'])) return 'u_' . $_SESSION['id'];
    if (!empty($_SESSION['username'])) return 'u_' . strtolower(trim((string) $_SESSION['username']));
    if (!empty($_SESSION['user']['id'])) return 'u_' . $_SESSION['user']['id'];
    if (!empty($GLOBALS['ds_user']['id'])) return 'u_' . $GLOBALS['ds_user']['id'];
    if (!empty($GLOBALS['ds_user']['name'])) return 'u_' . strtolower(trim((string) $GLOBALS['ds_user']['name']));
    return '';
}

/* patch: v25-theme-fix -- the daily lock follows the SAME key types as index.php (KEY_DAILY_LIMIT_TYPES).
   If index.php does not define that constant, every key type is locked, like before. */
function ds_daily_key_applies(): bool
{
    if (!defined('KEY_DAILY_LIMIT_TYPES')) {
        return true;
    }
    $kt = strtoupper(trim((string) (isset($GLOBALS['keyType']) ? $GLOBALS['keyType'] : '')));
    return $kt === '' || in_array($kt, KEY_DAILY_LIMIT_TYPES, true);
}

function ds_is_daily_key_locked(): bool
{
    /* locked if this IP or account activated a key within the last ds_daily_key_hours() hours */
    $data = ds_daily_key_load();
    if (empty($data['roll']) || !is_array($data['roll'])) {
        return false;
    }
    $lim = time() - ds_daily_key_hours() * 3600;
    $ip  = ds_real_client_ip();
    $uid = ds_current_uid_key();
    if (!empty($data['roll']['ips'][$ip]) && (int) $data['roll']['ips'][$ip] > $lim) {
        return true;
    }
    if ($uid !== '' && !empty($data['roll']['users'][$uid]) && (int) $data['roll']['users'][$uid] > $lim) {
        return true;
    }
    return false;
}

function ds_mark_daily_key_used(?string $keyVal = null): void
{
    $data = ds_daily_key_load();
    if (empty($data['roll']) || !is_array($data['roll'])) {
        $data['roll'] = ['ips' => [], 'users' => [], 'keys' => []];
    }
    $ip  = ds_real_client_ip();
    $uid = ds_current_uid_key();
    $data['roll']['ips'][$ip] = time();
    if ($uid !== '') {
        $data['roll']['users'][$uid] = time();
    }
    if ($keyVal !== null && trim($keyVal) !== '') {
        $data['roll']['keys'][ strtoupper(trim($keyVal)) ] = time();
    }
    ds_daily_key_save($data);
}

/* Ki?m tra n?u request POST g?i kích ho?t key ho?c URL tr? v? status=success */
(function () {
    if (isset($_GET['ds_mark_key_ok']) && $_GET['ds_mark_key_ok'] === '1') {
        $kv = isset($_POST['key']) ? (string) $_POST['key'] : '';
        ds_mark_daily_key_used($kv);
        while (ob_get_level() > 0) { @ob_end_clean(); } // patch: v25-theme-fix -- drop the page already printed, so only JSON is sent
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true, 'locked' => true, 'date' => date('Y-m-d')]);
        exit;
    }
    if (isset($_GET['ds_check_key_lock']) && $_GET['ds_check_key_lock'] === '1') {
        while (ob_get_level() > 0) { @ob_end_clean(); } // patch: v25-theme-fix -- drop the page already printed, so only JSON is sent
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['locked' => ds_is_daily_key_locked(), 'date' => date('Y-m-d')]);
        exit;
    }
    if (isset($_GET['status']) && $_GET['status'] === 'success') {
        if (ds_daily_key_applies() && !ds_is_daily_key_locked()) { ds_mark_daily_key_used(); }
    }
})();
/*DS-DAILY-IP-KEY-GUARD-END*/

function ds_t(string $key): string
{
    static $s = [
        'vip_t'  => 'VIP tools',
        'free_t' => 'Free tools',
        'vip_s'  => 'Needs a VIP key. One turn is used each time a tool opens.',
        'free_s' => 'Works with a Free key. One turn is used each time a tool opens.',
        'ios_s'  => 'iOS tools. The key type is shown under each tool. One turn is used each time a tool opens.',
        'vip_1'  => 'VIP tool',
        'free_1' => 'Free tool',
        'view'   => 'View',
        'keys'   => 'Keys',
        'tools'  => 'Tools',
        'kh_t'   => 'Activate a key',
        'kh_s'   => 'Paste your VIP or Free key below. You can enter a new key once your turns are used up.',
        'need_key' => 'This tool closed. Enter your key to open it again.',
        'tools_empty' => 'The tools section is currently empty.',
        'pkg1'   => 'Package 1',
        'pkg2'   => 'Package 2',
        'pkg3'   => 'Package 3',
        'search' => 'Search...',
        'no_res' => 'No tools found.',
        'history'     => 'History',
        'me'          => 'Me',
        'logout'      => 'Log out',
        'hist_s'      => 'Turns you received and the tools you opened.',
        'hist_empty'  => 'No activity yet',
        'hist_empty_s' => 'Open a tool or activate a key and it will show up here.',
        'previews'    => 'Previews',
        'no_prev'     => 'No preview available',
        'uploaded'    => 'Uploaded',
        'docs'        => 'Documents',
    ];
    $o = dsx_cfg('ds_t_' . $key);
    if ($o !== '') { return $o; }
    if (isset($s[$key])) { return $s[$key]; }
    static $x = [
        'desc_h' => 'Description', 'feat_h' => 'Features', 'info_h' => 'Detailed information', 'rel_h' => 'You may also like', 'get' => 'GET',
        'hist_kick' => 'Receipts & Orders', 'hist_title' => 'Order History', 'hist_empty_t' => 'No Orders Found',
        'hist_empty_p' => 'All opened tools and received turns will appear here.', 'hist_nores' => 'No results.',
        'tools_kick' => 'Utilities', 'docs_brand' => 'DEVNVIOS',
        'docs_about' => 'Keys and tools for iOS in one place. Pick a package, activate a key, open a tool.',
        'docs_legal_t' => 'Legal disclaimer',
        'docs_legal' => 'Tools are provided for testing and entertainment purposes only. You are responsible for how you use them.',
        'st_active' => 'Active', 'st_ready' => 'Ready', 'st_mt_sub' => 'Under maintenance', 'mt_label' => 'Maintenance',
        'mt_msg' => 'This tool is under maintenance. Please come back later.',
    ];
    /*PATCH-V25-FIX2*/
    $x += [
        'tool_1' => 'tool', 'tool_n' => 'tools',
        'lbl_status' => 'Status', 'lbl_device' => 'Device', 'lbl_plan' => 'Plan', 'lbl_cost' => 'Cost', 'lbl_dev' => 'Developer',
        'lbl_pkg' => 'Package', 'lbl_key_needed' => 'Key needed', 'lbl_cost_open' => 'Cost per open', 'lbl_supports' => 'Supports',
        'lbl_upload_date' => 'Upload date', 'lbl_last_upd' => 'Last updated', 'lbl_version' => 'Version', 'lbl_per_open' => 'Per open',
        'val_device' => 'iPhone, iPad', 'val_cost' => '1 turn', 'val_vipkey' => 'VIP key', 'val_freekey' => 'Free key',
        'need_vip' => 'Needs a VIP key', 'need_free' => 'Needs a Free key',
        'det_vip' => '{name} is a VIP tool from DEVNVIOS. It opens with an active VIP key and uses one turn each time you open it. A Free key cannot open VIP tools.',
        'det_free' => '{name} is a Free tool from DEVNVIOS. It opens with a Free key and uses one turn each time you open it. A VIP key cannot open Free tools.',
        'det_reopen' => 'If the tool does not open, tap OPEN again within {sec} seconds. It reopens without using another turn. After that, you need to enter your key again.',
    ];
    return isset($x[$key]) ? $x[$key] : $key;
}

/** v32: danh sach anh banner trang chu (dung chung cho <head> de tai san anh dau tien, va cho khung banner o cuoi trang).
    Moi phan tu: ['src' => link dung de hien thi (co the la ban nen WebP neu bat DS_HERO_OPT), 'orig' => link anh goc].
    Doi anh: define('DS_HERO_IMAGES', 'link1,link2,link3'); */
function devnvios_theme_hero_images(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    dsx_boot();
    $imgs = [
        'https://i.postimg.cc/rmWwBgXw/5DF6A303-58A5-4ECF-9A0C-D95D8DFFE342.png',
        'https://i.postimg.cc/3RDkKYpp/E8288903-7705-4636-9F20-4B7F63BCDB53.png',
        'https://i.postimg.cc/x1TqXKMf/9448B139-AA7C-44CF-8622-B1AF26FF79CA.png',
    ];
    if (defined('DS_HERO_IMAGES')) {
        $hx = [];
        foreach (preg_split('/[\s,]+/', (string) DS_HERO_IMAGES, -1, PREG_SPLIT_NO_EMPTY) as $hu) {
            if (preg_match('#^(https?://|/(?!/))#i', $hu)) { $hx[] = $hu; }
        }
        if ($hx) { $imgs = $hx; }
    }
    /* DS_HERO_OPT = true: nen anh sang WebP nho qua wsrv.nl (anh PNG goc thuong rat nang). Loi => tu dung anh goc. */
    $opt = defined('DS_HERO_OPT') && DS_HERO_OPT;
    $w   = defined('DS_HERO_WIDTH') ? max(480, min(1600, (int) DS_HERO_WIDTH)) : 960;
    $list = [];
    foreach ($imgs as $u) {
        $src = $u;
        if ($opt && preg_match('#^https://#i', $u)) {
            $src = 'https://wsrv.nl/?url=' . rawurlencode($u) . '&w=' . $w . '&output=webp&q=82';
        }
        $list[] = ['src' => $src, 'orig' => $u];
    }
    return $list;
}

/** v28: man hinh nap (vong tron xoay). Dung cho MOI trang: trang chu (tu dong qua devnvios_theme_head),
    con trang dang nhap / dang ky chi can goi devnvios_theme_boot(); trong <head>. Goi nhieu lan van chi in 1 lan. */
function devnvios_theme_boot(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    /* thoi gian toi thieu hien vong tron (mili giay), mac dinh 800, doi bang define('DS_BOOT_MS', 800); */
    dsx_boot();
    $dsBootMs = defined('DS_BOOT_MS') ? max(200, min(3000, (int) DS_BOOT_MS)) : 700;
    ?>
<!--DS-PATCH-V48-BOOT-BEGIN-->
<style id="ds-patch-v48-boot">
/* v48: vong tron load (luc vao web / tai lai / quay lai) = DUNG 1 vong xoay xanh. Khong vong phu, khong vong nho ben trong, khong phat sang. */
html:root:root:root:root #ds-boot > *:not(.ds-sp1) { display: none !important; }
html:root:root:root:root #ds-boot .ds-sp1 { display: block !important; width: 32px !important; height: 32px !important; max-width: none !important; margin: 0 !important; fill: none; overflow: visible; opacity: 1 !important; -webkit-filter: none !important; filter: none !important; box-shadow: none !important; -webkit-animation: ds-sp1-rot .9s linear infinite !important; animation: ds-sp1-rot .9s linear infinite !important; }
html:root:root:root:root #ds-boot .ds-sp1 * { -webkit-animation: none !important; animation: none !important; -webkit-filter: none !important; filter: none !important; }
@keyframes ds-sp1-rot { to { -webkit-transform: rotate(360deg); transform: rotate(360deg); } }
</style>
<script id="ds-patch-v48-boot-js">
(function () {
    try {
        if (window.__dsP48b) return;
        window.__dsP48b = 1;
        var ONE = '<svg class="ds-sp1" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9.5" fill="none" stroke="#0a84ff" stroke-width="2" stroke-linecap="round" stroke-dasharray="16.7 43"></circle></svg>';
        function fix() {
            var b = document.getElementById('ds-boot');
            if (!b) return;
            var f = b.firstChild;
            if (b.children.length === 1 && f && f.classList && f.classList.contains('ds-sp1')) return;
            b.innerHTML = ONE;
        }
        fix();
        if (window.MutationObserver) new MutationObserver(fix).observe(document.documentElement, { childList: true });
        document.addEventListener('DOMContentLoaded', fix);
    } catch (e) {}
})();
</script>
<!--DS-PATCH-V48-BOOT-END-->
<style id="ds-boot-css">
    /* patch_spinner3: vong xoay moi - nho gon, khong chu, chuyen dong muot */
    #ds-boot { position: fixed; left: 0; right: 0; top: 0; bottom: 0; z-index: 99999; display: flex; align-items: center; justify-content: center; background: #000; opacity: 1; transition: opacity .28s ease; }
    #ds-boot.is-out { opacity: 0; pointer-events: none; }
    #ds-boot::before, #ds-boot::after { content: none !important; }
    html #ds-boot > *:not(svg) { display: none !important; }
    html #ds-boot svg { width: 36px; height: 36px; fill: none; overflow: visible; opacity: 1; transition: transform .28s ease; animation: ds-bt-in .5s cubic-bezier(.2, .8, .2, 1) backwards; }
    #ds-boot.is-out svg { transform: scale(1.18); }
    #ds-boot .ds-bt-glow, #ds-boot .ds-bt-a, #ds-boot .ds-bt-b { transform-box: view-box; transform-origin: 24px 24px; }
    #ds-boot .ds-bt-glow { animation: ds-bt-pulse 1.8s ease-in-out infinite; }
    #ds-boot .ds-bt-a { animation: ds-bt-rot 1.5s linear infinite; }
    #ds-boot .ds-bt-b { animation: ds-bt-rot 2.4s cubic-bezier(.65, 0, .35, 1) infinite reverse; }
    #ds-boot .ds-bt-arc { animation: ds-bt-dash 1.5s cubic-bezier(.45, .05, .3, .95) infinite; }
    @keyframes ds-bt-rot { to { transform: rotate(360deg); } }
    @keyframes ds-bt-dash {
        0%   { stroke-dasharray: 4 109.1;  stroke-dashoffset: 0; }
        50%  { stroke-dasharray: 66 47.1;  stroke-dashoffset: -26; }
        100% { stroke-dasharray: 4 109.1;  stroke-dashoffset: -113.1; }
    }
    @keyframes ds-bt-pulse { 0%, 100% { transform: scale(.8); opacity: .55; } 50% { transform: scale(1.08); opacity: 1; } }
    @keyframes ds-bt-in { from { opacity: 0; transform: scale(.72); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { #ds-boot .ds-bt-glow { animation: none; } #ds-boot .ds-bt-b { animation-duration: 4s; } }
    html.ds-quiet #ds-boot { display: none; }
</style>
<script>
/* v28: man hinh nap hien o MOI lan vao web / tai lai (khong con "chi hien khi nap lau").
   Hien toi thieu DS_BOOT_MS, dong khi trang san sang. Khong hien neu vua nhap key / vua co thong bao (ds-quiet). */
(function () {
    try {
        if (window.__dsBootOn) return;
        window.__dsBootOn = true;
        var d = document.documentElement, t0 = Date.now(), closed = false, MIN = <?= (int) $dsBootMs ?>, MAX = MIN + 1500;
        var themed = (typeof window.__dsBoot !== 'undefined');
        var qq = null;
        try { qq = sessionStorage.getItem('ds-quiet'); sessionStorage.removeItem('ds-quiet'); } catch (e) {}
        if (qq && Date.now() - parseInt(qq, 10) < 20000) { d.classList.add('ds-quiet'); return; }
        var light = false;
        try {
            var th = localStorage.getItem('ds-theme');
            light = th === 'light' || (th === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches);
        } catch (e) {}
        var b = document.createElement('div');
        b.id = 'ds-boot';
        b.setAttribute('aria-hidden', 'true');
        b.style.background = light ? '#f5f7fa' : '#000';
        /* patch_spinner3: chi con hinh vong xoay (khong chu). Sang / toi doi mau rieng. */
        var tk = light ? '#dfe3ea' : '#1d1d22';
        b.innerHTML = '<svg viewBox="0 0 48 48" aria-hidden="true">'
            + '<defs>'
            + '<linearGradient id="ds-bt-g" gradientUnits="userSpaceOnUse" x1="6" y1="8" x2="42" y2="40"><stop offset="0" stop-color="#5ac8fa"/><stop offset=".55" stop-color="#0a84ff"/><stop offset="1" stop-color="#7d6bff"/></linearGradient>'
            + '<radialGradient id="ds-bt-r"><stop offset="0" stop-color="#0a84ff" stop-opacity="' + (light ? '.16' : '.3') + '"/><stop offset="1" stop-color="#0a84ff" stop-opacity="0"/></radialGradient>'
            + '</defs>'
            + '<circle class="ds-bt-glow" cx="24" cy="24" r="23" fill="url(#ds-bt-r)"/>'
            + '<circle cx="24" cy="24" r="18" fill="none" stroke="' + tk + '" stroke-width="1.8"/>'
            + '<g class="ds-bt-a"><circle class="ds-bt-arc" cx="24" cy="24" r="18" fill="none" stroke="url(#ds-bt-g)" stroke-width="2.6" stroke-linecap="round" stroke-dasharray="4 109.1"/></g>'
            + '<g class="ds-bt-b"><circle cx="24" cy="24" r="11" fill="none" stroke="url(#ds-bt-g)" stroke-width="1.8" stroke-linecap="round" stroke-dasharray="20 49.1" opacity=".55"/></g>'
            + '</svg>';
        d.appendChild(b);
        /* v32: trang chu cho them anh banner dau tien hien xong (window.__dsHeroWait) roi moi dong vong tron; qua MAX thi dong luon */
        function ready() { return (themed ? d.classList.contains('ds-ready') : document.readyState === 'complete') && !window.__dsHeroWait; }
        function close() {
            if (closed) return;
            closed = true;
            b.classList.add('is-out');
            setTimeout(function () { if (b.parentNode) b.parentNode.removeChild(b); }, 300);
        }
        function check() {
            var el = Date.now() - t0;
            if ((ready() && el >= MIN) || el >= MAX) { close(); return; }
            setTimeout(check, 40);
        }
        check();
        /* patch_spinner3: quay lai trang tu bo nho dem (back / forward tren Chrome, Google, Safari) cung hien vong xoay ngan roi moi vao */
        window.addEventListener('pageshow', function (ev) {
            try {
                if (!ev.persisted) return;
                var o = document.getElementById('ds-boot');
                if (o && o.parentNode) o.parentNode.removeChild(o);
                var n = b.cloneNode(true);
                n.className = '';
                d.appendChild(n);
                setTimeout(function () {
                    n.classList.add('is-out');
                    setTimeout(function () { if (n.parentNode) n.parentNode.removeChild(n); }, 320);
                }, 500);
            } catch (e) {}
        });
    } catch (e) {}
})();
</script>
<?php
}

function devnvios_theme_head(): void
{
    /* Ep UTF-8 (neu header chua duoc gui) de ten tool tieng Viet trong DB khong bi "?" */
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }

    /* Link chia se rieng cua tool: ?devnvios=ID-ten-tool (doc ca link cu ?ds_tool=ID) */
    $bootId = 0;
    foreach (['devnvios', 'ds_tool'] as $dsK) {
        if ($bootId <= 0 && isset($_GET[$dsK]) && is_string($_GET[$dsK])) {
            $bootId = (int) $_GET[$dsK];
        }
    }
    /* Trang goi: ?package=1 (VIP), ?package=2 (Free), ?package=3 (iOS) */
    $bootPkg = (isset($_GET['package']) && is_string($_GET['package'])) ? (int) $_GET['package'] : 0;
    /* Xem truoc link khi dan vao Messenger/Telegram (neu index.php da nap danh sach tool) */
    if ($bootId > 0 && !empty($GLOBALS['tools']) && is_array($GLOBALS['tools'])) {
        $dsH = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        foreach ($GLOBALS['tools'] as $dsT) {
            if ((int) (isset($dsT['id']) ? $dsT['id'] : 0) === $bootId) {
                $dsVip = strtoupper(trim((string) (isset($dsT['type']) ? $dsT['type'] : 'FREE'))) === 'VIP';
                $dsNm  = isset($dsT['name']) ? (string) $dsT['name'] : '';
                echo '<meta property="og:title" content="' . $dsH($dsNm . ' - ' . ($dsVip ? 'VIP tool' : 'Free tool')) . '">' . "\n";
                echo '<meta property="og:description" content="' . $dsH('Open ' . $dsNm . ' on DEVNVIOS.') . '">' . "\n";
                if (!empty($dsT['image']) && preg_match('#^https?://#i', (string) $dsT['image'])) {
                    echo '<meta property="og:image" content="' . $dsH($dsT['image']) . '">' . "\n";
                }
                break;
            }
        }
    }
    /* v32: tai san anh banner dau tien NGAY TRONG <head> (chi o trang chu). Truoc day thanh <img> nam o cuoi trang nen anh chi
       bat dau tai khi trinh duyet doc toi cuoi file => vao web phai doi lau moi thay anh. */
    $dsPreUrl = '';
    $dsPreOrigin = '';
    if ($bootId <= 0 && $bootPkg <= 0) {
        $dsPreList = devnvios_theme_hero_images();
        if ($dsPreList) {
            $dsPreUrl = $dsPreList[0]['src'];
            $dsPo = parse_url($dsPreUrl);
            if (!empty($dsPo['scheme']) && !empty($dsPo['host'])) {
                $dsPreOrigin = $dsPo['scheme'] . '://' . $dsPo['host'] . (!empty($dsPo['port']) ? ':' . (int) $dsPo['port'] : '');
            }
        }
    }
    ?>
<meta charset="utf-8">
<?php if ($dsPreUrl !== ''): ?>
<?php if ($dsPreOrigin !== ''): ?>
<link rel="preconnect" href="<?= htmlspecialchars($dsPreOrigin, ENT_QUOTES, 'UTF-8') ?>">
<link rel="dns-prefetch" href="<?= htmlspecialchars($dsPreOrigin, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<link rel="preload" as="image" href="<?= htmlspecialchars($dsPreUrl, ENT_QUOTES, 'UTF-8') ?>" fetchpriority="high" referrerpolicy="no-referrer">
<script>window.__dsHeroWait=true;</script>
<?php endif; ?>
<script>try{var __t=localStorage.getItem('ds-theme');if(__t==='light'||(__t==='auto'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: light)').matches))document.documentElement.setAttribute('data-theme','light');}catch(e){}try{if('scrollRestoration' in history)history.scrollRestoration='manual';}catch(e){}(function(){var de=document.documentElement;setTimeout(function(){de.classList.add('ds-ready');},1200);var id=<?= (int) $bootId ?>,pk=<?= (int) $bootPkg ?>;var st=null,nt='';try{st=JSON.parse(sessionStorage.getItem('ds-st')||'null');}catch(e){}try{nt=performance.getEntriesByType('navigation')[0].type;}catch(e){}var rs=!!(st&&st.p===location.pathname&&(nt==='reload'||nt==='back_forward'||(Date.now()-(st.t||0))<10000));window.__dsRestore=rs;try{if(!id){var q=/[?&](?:devnvios|ds_tool)=(\d+)/.exec(location.search)||/^#tool-(\d+)$/.exec(location.hash);if(q)id=parseInt(q[1],10)||0;}}catch(e){}if(!id&&rs&&st.d)id=parseInt(st.d,10)||0;window.__dsBoot=id||0;if(!pk){var pm=/[?&]package=(\d+)/.exec(location.search);if(pm)pk=parseInt(pm[1],10)||0;}window.__dsPkg=id?0:(pk||0);try{if(!id&&!window.__dsPkg){var v=sessionStorage.getItem('ds-view');if(v==='keys')de.classList.add('ds-keys');else if(v==='tools')de.classList.add('ds-tools');else if(v==='hist')de.classList.add('ds-hist');else if(v==='me')de.classList.add('ds-me');}}catch(e){}})();</script>
<style id="ds-theme">
    /* :root:root thang ca mau admin dat trong site_config */
    :root:root {
        color-scheme: dark;
        --ds-bg: #000; --ds-card: #0a0a0b; --ds-field: #141416; --ds-line: #1f1f23; --ds-line2: #26262b;
        --ds-h: #fff; --ds-p: #9ca3af; --ds-link: #3b9eff; --ds-soft: #0d2744; --ds-nav: #0e0e10;
        --ds-red: #ff6961; --ds-menu-head: #141416; --ds-item: #d1d5db; --ds-ok: #34c759;
        --ds-ok-soft: rgba(52, 199, 89, .14); --ds-red-soft: rgba(255, 105, 97, .14);
        --ds-warn: #ff9f0a; --ds-warn-soft: rgba(255, 159, 10, .15);
        --ds-hd-bg: #000; --ds-hd-line: #1c1c1f;
        --ds-btn-bg: #0a0a0b; --ds-btn-line: #26262b; --ds-btn-fg: #fff;
        --ds-nav-glass: rgba(14, 14, 16, .56); --ds-nav-line: rgba(255, 255, 255, .09); --ds-pill: rgba(59, 158, 255, .2);
        --ds-hd-glass: rgba(0, 0, 0, .58);
        --ds-font: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif;
        --bg: var(--ds-bg); --surface: var(--ds-card); --line: var(--ds-line); --text: var(--ds-h); --muted: var(--ds-p);
        --blue: #0a84ff; --blue-600: var(--ds-link); --red: var(--ds-red);
        /* Kich thuoc header KHOA CUNG: --hd = chieu cao khung, --logo-h = chieu cao logo, --hbtn = nut EN / sang-toi / avatar */
        --hd: 50px; --logo-h: 38px; --hbtn: 32px; --nav-h: 66px;
    }
    :root[data-theme="light"] {
        color-scheme: light;
        --ds-bg: #f5f7fa; --ds-card: #fff; --ds-field: #f3f4f6; --ds-line: #e5e7eb; --ds-line2: #d1d5db;
        --ds-h: #111827; --ds-p: #4b5563; --ds-link: #0a6cdc; --ds-soft: #e5f1ff; --ds-nav: #fff;
        --ds-red: #dc2626; --ds-menu-head: #f3f4f6; --ds-item: #374151; --ds-ok: #16a34a;
        --ds-ok-soft: rgba(22, 163, 74, .12); --ds-red-soft: rgba(220, 38, 38, .1);
        --ds-warn: #b45309; --ds-warn-soft: rgba(217, 119, 6, .13);
        --ds-hd-bg: #fff; --ds-hd-line: #e5e7eb;
        --ds-btn-bg: #f3f4f6; --ds-btn-line: #d1d5db; --ds-btn-fg: #111827;
        --ds-nav-glass: rgba(255, 255, 255, .66); --ds-nav-line: rgba(0, 0, 0, .08); --ds-pill: rgba(10, 108, 220, .13);
        --ds-hd-glass: rgba(255, 255, 255, .7);
    }
    html, body { background: var(--ds-bg) !important; }

    /* Giu trang an toi khi JS xep xong (het nhay layout luc reload). JS tu bat lai, co du phong 1.2 giay */
    html:not(.ds-ready) body { opacity: 0; }
    body { transition: opacity .12s ease; }
    html { overflow-anchor: none; touch-action: manipulation; }

    /* Font he thong de dau tieng Viet cho toan trang, giu nguyen font icon neu co */
    html, body,
    body *:not(.fa):not(.fas):not(.far):not(.fab):not(.fal):not(.fad):not([class^="fa-"]):not([class*=" fa-"]):not(.material-icons):not(.material-symbols-outlined):not(.bi):not([class^="bi-"]):not([class*=" bi-"]) {
        font-family: var(--ds-font) !important;
    }
    html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
    body { color: var(--ds-h); font-size: 14px; line-height: 1.45; -webkit-font-smoothing: antialiased; padding-bottom: calc(var(--nav-h) + env(safe-area-inset-bottom, 0px)); }
    /* v20: header la position: fixed nen body chua san cho o tren (khong con sticky => cuon muot) */
    html body { padding-top: var(--hd) !important; }

    /* Chong zoom khi nhap tren iPhone: Safari tu phong to neu chu o nhap nho hon 16px */
    input, textarea, select, .field { font-size: 16px !important; }
    html.ds-typing body { padding-bottom: 46vh; }
    html.ds-typing .ds-nav { display: none; }

    /* Tat moi hieu ung sang / bong */
    .brand__shine { display: none !important; }
    .site-header, .banner, .card, .avatar, .eagle__wing, .toast__icon, .site-about { box-shadow: none !important; }
    .eagle__wing, .toast__icon { filter: none !important; }

    /* Header GHIM CUNG (v20): fixed, layer rieng. Chieu cao / padding / logo khoa bang !important. */
    html .site-header { position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; width: 100% !important; z-index: 900; box-sizing: border-box !important; height: var(--hd) !important; min-height: var(--hd) !important; max-height: var(--hd) !important; margin: 0 !important; padding: 0 14px !important; overflow: visible; line-height: 1 !important; background: var(--ds-hd-bg) !important; border: 0 !important; border-bottom: 1px solid var(--ds-hd-line) !important; -webkit-transform: translate3d(0, 0, 0); transform: translate3d(0, 0, 0); -webkit-backface-visibility: hidden; backface-visibility: hidden; }
    .site-header::before { content: none !important; }
    html .site-header .brand { display: flex !important; align-items: center !important; height: 100% !important; min-width: 0; margin: 0 !important; padding: 0 !important; }
    html .site-header .brand__logo { display: block !important; height: var(--logo-h) !important; min-height: var(--logo-h) !important; max-height: var(--logo-h) !important; width: auto !important; max-width: 70vw !important; margin: 0 !important; padding: 0 !important; object-fit: contain; }
    /* Logo lang bong: CHI dung transform => chay bang GPU, muot khi cuon */
    .ds-logo { position: relative; display: inline-block; flex: 0 1 auto; min-width: 0; max-width: 100%; line-height: 0; overflow: hidden; -webkit-transform: translateZ(0); transform: translateZ(0); }
    html .site-header .ds-logo { height: var(--logo-h) !important; max-width: 70vw; }
    .ds-sw { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; transform-origin: 50% 50%; -webkit-mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0) 26%, rgba(0, 0, 0, .55) 41%, #000 50%, rgba(0, 0, 0, .55) 59%, rgba(0, 0, 0, 0) 74%, rgba(0, 0, 0, 0) 100%); mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0) 26%, rgba(0, 0, 0, .55) 41%, #000 50%, rgba(0, 0, 0, .55) 59%, rgba(0, 0, 0, 0) 74%, rgba(0, 0, 0, 0) 100%); will-change: transform; -webkit-backface-visibility: hidden; backface-visibility: hidden; animation: ds-sw 2.2s linear infinite; }
    .ds-swi { position: absolute; top: 0; left: 0; display: block; width: 100%; height: 100%; max-width: none; object-fit: contain; -webkit-filter: brightness(0) invert(1); filter: brightness(0) invert(1); opacity: .95; transform-origin: 50% 50%; will-change: transform; animation: ds-swi 2.2s linear infinite; }
    @keyframes ds-sw  { from { -webkit-transform: translate3d(-85%, 0, 0) skewX(-18deg); transform: translate3d(-85%, 0, 0) skewX(-18deg); } to { -webkit-transform: translate3d(85%, 0, 0) skewX(-18deg); transform: translate3d(85%, 0, 0) skewX(-18deg); } }
    @keyframes ds-swi { from { -webkit-transform: skewX(18deg) translate3d(85%, 0, 0); transform: skewX(18deg) translate3d(85%, 0, 0); } to { -webkit-transform: skewX(18deg) translate3d(-85%, 0, 0); transform: skewX(18deg) translate3d(-85%, 0, 0); } }
    @media (prefers-reduced-motion: reduce) { .ds-sw { display: none; } }
    .menu-toggle { display: none !important; }
    .ds-hd { display: flex; align-items: center; gap: 6px; flex: 0 0 auto; }
    .ds-hbtn { box-sizing: border-box; display: flex; align-items: center; justify-content: center; min-width: var(--hbtn); height: var(--hbtn); padding: 0 8px; font-size: 12px; font-weight: 800; color: var(--ds-btn-fg); background: var(--ds-btn-bg); border: 1px solid var(--ds-btn-line); border-radius: 10px; }
    .ds-hbtn svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }

    .ds-lang { position: relative; }
    .ds-pop { position: absolute; top: calc(100% + 8px); right: 0; z-index: 2000; min-width: 140px; overflow: hidden; background: var(--ds-nav); border: 1px solid var(--ds-btn-line); border-radius: 12px; box-shadow: 0 10px 24px rgba(0, 0, 0, .35); }
    .ds-pop[hidden] { display: none; }
    .ds-pop button { display: block; width: 100%; padding: 11px 14px; font-size: 13px; font-weight: 600; text-align: left; color: var(--ds-item); background: transparent; }
    .ds-pop button.is-on { color: var(--ds-link); }

    /* Menu Tools (menu cu, giu nguyen de logic mo tool cua index.php hoat dong) */
    .menu { top: calc(var(--hd) + 6px); right: 12px; width: 240px; background: var(--ds-nav) !important; border: 1px solid var(--ds-line); border-radius: 16px; box-shadow: 0 12px 30px rgba(0, 0, 0, .35); }
    .menu-overlay { inset: var(--hd) 0 0 0; background: rgba(0, 0, 0, .5); }
    .menu__item { font-size: 14px; color: var(--ds-item); background: transparent !important; border-bottom: 1px solid var(--ds-line); }
    .menu__heading { font-size: 11px; color: var(--ds-p); background: var(--ds-menu-head) !important; border-bottom: 1px solid var(--ds-line); }
    .menu__tool span { font-size: 14px; color: var(--ds-h); }
    .menu__tool { border-bottom-color: var(--ds-line); }
    .menu__home { color: var(--blue); }
    .menu__item--admin, .menu__item--logout { color: var(--ds-red); border-top: 1px solid var(--ds-line); }
    .badge--free { color: var(--ds-p); }

    /* Thanh nguoi dung (chua dang nhap thi an han: da co muc Log in o thanh tab duoi day) */
    .user-bar { font-size: 12px; background: transparent !important; border-bottom: 1px solid var(--ds-line); box-shadow: none; }
    html.ds-guest .user-bar { display: none !important; }
    .user-bar { position: relative !important; top: auto !important; bottom: auto !important; z-index: 1 !important; height: auto !important; max-height: none !important; margin-top: 0 !important; margin-bottom: 0 !important; transform: none !important; }
    .user-bar__info { color: var(--ds-p); }
    .user-bar__name, .user-bar__plan.is-free { color: var(--ds-link); }
    .user-bar__timer, .user-bar__plan.is-vip { color: var(--ds-red); }
    .auth__link--login { color: var(--ds-item); }

    /* Banner + card + o nhap */
    .banner, .card { background: var(--ds-card) !important; border: 1px solid var(--ds-line) !important; border-radius: 22px; }
    .card { padding: 14px; }
    .banner__title { font-size: 22px; color: var(--ds-link) !important; }
    .banner__desc { font-size: 13px; color: var(--ds-p) !important; }
    .avatar__inner { background: var(--ds-card) !important; }
    .avatar__fallback { color: var(--blue); background: var(--ds-field); }
    .group-link { font-size: 13px; color: var(--ds-red); }
    .group-link span { text-decoration-color: var(--ds-red); }
    .card__title { font-size: 12px; }
    .card__title--vip { color: var(--ds-red); }
    .card__title--free { color: var(--ds-link); }
    .field { height: 40px; margin-top: 8px; padding: 0 14px; font-size: 16px; color: var(--ds-h); background: var(--ds-field) !important; border: 1px solid var(--ds-line2); border-radius: 14px; }
    /* Chu that 16px (iOS khong zoom) nhung thu nho hien thi con ~14px (v26: da thay bang o nhap that, xem khoi v26) */
    html .field { box-sizing: border-box; width: 114.2857%; max-width: none; height: 46px; margin: 8px 0 -6px; font-size: 16px !important; transform: scale(.875); transform-origin: left top; }
    .field::placeholder { color: var(--ds-p); opacity: .8; }
    .field:focus, .field--free:focus { border-color: var(--blue); }
    .link-btn { font-size: 12.5px; color: var(--ds-link); }
    .btn { height: 36px; padding: 0 16px; font-size: 12.5px; background: var(--blue); border-radius: 12px; }
    .note { font-size: 12.5px; color: var(--ds-p); background: var(--ds-soft) !important; border-color: var(--ds-line); border-radius: 12px; }
    .note a, .free-link { color: var(--ds-link); }
    .free-help { border-top-color: var(--ds-line2); }

    /* Chia man: Home (banner + tool), Keys (o nhap key), Detail (trang chi tiet tool) */
    html:not(.ds-keys) .page-top > section.card { display: none !important; }
    html.ds-keys .banner, html.ds-keys .group-link, html.ds-keys .ds-products, html.ds-keys .ds-detwrap { display: none !important; }
    html.ds-detail .banner, html.ds-detail .group-link, html.ds-detail .ds-products, html.ds-detail .ds-keyhead, html.ds-detail .page-top > section.card { display: none !important; }
    .ds-keyhead { display: none; }
    html.ds-keys .ds-keyhead { display: block; }
    html.ds-detail.ds-keys .ds-keyhead { display: none; }
    .ds-need { margin: 10px 0 0; padding: 10px 12px; font-size: 13px; font-weight: 600; line-height: 1.45; color: var(--ds-warn); background: var(--ds-warn-soft); border-radius: 12px; }
    .ds-need[hidden] { display: none; }

    /* Danh sach tool nho gon */
    .ds-sec__title { margin: 16px 0 2px; font-size: 18px; font-weight: 800; line-height: 1.25; color: var(--ds-h); }
    .ds-sec__sub { margin: 0 0 4px; font-size: 12px; line-height: 1.45; color: var(--ds-p); }
    .ds-keyhead .ds-sec__title { margin-top: 8px; }
    .ds-products .ds-sec__title { margin: 14px 0 2px; font-size: 15px; font-weight: 800; line-height: 1.3; }
    .ds-products .ds-sec__sub { font-size: 11.5px; }
    .ds-row { display: flex; align-items: center; gap: 11px; padding: 9px 0; border-bottom: 1px solid var(--ds-line); }
    .ds-row:last-child { border-bottom: 0; }
    .ds-row img { flex-shrink: 0; width: 44px; height: 44px; object-fit: cover; background: var(--ds-field); border-radius: 11px; }
    .ds-row__info { flex: 1 1 0; min-width: 0; }
    .ds-row__info strong { display: block; overflow: hidden; font-size: 14px; font-weight: 700; color: var(--ds-h); text-overflow: ellipsis; white-space: nowrap; }
    .ds-row__info span { display: block; margin-top: 1px; font-size: 12px; color: var(--ds-p); }
    .ds-row__info span.is-vip { color: var(--ds-red); }
    .ds-open { flex-shrink: 0; height: 28px; padding: 0 16px; font-size: 12px; font-weight: 700; color: var(--ds-link); background: var(--ds-soft); border-radius: 999px; }
    .ds-open:active { transform: scale(.97); }
    .ds-row.is-locked { opacity: .5; }
    .ds-row.is-locked img { filter: grayscale(1); }
    .ds-row.is-busy { opacity: .5; pointer-events: none; }

    /* Trang chi tiet tool (kieu shopdsgm.vn) */
    .ds-detwrap { display: none; }
    html.ds-detail .ds-detwrap { display: block; }
    .ds-det { display: none; }
    .ds-det.is-on { display: block; }
    .ds-det__top { margin: 0 -16px; padding: 12px 16px 18px; background: var(--ds-field); border-bottom: 1px solid var(--ds-line); }
    .ds-det__back { display: inline-flex; align-items: center; margin: 0 0 10px -4px; padding: 4px 8px 4px 0; font-size: 13px; font-weight: 600; color: var(--ds-link); background: none; }
    .ds-det__back svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .ds-det__head { display: flex; align-items: center; gap: 14px; }
    .ds-det__head img { flex-shrink: 0; width: 88px; height: 88px; object-fit: cover; background: var(--ds-card); border: 1px solid var(--ds-line); border-radius: 21px; }
    .ds-det__main { flex: 1 1 0; min-width: 0; }
    .ds-det__main h1 { margin: 0; font-size: 20px; font-weight: 800; line-height: 1.2; color: var(--ds-h); overflow-wrap: anywhere; }
    .ds-det__main p { margin: 2px 0 10px; font-size: 13px; color: var(--ds-p); }
    .ds-det__main p.is-vip { color: var(--ds-red); }
    .ds-det__act { display: flex; align-items: center; gap: 10px; }
    .ds-get { display: inline-flex; align-items: center; justify-content: center; height: 34px; padding: 0 24px; font-size: 13px; font-weight: 800; letter-spacing: .05em; color: #fff; background: var(--blue); border-radius: 999px; }
    .ds-get:active { transform: scale(.97); }
    .ds-get.is-locked { opacity: .55; }
    .ds-get.is-busy { opacity: .5; pointer-events: none; }
    .ds-share { display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; margin-left: auto; color: var(--ds-link); background: var(--ds-soft); border-radius: 50%; }
    .ds-share svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
    .ds-stats { display: flex; padding: 14px 0; border-bottom: 1px solid var(--ds-line); }
    .ds-stat { flex: 1 1 0; min-width: 0; padding: 0 6px; text-align: center; border-left: 1px solid var(--ds-line); }
    .ds-stat:first-child { border-left: 0; }
    .ds-stat small { display: block; font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--ds-p); }
    .ds-stat b { display: block; margin: 3px 0 1px; font-size: 15px; font-weight: 800; color: var(--ds-h); }
    .ds-stat b.is-ok { color: var(--ds-ok); }
    .ds-stat b.is-vip { color: var(--ds-red); }
    .ds-stat span { display: block; font-size: 11px; color: var(--ds-p); }
    .ds-det h2 { margin: 20px 0 8px; font-size: 17px; font-weight: 800; color: var(--ds-h); }
    .ds-det__txt { margin: 0 0 8px; font-size: 13px; line-height: 1.65; color: var(--ds-p); }
    .ds-info > div { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 11px 0; font-size: 13px; border-bottom: 1px solid var(--ds-line); }
    .ds-info span { color: var(--ds-p); }
    .ds-info b { font-weight: 700; color: var(--ds-h); text-align: right; }
    .ds-info b.is-ok { color: var(--ds-ok); }
    .ds-info b.is-vip { color: var(--ds-red); }
    .ds-rel { display: flex; gap: 10px; margin: 0 -16px; padding: 2px 16px 6px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .ds-rel__card { display: flex; flex: 0 0 82%; align-items: center; gap: 10px; min-width: 0; padding: 10px; background: var(--ds-card); border: 1px solid var(--ds-line); border-radius: 18px; }
    .ds-rel__card img { flex-shrink: 0; width: 44px; height: 44px; object-fit: cover; background: var(--ds-field); border-radius: 11px; }
    .ds-rel__info { flex: 1 1 0; min-width: 0; }
    .ds-rel__info strong { display: block; overflow: hidden; font-size: 13px; font-weight: 700; color: var(--ds-h); text-overflow: ellipsis; white-space: nowrap; }
    .ds-rel__info span { display: block; font-size: 11.5px; color: var(--ds-p); }
    .ds-rel__info span.is-vip { color: var(--ds-red); }
    .ds-feat__head { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
    .ds-feat__ver { font-size: 12px; color: var(--ds-p); }
    .ds-feat { margin: 0; padding: 0; list-style: none; }
    .ds-feat li { display: flex; align-items: flex-start; gap: 10px; padding: 6px 0; font-size: 13.5px; line-height: 1.45; color: var(--ds-h); }
    .ds-feat li svg { flex: 0 0 18px; width: 18px; height: 18px; margin-top: 1px; fill: none; stroke: var(--ds-link); stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .ds-feat.is-collapsed li:nth-child(n+4) { display: none; }
    .ds-more { padding: 6px 0 0; font-size: 13px; font-weight: 700; color: var(--ds-link); background: none; }

    /* Package: tieu de co mui ten o Home + trang goi */
    .ds-pkgbtn { display: inline-flex; align-items: center; gap: 4px; padding: 0; font: inherit; color: inherit; text-align: left; background: none; }
    .ds-pkgbtn svg { flex: 0 0 auto; width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
    .ds-pkgwrap { display: none; }
    html.ds-pkg .ds-pkgwrap { display: block; }
    html.ds-pkg .banner, html.ds-pkg .group-link, html.ds-pkg .ds-products, html.ds-pkg .ds-keyhead, html.ds-pkg .ds-detwrap, html.ds-pkg .ds-toolspage, html.ds-pkg .page-top > section.card { display: none !important; }
    .ds-pkgpage { display: none; }
    .ds-pkgpage.is-on { display: block; }
    .ds-pkgback { display: inline-flex; align-items: center; margin: 12px 0 6px -4px; padding: 4px 8px 4px 0; font-size: 15px; font-weight: 600; color: var(--ds-link); background: none; }
    .ds-pkgback svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .ds-pkgtitle { margin: 6px 0 2px; font-size: 24px; font-weight: 800; line-height: 1.2; color: var(--ds-h); }
    .ds-pkgsub { margin: 0 0 14px; font-size: 13px; line-height: 1.45; color: var(--ds-p); }
    .ds-search { display: flex; align-items: center; gap: 8px; height: 44px; padding: 0 14px; background: var(--ds-field); border: 1px solid var(--ds-line2); border-radius: 14px; }
    .ds-search:focus-within { border-color: var(--blue); }
    .ds-search svg { flex: 0 0 18px; width: 18px; height: 18px; fill: none; stroke: var(--ds-p); stroke-width: 2; stroke-linecap: round; }
    .ds-search__in { flex: 1 1 0; min-width: 0; height: 100%; padding: 0; color: var(--ds-h); background: transparent !important; border: 0; outline: 0; -webkit-appearance: none; appearance: none; }
    .ds-search__in::placeholder { color: var(--ds-p); opacity: .8; }
    .ds-pkgcount { margin: 16px 0 4px; padding-top: 14px; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--ds-p); border-top: 1px solid var(--ds-line); }
    .ds-pkgempty { padding: 24px 0; font-size: 13px; text-align: center; color: var(--ds-p); }
    .ds-pkgempty[hidden] { display: none; }

    /* Trang Tools (tab Tools) */
    .ds-toolspage { display: none; }
    html.ds-tools .ds-toolspage { display: block; }
    html.ds-tools .banner, html.ds-tools .group-link, html.ds-tools .ds-products, html.ds-tools .ds-detwrap, html.ds-tools .ds-keyhead, html.ds-tools .page-top > section.card { display: none !important; }
    .ds-empty { padding: 26px 0 10px; text-align: center; }
    .ds-toolspage .ds-empty { padding: 44px 0 40px; }
    .ds-empty__ico { display: flex; align-items: center; justify-content: center; width: 84px; height: 84px; margin: 0 auto 14px; color: var(--ds-link); background: var(--ds-soft); border-radius: 50%; }
    .ds-empty__ico svg { width: 38px; height: 38px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .ds-empty h2 { margin: 0 0 4px; font-size: 22px; font-weight: 800; color: var(--ds-h); }
    .ds-empty p { margin: 0; font-size: 13px; color: var(--ds-p); }
    .ds-chips { display: flex; gap: 8px; margin: 14px 0 4px; }
    .ds-chip, .ds-hchip { height: 32px; padding: 0 16px; font-size: 13px; font-weight: 700; color: var(--ds-p); background: var(--ds-card); border: 1px solid var(--ds-line2); border-radius: 999px; }
    .ds-chip.is-on, .ds-hchip.is-on { color: var(--ds-link); background: var(--ds-soft); border-color: transparent; }
    .ds-row[hidden] { display: none; }
    #ds-lang-btn { cursor: default; }

    /* Avatar + menu tai khoan, trang Me, trang History */
    .ds-badge { display: inline-block; box-sizing: border-box; height: 22px; padding: 0 10px; font-size: 11.5px; font-weight: 700; line-height: 22px; white-space: nowrap; color: var(--ds-link); background: var(--ds-soft); border-radius: 999px; }
    .ds-badge.is-vip { color: var(--ds-red); background: var(--ds-red-soft); }
    .ds-badge.is-ok { color: var(--ds-ok); background: var(--ds-ok-soft); }
    .ds-tag { box-sizing: border-box; display: inline-block; height: 22px; padding: 0 9px; font-size: 11.5px; font-weight: 700; line-height: 22px; white-space: nowrap; border-radius: 999px; }
    .ds-tag.is-warn { color: var(--ds-warn); background: var(--ds-warn-soft); }
    .ds-tag.is-ok { color: var(--ds-ok); background: var(--ds-ok-soft); }
    .ds-acc { position: relative; }
    .ds-avatar { position: relative; overflow: hidden; box-sizing: border-box; display: flex; align-items: center; justify-content: center; width: var(--hbtn); height: var(--hbtn); padding: 0; font-size: 14px; font-weight: 800; color: #fff; background: var(--blue); border: 0; border-radius: 10px; }
    .ds-avatar:active { transform: scale(.96); }
    /* Anh avatar phu kin khung, cat vua (object-fit: cover). Loi anh thi hien chu cai dau ben duoi */
    .ds-av__img { position: absolute; top: 0; left: 0; display: block; width: 100%; height: 100%; object-fit: cover; object-position: center; background: var(--blue); }
    .ds-um-ov { position: fixed; left: 0; right: 0; top: 0; bottom: 0; z-index: 850; background: transparent; }
    .ds-um-ov[hidden] { display: none; }
    .ds-um { position: absolute; top: calc(100% + 9px); right: 0; z-index: 2000; box-sizing: border-box; width: min(272px, calc(100vw - 28px)); padding: 14px; text-align: left; line-height: 1.35; background: var(--ds-nav); border: 1px solid var(--ds-line2); border-radius: 18px; box-shadow: 0 10px 24px rgba(0, 0, 0, .35); }
    .ds-um[hidden] { display: none; }
    .ds-um__hello { font-size: 12px; font-weight: 600; color: var(--ds-p); }
    .ds-um__name { margin: 1px 0 8px; overflow: hidden; font-size: 17px; font-weight: 800; color: var(--ds-h); text-overflow: ellipsis; white-space: nowrap; }
    .ds-um__row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 12px; padding: 10px 12px; font-size: 13px; color: var(--ds-p); background: var(--ds-field); border-radius: 12px; }
    .ds-um__row b { font-size: 15px; font-weight: 800; color: var(--ds-link); }
    .ds-um__sep { height: 1px; margin: 8px 0; background: var(--ds-line); }
    .ds-um__item { box-sizing: border-box; display: flex; align-items: center; gap: 12px; width: 100%; padding: 10px 2px; font-size: 14px; font-weight: 600; text-align: left; text-decoration: none; color: var(--ds-item); background: transparent; border: 0; }
    .ds-um__item:active { opacity: .6; }
    .ds-um__item .ds-ico { flex: 0 0 auto; width: 18px; height: 18px; }
    .ds-um__item .ds-tag { margin-left: auto; }
    .ds-um__item--out { color: var(--ds-red); }

    .ds-mepage, .ds-histpage { display: none; }
    html.ds-me .ds-mepage, html.ds-hist .ds-histpage { display: block; }
    html.ds-me .banner, html.ds-me .group-link, html.ds-me .ds-products, html.ds-me .ds-detwrap, html.ds-me .ds-keyhead, html.ds-me .ds-toolspage, html.ds-me .page-top > section.card,
    html.ds-hist .banner, html.ds-hist .group-link, html.ds-hist .ds-products, html.ds-hist .ds-detwrap, html.ds-hist .ds-keyhead, html.ds-hist .ds-toolspage, html.ds-hist .page-top > section.card { display: none !important; }

    .ds-me__top { display: flex; align-items: center; gap: 14px; margin: 0 -16px; padding: 18px 16px; background: var(--ds-field); border-bottom: 1px solid var(--ds-line); }
    .ds-me__av { position: relative; overflow: hidden; display: flex; flex: 0 0 auto; align-items: center; justify-content: center; width: 64px; height: 64px; font-size: 28px; font-weight: 800; color: #fff; background: var(--blue); border-radius: 18px; }
    .ds-me__who { flex: 1 1 0; min-width: 0; }
    .ds-me__who h1 { margin: 0 0 7px; font-size: 20px; font-weight: 800; line-height: 1.2; color: var(--ds-h); overflow-wrap: anywhere; }
    .ds-me__badges { display: flex; flex-wrap: wrap; gap: 6px; }
    .ds-me__h { margin: 20px 0 8px 2px; font-size: 13px; font-weight: 700; color: var(--ds-p); }
    .ds-secwarn { display: flex; align-items: flex-start; gap: 10px; margin: 14px 0 0; padding: 12px 14px; font-size: 13px; font-weight: 600; line-height: 1.45; text-decoration: none; color: var(--ds-warn); background: var(--ds-warn-soft); border-radius: 14px; }
    .ds-lcard { overflow: hidden; background: var(--ds-card); border: 1px solid var(--ds-line); border-radius: 18px; }
    .ds-lrow { box-sizing: border-box; display: flex; align-items: center; justify-content: space-between; gap: 12px; width: 100%; min-height: 48px; padding: 8px 14px; font-size: 14px; font-weight: 600; text-align: left; text-decoration: none; color: var(--ds-h); background: transparent; border: 0; border-top: 1px solid var(--ds-line); }
    .ds-lcard > .ds-lrow:first-child { border-top: 0; }
    button.ds-lrow:active, a.ds-lrow:active { background: var(--ds-field); }
    .ds-lrow svg { flex: 0 0 auto; width: 16px; height: 16px; fill: none; stroke: var(--ds-p); stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .ds-lrow__r { display: flex; align-items: center; gap: 8px; min-width: 0; }
    .ds-lrow.is-static > span { font-weight: 500; color: var(--ds-p); }
    .ds-lrow.is-static > b, .ds-lrow__v { font-size: 14px; font-weight: 700; color: var(--ds-h); text-align: right; overflow-wrap: anywhere; }
    .ds-sel { box-sizing: border-box; height: 34px; padding: 0 30px 0 12px; font-size: 16px; font-weight: 600; color: var(--ds-h); background: var(--ds-field) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>") no-repeat right 10px center; border: 1px solid var(--ds-line2); border-radius: 10px; -webkit-appearance: none; appearance: none; }
    .ds-logout { box-sizing: border-box; display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; height: 48px; margin: 22px 0 6px; font-size: 15px; font-weight: 700; text-decoration: none; color: var(--ds-red); background: var(--ds-card); border: 1px solid var(--ds-line2); border-radius: 16px; }
    .ds-logout:active { background: var(--ds-red-soft); }
    .ds-logout .ds-ico { width: 18px; height: 18px; }

    .ds-hsum { display: flex; gap: 8px; margin: 12px 0 4px; }
    .ds-hsum > div { flex: 1 1 0; min-width: 0; padding: 11px 12px; background: var(--ds-card); border: 1px solid var(--ds-line); border-radius: 16px; }
    .ds-hsum small { display: block; font-size: 12px; color: var(--ds-p); }
    .ds-hsum b { display: block; margin-top: 2px; font-size: 20px; font-weight: 800; line-height: 1.2; color: var(--ds-h); }
    .ds-hsum b.is-in { color: var(--ds-ok); }
    .ds-hsum b.is-out { color: var(--ds-red); }
    .ds-hday { margin: 18px 0 2px; font-size: 13px; font-weight: 700; color: var(--ds-p); }
    .ds-h { display: flex; align-items: center; gap: 11px; padding: 10px 0; border-bottom: 1px solid var(--ds-line); }
    .ds-h.is-link:active { opacity: .6; }
    .ds-h__ic { display: flex; flex: 0 0 auto; align-items: center; justify-content: center; width: 44px; height: 44px; overflow: hidden; background: var(--ds-field); border-radius: 11px; }
    .ds-h__ic.is-in { color: var(--ds-ok); background: var(--ds-ok-soft); }
    .ds-h__ic img { display: block; width: 44px; height: 44px; object-fit: cover; }
    .ds-h__ic svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
    .ds-h__info { flex: 1 1 0; min-width: 0; }
    .ds-h__info strong { display: block; overflow: hidden; font-size: 14px; font-weight: 700; color: var(--ds-h); text-overflow: ellipsis; white-space: nowrap; }
    .ds-h__info span { display: block; margin-top: 1px; font-size: 12px; color: var(--ds-p); }
    .ds-h__amt { flex: 0 0 auto; box-sizing: border-box; height: 26px; padding: 0 10px; font-size: 12.5px; font-weight: 800; line-height: 26px; white-space: nowrap; border-radius: 999px; }
    .ds-h__amt.is-in { color: var(--ds-ok); background: var(--ds-ok-soft); }
    .ds-h__amt.is-out { color: var(--ds-red); background: var(--ds-red-soft); }
    .ds-h__amt.is-zero { color: var(--ds-p); background: var(--ds-field); }
    .ds-hempty[hidden], .ds-hclear[hidden], .ds-hnote[hidden] { display: none; }
    .ds-histpage .ds-empty { padding: 40px 0 24px; }
    .ds-hnote { margin: 16px 0 0; font-size: 12px; line-height: 1.55; color: var(--ds-p); }
    .ds-hclear { display: block; margin: 8px auto 0; padding: 8px 14px; font-size: 13px; font-weight: 700; color: var(--ds-red); background: none; border: 0; }

    /* Thong bao noi ngay tren thanh tab */
    .toast-anchor { position: fixed; left: 16px; right: 16px; bottom: calc(var(--nav-h) + env(safe-area-inset-bottom, 0px) + 10px); z-index: 1100; min-height: 0; margin: 0; pointer-events: none; }
    .toast { display: flex; align-items: center; gap: 8px; width: fit-content; max-width: 100%; margin: 0 auto; padding: 8px 14px; font-size: 13px; font-weight: 600; line-height: 1.35; color: var(--ds-h); text-shadow: none; background: var(--ds-field); border: 0 !important; border-radius: 16px; box-shadow: none !important; }
    .toast__text, .toast__text * { font-size: 13px !important; font-weight: 600; color: var(--ds-h); }
    .toast__icon { display: inline-flex; flex: 0 0 auto; align-items: center; justify-content: center; width: 18px; height: 18px; background: none !important; border: 0 !important; }
    .toast__icon svg { width: 16px; height: 16px; }
    .toast__x { stroke: var(--ds-p); }

    /* Gioi thieu + footer, logo devnvios.io.vn (52px), khong khung */
    .site-about { margin: 8px -16px 0; padding: 22px 16px 8px; background: transparent !important; border: 0; border-top: 1px solid var(--ds-line); border-radius: 0; }
    .ds-brandblock { display: block; margin: 0 0 14px; padding: 0; line-height: 0; background: none; border: 0; border-radius: 0; }
    .ds-brandblock .ds-logo { display: block; width: fit-content; max-width: 86%; height: 52px; }
    .ds-brandblock img { display: block; width: auto; max-width: 100%; height: 52px !important; min-height: 52px; max-height: 52px; object-fit: contain; }
    .site-about__title { color: var(--ds-h); font-size: 17px; }
    .site-about__title:not(:first-child) { font-size: 13px; letter-spacing: .05em; text-transform: uppercase; }
    .site-about__text { color: var(--ds-p); font-size: 13px; line-height: 1.6; }
    .site-footer { margin-top: 0; font-size: 12px; color: var(--ds-p); background: transparent !important; border-top: 1px solid var(--ds-line); text-transform: none; font-weight: 600; }
    .site-footer a { color: var(--blue); }
    .site-footer__year { color: var(--ds-p); }

    /* Keo xuong de tai lai */
    .ds-ptr { position: fixed; left: 50%; top: calc(var(--hd) - 40px); z-index: 800; display: flex; align-items: center; justify-content: center; box-sizing: border-box; width: 34px; height: 34px; margin-left: -17px; color: var(--blue); background: var(--ds-field); border: 1px solid var(--ds-line2); border-radius: 50%; opacity: 0; visibility: hidden; pointer-events: none; will-change: transform, opacity; }
    .ds-ptr svg { display: block; width: 22px; height: 22px; fill: none; }
    .ds-ptr__track { stroke: var(--ds-line2); stroke-width: 2.4; }
    .ds-ptr__arc { stroke: var(--blue); stroke-width: 2.4; stroke-linecap: round; }
    .ds-ptr.is-spin svg { animation: ds-spin .8s linear infinite; }
    @keyframes ds-spin { to { transform: rotate(360deg); } }

    /* Thanh tab duoi day: kinh mo xuyen thau nhe, lop kinh keo dai xuong day de khong lo khe khi thanh Safari truot */
    .ds-nav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 1000; isolation: isolate; -webkit-transform: translate3d(0, 0, 0); transform: translate3d(0, 0, 0); padding: 6px 10px calc(6px + env(safe-area-inset-bottom, 0px)); background: transparent; border: 0; -webkit-tap-highlight-color: transparent; -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; }
    .ds-nav::before { content: ""; position: absolute; z-index: -1; left: 0; right: 0; top: 0; bottom: -120px; pointer-events: none; background: var(--ds-nav-glass); border-top: 1px solid var(--ds-nav-line); -webkit-backdrop-filter: blur(12px) saturate(150%); backdrop-filter: blur(12px) saturate(150%); }
    @supports not ((-webkit-backdrop-filter: blur(1px)) or (backdrop-filter: blur(1px))) { .ds-nav::before { background: var(--ds-nav); } }
    .ds-nav__in { position: relative; display: flex; gap: 4px; max-width: 36rem; margin: 0 auto; }
    .ds-nav__item { box-sizing: border-box; display: flex; flex: 1 1 0; flex-direction: column; align-items: center; gap: 3px; min-width: 0; padding: 7px 2px 6px; font-size: 11px; font-weight: 600; color: var(--ds-p); text-decoration: none; background: transparent; border: 0; border-radius: 14px; transition: background-color .18s ease, color .18s ease; -webkit-tap-highlight-color: transparent; }
    .ds-nav__item:active { background: var(--ds-pill); }
    .ds-nav__item.is-active { color: var(--ds-link); background: var(--ds-pill); }
    .ds-nav__item span { display: block; max-width: 100%; overflow: hidden; line-height: 1.25; text-overflow: ellipsis; white-space: nowrap; text-transform: none; letter-spacing: 0; color: inherit !important; opacity: 1 !important; visibility: visible !important; }
    .ds-ico { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
</style>
<style id="ds-theme-v21">
    /* ===== v21: KHOA CUNG khung (header + thanh tab Home), cuon muot ===== */
    html, html body { overscroll-behavior-y: none; }
    html .site-header, .ds-nav { will-change: transform; touch-action: manipulation; }
    .ds-nav::before { -webkit-backdrop-filter: none; backdrop-filter: none; background: var(--ds-nav); }
    html.ds-detail .page-top, html.ds-me .page-top { padding-top: 0 !important; margin-top: 0 !important; }
    html.ds-detail .ds-det__top, html.ds-me .ds-me__top { margin-top: 0; }

    /* Trang chi tiet kieu DS: dong thong so cuon ngang */
    .ds-stats { overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
    .ds-stats::-webkit-scrollbar { display: none; }
    .ds-stat { flex: 1 0 auto; min-width: 118px; padding: 0 14px; }
    .ds-stat b, .ds-stat span { white-space: nowrap; }
    .ds-det__main p span.is-vip { color: var(--ds-red); }
    .ds-det__date { display: block; margin-top: 2px; font-size: 12px; color: var(--ds-p); }
    .ds-det__top { padding-top: 18px; }

    /* Previews (anh xem truoc) */
    .ds-prevbox { margin: 0 -16px; }
    .ds-prev { display: flex; gap: 10px; padding: 2px 16px 6px; overflow-x: auto; -webkit-overflow-scrolling: touch; scroll-snap-type: x proximity; scrollbar-width: none; }
    .ds-prev::-webkit-scrollbar { display: none; }
    .ds-prev[hidden] { display: none; }
    .ds-prev img { flex: 0 0 auto; width: auto; max-width: 78vw; height: 230px; object-fit: cover; background: var(--ds-field); border: 1px solid var(--ds-line); border-radius: 14px; scroll-snap-align: start; }
    .ds-noprev { margin: 0 16px; padding: 26px 14px; font-size: 13px; text-align: center; color: var(--ds-p); background: var(--ds-field); border: 1px dashed var(--ds-line2); border-radius: 14px; }
    .ds-noprev[hidden] { display: none; }

    /* Ngay up trong danh sach */
    .ds-row__d { font-style: normal; color: var(--ds-p); }

    /* Gioi thieu gon lai */
    .site-about { padding-top: 16px; }
    .site-about__title { margin-bottom: 4px; font-size: 15px; }
    .site-about__text { margin: 0 0 6px; font-size: 12.5px; line-height: 1.55; }
    .site-about__text.ds-clamp { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow: hidden; }
    .site-about__text.ds-clamp.is-open { display: block; -webkit-line-clamp: unset; }
    .ds-readmore { display: block; margin: 0 0 8px; padding: 2px 0; font-size: 12.5px; font-weight: 700; color: var(--ds-link); background: none; border: 0; }

    /* Khoi tai lieu (icon kieu DS) */
    .ds-docs { margin: 14px 0 6px; }
    .ds-docs a { text-decoration: none; color: inherit; }
    .ds-docs__blk { display: block; margin: 0 0 6px; padding: 10px 0 8px; border-bottom: 1px solid var(--ds-line); }
    .ds-docs__blk:active, .ds-doc:active { opacity: .6; }
    .ds-docs__h { display: flex; align-items: center; gap: 10px; margin: 0 0 4px; font-size: 13px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--ds-h); }
    .ds-docs__h svg { flex: 0 0 auto; width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .ds-docs__h .ds-chev { margin-left: auto; width: 15px; height: 15px; stroke: var(--ds-p); stroke-width: 2.2; }
    .ds-docs__p { margin: 0; font-size: 12.5px; line-height: 1.55; color: var(--ds-p); }
    .ds-doc { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--ds-line); }
    .ds-doc__ic { display: flex; flex: 0 0 36px; align-items: center; justify-content: center; width: 36px; height: 36px; color: var(--ds-link); background: var(--ds-soft); border-radius: 10px; }
    .ds-doc__ic svg { width: 19px; height: 19px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .ds-doc__t { flex: 1 1 0; min-width: 0; }
    .ds-doc__t strong { display: block; font-size: 14px; font-weight: 700; color: var(--ds-h); }
    .ds-doc__t span { display: block; font-size: 12px; color: var(--ds-p); }
    .ds-doc > svg { flex: 0 0 auto; width: 15px; height: 15px; fill: none; stroke: var(--ds-p); stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .ds-doc:last-child { border-bottom: 0; }
</style>
<style id="ds-theme-v22">
    /* v22: chu to hon, giong shopdsgm.vn */
    .site-about { padding-top: 24px; }
    .site-about__title { margin-bottom: 8px; font-size: 22px; }
    .site-about__title:not(:first-child) { font-size: 17px; }
    .site-about__text { margin: 0 0 10px; font-size: 16px; line-height: 1.65; }
    .ds-readmore { font-size: 15px; }
    .site-footer { font-size: 14px; }
    .ds-docs { margin: 22px 0 8px; }
    .ds-docs__blk { display: block; margin: 0 0 24px; padding: 0; border: 0; }
    .ds-docs__blk:active { opacity: 1; }
    .ds-docs__h { gap: 12px; margin: 0 0 10px; font-size: 20px; letter-spacing: .06em; }
    .ds-docs__h svg { width: 26px; height: 26px; }
    .ds-docs__h--brand { font-size: 26px; letter-spacing: -.01em; text-transform: none; }
    .ds-docs > .ds-docs__h { margin-top: 6px; }
    .ds-docs__p { font-size: 16px; line-height: 1.7; }
    .ds-docs .ds-doclink { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 0; font-size: 17px; color: var(--ds-p); border-bottom: 1px solid var(--ds-line); transition: opacity .3s ease; }
    .ds-docs .ds-doclink:active { opacity: .6; }
    .ds-docs .ds-doclink svg { flex: 0 0 auto; width: 15px; height: 15px; fill: none; stroke: var(--ds-p); stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }

    /* v22: chuyen man nhe nhang (v26: nhanh hon) */
    body { transition: opacity .18s ease; }
    .ds-nav__item { transition: background-color .35s ease, color .35s ease; }
    .ds-open, .ds-get, .ds-share, .ds-avatar { transition: transform .3s ease, opacity .3s ease; }
    @keyframes ds-soft-in { from { opacity: 0; } to { opacity: 1; } }
    .ds-softin { animation: ds-soft-in .3s cubic-bezier(.22, .61, .36, 1) both; }
    @media (prefers-reduced-motion: reduce) { .ds-softin { animation: none; } }
</style>
<style id="ds-theme-v23">
    /* v23: an khoi About / Dev team / Support / How to use + logo cuoi trang (chi giu DEVNVIOS, Legal disclaimer, Documents) */
    .site-about > :not(.ds-docs) { display: none !important; }
    .site-about { margin-top: 0; padding: 22px 16px 6px; }
    .ds-docs { margin: 0 0 4px; }
    .ds-docs__blk { margin: 0 0 20px; }
    .ds-docs__h { gap: 10px; margin: 0 0 8px; font-size: 15px; letter-spacing: .06em; }
    .ds-docs__h svg { width: 20px; height: 20px; }
    .ds-docs__h--brand { font-size: 21px; letter-spacing: -.01em; }
    .ds-docs__p { font-size: 13.5px; line-height: 1.55; }
    .ds-docs > .ds-docs__h { margin-top: 4px; }
    .ds-docs .ds-doclink { padding: 8px 0; font-size: 14px; border-bottom: 0; }
    .ds-docs .ds-doclink svg { display: none; }
    .site-footer { font-size: 12.5px; }

    /* v23: khung dau trang san pham trong suot, mo mo nhu DS */
    .ds-det__top { position: relative; overflow: hidden; isolation: isolate; background: transparent; }
    .ds-det__top > .ds-det__head, .ds-det__top > .ds-det__back { position: relative; z-index: 1; }
    .ds-det__bg { position: absolute; z-index: 0; left: -15%; right: -15%; top: -50%; bottom: -50%; background-size: cover; background-position: center; -webkit-filter: blur(36px) saturate(1.4); filter: blur(36px) saturate(1.4); opacity: .5; pointer-events: none; }
    .ds-det__top::after { content: ""; position: absolute; z-index: 0; left: 0; right: 0; top: 0; bottom: 0; pointer-events: none; background: linear-gradient(180deg, rgba(10, 10, 12, .3), rgba(10, 10, 12, .55)); }
    :root[data-theme="light"] .ds-det__bg { opacity: .32; }
    :root[data-theme="light"] .ds-det__top::after { background: linear-gradient(180deg, rgba(255, 255, 255, .5), rgba(255, 255, 255, .72)); }
</style>
<style id="ds-theme-v24">
    /* ===== v24: khung san pham giong DS (nen mo mo trong suot) ===== */
    .ds-det__top { padding: 22px 16px 24px; background: #0b0b0e; border-bottom: 1px solid var(--ds-line); }
    .ds-det__bg { top: -30%; bottom: -30%; opacity: .3; -webkit-filter: blur(46px) saturate(1.6); filter: blur(46px) saturate(1.6); -webkit-transform: translateZ(0); transform: translateZ(0); }
    .ds-det__top::after { background: linear-gradient(135deg, rgba(46, 48, 68, .5) 0%, rgba(12, 12, 16, .55) 55%, rgba(34, 16, 20, .5) 100%); }
    :root[data-theme="light"] .ds-det__top { background: #eef1f6; }
    :root[data-theme="light"] .ds-det__bg { opacity: .2; }
    :root[data-theme="light"] .ds-det__top::after { background: linear-gradient(135deg, rgba(255, 255, 255, .55), rgba(255, 255, 255, .75)); }

    /* san pham to hon 1 chut */
    .ds-det__head { gap: 16px; }
    .ds-det__head img { width: 100px; height: 100px; border-radius: 23px; }
    .ds-det__main h1 { font-size: 23px; }
    .ds-det__main p { margin: 3px 0 12px; font-size: 14px; }
    .ds-get { height: 38px; padding: 0 30px; font-size: 14px; }
    .ds-share { width: 38px; height: 38px; }
    .ds-stat b { font-size: 16px; }
    .ds-det__txt { font-size: 14px; }
    .ds-row { gap: 13px; padding: 11px 0; }
    .ds-row img { width: 54px; height: 54px; border-radius: 13px; }
    .ds-row__info strong { font-size: 15.5px; }
    .ds-row__info span { font-size: 12.5px; }
    .ds-open { height: 31px; padding: 0 18px; font-size: 12.5px; }

    /* ===== v24: cuoi trang nho lai ===== */
    .ds-docs__blk { margin: 0 0 16px; }
    .ds-docs__h { gap: 9px; margin: 0 0 6px; font-size: 13px; letter-spacing: .06em; }
    .ds-docs__h svg { width: 18px; height: 18px; }
    .ds-docs__h--brand { font-size: 18px; letter-spacing: -.01em; }
    .ds-docs__p { font-size: 12.5px; line-height: 1.5; }
    .ds-docs .ds-doclink { padding: 7px 0; font-size: 13px; }

    /* ===== v24: thong bao co nen mo nhe (v26: da bo khung, xem khoi v26) ===== */
    html .toast-anchor { z-index: 1200; }
    html .toast { background: rgba(24, 24, 28, .92) !important; border: 1px solid var(--ds-line2) !important; -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px); }
    :root[data-theme="light"] .toast { background: rgba(255, 255, 255, .94) !important; }

    /* ===== v24: cuon muot: dung hieu ung bong logo luc dang cuon ===== */
    html.ds-scrolling .ds-sw, html.ds-scrolling .ds-swi { animation-play-state: paused; }
    html .site-header, .ds-nav { -webkit-backface-visibility: hidden; backface-visibility: hidden; }

    /* ===== v24: man hinh nap (vong tron xoay giong luc keo de tai lai) =====
       v26: vong tron chi hien neu nap lau hon ~0.3 giay; nap nhanh thi khong thay gi, mo ra ngay
       v28: da doi: hien o MOI lan vao web (xem ham devnvios_theme_boot ben tren, css cua no ghi de khoi nay) */
    #ds-boot { position: fixed; left: 0; right: 0; top: 0; bottom: 0; z-index: 99999; display: flex; align-items: center; justify-content: center; background: var(--ds-bg, #000); opacity: 1; transition: opacity .2s ease; }
    #ds-boot.is-out { opacity: 0; pointer-events: none; }
    #ds-boot svg { width: 42px; height: 42px; fill: none; opacity: 0; animation: ds-boot-spin .8s linear infinite, ds-boot-show .2s ease .3s forwards; }
    @keyframes ds-boot-spin { to { transform: rotate(360deg); } }
    @keyframes ds-boot-show { to { opacity: 1; } }
</style>
<?php
    /* v24 -> v28: man hinh nap (chuyen thanh ham devnvios_theme_boot de trang dang nhap / dang ky dung chung) */
    devnvios_theme_boot();
?>
<style id="ds-theme-v25">
    /* v25: khoang trong tren phan cuoi trang giong DS */
    .site-about { margin-top: 48px; }

    /* v25: thanh tab nho lai giong DS */
    :root:root { --nav-h: 56px; }
    .ds-nav__in { gap: 12px; }
    .ds-nav__item { gap: 2px; padding: 4px 2px 3px; border-radius: 12px; }
    .ds-nav .ds-ico { width: 20px; height: 20px; }

    /* v25: nut GET co bieu tuong tai (giong DS) */
    .ds-get svg { flex: 0 0 auto; width: 18px; height: 18px; margin-right: 8px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }

    /* v25: danh sach chuc nang: 3 dong dau + more (dong thu 3 mo dan) */
    .ds-feat.is-collapsed li:nth-child(3) { -webkit-mask-image: linear-gradient(180deg, #000 0%, rgba(0, 0, 0, .18) 100%); mask-image: linear-gradient(180deg, #000 0%, rgba(0, 0, 0, .18) 100%); }
    .ds-more { padding: 8px 0 2px; font-size: 14.5px; }

    /* v25: tai lai sau khi nhap key / thong bao: hien trang nhanh */
    html.ds-quiet body { transition: opacity .1s ease; }
</style>
<style id="ds-theme-v26">
    /* ===================================================================
       v26: nav kinh mo noi + header kinh nhe, khung Home/Keys nho lai,
       thong bao key moi, toast khong khung, History lam lai
       =================================================================== */

    /* ---- Thanh tab: vien thuoc noi, kinh mo nhin xuyen nhe, nho gon ---- */
    :root:root { --nav-h: 66px; --ds-nav-glass: rgba(28, 28, 33, .5); --ds-nav-line: rgba(255, 255, 255, .11); }
    :root[data-theme="light"] { --ds-nav-glass: rgba(255, 255, 255, .58); --ds-nav-line: rgba(0, 0, 0, .08); }
    html .ds-nav { left: 50%; right: auto; width: min(calc(100% - 28px), 30rem); bottom: calc(8px + env(safe-area-inset-bottom, 0px)); padding: 4px; border-radius: 22px; -webkit-transform: translate3d(-50%, 0, 0); transform: translate3d(-50%, 0, 0); }
    .ds-nav::before { left: 0; right: 0; top: 0; bottom: 0; border: 1px solid var(--ds-nav-line); border-radius: 22px; background: var(--ds-nav-glass); -webkit-backdrop-filter: blur(18px) saturate(170%); backdrop-filter: blur(18px) saturate(170%); box-shadow: 0 6px 20px rgba(0, 0, 0, .28); }
    :root[data-theme="light"] .ds-nav::before { box-shadow: 0 6px 18px rgba(0, 0, 0, .1); }
    @supports not ((-webkit-backdrop-filter: blur(1px)) or (backdrop-filter: blur(1px))) { .ds-nav::before { background: var(--ds-nav); } }
    html .ds-nav__in { gap: 2px; max-width: none; }
    html .ds-nav__item { gap: 1px; padding: 5px 2px 4px; font-size: 10.5px; border-radius: 18px; }
    html .ds-nav .ds-ico { width: 19px; height: 19px; }
    html .ds-nav__item.is-active { background: var(--ds-pill); }

    /* ---- Dau trang va bat dau cuon muot ---- */
    html .site-header, html .ds-nav { contain: layout style; }

    /* ---- Khung Home / Keys nho lai ---- */
    .banner { border-radius: 20px; }
    .banner__title { font-size: 20px; }
    .banner__desc { font-size: 12.5px; }
    html.ds-keys .ds-keyhead .ds-sec__title { margin-top: 4px; font-size: 20px; }
    html.ds-keys .page-top > section.card { min-height: 0 !important; height: auto !important; margin-top: 10px; margin-bottom: 0; padding: 12px 14px 12px; border-radius: 20px; }
    html.ds-keys .page-top > section.card > :last-child { margin-bottom: 0; }
    .card__title { font-size: 11.5px; }
    /* o nhap that 16px, KHONG dung transform scale nua (het giat, het lech con tro khi go) */
    html .field { width: 100%; max-width: 100%; height: 42px; margin: 8px 0 0; padding: 0 14px; border-radius: 13px; transform: none; }
    html .btn { height: 34px; padding: 0 18px; font-size: 12px; border-radius: 11px; }
    html .link-btn { font-size: 12px; }

    /* ---- Thong bao "tool da dong, nhap key": thay dong chu vang ---- */
    .ds-need { position: relative; display: flex; align-items: center; gap: 11px; margin: 10px 0 0; padding: 10px 14px 10px 10px; overflow: hidden; font-size: 13px; font-weight: 600; line-height: 1.4; color: var(--ds-h); background: var(--ds-field); border: 1px solid var(--ds-line2); border-radius: 16px; }
    .ds-need[hidden] { display: none !important; }
    .ds-need__ic { position: relative; display: flex; flex: 0 0 auto; align-items: center; justify-content: center; width: 34px; height: 34px; color: var(--ds-link); background: var(--ds-soft); border-radius: 11px; }
    .ds-need__ic svg { width: 19px; height: 19px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; transform-origin: 50% 85%; }
    .ds-need__ic::after { content: ""; position: absolute; left: 0; right: 0; top: 0; bottom: 0; border: 1.5px solid var(--ds-link); border-radius: 11px; opacity: 0; pointer-events: none; }
    .ds-need__t { flex: 1 1 0; min-width: 0; }
    .ds-need::after { content: ""; position: absolute; left: 0; bottom: 0; width: 100%; height: 2px; background: var(--ds-link); opacity: .55; transform-origin: 0 50%; transform: scaleX(0); }
    .ds-need.is-in { animation: ds-need-in .42s cubic-bezier(.2, .9, .25, 1.08) both; }
    .ds-need.is-in .ds-need__ic svg { animation: ds-need-wob .75s ease .3s both; }
    .ds-need.is-in .ds-need__sh { animation: ds-need-sh 1.1s ease .3s both; }
    .ds-need.is-in .ds-need__ic::after { animation: ds-need-ring 1.2s ease-out .2s 2 both; }
    .ds-need.is-in::after { animation: ds-need-bar var(--ds-need-ms, 4500ms) linear both; }
    .ds-need.is-out { animation: ds-need-out .34s ease forwards; }
    @keyframes ds-need-in { from { opacity: 0; transform: translateY(-8px) scale(.97); } to { opacity: 1; transform: none; } }
    @keyframes ds-need-out { from { opacity: 1; max-height: 90px; margin-top: 10px; } to { opacity: 0; max-height: 0; margin-top: 0; padding-top: 0; padding-bottom: 0; border-width: 0; } }
    @keyframes ds-need-wob { 0% { transform: rotate(0); } 20% { transform: rotate(-12deg); } 45% { transform: rotate(10deg); } 70% { transform: rotate(-5deg); } 100% { transform: rotate(0); } }
    @keyframes ds-need-sh { 0% { transform: translateY(0); } 35% { transform: translateY(-2.2px); } 70% { transform: translateY(-2.2px); } 100% { transform: translateY(0); } }
    @keyframes ds-need-ring { 0% { opacity: .65; transform: scale(1); } 100% { opacity: 0; transform: scale(1.55); } }
    @keyframes ds-need-bar { from { transform: scaleX(1); } to { transform: scaleX(0); } }
    @media (prefers-reduced-motion: reduce) { .ds-need.is-in, .ds-need.is-out, .ds-need.is-in *, .ds-need.is-in::after { animation: none !important; } }

    /* ---- Toast (dung key / sai key / canh bao): BO KHUNG, chi con icon + chu ---- */
    :root[data-theme="light"] .toast, html .toast { padding: 4px 6px; background: transparent !important; border: 0 !important; border-radius: 0; box-shadow: none !important; -webkit-backdrop-filter: none !important; backdrop-filter: none !important; text-shadow: 0 1px 2px rgba(0, 0, 0, .85), 0 0 12px rgba(0, 0, 0, .7); animation: ds-toast-in .28s ease both; }
    :root[data-theme="light"] .toast { text-shadow: 0 1px 2px rgba(255, 255, 255, .95), 0 0 12px rgba(255, 255, 255, .9); }
    html .toast__text, html .toast__text * { font-weight: 700; }
    @keyframes ds-toast-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { html .toast { animation: none; } }

    /* ---- History lam lai: gon, dang ledger / dong thoi gian ---- */
    .ds-badge[hidden] { display: none; }
    .ds-hhead { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 14px; }
    .ds-hhead .ds-pkgtitle { margin: 0; letter-spacing: -.02em; }
    .ds-histpage > .ds-pkgsub { margin: 4px 0 12px; }
    .ds-hstat { display: flex; margin: 0 0 12px; padding: 12px 0; border-top: 1px solid var(--ds-line); border-bottom: 1px solid var(--ds-line); }
    .ds-hstat > div { flex: 1 1 0; min-width: 0; padding: 0 14px; border-left: 1px solid var(--ds-line); }
    .ds-hstat > div:first-child { padding-left: 2px; border-left: 0; }
    .ds-hstat b { display: block; font-size: 18px; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; color: var(--ds-h); }
    .ds-hstat b.is-in { color: var(--ds-ok); }
    .ds-hstat b.is-out { color: var(--ds-red); }
    .ds-hstat span { display: block; margin-top: 1px; font-size: 12px; color: var(--ds-p); }

    .ds-seg { display: flex; gap: 2px; margin: 0 0 4px; padding: 3px; background: var(--ds-field); border-radius: 13px; }
    .ds-seg .ds-hchip { flex: 1 1 0; height: 32px; padding: 0 6px; font-size: 13px; font-weight: 600; color: var(--ds-p); background: transparent; border: 0; border-radius: 10px; transition: background-color .25s ease, color .25s ease; }
    .ds-seg .ds-hchip.is-on { color: var(--ds-h); background: var(--ds-card); box-shadow: 0 1px 3px rgba(0, 0, 0, .22); }

    .ds-hday { margin: 18px 0 8px 2px; font-size: 13px; font-weight: 600; color: var(--ds-p); }
    .ds-hgrp { position: relative; }
    .ds-hgrp::before { content: ""; position: absolute; left: 20px; top: 30px; bottom: 30px; width: 1px; background: var(--ds-line2); }
    .ds-hgrp .ds-h { position: relative; gap: 12px; padding: 10px 0; border-bottom: 0; }
    .ds-hgrp .ds-h__ic { z-index: 1; width: 40px; height: 40px; border-radius: 50%; background-color: var(--ds-bg); background-image: linear-gradient(var(--ds-field), var(--ds-field)); box-shadow: 0 0 0 4px var(--ds-bg); }
    .ds-hgrp .ds-h__ic.is-in { background-image: linear-gradient(var(--ds-ok-soft), var(--ds-ok-soft)); }
    .ds-hgrp .ds-h__ic img { width: 40px; height: 40px; }
    .ds-hgrp .ds-h__info strong { font-size: 14.5px; font-weight: 600; }
    .ds-hgrp .ds-h__amt { height: auto; padding: 0; font-size: 14px; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; background: transparent !important; border-radius: 0; }
    .ds-hgrp .ds-h__amt.is-zero { font-weight: 600; }

    .ds-histpage .ds-hempty { padding: 36px 16px 20px; }
    .ds-histpage .ds-hempty .ds-empty__ico { width: 44px; height: 44px; margin-bottom: 12px; color: var(--ds-p); background: var(--ds-field); border-radius: 14px; }
    .ds-histpage .ds-hempty .ds-empty__ico svg { width: 22px; height: 22px; }
    .ds-histpage .ds-hempty h2 { margin: 0 0 4px; font-size: 16px; font-weight: 700; }
    .ds-histpage .ds-hempty p { font-size: 13px; line-height: 1.5; }
    .ds-hgo { display: inline-flex; align-items: center; justify-content: center; height: 34px; margin-top: 14px; padding: 0 18px; font-size: 13px; font-weight: 700; color: var(--ds-link); background: var(--ds-soft); border: 0; border-radius: 999px; }
    .ds-hgo:active { transform: scale(.97); }
</style>
<style id="ds-theme-v27">
    /* ===================================================================
       v27: Home giong shopdsgm.vn, cuon muot, thong bao o giua man hinh,
       thong bao chua co key (dau X dong), menu avatar gon dep
       =================================================================== */

    /* ---- Cuon muot (v27) ---- */
    html { -webkit-overflow-scrolling: touch; scroll-behavior: auto; }
    body { -webkit-overflow-scrolling: touch; }
    .ds-row, .ds-rel__card, .ds-lrow { -webkit-tap-highlight-color: transparent; }

    /* ---- Thong bao (toast) o GIUA man hinh: icon tren, chu duoi ---- */
    html .toast-anchor { top: var(--ds-toast-top, 40%); bottom: auto; left: 16px; right: 16px; z-index: 1300; -webkit-transform: translate3d(0, -50%, 0); transform: translate3d(0, -50%, 0); }
    html .toast { position: relative; isolation: isolate; flex-direction: column; justify-content: center; gap: 10px; max-width: min(88vw, 22rem); padding: 6px 10px; text-align: center; font-size: 15px; animation: ds-toast-pop .34s cubic-bezier(.2, .9, .25, 1.12) both; }
    html .toast::before { content: ""; position: absolute; z-index: -1; left: -30px; right: -30px; top: -24px; bottom: -24px; pointer-events: none; background: radial-gradient(closest-side, var(--ds-bg) 0%, rgba(0, 0, 0, 0) 100%); opacity: .9; }
    html .toast__icon { width: 36px; height: 36px; }
    html .toast__icon svg { width: 32px; height: 32px; animation: ds-toast-ico .5s cubic-bezier(.2, .9, .25, 1.3) .06s both; }
    html .toast__text, html .toast__text * { font-size: 15px !important; font-weight: 700; text-align: center; }
    @keyframes ds-toast-pop { from { opacity: 0; transform: translateY(10px) scale(.92); } to { opacity: 1; transform: none; } }
    @keyframes ds-toast-ico { 0% { opacity: 0; transform: scale(.3) rotate(-25deg); } 60% { opacity: 1; transform: scale(1.15) rotate(6deg); } 100% { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { html .toast, html .toast__icon svg { animation: none; } }

    /* ---- Thong bao "chua co key": dau X do tu ve + rung nhe ---- */
    .ds-need__ic { color: var(--ds-red); background: var(--ds-red-soft); }
    .ds-need__ic::after { border-color: var(--ds-red); }
    .ds-need::after { background: var(--ds-red); opacity: .6; }
    .ds-need__ic svg { overflow: visible; stroke-width: 2.1; transform-origin: 50% 50%; }
    .ds-x__c { stroke-dasharray: 60; stroke-dashoffset: 60; }
    .ds-x__a, .ds-x__b { stroke-dasharray: 11; stroke-dashoffset: 11; }
    .ds-need.is-in .ds-x__c { animation: ds-x-draw .5s ease .1s forwards; }
    .ds-need.is-in .ds-x__a { animation: ds-x-draw .22s ease .5s forwards; }
    .ds-need.is-in .ds-x__b { animation: ds-x-draw .22s ease .68s forwards; }
    .ds-need.is-in .ds-need__ic svg { animation: ds-x-shake .55s ease .92s both; }
    @keyframes ds-x-draw { to { stroke-dashoffset: 0; } }
    @keyframes ds-x-shake { 0%, 100% { transform: translateX(0) rotate(0); } 18% { transform: translateX(-3px) rotate(-8deg); } 38% { transform: translateX(3px) rotate(7deg); } 58% { transform: translateX(-2px) rotate(-4deg); } 78% { transform: translateX(1.5px) rotate(2deg); } }
    @media (prefers-reduced-motion: reduce) { .ds-x__c, .ds-x__a, .ds-x__b { stroke-dashoffset: 0; } }

    /* ---- Menu avatar: gon, dep ---- */
    .ds-um { width: min(252px, calc(100vw - 28px)); padding: 10px; border-radius: 18px; transform-origin: 100% 0; box-shadow: 0 14px 34px rgba(0, 0, 0, .45); animation: ds-um-in .18s cubic-bezier(.2, .9, .25, 1.05) both; }
    :root[data-theme="light"] .ds-um { box-shadow: 0 14px 34px rgba(0, 0, 0, .16); }
    @keyframes ds-um-in { from { opacity: 0; transform: translateY(-6px) scale(.94); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { .ds-um { animation: none; } }
    .ds-um__top { display: flex; align-items: center; gap: 11px; padding: 4px 4px 10px; }
    .ds-um__av { position: relative; overflow: hidden; display: flex; flex: 0 0 auto; align-items: center; justify-content: center; width: 42px; height: 42px; font-size: 18px; font-weight: 800; color: #fff; background: var(--blue); border-radius: 14px; }
    .ds-um__who { flex: 1 1 0; min-width: 0; }
    .ds-um__hello { font-size: 11px; line-height: 1.2; }
    .ds-um__name { margin: 0 0 4px; font-size: 15px; line-height: 1.25; }
    .ds-um__who .ds-badge { height: 19px; padding: 0 8px; font-size: 10.5px; line-height: 19px; }
    .ds-um__row { margin: 0 0 4px; padding: 8px 12px; font-size: 12.5px; border-radius: 11px; }
    .ds-um__row b { font-size: 15px; }
    .ds-um__sep { margin: 5px 2px; }
    .ds-um__item { gap: 10px; padding: 5px 4px; font-size: 13.5px; border-radius: 11px; }
    .ds-um__item:active { opacity: 1; background: var(--ds-field); }
    .ds-um__item .ds-ico { box-sizing: content-box; width: 16px; height: 16px; padding: 6px; background: var(--ds-field); border-radius: 10px; }
    .ds-um__item:active .ds-ico { background: var(--ds-line2); }
    .ds-um__item--out .ds-ico { background: var(--ds-red-soft); }
</style>
<?php if (!(defined('DS_NAV_FLOAT') && DS_NAV_FLOAT)): ?>
<style id="ds-nav-dock">
    /* v27: thanh tab kieu DS NHO GON, dinh sat day, KINH MO nhin xuyen mo mo. (DS_NAV_FLOAT = true => thanh noi nhu v26) */
    :root:root { --nav-h: 56px; --ds-nav-glass: rgba(24, 24, 28, .5); }
    :root[data-theme="light"] { --ds-nav-glass: rgba(255, 255, 255, .55); }
    html .ds-nav { left: 0; right: 0; width: auto; bottom: 0; padding: 4px 10px calc(4px + env(safe-area-inset-bottom, 0px)); border-radius: 0; -webkit-transform: translate3d(0, 0, 0); transform: translate3d(0, 0, 0); }
    .ds-nav::before { top: 0; bottom: -120px; border: 0; border-top: 1px solid var(--ds-nav-line); border-radius: 0; background: var(--ds-nav-glass); -webkit-backdrop-filter: blur(18px) saturate(170%); backdrop-filter: blur(18px) saturate(170%); box-shadow: none; }
    :root[data-theme="light"] .ds-nav::before { box-shadow: none; }
    @supports not ((-webkit-backdrop-filter: blur(1px)) or (backdrop-filter: blur(1px))) { .ds-nav::before { background: var(--ds-nav); } }
    html .ds-nav__in { gap: 6px; max-width: 36rem; }
    html .ds-nav__item { gap: 1px; padding: 5px 2px 4px; font-size: 10.5px; border-radius: 12px; }
    html .ds-nav .ds-ico { width: 20px; height: 20px; }
    html .ds-nav__item.is-active { background: var(--ds-pill); }
</style>
<?php else: ?>
<style id="ds-nav-float">
    :root:root { --nav-h: 66px; }
</style>
<?php endif; ?>
<?php
    /* v27: do cao thong bao (phan tram tu tren xuong), doi bang define('DS_TOAST_TOP', 40); */
    $dsToastTop = defined('DS_TOAST_TOP') ? max(10, min(80, (int) DS_TOAST_TOP)) : 40;
    /* v28: cuon muot => mac dinh KHONG blur header + thanh tab. Bat lai: DS_GLASS_BLUR (ca hai) / DS_HEADER_GLASS / DS_NAV_GLASS */
    $dsBlurAll = defined('DS_GLASS_BLUR') && DS_GLASS_BLUR;
    $dsHdBlur  = $dsBlurAll || (defined('DS_HEADER_GLASS') && DS_HEADER_GLASS);
    $dsNavBlur = $dsBlurAll || (defined('DS_NAV_GLASS') && DS_NAV_GLASS);
    /* v33: thanh tab duoi kinh mo nhin xuyen nhe (mac dinh BAT). Tat: define('DS_NAV_SEE', false); do dac: define('DS_NAV_SEE_ALPHA', 58); */
    $dsNavSee   = !(defined('DS_NAV_SEE') && !DS_NAV_SEE);
    $dsNavAlpha = defined('DS_NAV_SEE_ALPHA') ? max(20, min(90, (int) DS_NAV_SEE_ALPHA)) : 46;
?>
<style id="ds-v27-vars">:root:root { --ds-toast-top: <?= (int) $dsToastTop ?>%; }</style>
<?php if ($dsHdBlur): ?>
<style id="ds-hdglass">html .site-header { background: var(--ds-hd-glass) !important; -webkit-backdrop-filter: blur(16px) saturate(160%); backdrop-filter: blur(16px) saturate(160%); }</style>
<?php endif; ?>
<?php if (defined('DS_HEADER_STICKY') && !DS_HEADER_STICKY): ?>
<style id="ds-sticky">html .site-header { position: relative !important; } html body { padding-top: 0 !important; }</style>
<?php endif; ?>
<?php if (defined('DS_NAV_GLASS') && DS_NAV_GLASS): ?>
<style id="ds-glass">.ds-nav::before { background: var(--ds-nav-glass); -webkit-backdrop-filter: blur(12px) saturate(150%); backdrop-filter: blur(12px) saturate(150%); }</style>
<?php endif; ?>
<?php if (defined('DS_NAV_GLASS') && !DS_NAV_GLASS): ?>
<style id="ds-noglass">.ds-nav::before { -webkit-backdrop-filter: none; backdrop-filter: none; background: var(--ds-nav); }</style>
<?php endif; ?>
<?php /* v35: bong logo MAC DINH BAT (chay cham). Chi tat khi index.php define('DS_SHINE', false) */ ?>
<?php if (defined('DS_SHINE') && !DS_SHINE): ?>
<style id="ds-noshine">.ds-sw { display: none !important; animation: none !important; }</style>
<?php endif; ?>
<?php if (!(defined('DS_USERBAR_SHOW') && DS_USERBAR_SHOW)): ?>
<style id="ds-ubar">html .user-bar { display: none !important; }</style>
<?php endif; ?>
<style id="ds-theme-v28">
    /* ===================================================================
       v28: cuon muot (bo blur, an vet lang bong luc cuon), thong bao nam
       TREN o VIP key / DUOI dong chu "used up", menu avatar nho gon
       =================================================================== */

    /* v32: header + thanh tab DAC hoan toan (truoc la .93 / .94 nen chu phia sau "xuyen" len, nhin nhu bi de len dong Made with ...).
       Muon kinh mo lai: define('DS_GLASS_BLUR', true); */
    :root:root { --ds-hd-soft: #000; --ds-nav-soft: #0e0e10; }
    :root:root[data-theme="light"] { --ds-hd-soft: #fff; --ds-nav-soft: #fff; }

    /* ---- Cuon muot: luc dang cuon bo han vet lang bong cua logo (khong con lop mask dang chay), tranh ve lai thua ---- */
    html.ds-scrolling .ds-sw { display: none !important; }
    /* v32: da bo backface-visibility cua icon (moi icon thanh 1 lop ve rieng => nhieu lop bo goc, nang khi cuon). */

    /* ---- Thong bao (toast) nam TREN o VIP key, DUOI dong chu "used up" (giong thong bao khi bam GET ma het luot) ---- */
    :root:root .ds-keyhead .toast-anchor { position: static !important; left: auto !important; right: auto !important; top: auto !important; bottom: auto !important; z-index: auto !important; width: auto !important; height: auto !important; min-height: 0 !important; margin: 0 !important; padding: 0 !important; -webkit-transform: none !important; transform: none !important; pointer-events: none; }
    :root:root .ds-keyhead .toast { position: relative; display: flex; flex-direction: row; align-items: center; justify-content: flex-start; gap: 11px; width: auto; max-width: none; margin: 10px 0 0; padding: 10px 14px 10px 12px; font-size: 13.5px; font-weight: 600; line-height: 1.4; text-align: left; color: var(--ds-h); text-shadow: none; background: var(--ds-field) !important; border: 1px solid var(--ds-line2) !important; border-radius: 16px !important; box-shadow: none !important; -webkit-backdrop-filter: none !important; backdrop-filter: none !important; animation: ds-toast-slot .36s cubic-bezier(.2, .9, .25, 1.08) both; }
    :root:root .ds-keyhead .toast::before { content: none !important; }
    :root:root .ds-keyhead .toast__icon { display: inline-flex; flex: 0 0 auto; align-items: center; justify-content: center; width: 28px; height: 28px; margin: 0; }
    :root:root .ds-keyhead .toast__icon svg { width: 26px; height: 26px; }
    :root:root .ds-keyhead .toast__text, :root:root .ds-keyhead .toast__text * { flex: 1 1 0; min-width: 0; font-size: 13.5px !important; font-weight: 600 !important; line-height: 1.4; text-align: left !important; text-shadow: none !important; color: var(--ds-h) !important; }
    @keyframes ds-toast-slot { from { opacity: 0; transform: translateY(-8px) scale(.97); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { :root:root .ds-keyhead .toast { animation: none; } }

    /* ---- Khung menu avatar nho gon lai ---- */
    html .ds-um { width: min(220px, calc(100vw - 28px)); padding: 8px; border-radius: 16px; }
    html .ds-um__top { gap: 9px; padding: 2px 3px 8px; }
    html .ds-um__av { width: 36px; height: 36px; font-size: 15px; border-radius: 12px; }
    html .ds-um__hello { font-size: 10.5px; }
    html .ds-um__name { margin: 0 0 3px; font-size: 14px; }
    html .ds-um__who .ds-badge { height: 17px; padding: 0 7px; font-size: 10px; line-height: 17px; }
    html .ds-um__row { margin: 0 0 2px; padding: 6px 10px; font-size: 12px; border-radius: 10px; }
    html .ds-um__row b { font-size: 14px; }
    html .ds-um__sep { margin: 4px 2px; }
    html .ds-um__item { gap: 9px; padding: 3px 3px; font-size: 13px; border-radius: 10px; }
    html .ds-um__item .ds-ico { width: 14px; height: 14px; padding: 5px; border-radius: 9px; }
</style>
<style id="ds-theme-v29">
    /* ===================================================================
       v29: thong bao KHONG khung + nho gon + canh le trai, dau X "chua co key"
       giong thong bao sai key, danh sach san pham nho lai mot chut
       =================================================================== */

    /* ---- Thong bao "tool da dong, nhap key" (bam GET ma het luot): chi con dau X do + chu, giong thong bao sai key ---- */
    :root:root .ds-need { display: flex; align-items: center; gap: 10px; margin: 10px 0 0; padding: 0; overflow: visible; font-size: 13px; font-weight: 700; line-height: 1.4; color: var(--ds-h); background: transparent !important; border: 0 !important; border-radius: 0; box-shadow: none; }
    :root:root .ds-need[hidden] { display: none !important; }
    :root:root .ds-need::after { content: none; display: none; }
    :root:root .ds-need__ic { width: 20px; height: 20px; color: var(--ds-red); background: transparent !important; border-radius: 0; }
    :root:root .ds-need__ic::after { content: none; display: none; }
    :root:root .ds-need__ic svg { width: 20px; height: 20px; overflow: visible; stroke: var(--ds-red); stroke-width: 3; stroke-linecap: round; }
    :root:root .ds-x__c { display: none; }
    :root:root .ds-x__a, :root:root .ds-x__b { stroke-dasharray: 17; stroke-dashoffset: 17; }
    :root:root .ds-need.is-in .ds-x__a { animation: ds-x-draw .2s ease .05s forwards; }
    :root:root .ds-need.is-in .ds-x__b { animation: ds-x-draw .2s ease .22s forwards; }
    :root:root .ds-need.is-in .ds-need__ic svg { animation: ds-x-shake .55s ease .45s both; }
    @media (prefers-reduced-motion: reduce) { :root:root .ds-x__a, :root:root .ds-x__b { stroke-dashoffset: 0; } }

    /* ---- Thong bao (toast) trong khu Keys: bo khung, nho gon, canh le trai (het lech ra le) ---- */
    :root:root .ds-keyhead .toast-anchor { display: block !important; text-align: left; }
    :root:root .ds-keyhead .toast { width: auto !important; max-width: none !important; margin: 10px 0 0 !important; padding: 0 !important; left: 0 !important; right: auto !important; top: auto !important; -webkit-transform: none !important; transform: none !important; justify-content: flex-start; gap: 10px; font-size: 13px; font-weight: 700; background: transparent !important; border: 0 !important; border-radius: 0 !important; box-shadow: none !important; animation: ds-toast-fade .3s ease both; }
    :root:root .ds-keyhead .toast__icon { width: 20px; height: 20px; }
    :root:root .ds-keyhead .toast__icon svg { width: 20px !important; height: 20px !important; animation: none; }
    :root:root .ds-keyhead .toast__text, :root:root .ds-keyhead .toast__text * { font-size: 13px !important; font-weight: 700 !important; line-height: 1.4; }
    @keyframes ds-toast-fade { from { opacity: 0; } to { opacity: 1; } }
    @media (prefers-reduced-motion: reduce) { :root:root .ds-keyhead .toast { animation: none; } }

    /* ---- Danh sach san pham nho lai mot chut ---- */
    html .ds-row { gap: 12px; padding: 9px 0; }
    html .ds-row img { width: 48px; height: 48px; border-radius: 12px; }
    html .ds-row__info strong { font-size: 14.5px; }
    html .ds-row__info span { font-size: 12px; }
    html .ds-open { height: 29px; padding: 0 16px; font-size: 12px; }
</style>
<style id="ds-theme-v30">
    /* ===================================================================
       v30: thong bao ep CANH LE TRAI (het bi day ra ngoai le phai), X "chua co key"
       giong X sai key, san pham nho lai them 1 chut, cuon muot hon
       =================================================================== */

    /* ---- Thong bao (dung key / sai key / chua dang nhap / canh bao): KHONG khung, X + chu sat le trai ----
       Khung chua .toast-anchor ep thanh khoi doc, canh trai; .toast chiem tron chieu ngang, khong con margin auto / left / transform
       (do index.php hoac cac khoi cu co the dat lech ra phai). */
    html:root:root body .ds-keyhead .toast-anchor {
        position: static !important; inset: auto !important; display: flex !important; flex-direction: column !important;
        align-items: flex-start !important; justify-content: flex-start !important; width: 100% !important; max-width: 100% !important;
        height: auto !important; min-height: 0 !important; margin: 0 !important; padding: 0 !important; text-align: left !important;
        -webkit-transform: none !important; transform: none !important; translate: none !important; pointer-events: none;
    }
    html:root:root body .ds-keyhead .toast-anchor .toast {
        position: relative !important; inset: auto !important; left: 0 !important; right: auto !important; top: auto !important; bottom: auto !important;
        float: none !important; display: flex !important; flex-direction: row !important; align-items: center !important; justify-content: flex-start !important;
        align-self: flex-start !important; box-sizing: border-box !important; width: 100% !important; max-width: 100% !important;
        margin: 10px 0 0 !important; padding: 0 !important; gap: 10px !important; text-align: left !important;
        font-size: 13px !important; font-weight: 700 !important; line-height: 1.4 !important; color: var(--ds-h) !important;
        background: transparent !important; border: 0 !important; border-radius: 0 !important; box-shadow: none !important; text-shadow: none !important;
        -webkit-backdrop-filter: none !important; backdrop-filter: none !important;
        -webkit-transform: none !important; transform: none !important; translate: none !important;
        animation: ds-toast-fade .3s ease both;
    }
    html:root:root body .ds-keyhead .toast-anchor .toast::before, html:root:root body .ds-keyhead .toast-anchor .toast::after { content: none !important; display: none !important; }
    html:root:root body .ds-keyhead .toast-anchor .toast__icon { display: inline-flex !important; flex: 0 0 20px !important; align-items: center !important; justify-content: center !important; width: 20px !important; height: 20px !important; margin: 0 !important; padding: 0 !important; background: none !important; border: 0 !important; }
    html:root:root body .ds-keyhead .toast-anchor .toast__icon svg { width: 20px !important; height: 20px !important; margin: 0 !important; animation: none !important; }
    html:root:root body .ds-keyhead .toast-anchor .toast__text, html:root:root body .ds-keyhead .toast-anchor .toast__text * { flex: 1 1 0 !important; min-width: 0 !important; margin: 0 !important; font-size: 13px !important; font-weight: 700 !important; line-height: 1.4 !important; text-align: left !important; text-shadow: none !important; color: var(--ds-h) !important; }
    @media (prefers-reduced-motion: reduce) { html:root:root body .ds-keyhead .toast-anchor .toast { animation: none !important; } }

    /* ---- Thong bao "tool da dong, nhap key": cung dang X do + chu, khong khung, sat le trai ---- */
    html:root:root body .ds-keyhead .ds-need { box-sizing: border-box; width: 100%; margin: 10px 0 0; padding: 0; justify-content: flex-start; text-align: left; font-size: 13px; font-weight: 700; background: transparent !important; border: 0 !important; border-radius: 0; }
    html:root:root body .ds-keyhead .ds-need__ic { flex: 0 0 20px; margin: 0; }
    html:root:root body .ds-keyhead .ds-need__t { flex: 1 1 0; min-width: 0; font-size: 13px; font-weight: 700; text-align: left; }

    /* ---- Danh sach san pham nho lai them 1 chut ---- */
    html .ds-row { gap: 11px; padding: 8px 0; }
    html .ds-row img { width: 45px; height: 45px; border-radius: 11px; }
    html .ds-row__info strong { font-size: 14px; }
    html .ds-row__info span { margin-top: 0; font-size: 11.5px; }
    html .ds-open { height: 27px; padding: 0 14px; font-size: 11.5px; }

    /* ---- Cuon muot hon ---- */
    /* v32: DA TAT content-visibility cua dong tool. No lam dong ben tren man hinh doi chieu cao khi cuon NGUOC LEN,
       trang bi day / nhay vi tri => chinh la loi "cuon xuong roi cuon len 1 chut thi giat". Chi co ~25 dong nen khong can. */
    html .ds-row { content-visibility: visible; contain-intrinsic-size: none; }
    /* bo lop cuon cu cua body (tren iOS moi chi tao them lop ve thua) */
    html body { -webkit-overflow-scrolling: auto; }
    /* vong keo-de-tai chi can lop rieng khi dang keo */
    html .ds-ptr { will-change: auto; }
    html .ds-ptr.is-spin, html .ds-ptr[style*="visible"] { will-change: transform, opacity; }
    /* luc dang cuon: chi tam dung + lam mo vet lang bong cua logo (khong display:none => khong phai tinh lai bo cuc).
       Class nay gan tren HEADER (khong gan len <html> nua) nen chi header tinh lai style, khong dung toi ca trang. */
    html .site-header.ds-scrolling .ds-sw, html .site-header.ds-scrolling .ds-swi { animation-play-state: paused !important; }
    html .site-header.ds-scrolling .ds-sw { opacity: 0; }
    html .site-header .ds-sw { transition: opacity .12s ease; }
</style>
<style id="ds-theme-v31">
    /* ===================================================================
       v31: trang chu kieu shopdsgm.vn (IPA - TOOLS, banner tu chuyen, the keo ngang),
       khung VIP key / Free key nhich ra them mot chut
       =================================================================== */

    /* bo khung banner DEVNVIOS cu (chi ghi de bang CSS, khong xoa gi cua index.php) */
    html .banner { display: none !important; }

    .ds-hero { display: none; padding-top: 18px; }
    .ds-hero.is-on { display: block; }
    html.ds-keys .ds-hero, html.ds-tools .ds-hero, html.ds-hist .ds-hero, html.ds-me .ds-hero, html.ds-detail .ds-hero, html.ds-pkg .ds-hero { display: none !important; }
    .ds-hero__title { margin: 0; font-size: 28px; font-weight: 800; line-height: 1.15; letter-spacing: -.01em; color: var(--ds-h); }
    .ds-hero__desc { margin: 6px 0 0; font-size: 14px; line-height: 1.45; color: var(--ds-p); }

    /* banner anh: vuot qua lai (cuon ngang bam diem dung), tu chuyen sau vai giay */
    .ds-slider { position: relative; margin: 16px 0 0; overflow: hidden; background: var(--ds-field); border: 1px solid var(--ds-line); border-radius: 20px; -webkit-transform: translateZ(0); transform: translateZ(0); }
    .ds-slider__track { display: flex; overflow-x: auto; overflow-y: hidden; scroll-snap-type: x mandatory; scrollbar-width: none; -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; }
    .ds-slider__track::-webkit-scrollbar { display: none; }
    .ds-slide { flex: 0 0 100%; min-width: 0; scroll-snap-align: start; scroll-snap-stop: always; }
    .ds-slide img { display: block; width: 100%; height: auto; aspect-ratio: 1.7 / 1; object-fit: cover; background: var(--ds-field); -webkit-user-drag: none; -webkit-user-select: none; user-select: none; }
    .ds-slider__dots { position: absolute; right: 12px; bottom: 10px; display: flex; align-items: center; gap: 5px; padding: 4px 6px; background: rgba(0, 0, 0, .38); border-radius: 999px; pointer-events: none; }
    .ds-dot { width: 6px; height: 6px; background: rgba(255, 255, 255, .55); border-radius: 999px; transition: width .25s ease, background-color .25s ease; }
    .ds-dot.is-on { width: 18px; background: #fff; }

    /* hang the nho: tu chay ngang, cham vao keo qua keo lai duoc (chi dung transform => muot) */
    .ds-chips2 { position: relative; margin: 14px -16px 0; overflow: hidden; touch-action: pan-y; -webkit-user-select: none; user-select: none; cursor: grab; }
    .ds-chips2:active { cursor: grabbing; }
    .ds-chips2__track { display: flex; width: max-content; padding-left: 16px; will-change: transform; -webkit-backface-visibility: hidden; backface-visibility: hidden; }
    .ds-chipset { display: flex; flex: 0 0 auto; }
    .ds-chip2 { display: inline-flex; flex: 0 0 auto; align-items: center; gap: 7px; height: 34px; margin-right: 8px; padding: 0 14px; font-size: 13px; font-weight: 600; white-space: nowrap; color: var(--ds-h); background: var(--ds-card); border: 1px solid var(--ds-line2); border-radius: 12px; }
    .ds-chip2 svg { flex: 0 0 auto; width: 16px; height: 16px; fill: none; stroke: var(--ds-link); stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }

    /* khung VIP key / Free key nhich ra them mot chut */
    html:root.ds-keys .page-top > section.card { margin-top: 18px !important; }
</style>
<style id="ds-theme-v32">
    /* ===================================================================
       v32: khung IPA - TOOLS nhich len, hang the nho tu chay (CSS) + chi 9 the,
       dong JOIN co khoang tho, het giat khi cuon len, cuoi trang gon lai
       =================================================================== */

    /* ---- Khung dau trang chu nhich LEN (khoang cach tu header toi chu IPA - TOOLS do JS canh chinh xac, xem DS_HERO_TOP) ---- */
    html .ds-hero { padding-top: 0; }
    html .ds-hero__desc { margin-top: 4px; }
    html .ds-slider { margin-top: 12px; }

    /* ---- Hang the nho: TU CHAY ngang bang CSS (transform tren GPU, khong JS, khong keo) ----
       Track chua 2 bo the giong nhau => dich -50% la khop vong lap. Moi bo the co padding-left 16px de hai nua bang nhau. */
    html .ds-chips2 { margin: 10px -16px 12px; cursor: default; touch-action: auto; pointer-events: none; }
    html .ds-chips2:active { cursor: default; }
    html .ds-chips2__track { padding-left: 0; -webkit-animation: ds-chips-run var(--ds-chips-sec, 24s) linear infinite; animation: ds-chips-run var(--ds-chips-sec, 24s) linear infinite; }
    html .ds-chipset { padding-left: 16px; }
    html .ds-chips2.is-off .ds-chips2__track { -webkit-animation-play-state: paused; animation-play-state: paused; }
    @-webkit-keyframes ds-chips-run { from { -webkit-transform: translate3d(0, 0, 0); transform: translate3d(0, 0, 0); } to { -webkit-transform: translate3d(-50%, 0, 0); transform: translate3d(-50%, 0, 0); } }
    @keyframes ds-chips-run { from { transform: translate3d(0, 0, 0); } to { transform: translate3d(-50%, 0, 0); } }
    /* may bat "Giam chuyen dong": van chay nhung cham hon mot nua (khong tat han de the van tu chay) */
    @media (prefers-reduced-motion: reduce) { html .ds-chips2__track { animation-duration: calc(var(--ds-chips-sec, 24s) * 2); } }

    /* ---- Cuon muot: bo moi thu lam trang nhay khi cuon nguoc ---- */
    html .ds-row { content-visibility: visible; contain-intrinsic-size: none; }
    html .ds-row img, html .ds-rel__card img, html .ds-h__ic img { -webkit-backface-visibility: visible; backface-visibility: visible; }

    /* ---- Cuoi trang gon lai: giam khoang trong giua danh sach tool / khoi DEVNVIOS / dong Made with ... @2024 / thanh tab ---- */
    html .site-about { margin-top: 12px; padding-top: 14px; padding-bottom: 0; }
    html .ds-docs { margin-bottom: 0; }
    html .ds-docs__blk { margin-bottom: 12px; }
    html .ds-docs .ds-doclink:last-child { padding-bottom: 6px; }
    html .site-footer { margin-top: 0; padding-top: 10px; padding-bottom: 8px; }
    html .site-footer p { margin-top: 0; margin-bottom: 2px; }
</style>
<style id="ds-theme-v33">
    /* ===================================================================
       v33 (ban cuoi): IPA - TOOLS xuong chut, JOIN thang hang, hang the keo tay, bo cham tron banner,
       het de len khi cuon, thanh tab kinh mo, khoi gioi thieu tach khoi san pham, cuoi trang het khoang trong thua
       =================================================================== */

    /* ---- Banner: bo cham tron o goc phai ---- */
    html .ds-slider__dots { display: none !important; }

    /* ---- Hang the nho: KHONG tu chay. Vao web thay the dau tien (IPA), tu keo ngang bang tay (cuon ngang goc cua trinh duyet => muot) ---- */
    html .ds-chips2 { margin: 12px -16px 0; overflow-x: auto; overflow-y: hidden; pointer-events: auto; touch-action: pan-x pan-y; cursor: auto; -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; scrollbar-width: none; -ms-overflow-style: none; -webkit-user-select: none; user-select: none; }
    html .ds-chips2::-webkit-scrollbar { display: none; }
    html .ds-chips2__track { -webkit-animation: none !important; animation: none !important; -webkit-transform: none !important; transform: none !important; will-change: auto; padding-left: 0; }
    html .ds-chipset { padding-left: 16px; padding-right: 8px; }
    html .ds-chips2__track > .ds-chipset[aria-hidden="true"] { display: none; }

    /* ---- Dong JOIN THE TELEGRAM GROUP: thang hang, can giua, co khoang tho ---- */
    html .group-link { display: block; box-sizing: border-box; clear: both; width: 100%; height: auto; min-height: 0; margin: 16px 0 6px; padding: 8px 0; line-height: 1.45; text-align: center; overflow: visible; -webkit-transform: none; transform: none; }
    html .group-link span, html .group-link a { display: inline-block; line-height: 1.45; }

    /* ---- Het de len khi cuon len xuong lien tuc: header DAC + lop che phia tren, bo goi y ve lai thua ---- */
    html .site-header { background: var(--ds-hd-soft, #000) !important; -webkit-backdrop-filter: none !important; backdrop-filter: none !important; contain: none !important; will-change: auto !important; -webkit-backface-visibility: visible; backface-visibility: visible; -webkit-transform: translateZ(0); transform: translateZ(0); isolation: isolate; }
    html .site-header::after { content: ""; position: absolute; left: 0; right: 0; bottom: 100%; height: 600px; pointer-events: none; background: var(--ds-hd-soft, #000); }
    html .site-header .ds-sw, html .site-header .ds-swi { transition: none !important; }
    html .site-header.ds-scrolling .ds-sw { opacity: 1; }
    html .ds-nav { contain: none !important; will-change: auto !important; -webkit-backface-visibility: visible; backface-visibility: visible; }
    html .ds-softin { animation-duration: .2s; }

    /* ---- Khoi gioi thieu / tai lieu: tach khoi san pham (het bi dinh), giong shopdsgm.vn ---- */
    html .site-about { margin-top: 30px; padding-top: 26px; padding-bottom: 0; }
    html .ds-docs__blk { margin-bottom: 20px; }
    html .ds-docs__p { line-height: 1.6; }
    html .ds-docs .ds-doclink:last-child { padding-bottom: 8px; }
    html .site-footer { margin-top: 10px; padding-top: 14px; padding-bottom: 10px; text-align: center; }
    html .site-footer p { margin-top: 0; margin-bottom: 4px; }
    html.ds-keys .site-about, html.ds-tools .site-about, html.ds-hist .site-about, html.ds-me .site-about { margin-top: 30px; }

    /* ---- Cuoi trang: khoang trong duoi = chieu cao that cua thanh tab (do bang JS, mac dinh du phong ben duoi) ---- */
    html:not(.ds-typing) body { padding-bottom: calc(var(--ds-nav-real, var(--nav-h)) + 2px) !important; }
</style>
<?php if (!$dsHdBlur): ?>
<style id="ds-v28-hd">html .site-header { background: var(--ds-hd-soft) !important; -webkit-backdrop-filter: none !important; backdrop-filter: none !important; }</style>
<?php endif; ?>
<?php if (!$dsNavBlur && !$dsNavSee): ?>
<style id="ds-v28-nav">html .ds-nav::before { background: var(--ds-nav-soft) !important; -webkit-backdrop-filter: none !important; backdrop-filter: none !important; }</style>
<?php endif; ?>
<?php if ($dsNavSee): ?>
<style id="ds-v33-navsee">
    /* Thanh tab kinh mo MONG nhin xuyen mo mo: nen ban trong suot + blur vua (chu phia sau nhoe, khong ro => khong bi de).
       Dat SAU cac luat v28 de khong bi de len.
       v35: lop kinh keo dai xuong duoi day (bottom: -160px) de khi thanh Safari truot len / xuong khong lo khe hay giat. */
    :root:root { --ds-nav-see: rgba(22, 22, 26, <?= number_format($dsNavAlpha / 100, 2, '.', '') ?>); --ds-nav-see-line: rgba(255, 255, 255, .07); }
    :root:root[data-theme="light"] { --ds-nav-see: rgba(255, 255, 255, <?= number_format(min(.9, $dsNavAlpha / 100 + .06), 2, '.', '') ?>); --ds-nav-see-line: rgba(0, 0, 0, .06); }
    html html .ds-nav::before { top: 0; bottom: -160px; background: var(--ds-nav-see) !important; border-top: 1px solid var(--ds-nav-see-line) !important; -webkit-backdrop-filter: blur(18px) saturate(165%) !important; backdrop-filter: blur(18px) saturate(165%) !important; }
    @supports not ((-webkit-backdrop-filter: blur(1px)) or (backdrop-filter: blur(1px))) { html html .ds-nav::before { background: var(--ds-nav-soft, #0e0e10) !important; } }
</style>
<?php endif; ?>
<style id="ds-theme-v34">
    /* ===================================================================
       v34: logo to hon chut, thanh tab mong, tro lai dung cho + hien nhe nhang, khoi DEVNVIOS cach xa hon,
       trang san pham co anh xem truoc kieu shopdsgm.vn
       =================================================================== */

    /* ---- Logo header to hon mot chut ---- */
    :root:root { --hd: 54px; --logo-h: 42px; }

    /* ---- Thanh tab mong hon ---- */
    html html .ds-nav { padding-top: 3px; }

    /* ---- Hien len nhe nhang khi doi man (cham hon, co truot len rat nhe) ---- */
    @keyframes ds-soft-in { from { opacity: 0; transform: translate3d(0, 8px, 0); } to { opacity: 1; transform: none; } }
    html html .ds-softin { animation: ds-soft-in .6s cubic-bezier(.22, .61, .36, 1) both; }
    @media (prefers-reduced-motion: reduce) { html html .ds-softin { animation: none; } }

    /* ---- Khoi DEVNVIOS cach xa san pham them chut ---- */
    html html .site-about { margin-top: 48px; padding-top: 30px; }
    html.ds-keys .site-about, html.ds-tools .site-about, html.ds-hist .site-about, html.ds-me .site-about { margin-top: 48px; }

    /* ---- Trang san pham CO anh xem truoc: giong shopdsgm.vn ---- */
    html .ds-det--prev .ds-det__act { justify-content: space-between; }
    html .ds-det--prev .ds-share { margin-left: 0; }
    html .ds-det--prev .ds-stats { padding: 20px 0; }
    html .ds-det--prev .ds-stat { flex: 1 0 33.333%; min-width: 0; padding: 0 4px; }
    html .ds-det--prev .ds-stat small { font-size: 11px; letter-spacing: .12em; }
    html .ds-det--prev .ds-stat b { margin: 6px 0 3px; font-size: 20px; letter-spacing: -.01em; }
    html .ds-det--prev .ds-stat span { font-size: 13px; }
    html .ds-det--prev h2 { margin: 20px 0 8px; font-size: 17px; font-weight: 800; letter-spacing: 0; }
    html .ds-det--prev .ds-prevbox + h2 { margin-top: 0; padding-top: 28px; border-top: 1px solid var(--ds-line); }
    html .ds-det--prev .ds-prevbox { margin: 0 -16px 28px; }
    html .ds-det--prev .ds-prev { gap: 12px; padding: 2px 16px 6px; scroll-snap-type: x mandatory; scroll-padding-left: 16px; }
    html .ds-det--prev .ds-prev img { flex: 0 0 auto; width: auto; height: auto; max-width: calc(100vw - 36px); max-height: 440px; min-width: 60vw; min-height: 150px; object-fit: cover; border-radius: 18px; scroll-snap-align: start; }
    html .ds-det--prev .ds-det__txt { font-size: 14px; line-height: 1.65; }
</style>
<?php
    /* v35: logo thang (DS_LOGO_STRAIGHT, do), toc do lap loa (DS_SHINE_SECONDS, giay)
       v36: toc do mac dinh nhanh hon (2.6 giay / luot, truoc la 5) */
    $dsLogoSkew = defined('DS_LOGO_STRAIGHT') ? max(-30, min(30, (float) DS_LOGO_STRAIGHT)) : 10;
    $dsShineSec = defined('DS_SHINE_SECONDS') ? max(1.5, min(20, (float) DS_SHINE_SECONDS)) : 2.6;
    $dsSk = rtrim(rtrim(number_format($dsLogoSkew, 2, '.', ''), '0'), '.');
    if ($dsSk === '' || $dsSk === '-0') { $dsSk = '0'; }
    $dsSw = rtrim(rtrim(number_format($dsShineSec, 2, '.', ''), '0'), '.');
?>
<style id="ds-theme-v35">
    /* ===================================================================
       v35: logo thang + lap loa thuong hieu chay cham lien tuc, Dang xuat phang (khong sang),
       het giat khung khi cuon cham day trang, bam GET het khoang trong
       =================================================================== */

    /* ---- Logo devnvios.io.vn dung THANG lai: bu goc nghieng cua anh logo (skewX duong keo dinh chu sang trai).
       Chinh bang define('DS_LOGO_STRAIGHT', so_do); 0 = khong bu. Ap len khung boc nen logo va lap loa nghieng cung nhau. ---- */
    html .ds-logo, html .site-header .ds-logo, html .ds-brandblock .ds-logo { -webkit-transform: translateZ(0) skewX(<?= $dsSk ?>deg); transform: translateZ(0) skewX(<?= $dsSk ?>deg); transform-origin: 50% 50%; }

    /* ---- Lap loa thuong hieu: dai sang THANG (khong nghieng), chay lien tuc (<?= $dsSw ?> giay / luot) ----
       Cua so .ds-sw truot tu trai sang phai, ban sao trang cua logo ben trong truot nguoc lai de dung yen => chi sang dung phan chu logo.
       Chi dung transform => chay bang GPU, khong lam nang khi cuon. */
    html .ds-logo .ds-sw { display: block !important; opacity: 1 !important; animation: ds-sw35 <?= $dsSw ?>s linear infinite !important; animation-play-state: running !important; }
    html .ds-logo .ds-swi { opacity: .9; animation: ds-swi35 <?= $dsSw ?>s linear infinite !important; animation-play-state: running !important; }
    html .site-header .ds-sw, html .site-header.ds-scrolling .ds-sw { opacity: 1 !important; transition: none !important; }
    @keyframes ds-sw35  { from { -webkit-transform: translate3d(-78%, 0, 0); transform: translate3d(-78%, 0, 0); } to { -webkit-transform: translate3d(78%, 0, 0); transform: translate3d(78%, 0, 0); } }
    @keyframes ds-swi35 { from { -webkit-transform: translate3d(78%, 0, 0); transform: translate3d(78%, 0, 0); } to { -webkit-transform: translate3d(-78%, 0, 0); transform: translate3d(-78%, 0, 0); } }

    /* ---- Nut Dang xuat do: PHANG, khong nen do nhat / khong sang / khong hieu ung. Chi con chu + icon do. ---- */
    html .ds-logout, html .ds-um__item--out, html .menu__item--logout,
    html .ds-logout .ds-ico, html .ds-um__item--out .ds-ico, html .ds-um__item--out span {
        box-shadow: none !important; text-shadow: none !important; -webkit-filter: none !important; filter: none !important;
        animation: none !important; transition: none !important; -webkit-tap-highlight-color: transparent;
    }
    html .ds-logout { color: var(--ds-red); background: var(--ds-card) !important; border: 1px solid var(--ds-line2) !important; outline: 0; }
    html .ds-logout:hover, html .ds-logout:focus, html .ds-logout:active { color: var(--ds-red); background: var(--ds-field) !important; outline: 0; }
    html .ds-um__item--out { color: var(--ds-red); background: transparent !important; }
    html .ds-um__item--out:active, html .ds-um__item--out:focus { background: var(--ds-field) !important; outline: 0; }
    html .ds-um__item--out .ds-ico, html .ds-um__item--out:active .ds-ico { background: var(--ds-field) !important; }
    html .menu__item--logout:active, html .menu__item--logout:focus { background: transparent !important; }
</style>
<style id="ds-theme-v36">
    /* ===================================================================
       v36: cuon muot hon + het cuon 2 lan o day trang, quay lai tu tu, bo khung thanh vien o ME,
       san pham + package to len, khoa cung VIP / Free, anh xem truoc kieu App Store, lap loa nhanh hon
       =================================================================== */

    /* ---- Trang Me: BO KHUNG thanh vien (khong nen, khong vien) ---- */
    html html .ds-me__top { margin: 0; padding: 16px 0 4px; background: transparent !important; border: 0 !important; border-radius: 0; }

    /* ---- Danh sach san pham to len mot chut ---- */
    html html .ds-row { gap: 13px; padding: 10px 0; }
    html html .ds-row img { width: 52px; height: 52px; border-radius: 13px; }
    html html .ds-row__info strong { font-size: 15px; }
    html html .ds-row__info span { margin-top: 1px; font-size: 12.5px; }
    html html .ds-open { height: 30px; padding: 0 17px; font-size: 12.5px; }

    /* ---- Package to len mot chut (Home + trang goi) ---- */
    html html .ds-products .ds-sec__title { margin: 20px 0 3px; font-size: 20px; line-height: 1.25; }
    html html .ds-products .ds-sec__sub { margin-bottom: 6px; font-size: 12.5px; }
    html html .ds-pkgbtn { gap: 5px; }
    html html .ds-pkgbtn svg { width: 18px; height: 18px; }
    html html .ds-pkgtitle { font-size: 28px; }
    html html .ds-pkgsub { font-size: 14px; }

    /* ---- Quay lai tu tu: doi man mo dan (cham, truot len nhe) ---- */
    @keyframes ds-soft-in36 { from { opacity: 0; transform: translate3d(0, 14px, 0); } to { opacity: 1; transform: none; } }
    html html .ds-softin { animation: ds-soft-in36 .75s cubic-bezier(.16, 1, .3, 1) both; }
    html html .ds-fadeout { opacity: 0 !important; transition: opacity .16s ease !important; }
    @media (prefers-reduced-motion: reduce) { html html .ds-softin { animation: none; } html html .ds-fadeout { transition: none !important; } }

    /* ---- Khoa cung VIP / Free: nut GET mo di khi sai loai key ---- */
    html html .ds-get.is-mismatch { opacity: .55; }

    /* ---- Cuoi trang on dinh: khoang trong duoi co gia tri du phong dung ngay tu dau (khong nhay chieu cao sau khi do thanh tab) ---- */
    html:not(.ds-typing) body { padding-bottom: calc(var(--ds-nav-real, calc(var(--nav-h) + env(safe-area-inset-bottom, 0px))) + 2px) !important; }

    /* ---- Cuon muot hon: bo bot viec ve lai ---- */
    html html .ds-det__bg { -webkit-filter: blur(32px) saturate(1.5); filter: blur(32px) saturate(1.5); }
    html .ds-logo { contain: paint; }

    /* ---- Anh xem truoc kieu shopdsgm.vn / App Store: anh doc to, xep hang ngang, luot ngang xem tiep, anh ke tiep lo ra mot phan ---- */
    html html .ds-det--prev .ds-prevbox { margin: 0 -16px 28px; }
    html html .ds-prev { display: flex; gap: 12px; padding: 2px 16px 10px; overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; scroll-snap-type: x mandatory; scroll-padding-left: 16px; overscroll-behavior-x: contain; touch-action: pan-x pan-y; scrollbar-width: none; }
    html html .ds-prev::-webkit-scrollbar { display: none; }
    html html .ds-prev::after { content: ""; flex: 0 0 4px; }
    html html .ds-det--prev .ds-prev img { flex: 0 0 auto; width: auto; height: clamp(300px, 56vh, 480px); min-width: 0; min-height: 0; max-width: none; max-height: none; aspect-ratio: auto 9 / 19.5; object-fit: cover; background: var(--ds-field); border: 1px solid var(--ds-line); border-radius: 20px; scroll-snap-align: start; scroll-snap-stop: normal; -webkit-user-drag: none; }
</style>
<style id="ds-theme-v37">
    /* ===================================================================
       v37: het loi cuon xuong cuoi trang, san pham to them xiu, nut GET nho lai, Package to len,
       avatar header bo khung, anh banner khong con mat chu. Khung kinh mo cua thanh tab Home giu nguyen.
       =================================================================== */

    /* ---- Het loi khi cuon xuong cuoi trang (nguyen nhan: khoang trong duoi bi do lai theo chieu cao thanh tab / safe-area,
       thanh Safari an hien lam so do nhay => cuoi trang tut len xuong, dong "Made with ..." luc bi che luc hien).
       Gio la mot so CO DINH, khong phu thuoc JS do thanh tab: luon du cho thanh tab + dong cuoi. ---- */
    html html:not(.ds-typing) body { padding-bottom: calc(var(--nav-h) + 40px) !important; }
    html html .site-footer { position: relative; margin-bottom: 0; }

    /* ---- San pham to len mot xiu ---- */
    html html html .ds-row { gap: 14px; padding: 11px 0; }
    html html html .ds-row img { width: 56px; height: 56px; border-radius: 14px; }
    html html html .ds-row__info strong { font-size: 16px; }
    html html html .ds-row__info span { margin-top: 1px; font-size: 13px; }
    html html html .ds-open { height: 31px; padding: 0 18px; font-size: 13px; }

    /* ---- Nut GET trong trang san pham: nho lai ---- */
    html html html .ds-get { height: 32px; padding: 0 22px; font-size: 13px; letter-spacing: .04em; }
    html html html .ds-get svg { width: 16px; height: 16px; margin-right: 6px; }
    html html html .ds-share { width: 32px; height: 32px; }
    html html html .ds-share svg { width: 16px; height: 16px; }

    /* ---- Package to len (tieu de + mui ten + dong mo ta) ---- */
    html html html .ds-products .ds-sec__title { margin: 22px 0 3px; font-size: 24px; line-height: 1.25; }
    html html html .ds-products .ds-sec__sub { margin-bottom: 6px; font-size: 13.5px; }
    html html html .ds-pkgbtn { gap: 6px; }
    html html html .ds-pkgbtn svg { width: 21px; height: 21px; }

    /* ---- Avatar tren header: BO KHUNG (khong nen, khong vien, khong bo goc, khong cat). Chi con hinh avatar. ---- */
    html html html .ds-avatar { overflow: visible; background: transparent !important; border: 0 !important; border-radius: 0; box-shadow: none !important; }
    html html html .ds-avatar .ds-av__img { background: transparent; border-radius: 0; object-fit: contain; }
    :root:not([data-theme="light"]) .ds-avatar .ds-av__img { mix-blend-mode: screen; }

    /* ---- Anh banner devnvios.io.vn: hien DU anh, khong cat chu 2 ben. Ti le khung do bang JS theo anh that (--ds-ar). ---- */
    html html html .ds-slide img { height: auto; aspect-ratio: var(--ds-ar, 1.7 / 1); object-fit: contain; }
</style>
<?php if ($dsNavSee): ?>
<style id="ds-v36-navsee">
    /* v36: lam mo (blur) cua thanh tab nhe hon (10px, bo saturate) => cuon muot hon. Van kinh mo nhin xuyen. */
    html html html .ds-nav::before { -webkit-backdrop-filter: blur(10px) !important; backdrop-filter: blur(10px) !important; }
</style>
<?php endif; ?>
<style id="ds-theme-v38">
    /* ===================================================================
       v38: het khung / bat len xuong o day trang (khoa cung bang khung cuon rieng),
       san pham to them 1 tí, Package to hon, trang san pham: anh + nut GET nho lai
       =================================================================== */

    /* ---- KHUNG CUON RIENG (class ds-shell do JS gan): trang khong con cuon bang tai lieu cua Safari,
       nen thanh Safari khong con an / hien => chieu cao man hinh KHONG doi => het nhay len xuong o day trang.
       Header va thanh tab van la fixed, dung yen tuyet doi. ---- */
    html.ds-shell, html.ds-shell body { height: 100%; overflow: hidden; overscroll-behavior: none; }
    html.ds-shell.ds-shell body { padding: 0 !important; }
    html.ds-shell #ds-scroll {
        position: fixed; left: 0; right: 0; top: 0; bottom: 0; box-sizing: border-box; z-index: 0;
        overflow-x: hidden; overflow-y: auto; -webkit-overflow-scrolling: touch; overscroll-behavior-y: contain; scroll-behavior: auto;
        padding-top: var(--hd);
        padding-bottom: calc(var(--nav-h) + env(safe-area-inset-bottom, 0px) + 28px);
    }
    html.ds-shell.ds-typing #ds-scroll { padding-bottom: 46vh; }

    /* ---- San pham to len them 1 tí (dung to qua) ---- */
    html html html html .ds-row { gap: 15px; padding: 12px 0; }
    html html html html .ds-row img { width: 60px; height: 60px; border-radius: 15px; }
    html html html html .ds-row__info strong { font-size: 17px; }
    html html html html .ds-row__info span { margin-top: 1px; font-size: 13.5px; }
    html html html html .ds-open { height: 33px; padding: 0 19px; font-size: 13.5px; }

    /* ---- Chu Package 1 / 2 / 3 to hon ---- */
    html html html html .ds-products .ds-sec__title { margin: 24px 0 4px; font-size: 28px; line-height: 1.2; }
    html html html html .ds-products .ds-sec__sub { margin-bottom: 8px; font-size: 14.5px; }
    html html html html .ds-pkgbtn { gap: 7px; }
    html html html html .ds-pkgbtn svg { width: 24px; height: 24px; }
    html html html html .ds-pkgtitle { font-size: 32px; }

    /* ---- Trang san pham (bam View): anh san pham nho lai, GET nho bang nut View ---- */
    html html html html .ds-det__head { gap: 14px; }
    html html html html .ds-det__head img { width: 80px; height: 80px; border-radius: 19px; }
    html html html html .ds-det__main h1 { font-size: 21px; }
    html html html html .ds-det__main p { margin: 2px 0 10px; font-size: 13.5px; }
    html html html html .ds-get { height: 33px; padding: 0 19px; font-size: 13.5px; letter-spacing: .03em; }
    html html html html .ds-get svg { width: 15px; height: 15px; margin-right: 6px; }
    html html html html .ds-share { width: 33px; height: 33px; }
    html html html html .ds-share svg { width: 16px; height: 16px; }
</style>
<script>try{if(window.MutationObserver){var __dd=document.documentElement;__dd.classList.add('ds-tdd');setTimeout(function(){if(!window.__dsTddOk)__dd.classList.remove('ds-tdd');},3000);}}catch(e){}</script>
<style id="ds-v43-head">
    /* v43: ban goc cua thong bao (index.php in ra) bi AN ngay tu khung hinh dau tien; chi hien ban sao co hieu ung (data-ds-c).
       Truoc day ban goc hien 1-2 khung hinh (day trang xuong) roi bi go, ban sao moi hien lai => thay thong bao 2 lan. */
    html:root:root:root.ds-tdd body .toast-anchor .toast:not([data-ds-c]) { display: none !important; }
</style>
<noscript><style>html:not(.ds-ready) body { opacity: 1 !important; }</style></noscript>
<!--DS-SMOOTH-HEAD-BEGIN-->
<script>try{var p=Date.now()%1600,s=document.documentElement.style;s.setProperty('--ds-ph',(-p/1000)+'s');s.setProperty('--ds-ph2',(-((p+800)%1600)/1000)+'s');}catch(e){}</script>
<style id="ds-logo-smooth-css">
    /* patch spinner (muot): lap loa logo chay lien tuc, nhip khoa theo dong ho, giam do sang 1 chut */
    html:root:root:root:root:root:root .ds-logo { contain: paint; }
    html:root:root:root:root:root:root .ds-logo .ds-sw {
        display: block !important; opacity: 1 !important; visibility: visible !important;
        -webkit-mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0) 8%, rgba(0, 0, 0, .4) 28%, rgba(0, 0, 0, .72) 50%, rgba(0, 0, 0, .4) 72%, rgba(0, 0, 0, 0) 92%) !important;
        mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0) 8%, rgba(0, 0, 0, .4) 28%, rgba(0, 0, 0, .72) 50%, rgba(0, 0, 0, .4) 72%, rgba(0, 0, 0, 0) 92%) !important;
        will-change: transform; -webkit-backface-visibility: hidden; backface-visibility: hidden;
        transition: none !important;
        -webkit-animation: ds-sw35 1.6s linear infinite !important; animation: ds-sw35 1.6s linear infinite !important;
        -webkit-animation-delay: var(--ds-ph, 0s) !important; animation-delay: var(--ds-ph, 0s) !important;
        -webkit-animation-play-state: running !important; animation-play-state: running !important;
    }
    html:root:root:root:root:root:root .ds-logo .ds-swi {
        display: block !important; opacity: 1 !important; visibility: visible !important;
        will-change: transform; -webkit-backface-visibility: hidden; backface-visibility: hidden;
        transition: none !important;
        -webkit-animation: ds-swi35 1.6s linear infinite !important; animation: ds-swi35 1.6s linear infinite !important;
        -webkit-animation-delay: var(--ds-ph, 0s) !important; animation-delay: var(--ds-ph, 0s) !important;
        -webkit-animation-play-state: running !important; animation-play-state: running !important;
    }
    html:root:root:root:root:root:root .ds-logo .ds-sw2,
    html:root:root:root:root:root:root .ds-logo .ds-sw2 .ds-swi {
        -webkit-animation-delay: var(--ds-ph2, -.8s) !important; animation-delay: var(--ds-ph2, -.8s) !important;
    }

    /* patch spinner (muot): thong bao o phan Keys hien / tat mem, khong nhay bo cuc */
    @keyframes ds-need-in41 {
        from { opacity: 0; max-height: 0; margin-top: 0; -webkit-transform: translate3d(0, -6px, 0); transform: translate3d(0, -6px, 0); }
        to   { opacity: 1; max-height: 26px; margin-top: 10px; -webkit-transform: none; transform: none; }
    }
    @keyframes ds-need-out41 {
        from { opacity: 1; max-height: 26px; margin-top: 10px; -webkit-transform: none; transform: none; }
        to   { opacity: 0; max-height: 0; margin-top: 0; -webkit-transform: translate3d(0, -4px, 0); transform: translate3d(0, -4px, 0); }
    }
    html:root:root:root:root:root:root .ds-need.is-in {
        -webkit-animation: ds-need-in41 .32s cubic-bezier(.22, .61, .36, 1) backwards !important;
        animation: ds-need-in41 .32s cubic-bezier(.22, .61, .36, 1) backwards !important;
    }
    html:root:root:root:root:root:root .ds-need.is-out {
        -webkit-animation: ds-need-out41 .3s cubic-bezier(.4, 0, .2, 1) forwards !important;
        animation: ds-need-out41 .3s cubic-bezier(.4, 0, .2, 1) forwards !important;
    }
    @media (prefers-reduced-motion: reduce) {
        html:root:root:root:root:root:root .ds-need.is-in, html:root:root:root:root:root:root .ds-need.is-out { animation: none !important; -webkit-animation: none !important; }
    }
</style>
<!--DS-SMOOTH-HEAD-END-->
<?php }

/*DS-PATCH-BOOT2*/
/* patch_boot2: FIX tool da xoa van hien. Loc bo (1) tool da xoa mem, (2) tool khong con trong bang DB, (3) tool co ten trong danh sach an. */
if (!function_exists('dsx_live_tools')) {
    function dsx_live_tools(array $tools): array
    {
        if (!$tools) { return $tools; }
        $names = [/*BOOT2-N*/'tool spam call', 'tool spam sms', 'spam sms', 'spam call', '/root/patchboot2.py'/*BOOT2-E*/];
        $hideName = [];
        foreach ($names as $nm) { $hideName[strtolower(trim(preg_replace('/\s+/', ' ', (string) $nm)))] = 1; }
        $out = [];
        foreach ($tools as $t) {
            if (!is_array($t)) { continue; }
            $gone = false;
            foreach (['deleted', 'is_deleted', 'removed'] as $k) {
                if (isset($t[$k]) && (string) $t[$k] !== '' && (string) $t[$k] !== '0') { $gone = true; }
            }
            foreach (['deleted_at', 'removed_at'] as $k) {
                if (!empty($t[$k]) && (string) $t[$k] !== '0000-00-00 00:00:00') { $gone = true; }
            }
            foreach (['active', 'is_active', 'enabled', 'is_enabled'] as $k) {
                if (isset($t[$k]) && (string) $t[$k] === '0') { $gone = true; }
            }
            if (isset($t['status']) && is_string($t['status']) && in_array(strtolower(trim($t['status'])), ['deleted', 'removed', 'trash'], true)) { $gone = true; }
            if (isset($t['name']) && is_string($t['name'])) {
                $key = strtolower(trim(preg_replace('/\s+/', ' ', $t['name'])));
                if ($key !== '' && isset($hideName[$key])) { $gone = true; }
            }
            if (!$gone) { $out[] = $t; }
        }
        /* doi chieu voi DB that: tool nao khong con trong bang thi bo (chi ap dung khi tim dung bang chua cac tool dang hien) */
        $c = isset($GLOBALS['conn']) ? $GLOBALS['conn'] : null;
        if ($out && $c instanceof mysqli) {
            $ids = [];
            foreach ($out as $t) {
                $i = (int) (isset($t['id']) ? $t['id'] : 0);
                if ($i > 0) { $ids[$i] = 1; }
            }
            if ($ids) {
                $in = implode(',', array_keys($ids));
                foreach (['tools', 'ds_tools', 'tool_list', 'products'] as $tb) {
                    $q = false;
                    try { $q = @mysqli_query($c, 'SELECT id FROM `' . $tb . '` WHERE id IN (' . $in . ')'); } catch (Throwable $x) { $q = false; }
                    if (!$q) { continue; }
                    $live = [];
                    while ($r = mysqli_fetch_assoc($q)) { $live[(int) $r['id']] = 1; }
                    if ($live) {
                        $keep = [];
                        foreach ($out as $t) {
                            $i = (int) (isset($t['id']) ? $t['id'] : 0);
                            if ($i <= 0 || isset($live[$i])) { $keep[] = $t; }
                        }
                        $out = $keep;
                        break;
                    }
                }
            }
        }
        return $out;
    }
}

/** Nut sang/toi, menu avatar, danh sach tool, trang chi tiet tool, trang Me, trang History, thanh tab duoi day. Goi ngay truoc </body>. */
function devnvios_theme_body(bool $isLoggedIn, bool $isAdmin, string $textHome, string $textLogin, string $textRegister, string $textLogout, string $textAdmin): void
{
    global $tools; // danh sach san pham da duoc index.php nap san
    $tools = (isset($tools) && is_array($tools)) ? $tools : [];
    dsx_boot();
    dsx_text_start();

    $tools = dsx_live_tools($tools); /*DS-PATCH-BOOT2-CALL*/
    $dsMeta = json_decode(dsx_cfg('ds_tool_meta', '{}'), true);
    if (!is_array($dsMeta)) { $dsMeta = []; }
    $dsT2 = [];
    foreach ($tools as $dsT0) {
        $dsId = (int) (isset($dsT0['id']) ? $dsT0['id'] : 0);
        $dsM = (isset($dsMeta[$dsId]) && is_array($dsMeta[$dsId])) ? $dsMeta[$dsId] : [];
        if (!empty($dsM['hide'])) { continue; }
        if (isset($dsM['status']) && $dsM['status'] === 'maintenance') { $dsT0['__mt'] = 1; }
        if (!empty($dsM['note'])) { $dsT0['__note'] = (string) $dsM['note']; }
        if (!empty($dsM['previews']) && is_array($dsM['previews'])) { $dsT0['previews'] = array_values($dsM['previews']); }
        foreach (['desc' => 'description', 'dev' => 'developer', 'ios' => 'min_ios', 'ver' => 'version', 'feat' => 'features', 'date' => 'created_at', 'upd' => 'updated_at', 'device' => 'device_txt'] as $dsMk => $dsTk) {
            if (isset($dsM[$dsMk]) && is_scalar($dsM[$dsMk]) && trim((string) $dsM[$dsMk]) !== '') { $dsT0[$dsTk] = (string) $dsM[$dsMk]; }
        }
        if (!empty($dsM['pkg'])) { $dsT0['package'] = (string) (int) $dsM['pkg']; }
        $dsT0['__ord'] = isset($dsM['ord']) ? (int) $dsM['ord'] : 0;
        $dsT0['__i'] = count($dsT2);
        $dsT2[] = $dsT0;
    }
    usort($dsT2, function ($a, $b) {
        $c = $a['__ord'] <=> $b['__ord'];
        return $c !== 0 ? $c : ($a['__i'] <=> $b['__i']);
    });
    $tools = $dsT2;
    $dsToolsList = defined('DS_TOOLS_PAGE_LIST') && DS_TOOLS_PAGE_LIST;
    $e     = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $isVipTool = function ($t) { return strtoupper(trim((string) (isset($t['type']) ? $t['type'] : 'FREE'))) === 'VIP'; };
    $descOf = function ($t) {
        foreach (['description', 'desc', 'intro', 'summary'] as $k) {
            if (!empty($t[$k]) && is_string($t[$k])) {
                return trim($t[$k]);
            }
        }
        return '';
    };
    $pick = function ($t, array $keys) {
        foreach ($keys as $k) {
            if (isset($t[$k]) && is_scalar($t[$k]) && trim((string) $t[$k]) !== '') {
                return trim((string) $t[$k]);
            }
        }
        return '';
    };
    $fmtDate = function ($raw) {
        if ($raw === '') {
            return '';
        }
        $ts = ctype_digit($raw) ? (int) $raw : strtotime($raw);
        if ($ts > 99999999999) {
            $ts = (int) ($ts / 1000);
        }
        return ($ts && $ts > 100000) ? date('M j, Y', $ts) : '';
    };
    /* v21: ngay up cua tool (thu nhieu ten cot) */
    $dateOf = function ($t) use ($pick, $fmtDate) {
        return $fmtDate($pick($t, ['created_at', 'date_added', 'added_at', 'upload_date', 'uploaded_at', 'upload_time', 'ngay_up', 'created', 'created_time', 'date', 'time']));
    };
    /* v21: danh sach anh xem truoc (nhieu link: moi dong mot link, hoac ngan bang , | ; khoang trang, hoac mang JSON) */
    $previewsOf = function ($t) {
        $list = [];
        foreach (['previews', 'preview', 'preview_images', 'screenshots', 'screenshot', 'gallery', 'images', 'anh_xem_truoc'] as $k) {
            if (!isset($t[$k])) { continue; }
            $v = $t[$k];
            if (is_array($v)) {
                foreach ($v as $u) { if (is_string($u)) { $list[] = trim($u); } }
            } elseif (is_scalar($v) && trim((string) $v) !== '') {
                $raw = trim((string) $v);
                if ($raw[0] === '[') {
                    $j = json_decode($raw, true);
                    if (is_array($j)) { foreach ($j as $u) { if (is_string($u)) { $list[] = trim($u); } } }
                }
                if (!$list) {
                    foreach (preg_split('/[\s,|;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $u) { $list[] = trim($u); }
                }
            }
            if ($list) { break; }
        }
        $out = [];
        foreach ($list as $u) {
            if (preg_match('#^(https?://|/(?!/)|[A-Za-z0-9_./-]+\.(?:png|jpe?g|gif|webp|avif|svg)(?:\?.*)?$)#i', $u) && !in_array($u, $out, true)) { $out[] = $u; }
        }
        return array_slice($out, 0, 10);
    };
    /* v21: mot dong tool (dung chung cho Home, trang goi, trang Tools) - co them ngay up
       v28: icon nap san (khong lazy) de cuon xuong khong bi giat khi anh vua hien */
    $renderRow = function ($tool, $attr = '') use ($e, $isVipTool, $dateOf) {
        $isVip = $isVipTool($tool);
        $d = $dateOf($tool);
        return '<div class="ds-row" data-tool="' . (int) $tool['id'] . '"' . $attr . '>'
            . '<img class="ds-imgfb" src="' . $e(isset($tool['image']) ? $tool['image'] : '') . '" alt="" width="44" height="44" decoding="async">'
            . '<div class="ds-row__info"><strong>' . $e(isset($tool['name']) ? $tool['name'] : '') . '</strong>'
            . '<span class="' . ($isVip ? 'is-vip' : '') . '">' . $e(ds_t($isVip ? 'vip_1' : 'free_1')) . ($d !== '' ? '<em class="ds-row__d"> &middot; ' . $e($d) . '</em>' : '') . (!empty($tool['__mt']) ? '<em class="ds-row__mt"> &middot; ' . $e(ds_t('mt_label')) . '</em>' : '') . '</span></div>'
            . '<button class="ds-open" type="button">' . $e(ds_t('view')) . '</button></div>' . "\n";
    };
    $ico = [
        'home'  => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'key'   => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2m-4 4 3 3m-6-1 2 2"/>',
        'tools' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'login' => '<circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="10" r="3"/><path d="M6.2 18.6a6.5 6.5 0 0 1 11.6 0"/>',
        'plus'  => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M19 8v6m3-3h-6"/>',
        'admin' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/>',
        'lock'  => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'out'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'hist'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'chev'  => '<path d="m9 5 7 7-7 7"/>',
        /* v21: icon moi (thay emoji bi loi "??") va icon tai lieu */
        'warn'  => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
        'site'  => '<rect x="2" y="6" width="20" height="12" rx="4"/><path d="M7 10v4M5 12h4M16 11h.01M18 13h.01"/>',
        'scale' => '<path d="M12 3v18M7 21h10M5 7h14M5 7l-3 7a3.5 3.5 0 0 0 6 0zM19 7l-3 7a3.5 3.5 0 0 0 6 0z"/>',
        'doc'   => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
    ];
    $svg = function ($name) use ($ico) { return '<svg class="ds-ico" viewBox="0 0 24 24" aria-hidden="true">' . $ico[$name] . '</svg>'; };
    $chev = '<svg viewBox="0 0 24 24" aria-hidden="true">' . $ico['chev'] . '</svg>';
    /* Viet hoa chu cai dau cho nhan tab (index.php truyen vao "log in", "register"...) */
    $cap = function ($t) {
        $t = (string) $t;
        if ($t === '') { return $t; }
        if (function_exists('mb_strtoupper') && function_exists('mb_substr')) {
            return mb_strtoupper(mb_substr($t, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($t, 1, null, 'UTF-8');
        }
        return ucfirst($t);
    };
    $dsNavReg = defined('DS_NAV_REGISTER') && DS_NAV_REGISTER;
    $dsPriv   = defined('DS_PRIVACY_URL') ? trim((string) DS_PRIVACY_URL) : '';
    $dsTerms  = defined('DS_TERMS_URL') ? trim((string) DS_TERMS_URL) : '';
    $dsSecUrl = defined('DS_SECURITY_URL') ? trim((string) DS_SECURITY_URL) : '/security.php';
    $dsReopen = defined('DS_REOPEN_SECONDS') ? max(5, (int) DS_REOPEN_SECONDS) : 20;
    /* v26: thoi gian thong bao "nhap key" tu bien mat (giay, mac dinh 4.5) */
    $dsNeedMs = defined('DS_NEED_SECONDS') ? (int) round(max(2, (float) DS_NEED_SECONDS) * 1000) : 4500;
    /* v28: thong bao hien o bat ky man nao thi tu chuyen sang tab Keys (tat: define('DS_TOAST_KEYS', false);) */
    $dsToastKeys = !(defined('DS_TOAST_KEYS') && !DS_TOAST_KEYS);
    /* v21: file tai lieu, cong tac bat lai lien ket Gmail/SDT va nut Back */
    $dsDocs     = defined('DS_DOCS_URL') ? trim((string) DS_DOCS_URL) : '/docs.php';
    if ($dsDocs === '') { $dsDocs = '/docs.php'; }
    if ($dsPriv === '')  { $dsPriv  = $dsDocs . '?doc=privacy'; }
    if ($dsTerms === '') { $dsTerms = $dsDocs . '?doc=terms'; }
    $dsSecLinks = defined('DS_SECURITY_LINKS') && DS_SECURITY_LINKS;
    $dsShowBack = defined('DS_SHOW_BACK') && DS_SHOW_BACK;
    /* Home hien HET tool cua moi goi (0). Dat so > 0 neu muon chi hien vai tool dau */
    $dsPreview = defined('DS_HOME_PREVIEW') ? max(0, (int) DS_HOME_PREVIEW) : 0;

    /* ===== Goi: 1 = VIP, 2 = Free, 3 = iOS ===== */
    $dsIosKw = ['myren', 'gta5', 'gta 5', 'iosvn', 'pocket poster', 'filza'];
    if (defined('DS_IOS_KEYWORDS')) {
        $dsIosKw = array_values(array_filter(array_map('trim', explode(',', strtolower((string) DS_IOS_KEYWORDS))), 'strlen'));
    }
    $pkgOf = function ($t) use ($isVipTool, $pick, $dsIosKw) {
        $pp = strtolower($pick($t, ['package', 'pkg', 'package_id']));
        if (preg_match('/^[1-8]$/', $pp)) { return (int) $pp; }
        if (preg_match('/^package\s*([1-8])$/', $pp, $dsPm)) { return (int) $dsPm[1]; }
        $pc = strtolower($pick($t, ['category', 'platform', 'os']));
        if ($pc === 'ios') { return 3; }
        $nm = isset($t['name']) ? (string) $t['name'] : '';
        $nm = function_exists('mb_strtolower') ? mb_strtolower($nm, 'UTF-8') : strtolower($nm);
        foreach ($dsIosKw as $kw) {
            if ($kw !== '' && strpos($nm, $kw) !== false) { return 3; }
        }
        return $isVipTool($t) ? 1 : 2;
    };
    /*DS-V26-PKGS*/
    $dsPkgN = max(3, min(8, (int) dsx_cfg('ds_pkg_count', '3')));
    $dsPkgSubDef = [1 => ds_t('vip_s'), 2 => ds_t('free_s'), 3 => ds_t('ios_s')];
    $pkgs = [];
    for ($dsPi = 1; $dsPi <= $dsPkgN; $dsPi++) {
        $dsPt = dsx_cfg('ds_pkg' . $dsPi . '_title');
        if ($dsPt === '') { $dsPt = ($dsPi <= 3) ? ds_t('pkg' . $dsPi) : 'Package ' . $dsPi; }
        $dsPs = dsx_cfg('ds_pkg' . $dsPi . '_sub');
        if ($dsPs === '' && $dsPi <= 3) { $dsPs = $dsPkgSubDef[$dsPi]; }
        $pkgs[$dsPi] = ['title' => $dsPt, 'sub' => $dsPs, 'tools' => []];
    }
    $toolPkg = [];
    /*DS-SECTIONS-V28*/
    $dsPkgShow = function ($pn, $pk) {
        return $pn > 3 || !empty($pk['tools']) || dsx_cfg('ds_pkg' . $pn . '_title') !== '';
    };
    foreach ($tools as $dsT) {
        $pn = $pkgOf($dsT);
        if (!isset($pkgs[$pn])) { $pn = $isVipTool($dsT) ? 1 : 2; }
        $toolPkg[(int) (isset($dsT['id']) ? $dsT['id'] : 0)] = $pn;
        $pkgs[$pn]['tools'][] = $dsT;
    }

    /* Avatar: anh mac dinh (DS_AVATAR_URL), hoac anh rieng cua tung nguoi ($GLOBALS['ds_user']['avatar']) */
    $dsAvUrl = defined('DS_AVATAR_URL') ? trim((string) DS_AVATAR_URL) : 'https://i.postimg.cc/vZ7JgrPc/IMG-1721.jpg';
    if (!empty($GLOBALS['ds_user']['avatar']) && is_string($GLOBALS['ds_user']['avatar'])) {
        $dsAvUrl = trim($GLOBALS['ds_user']['avatar']);
    }
    if (!preg_match('#^(https?://|/(?!/))#i', $dsAvUrl)) { $dsAvUrl = ''; }
    $avImg = $dsAvUrl !== '' ? '<img class="ds-av__img" src="' . $e($dsAvUrl) . '" alt="" decoding="async" referrerpolicy="no-referrer" onerror="this.style.display=\'none\'">' : '';

    /* Trang thai bao mat (Gmail / so dien thoai). Lay tu ds_security_status() trong db.php, hoac $GLOBALS['ds_security'] = ['email'=>bool,'phone'=>bool]
       v21: chi dung khi bat DS_SECURITY_LINKS (mac dinh TAT => khong con lien ket Gmail / SDT). */
    $dsSec = ['email' => null, 'phone' => null];
    if ($dsSecLinks) {
        if (isset($GLOBALS['ds_security']) && is_array($GLOBALS['ds_security'])) {
            $dsSec['email'] = !empty($GLOBALS['ds_security']['email']);
            $dsSec['phone'] = !empty($GLOBALS['ds_security']['phone']);
        } elseif ($isLoggedIn && function_exists('ds_security_status')) {
            try {
                $sx = ds_security_status();
                if (is_array($sx)) {
                    $dsSec['email'] = !empty($sx['email']);
                    $dsSec['phone'] = !empty($sx['phone']);
                }
            } catch (\Throwable $x) {}
        }
    }
    $secKnown = $dsSec['email'] !== null;
    $secWarn  = $dsSecLinks && $isLoggedIn && $secKnown && (!$dsSec['email'] || !$dsSec['phone']);
    $secTag   = function ($ok) {
        return $ok ? '<span class="ds-tag is-ok">Linked</span>' : '<span class="ds-tag is-warn">Not linked</span>';
    };

    /* Du lieu nguoi dung / lich su do PHP dua vao (tuy chon). Neu khong co thi JS tu doc tu user-bar va luu lich su tren thiet bi.
       $GLOBALS['ds_user']    = ['name'=>'', 'plan'=>'VIP|Free|Member', 'turns'=>12, 'email'=>'', 'id'=>'', 'since'=>'', 'ip'=>'', 'avatar'=>''];
       $GLOBALS['ds_history'] = [['time'=>'2026-10-20 05:29:16' hoac unix, 'type'=>'in|out', 'amount'=>1, 'tool_id'=>5, 'label'=>'Free key'], ...]; */
    $jf = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $dsUser = [];
    if (!empty($GLOBALS['ds_user']) && is_array($GLOBALS['ds_user'])) {
        foreach (['name', 'plan', 'turns', 'email', 'id', 'since', 'ip'] as $uk) {
            if (isset($GLOBALS['ds_user'][$uk]) && is_scalar($GLOBALS['ds_user'][$uk])) {
                $dsUser[$uk] = (string) $GLOBALS['ds_user'][$uk];
            }
        }
    }
    $dsUserJson = json_encode((object) $dsUser, $jf);
    $dsHistJson = 'null';
    if (isset($GLOBALS['ds_history']) && is_array($GLOBALS['ds_history'])) {
        $hl = [];
        foreach ($GLOBALS['ds_history'] as $h) {
            if (!is_array($h)) { continue; }
            $raw = $pick($h, ['time', 'created_at', 'date', 'at']);
            $ts  = ctype_digit($raw) ? (int) $raw : (int) strtotime($raw);
            if ($ts > 99999999999) { $ts = (int) ($ts / 1000); }
            if ($ts <= 0) { continue; }
            $tp  = strtolower($pick($h, ['type', 'kind']));
            $amt = $pick($h, ['amount', 'turns', 'n']);
            $hl[] = [
                't'  => $ts * 1000,
                'k'  => in_array($tp, ['in', 'received', 'add', 'plus'], true) ? 'in' : 'out',
                'n'  => $amt === '' ? 1 : abs((int) $amt),
                'id' => (int) $pick($h, ['tool_id', 'product_id']),
                'nm' => $pick($h, ['label', 'name', 'tool_name', 'note']),
            ];
        }
        $dsHistJson = json_encode($hl, $jf);
    }
    /* v21: cac tai lieu (mo file docs.php) */
    $docItems = [
        ['about',      'About DEVNVIOS',    'What the website is and how it works', 'site'],
        ['keys',       'Keys & turns',      'VIP and Free keys, turns, reopen window', 'key'],
        ['disclaimer', 'Legal disclaimer',  'Using the tools and your responsibility', 'scale'],
        ['privacy',    'Privacy Policy',    'What we collect and why', 'admin'],
        ['terms',      'Terms of Service',  'Rules for using the website', 'doc'],
    ];
    ?>
<div class="ds-hd" id="ds-hd">
    <span class="ds-hbtn" id="ds-lang-btn">EN</span>
    <button class="ds-hbtn" id="ds-theme-btn" type="button" aria-label="Theme"></button>
<?php if ($isLoggedIn): ?>
    <div class="ds-acc" id="ds-acc">
        <button class="ds-avatar" id="ds-avatar" type="button" aria-label="Account" aria-haspopup="true" aria-expanded="false"><span id="ds-av-l">U</span><?= $avImg ?></button>
        <div class="ds-um" id="ds-um" hidden>
            <div class="ds-um__top">
                <div class="ds-um__av"><span id="ds-um-av-l">U</span><?= $avImg ?></div>
                <div class="ds-um__who">
                    <div class="ds-um__hello">Hello,</div>
                    <div class="ds-um__name" id="ds-um-name">Member</div>
                    <span class="ds-badge" id="ds-um-plan">Member</span>
                </div>
            </div>
            <div class="ds-um__row"><span>Turns left</span><b id="ds-um-turns">0</b></div>
            <div class="ds-um__sep"></div>
            <button class="ds-um__item" type="button" data-go="me"><?= $svg('login') ?><span>Profile</span></button>
            <a class="ds-um__item" href="<?= $e($dsSecUrl) ?>"><?= $svg('lock') ?><span>Security</span><?= $secWarn ? '<span class="ds-tag is-warn">!</span>' : '' ?></a>
            <button class="ds-um__item" type="button" data-go="hist"><?= $svg('hist') ?><span>Turn history</span></button>
            <button class="ds-um__item" type="button" data-go="keys"><?= $svg('key') ?><span>Activate a key</span></button>
            <div class="ds-um__sep"></div>
            <a class="ds-um__item ds-um__item--out" href="/logout"><?= $svg('out') ?><span><?= $e(ds_t('logout')) ?></span></a>
        </div>
    </div>
<?php endif; ?>
</div>
<?php if ($isLoggedIn): ?>
<div class="ds-um-ov" id="ds-um-ov" hidden></div>
<?php endif; ?>

<?php if ($tools): ?>
<section class="ds-products" id="ds-products">
<?php foreach ($pkgs as $pn => $pk):
        if (!$dsPkgShow($pn, $pk)) { continue; }
        $shown = $dsPreview > 0 ? array_slice($pk['tools'], 0, $dsPreview) : $pk['tools']; ?>
    <h2 class="ds-sec__title"><button class="ds-pkgbtn" type="button" data-pkg="<?= (int) $pn ?>"><?= $e($pk['title']) ?><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></button></h2>
    <p class="ds-sec__sub"><?= $e($pk['sub']) ?></p>
<?php   foreach ($shown as $tool): echo $renderRow($tool); endforeach; ?>
<?php endforeach; ?>
</section>

<section class="ds-detwrap" id="ds-detwrap">
<?php foreach ($tools as $tool):
        $isVip = $isVipTool($tool);
        $tid   = (int) $tool['id'];
        $pnum  = isset($toolPkg[$tid]) ? $toolPkg[$tid] : ($isVip ? 1 : 2);
        $name  = isset($tool['name']) ? $tool['name'] : '';
        $img   = isset($tool['image']) ? $tool['image'] : '';
        $desc  = $descOf($tool);
        $dev   = $pick($tool, ['developer', 'author', 'dev']);
        if ($dev === '') { $dev = 'DEVNVIOS'; }
        $ios   = $pick($tool, ['min_ios', 'ios', 'ios_version', 'supports', 'support']);
        if ($ios === '') {
            $ios = 'iOS 16+';
        } else {
            if (!preg_match('/ios/i', $ios)) { $ios = 'iOS ' . $ios; }
            if (preg_match('/\d$/', $ios)) { $ios .= '+'; }
        }
        $ver   = $pick($tool, ['version', 'ver', 'app_version']);
        $dUp   = $dateOf($tool);
        $dUpd  = $fmtDate($pick($tool, ['updated_at', 'update_at', 'updated', 'last_update']));
        $prevs = $previewsOf($tool);
        $feats = [];
        foreach (preg_split('/\r\n|\r|\n|\|/', $pick($tool, ['features', 'feature', 'chuc_nang', 'functions'])) as $fx) {
            $g  = preg_replace('/^[\s\-\*\x{2022}\x{00B7}\x{2713}\x{2714}]+/u', '', $fx);
            $fx = trim($g !== null ? $g : $fx);
            if ($fx !== '') { $feats[] = $fx; }
        }
        if (!$feats) {
            $feats = [$isVip ? 'Opens with an active VIP key' : 'Opens with a Free key', 'Uses one turn each time it opens', 'Tested before release', 'Updated when its services change'];
        }
        /* Tool goi y: uu tien cung goi, thieu thi lay them tool khac */
        $rel = [];
        foreach ($tools as $o) {
            if ((int) $o['id'] !== $tid && isset($toolPkg[(int) $o['id']]) && $toolPkg[(int) $o['id']] === $pnum) { $rel[] = $o; }
        }
        if (count($rel) < 6) {
            foreach ($tools as $o) {
                if ((int) $o['id'] !== $tid && (!isset($toolPkg[(int) $o['id']]) || $toolPkg[(int) $o['id']] !== $pnum)) { $rel[] = $o; }
            }
        }
        $rel = array_slice($rel, 0, 6); ?>
    <article class="ds-det<?= $prevs ? ' ds-det--prev' : '' ?>" id="ds-det-<?= $tid ?>" data-mt="<?= !empty($tool['__mt']) ? '1' : '0' ?>" data-tool="<?= $tid ?>" data-name="<?= $e($name) ?>" data-kind="<?= $e(ds_t($isVip ? 'vip_1' : 'free_1')) ?>">
        <div class="ds-det__top">
<?php if ($dsShowBack): ?>
            <button class="ds-det__back" type="button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg>Back</button>
<?php endif; ?>
            <div class="ds-det__head">
                <img class="ds-imgfb" src="<?= $e($img) ?>" alt="" width="88" height="88">
                <div class="ds-det__main">
                    <h1><?= $e($name) ?></h1>
                    <p><?= $e($pkgs[$pnum]['title']) ?><span class="<?= $isVip ? 'is-vip' : '' ?>"> &middot; <?= $e(ds_t($isVip ? 'vip_1' : 'free_1')) ?></span>
<?php if ($dUp !== ''): ?>
                        <small class="ds-det__date"><?= $e(ds_t('uploaded')) ?> <?= $e($dUp) ?></small>
<?php endif; ?>
                    </p>
                    <div class="ds-det__act">
                        <button class="ds-get<?= !empty($tool['__mt']) ? ' is-mt' : '' ?>" type="button"<?= !empty($tool['__mt']) ? ' disabled aria-disabled="true"' : '' ?>>OPEN</button>
                        <button class="ds-share" type="button" aria-label="Share" data-title="<?= $e($name) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V3m0 0L8 7m4-4 4 4M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="ds-stats">
            <div class="ds-stat"><small><?= $e(ds_t('lbl_status')) ?></small><?php if (!empty($tool['__mt'])): ?><b class="is-warn"><?= $e(ds_t('mt_label')) ?></b><span><?= $e(ds_t('st_mt_sub')) ?></span><?php else: ?><b class="is-ok"><?= $e(ds_t('st_active')) ?></b><span><?= $e(ds_t('st_ready')) ?></span><?php endif; ?></div>
            <div class="ds-stat"><small><?= $e(ds_t('lbl_device')) ?></small><b><?= $e($pick($tool, ['device_txt']) !== '' ? $pick($tool, ['device_txt']) : ds_t('val_device')) ?></b><span><?= $e($ios) ?></span></div>
            <div class="ds-stat"><small><?= $e(ds_t('lbl_plan')) ?></small><b class="<?= $isVip ? 'is-vip' : '' ?>"><?= $isVip ? 'VIP' : 'Free' ?></b><span><?= $e($isVip ? ds_t('need_vip') : ds_t('need_free')) ?></span></div>
            <div class="ds-stat"><small><?= $e(ds_t('lbl_cost')) ?></small><b><?= $e(ds_t('val_cost')) ?></b><span><?= $e(ds_t('lbl_per_open')) ?></span></div>
            <div class="ds-stat"><small><?= $e(ds_t('uploaded')) ?></small><b><?= $dUp !== '' ? $e($dUp) : '&mdash;' ?></b><span><?= $e(ds_t('lbl_upload_date')) ?></span></div>
            <div class="ds-stat"><small><?= $e(ds_t('lbl_dev')) ?></small><b><?= $e($dev) ?></b><span>Official</span></div>
        </div>

        <?php if (!empty($tool['__mt'])): ?><p class="ds-mtnote"><?= $e(isset($tool['__note']) ? $tool['__note'] : ds_t('mt_msg')) ?></p><?php endif; ?>
        <h2><?= $e(ds_t('previews')) ?></h2>
        <div class="ds-prevbox">
<?php if ($prevs): ?>
            <div class="ds-prev">
<?php foreach ($prevs as $pu): ?>
                <img src="<?= $e($pu) ?>" alt="" loading="eager" decoding="async" referrerpolicy="no-referrer">
<?php endforeach; ?>
            </div>
            <p class="ds-noprev" hidden><?= $e(ds_t('no_prev')) ?></p>
<?php else: ?>
            <p class="ds-noprev"><?= $e(ds_t('no_prev')) ?></p>
<?php endif; ?>
        </div>

        <h2><?= $e(ds_t('desc_h')) ?></h2>
<?php if ($desc !== ''): ?>
        <p class="ds-det__txt"><?= nl2br($e($desc)) ?></p>
<?php endif; ?>
<?php if ($isVip): ?>
        <p class="ds-det__txt"><?= $e(strtr(ds_t('det_vip'), ['{name}' => $name])) ?></p>
<?php else: ?>
        <p class="ds-det__txt"><?= $e(strtr(ds_t('det_free'), ['{name}' => $name])) ?></p>
<?php endif; ?>
        <p class="ds-det__txt"><?= $e(strtr(ds_t('det_reopen'), ['{sec}' => (int) $dsReopen])) ?></p>

        <div class="ds-feat__head">
            <h2><?= $e(ds_t('feat_h')) ?></h2>
<?php if ($ver !== ''): ?>
            <span class="ds-feat__ver"><?= $e(ds_t('lbl_version')) ?> <?= $e($ver) ?></span>
<?php endif; ?>
        </div>
        <ul class="ds-feat">
<?php foreach ($feats as $fx): ?>
            <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg><span><?= $e($fx) ?></span></li>
<?php endforeach; ?>
        </ul>

        <h2><?= $e(ds_t('info_h')) ?></h2>
        <div class="ds-info">
            <div><span><?= $e(ds_t('lbl_dev')) ?></span><b><?= $e($dev) ?></b></div>
            <div><span><?= $e(ds_t('lbl_pkg')) ?></span><b><?= $e($pkgs[$pnum]['title'] . ($pnum === 3 ? ' (iOS)' : '')) ?></b></div>
            <div><span><?= $e(ds_t('lbl_plan')) ?></span><b class="<?= $isVip ? 'is-vip' : '' ?>"><?= $isVip ? 'VIP' : 'Free' ?></b></div>
            <div><span><?= $e(ds_t('lbl_key_needed')) ?></span><b><?= $e($isVip ? ds_t('val_vipkey') : ds_t('val_freekey')) ?></b></div>
            <div><span><?= $e(ds_t('lbl_cost_open')) ?></span><b><?= $e(ds_t('val_cost')) ?></b></div>
            <div><span><?= $e(ds_t('lbl_supports')) ?></span><b><?= $e($ios) ?></b></div>
            <div><span><?= $e(ds_t('lbl_upload_date')) ?></span><b><?= $dUp !== '' ? $e($dUp) : '&mdash;' ?></b></div>
<?php if ($dUpd !== ''): ?>
            <div><span><?= $e(ds_t('lbl_last_upd')) ?></span><b><?= $e($dUpd) ?></b></div>
<?php endif; ?>
<?php if ($ver !== ''): ?>
            <div><span><?= $e(ds_t('lbl_version')) ?></span><b><?= $e($ver) ?></b></div>
<?php endif; ?>
            <div><span><?= $e(ds_t('lbl_status')) ?></span><?php if (!empty($tool['__mt'])): ?><b class="is-warn"><?= $e(ds_t('mt_label')) ?></b><?php else: ?><b class="is-ok"><?= $e(ds_t('st_active')) ?></b><?php endif; ?></div>
        </div>

<?php if ($rel): ?>
        <h2><?= $e(ds_t('rel_h')) ?></h2>
        <div class="ds-rel">
<?php foreach ($rel as $o): $oVip = $isVipTool($o); ?>
            <div class="ds-rel__card" data-go="<?= (int) $o['id'] ?>">
                <img class="ds-imgfb" src="<?= $e(isset($o['image']) ? $o['image'] : '') ?>" alt="" width="44" height="44" decoding="async">
                <div class="ds-rel__info">
                    <strong><?= $e(isset($o['name']) ? $o['name'] : '') ?></strong>
                    <span class="<?= $oVip ? 'is-vip' : '' ?>"><?= $e(ds_t($oVip ? 'vip_1' : 'free_1')) ?></span>
                </div>
                <button class="ds-open" type="button"><?= $e(ds_t('view')) ?></button>
            </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>
    </article>
<?php endforeach; ?>
</section>

<section class="ds-pkgwrap" id="ds-pkgwrap">
<?php foreach ($pkgs as $pn => $pk):
        if (!$dsPkgShow($pn, $pk)) { continue; } ?>
    <article class="ds-pkgpage" id="ds-pkgpage-<?= (int) $pn ?>" data-pkg="<?= (int) $pn ?>">
<?php if (true): /* v49: trang Package luon co nut Home giong DS */ ?>
        <button class="ds-pkgback" type="button"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg><?= $e($textHome) ?></button>
<?php endif; ?>
        <h1 class="ds-pkgtitle"<?= true ? '' : ' style="margin-top:14px"' ?>><?= $e($pk['title']) ?></h1>
        <p class="ds-pkgsub"><?= $e($pk['sub']) ?></p>
        <label class="ds-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input class="ds-search__in" type="search" placeholder="<?= $e(ds_t('search')) ?>" aria-label="<?= $e(ds_t('search')) ?>" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="search">
        </label>
        <div class="ds-pkgcount"><?= count($pk['tools']) ?> <?= $e(count($pk['tools']) === 1 ? ds_t('tool_1') : ds_t('tool_n')) ?></div>
        <div class="ds-pkglist">
<?php   foreach ($pk['tools'] as $tool): echo $renderRow($tool); endforeach; ?>
        </div>
        <p class="ds-pkgempty"<?= $pk['tools'] ? ' hidden' : '' ?>><?= $e(ds_t('no_res')) ?></p>
    </article>
<?php endforeach; ?>
</section>
<?php endif; ?>

<?php
    /* v31: khung dau trang chu kieu shopdsgm.vn (IPA - TOOLS + banner tu chuyen + the nho keo ngang) */
    /* v32: danh sach anh lay tu ham dung chung (de <head> tai san anh dau tien). Moi phan tu: ['src' => ..., 'orig' => ...] */
    $dsHeroImgs = devnvios_theme_hero_images();
    $dsHeroTop   = defined('DS_HERO_TOP') ? max(0, min(40, (int) DS_HERO_TOP)) : 22;
    $dsChipsSec  = defined('DS_CHIPS_SECONDS') ? max(8, min(120, (int) DS_CHIPS_SECONDS)) : 24;
    $dsHeroSec   = defined('DS_HERO_SECONDS') ? max(2, (float) DS_HERO_SECONDS) : 3.5;
    $dsHeroTitle = defined('DS_HERO_TITLE') ? (string) DS_HERO_TITLE : 'IPA - TOOLS';
    $dsHeroDesc  = defined('DS_HERO_DESC') ? (string) DS_HERO_DESC : 'IPA files and iOS tools in one place. Fast, simple and always up to date.';
    $dsChipSvg = [
        'cube'   => '<path d="M12 3 4 7.5v9L12 21l8-4.5v-9z"/><path d="M4 7.5 12 12l8-4.5M12 12v9"/>',
        'box'    => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'wrench' => $ico['tools'],
        'code'   => '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M14 5l-4 14"/>',
        'bolt'   => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
        'phone'  => '<rect x="7" y="2" width="10" height="20" rx="2.5"/><path d="M11 18h2"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
        'down'   => '<path d="M12 3v12m0 0-4-4m4 4 4-4M5 21h14"/>',
        'shield' => $ico['admin'],
        'doc'    => $ico['doc'],
        'cog'    => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
        'key'    => $ico['key'],
        /* v32: icon moi cho the kieu code */
        'term'   => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="m7.5 9.5 3 2.5-3 2.5M13 15h3.5"/>',
        'menu'   => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
    ];
    /* the nho lien quan toi tools: [ten, icon]. v32: chi 9 the kieu code, tu chay ngang. Sua tuy y (nen de 8-10 the cho gon). */
    $dsChips = [
        ['IPA', 'box'], ['C++', 'code'], ['Python', 'term'], ['Mod Menu', 'menu'], ['Lua', 'code'],
        ['Swift', 'bolt'], ['Unity', 'cube'], ['Il2Cpp', 'layers'], ['Dylib', 'cog'],
    ];
    $dsChRaw = dsx_cfg('ds_chips');
    if ($dsChRaw !== '') {
        $dsChNew = [];
        foreach (preg_split('/\r\n|\r|\n/', $dsChRaw) as $dsLn) {
            $dsPp = explode('|', $dsLn, 2);
            $dsNm = trim($dsPp[0]);
            if ($dsNm === '') { continue; }
            $dsIk = isset($dsPp[1]) ? strtolower(trim($dsPp[1])) : 'box';
            if (!isset($dsChipSvg[$dsIk])) { $dsIk = 'box'; }
            $dsChNew[] = [$dsNm, $dsIk];
        }
        if ($dsChNew) { $dsChips = $dsChNew; }
    }
    $dsChipSet = function ($hidden) use ($dsChips, $dsChipSvg, $e) {
        $o = '<div class="ds-chipset"' . ($hidden ? ' aria-hidden="true"' : '') . '>';
        foreach ($dsChips as $c) {
            $o .= '<span class="ds-chip2"><svg viewBox="0 0 24 24" aria-hidden="true">' . $dsChipSvg[$c[1]] . '</svg>' . $e($c[0]) . '</span>';
        }
        return $o . '</div>';
    };
?>
<section class="ds-hero" id="ds-hero" data-sec="<?= $e($dsHeroSec) ?>" data-top="<?= (int) $dsHeroTop ?>">
    <h1 class="ds-hero__title"><?= $e($dsHeroTitle) ?></h1>
    <p class="ds-hero__desc"><?= $e($dsHeroDesc) ?></p>
    <div class="ds-slider" id="ds-slider">
        <div class="ds-slider__track">
<?php /* v32: anh dau tien hien ngay (da tai san o <head>); anh 2, 3 chi tai SAU khi anh dau xong (data-src) de khong tranh bang thong voi anh dau.
         onerror: neu ban nen WebP loi thi tu doi sang anh goc. */ ?>
<?php foreach ($dsHeroImgs as $hi => $hm):
        $hErr = ($hm['src'] !== $hm['orig']) ? ' data-orig="' . $e($hm['orig']) . '" onerror="var o=this.getAttribute(\'data-orig\');if(o&amp;&amp;this.src!==o){this.onerror=null;this.src=o}"' : ''; ?>
            <div class="ds-slide"><?php if ($hi === 0): ?><img src="<?= $e($hm['src']) ?>" alt="" decoding="async" draggable="false" referrerpolicy="no-referrer" fetchpriority="high"<?= $hErr ?>><?php else: ?><img data-src="<?= $e($hm['src']) ?>" alt="" decoding="async" draggable="false" referrerpolicy="no-referrer"<?= $hErr ?>><?php endif; ?></div>
<?php endforeach; ?>
        </div>
        <div class="ds-slider__dots" aria-hidden="true"></div>
    </div>
    <div class="ds-chips2" id="ds-chips2" style="--ds-chips-sec:<?= (int) $dsChipsSec ?>s"><div class="ds-chips2__track"><?= $dsChipSet(false) ?><?= $dsChipSet(true) ?></div></div>
</section>

<section class="ds-toolspage" id="ds-toolspage">
    <div class="ds-empty">
        <div class="ds-empty__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $ico['tools'] ?></svg></div>
        <h2>Tools</h2>
        <p><?= $e(($dsToolsList && $tools) ? 'All DEVNVIOS tools in one place. Tap a tool to see its details.' : ds_t('tools_empty')) ?></p>
    </div>
<?php if ($dsToolsList && $tools): ?>
    <div class="ds-chips">
        <button class="ds-chip is-on" type="button" data-f="all">All</button>
        <button class="ds-chip" type="button" data-f="vip">VIP</button>
        <button class="ds-chip" type="button" data-f="free">Free</button>
    </div>
    <div class="ds-tlist">
<?php foreach ($tools as $tool): echo $renderRow($tool, ' data-type="' . ($isVipTool($tool) ? 'vip' : 'free') . '"'); endforeach; ?>
    </div>
<?php endif; ?>
</section>

<section class="ds-histpage" id="ds-histpage">
    <div class="ds-hhead">
        <h1 class="ds-pkgtitle"><?= $e(ds_t('history')) ?></h1>
        <span class="ds-badge" id="ds-hs-left" hidden></span>
    </div>
    <p class="ds-pkgsub"><?= $e(ds_t('hist_s')) ?></p>
    <div class="ds-hstat">
        <div><b class="is-in" id="ds-hs-in">+0</b><span>Received</span></div>
        <div><b class="is-out" id="ds-hs-out">&minus;0</b><span>Used</span></div>
        <div><b id="ds-hs-open">0</b><span>Opened</span></div>
    </div>
    <div class="ds-seg">
        <button class="ds-hchip is-on" type="button" data-f="all">All</button>
        <button class="ds-hchip" type="button" data-f="in">Received</button>
        <button class="ds-hchip" type="button" data-f="out">Used</button>
    </div>
    <div id="ds-hlist"></div>
    <div class="ds-empty ds-hempty" id="ds-hempty" hidden>
        <div class="ds-empty__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $ico['hist'] ?></svg></div>
        <h2><?= $e(ds_t('hist_empty')) ?></h2>
        <p><?= $e(ds_t('hist_empty_s')) ?></p>
        <button class="ds-hgo" type="button" data-go="keys">Activate a key</button>
    </div>
    <p class="ds-hnote" id="ds-hnote" hidden>History is saved on this device only. Clearing your browser data removes it.</p>
    <button class="ds-hclear" id="ds-hclear" type="button" hidden>Clear history</button>
</section>

<?php if ($isLoggedIn): ?>
<section class="ds-mepage" id="ds-mepage">
    <div class="ds-me__top">
        <div class="ds-me__av" id="ds-me-av"><span id="ds-me-av-l">U</span><?= $avImg ?></div>
        <div class="ds-me__who">
            <h1 id="ds-me-name">Member</h1>
            <div class="ds-me__badges"><span class="ds-badge" id="ds-me-plan">Member</span><span class="ds-badge is-ok">Active</span></div>
        </div>
    </div>
<?php if ($secWarn): ?>
    <a class="ds-secwarn" href="<?= $e($dsSecUrl) ?>"><?= $svg('warn') ?><span>Your account is not fully protected. Link your Gmail and phone number so you can recover it.</span></a>
<?php endif; ?>

    <h2 class="ds-me__h">Account details</h2>
    <div class="ds-lcard" id="ds-me-info"></div>

    <h2 class="ds-me__h">Security</h2>
    <div class="ds-lcard">
        <a class="ds-lrow" href="<?= $e($dsSecUrl . '#password') ?>"><span>Change password</span><span class="ds-lrow__r"><?= $chev ?></span></a>
<?php if ($dsSecLinks): ?>
        <a class="ds-lrow" href="<?= $e($dsSecUrl . '#email') ?>"><span>Gmail</span><span class="ds-lrow__r"><?= $secKnown ? $secTag($dsSec['email']) : '' ?><?= $chev ?></span></a>
        <a class="ds-lrow" href="<?= $e($dsSecUrl . '#phone') ?>"><span>Phone number</span><span class="ds-lrow__r"><?= $secKnown ? $secTag($dsSec['phone']) : '' ?><?= $chev ?></span></a>
<?php endif; ?>
    </div>

    <h2 class="ds-me__h">Activity</h2>
    <div class="ds-lcard">
        <button class="ds-lrow" type="button" data-go="hist"><span>Turn history</span><?= $chev ?></button>
        <button class="ds-lrow" type="button" data-go="keys"><span>Activate a key</span><?= $chev ?></button>
    </div>

    <h2 class="ds-me__h">Settings</h2>
    <div class="ds-lcard">
        <div class="ds-lrow is-static"><span>Appearance theme</span>
            <select class="ds-sel" id="ds-theme-sel" aria-label="Appearance theme">
                <option value="auto">Auto</option>
                <option value="light">Light mode</option>
                <option value="dark">Dark mode</option>
            </select>
        </div>
        <div class="ds-lrow is-static"><span>Language</span><b>English</b></div>
    </div>
<?php if ($dsPriv !== '' || $dsTerms !== ''): ?>

    <h2 class="ds-me__h">Support</h2>
    <div class="ds-lcard">
        <a class="ds-lrow" href="<?= $e($dsDocs) ?>"><span><?= $e(ds_t('docs')) ?></span><?= $chev ?></a>
<?php if ($dsPriv !== ''): ?>
        <a class="ds-lrow" href="<?= $e($dsPriv) ?>"><span>Privacy Policy</span><?= $chev ?></a>
<?php endif; ?>
<?php if ($dsTerms !== ''): ?>
        <a class="ds-lrow" href="<?= $e($dsTerms) ?>"><span>Terms of Service</span><?= $chev ?></a>
<?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($isAdmin): ?>

    <h2 class="ds-me__h">Admin</h2>
    <div class="ds-lcard">
        <a class="ds-lrow" href="/admin_cp"><span>Admin panel</span><?= $chev ?></a>
    </div>
<?php endif; ?>

    <a class="ds-logout" href="/logout"><?= $svg('out') ?><span><?= $e(ds_t('logout')) ?></span></a>
</section>
<?php endif; ?>

<div class="ds-keyhead" id="ds-keyhead">
    <h2 class="ds-sec__title"><?= $e(ds_t('kh_t')) ?></h2>
    <p class="ds-sec__sub"><?= $e(ds_t('kh_s')) ?></p>
    <div class="ds-need" id="ds-need" role="status" aria-live="polite" style="--ds-need-ms:<?= (int) $dsNeedMs ?>ms" hidden>
        <span class="ds-need__ic"><svg viewBox="0 0 24 24" aria-hidden="true"><circle class="ds-x__c" cx="12" cy="12" r="9.5" transform="rotate(-90 12 12)"/><path class="ds-x__a" d="M6 6l12 12"/><path class="ds-x__b" d="M18 6L6 18"/></svg></span>
        <span class="ds-need__t"><?= $e(ds_t('need_key')) ?></span>
    </div>
</div>

<section class="ds-docs" id="ds-docs">
    <div class="ds-docs__blk">
        <h3 class="ds-docs__h ds-docs__h--brand"><?= $svg('site') ?><span><?= $e(ds_t('docs_brand')) ?></span></h3>
        <p class="ds-docs__p"><?= $e(ds_t('docs_about')) ?></p>
    </div>
    <div class="ds-docs__blk">
        <h3 class="ds-docs__h"><?= $svg('scale') ?><span><?= $e(ds_t('docs_legal_t')) ?></span></h3>
        <p class="ds-docs__p"><?= $e(ds_t('docs_legal')) ?></p>
    </div>
    <h3 class="ds-docs__h"><?= $svg('doc') ?><span><?= $e(ds_t('docs')) ?></span></h3>
    <a class="ds-doclink" href="<?= $e($dsPriv) ?>"><span>Privacy Policy</span><?= $chev ?></a>
    <a class="ds-doclink" href="<?= $e($dsTerms) ?>"><span>Terms of Service</span><?= $chev ?></a>
</section>

<div class="ds-ptr" id="ds-ptr" aria-hidden="true"><svg viewBox="0 0 24 24"><circle class="ds-ptr__track" cx="12" cy="12" r="9"/><circle class="ds-ptr__arc" cx="12" cy="12" r="9" transform="rotate(-90 12 12)" stroke-dasharray="56.55" stroke-dashoffset="56.55"/></svg></div>

<nav class="ds-nav" aria-label="Main">
    <div class="ds-nav__in">
        <a class="ds-nav__item is-active" id="ds-home" href="/"><?= $svg('home') ?><span><?= $e($textHome) ?></span></a>
        <button class="ds-nav__item" id="ds-keys" type="button"><?= $svg('key') ?><span><?= $e(ds_t('keys')) ?></span></button>
        <button class="ds-nav__item" id="ds-tools" type="button"><?= $svg('tools') ?><span><?= $e(ds_t('tools')) ?></span></button>
        <button class="ds-nav__item" id="ds-hist" type="button"><?= $svg('hist') ?><span><?= $e(ds_t('history')) ?></span></button>
<?php if (!$isLoggedIn): ?>
        <a class="ds-nav__item" href="/dang-nhap"><?= $svg('login') ?><span><?= $e($cap($textLogin)) ?></span></a>
<?php if ($dsNavReg): ?>
        <a class="ds-nav__item" href="/dang-ky"><?= $svg('plus') ?><span><?= $e($cap($textRegister)) ?></span></a>
<?php endif; ?>
<?php else: ?>
        <button class="ds-nav__item" id="ds-me" type="button"><?= $svg('login') ?><span><?= $e(ds_t('me')) ?></span></button>
<?php endif; ?>
    </div>
</nav>
<script>
(function () {
    var root = document.documentElement;
    var SRV_USER = <?= $dsUserJson ?>, SRV_HIST = <?= $dsHistJson ?>;
    var IS_GUEST = <?= $isLoggedIn ? 'false' : 'true' ?>;
    var REOPEN_MS = <?= (int) $dsReopen * 1000 ?>;
    var NEED_MS = <?= (int) $dsNeedMs ?>;
    var TOOL_1 = <?= dsx_js(ds_t('tool_1')) ?>, TOOL_N = <?= dsx_js(ds_t('tool_n')) ?>;
    var TOAST_KEYS = <?= $dsToastKeys ? 'true' : 'false' ?>;

    /* Chua dang nhap: an thanh "WELCOME! / LOG IN / REGISTER" (da co muc Log in o thanh tab duoi day) */
    if (IS_GUEST) {
        root.classList.add('ds-guest');
        root.classList.remove('ds-me');
    }

    /* Khi go vao o nhap: cuon o vao giua, an thanh tab duoi day, chua cho cho ban phim.
       v35: CHI chua cho / an thanh tab khi ban phim THAT SU mo (do bang visualViewport). Truoc day web tu focus vao o key bang code,
       iPhone khong bat ban phim nhung trang van chua san 46vh + an thanh tab => "khoang trong cuc lon" sau khi bam GET.
       v36: kbOpen khat khe hon (> 150px) va KHONG doi trang thai ban phim trong luc dang cuon (thanh Safari truot lam visualViewport
       nhay vai chuc px => truoc day co the kich nham ds-typing, trang dai them 46vh roi tut lai => cam giac "cuon 2 lan" o day trang). */
    var typTimer = 0;
    function kbOpen() {
        var vv = window.visualViewport;
        if (!vv) return true;
        if (vv.scale && vv.scale > 1.05) return false; /* dang phong to bang tay, khong phai ban phim */
        return (window.innerHeight - vv.height) > 150;
    }
    document.addEventListener('focusin', function (e) {
        var t = e.target;
        if (!t || !/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
        clearTimeout(typTimer);
        setTimeout(function () {
            if (document.activeElement !== t) return;
            if (!kbOpen()) return;
            root.classList.add('ds-typing');
            try { t.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (x) {}
        }, 380);
    });
    document.addEventListener('focusout', function () {
        clearTimeout(typTimer);
        typTimer = setTimeout(function () { root.classList.remove('ds-typing'); }, 200);
    });
    /* ban phim mo muon hon 380ms => chua cho luc do; ban phim dong ma khong co focusout => tu go khoang trong (het bi ket) */
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function () {
            if (window.__dsScrolling) return; /* v36: dang cuon => khong doi trang thai ban phim */
            var a = document.activeElement, inp = !!(a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName));
            if (!kbOpen()) root.classList.remove('ds-typing');
            else if (inp) root.classList.add('ds-typing');
        });
    }

    function main() {
        var header = document.querySelector('.site-header');
        var top = document.querySelector('.page-top');
        var menu = document.getElementById('menu');
        var toggle = document.getElementById('menu-toggle');
        var nHome = document.getElementById('ds-home'), nKeys = document.getElementById('ds-keys'), nTools = document.getElementById('ds-tools');
        var nHist = document.getElementById('ds-hist'), nMe = document.getElementById('ds-me');
        if (!header || !top || !menu || !toggle) return;

        /* nut sang/toi (va avatar) vao header */
        var hd = document.getElementById('ds-hd');
        header.insertBefore(hd, toggle);

        /* ===== Giao dien sang / toi / tu dong ===== */
        var themeBtn = document.getElementById('ds-theme-btn'), themeSel = document.getElementById('ds-theme-sel');
        var meta = document.querySelector('meta[name="theme-color"]');
        if (!meta) { meta = document.createElement('meta'); meta.setAttribute('name', 'theme-color'); document.head.appendChild(meta); }
        var MOON = '<svg viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
        var SUN = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4m11.4-11.4 1.4-1.4"/></svg>';
        function paintTheme() {
            var light = root.getAttribute('data-theme') === 'light';
            themeBtn.innerHTML = light ? SUN : MOON;
            meta.setAttribute('content', light ? '#ffffff' : '#000000');
        }
        function themeMode() {
            var m = null;
            try { m = localStorage.getItem('ds-theme'); } catch (e) {}
            return (m === 'light' || m === 'auto') ? m : 'dark';
        }
        function prefersLight() { return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches); }
        function applyTheme(m) {
            var light = m === 'light' || (m === 'auto' && prefersLight());
            if (light) root.setAttribute('data-theme', 'light'); else root.removeAttribute('data-theme');
            try { localStorage.setItem('ds-theme', m); } catch (e) {}
            if (themeSel) themeSel.value = m;
            paintTheme();
        }
        themeBtn.addEventListener('click', function () {
            applyTheme(root.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
        });
        if (themeSel) themeSel.addEventListener('change', function () { applyTheme(themeSel.value); });
        try {
            var mq = window.matchMedia('(prefers-color-scheme: light)');
            var onMq = function () { if (themeMode() === 'auto') applyTheme('auto'); };
            if (mq.addEventListener) mq.addEventListener('change', onMq); else if (mq.addListener) mq.addListener(onMq);
        } catch (e) {}
        if (themeSel) themeSel.value = themeMode();
        paintTheme();

        /* dua danh sach tool, trang chi tiet, tieu de Keys, trang History / Me va logo vao dung cho */
        var prod = document.getElementById('ds-products'), detwrap = document.getElementById('ds-detwrap'), kh = document.getElementById('ds-keyhead'), pkgwrap = document.getElementById('ds-pkgwrap');
        var group = document.querySelector('.group-link'), firstKey = document.querySelector('.page-top > section.card');
        if (prod) { if (group) group.after(prod); else top.appendChild(prod); }
        if (detwrap) { if (prod) prod.after(detwrap); else top.appendChild(detwrap); }
        if (pkgwrap) { if (detwrap) detwrap.after(pkgwrap); else if (prod) prod.after(pkgwrap); else top.appendChild(pkgwrap); }
        var tp = document.getElementById('ds-toolspage');
        if (tp) { if (detwrap) detwrap.after(tp); else if (prod) prod.after(tp); else top.appendChild(tp); }
        var hp = document.getElementById('ds-histpage'), mp = document.getElementById('ds-mepage');
        var anchor = tp;
        if (hp) { if (anchor) anchor.after(hp); else top.appendChild(hp); anchor = hp; }
        if (mp) { if (anchor) anchor.after(mp); else top.appendChild(mp); }
        if (kh && firstKey) firstKey.before(kh);

        /* v28: moi thong bao (dung key / sai key / canh bao) nam TREN o VIP key, DUOI dong chu "used up":
           chuyen khung .toast-anchor vao trong khoi tieu de Keys, ngay duoi thong bao "chua co key" */
        var toastAnchor = document.querySelector('.toast-anchor');
        if (toastAnchor && kh && TOAST_KEYS) kh.appendChild(toastAnchor);

        /* logo lang bong: boc anh logo, them "cua so" dai sang chua ban sao trang cua logo. Chi transform */
        function makeShiny(img) {
            if (!img || !img.parentNode || (img.parentNode.classList && img.parentNode.classList.contains('ds-logo'))) return;
            var w = document.createElement('span'), sw = document.createElement('i'), c = document.createElement('img');
            w.className = 'ds-logo'; sw.className = 'ds-sw'; sw.setAttribute('aria-hidden', 'true');
            c.className = 'ds-swi'; c.alt = ''; c.setAttribute('aria-hidden', 'true'); c.decoding = 'async';
            c.src = img.currentSrc || img.src;
            img.parentNode.insertBefore(w, img);
            w.appendChild(img); sw.appendChild(c); w.appendChild(sw);
        }

        var about = document.querySelector('.site-about'), logo = document.querySelector('.brand__logo');
        if (about && logo) {
            var frame = document.createElement('div'), im = logo.cloneNode(false);
            frame.className = 'ds-brandblock';
            im.className = ''; im.removeAttribute('fetchpriority');
            frame.appendChild(im);
            about.insertBefore(frame, about.firstChild);
            makeShiny(im);
        }
        if (logo) makeShiny(logo);

        /* v21: khoi tai lieu (icon kieu DS) vao cuoi phan gioi thieu; cac doan gioi thieu cu rut gon 3 dong + "Read more" */
        var docsEl = document.getElementById('ds-docs');
        if (docsEl) {
            var fEl = document.querySelector('.site-footer');
            if (about) about.appendChild(docsEl);
            else if (fEl) fEl.parentNode.insertBefore(docsEl, fEl);
            else top.after(docsEl);
        }
        function clampAbout() {
            document.querySelectorAll('.site-about__text').forEach(function (p) {
                if (p.getAttribute('data-ds-clamp')) return;
                p.setAttribute('data-ds-clamp', '1');
                p.classList.add('ds-clamp');
                if (p.scrollHeight <= p.clientHeight + 2) { p.classList.remove('ds-clamp'); return; }
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'ds-readmore'; b.textContent = 'Read more';
                b.addEventListener('click', function () {
                    var open = p.classList.toggle('is-open');
                    b.textContent = open ? 'Show less' : 'Read more';
                });
                p.after(b);
            });
        }
        setTimeout(clampAbout, 60);
        window.addEventListener('load', clampAbout, { once: true });

        /* v21: anh xem truoc loi thi bo anh do; het anh thi hien "No preview available" */
        document.querySelectorAll('.ds-prevbox').forEach(function (box) {
            var strip = box.querySelector('.ds-prev'), none = box.querySelector('.ds-noprev');
            if (!strip || !none) return;
            function check() {
                if (!strip.querySelector('img')) { strip.hidden = true; none.hidden = false; var ar = box.closest('.ds-det'); if (ar) ar.classList.remove('ds-det--prev'); }
            }
            strip.querySelectorAll('img').forEach(function (im) {
                im.addEventListener('error', function () { im.remove(); check(); });
            });
        });

        /* ===== Du lieu nguoi dung (doc tu user-bar cu hoac tu PHP) ===== */
        function clean(s) { return String(s == null ? '' : s).replace(/\s+/g, ' ').trim(); }
        function textOf(sel) { var el = document.querySelector(sel); return el ? clean(el.textContent) : ''; }
        function readUser() {
            var bar = document.querySelector('.user-bar'), all = bar ? clean(bar.textContent) : '';
            var planEl = document.querySelector('.user-bar__plan');
            var planTxt = clean(SRV_USER.plan || (planEl ? planEl.textContent : ''));
            var plan = (/vip/i.test(planTxt) || (planEl && planEl.classList.contains('is-vip'))) ? 'VIP'
                : ((/free/i.test(planTxt) || (planEl && planEl.classList.contains('is-free'))) ? 'Free' : 'Member');
            var name = clean(SRV_USER.name || textOf('.user-bar__name')).replace(/^(hello|hi)[\s,!:]+/i, '');
            var timer = textOf('.user-bar__timer'), turns = null, timerIsTurns = false, m;
            if (SRV_USER.turns !== undefined && SRV_USER.turns !== '' && !isNaN(parseInt(SRV_USER.turns, 10))) turns = parseInt(SRV_USER.turns, 10);
            if (turns === null) {
                m = /(?:turns?|l\u01b0\u1ee3t|luot)\s*[:\uff1a]?\s*(\d+)/i.exec(all) || /(\d+)\s*(?:turns?|l\u01b0\u1ee3t|luot)/i.exec(all);
                if (m) turns = parseInt(m[1], 10);
            }
            if (turns === null && /^\d+$/.test(timer)) { turns = parseInt(timer, 10); timerIsTurns = true; }
            return { name: name, plan: plan, turns: turns, timer: timerIsTurns ? '' : timer, email: SRV_USER.email || '', id: SRV_USER.id || '', since: SRV_USER.since || '', ip: SRV_USER.ip || '' };
        }
        function setTxt(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }
        function setBadge(id, plan) {
            var el = document.getElementById(id);
            if (!el) return;
            el.textContent = plan;
            el.className = 'ds-badge' + (plan === 'VIP' ? ' is-vip' : '');
        }
        function addRow(box, k, v) {
            var r = document.createElement('div'), a = document.createElement('span'), b = document.createElement('b');
            r.className = 'ds-lrow is-static'; a.textContent = k; b.textContent = v;
            r.appendChild(a); r.appendChild(b); box.appendChild(r);
        }
        function paintUser() {
            if (IS_GUEST) return;
            var u = readUser(), nm = u.name || 'Member', ch = (Array.from(nm)[0] || 'U').toUpperCase();
            var tv = u.turns !== null ? String(u.turns) : (u.timer || '\u2014');
            setTxt('ds-av-l', ch); setTxt('ds-me-av-l', ch); setTxt('ds-um-av-l', ch);
            setTxt('ds-um-name', nm); setTxt('ds-me-name', nm);
            setBadge('ds-um-plan', u.plan); setBadge('ds-me-plan', u.plan);
            setTxt('ds-um-turns', tv);
            var box = document.getElementById('ds-me-info');
            if (box) {
                box.textContent = '';
                addRow(box, 'Name', nm);
                addRow(box, 'Plan', u.plan);
                addRow(box, 'Turns left', u.turns !== null ? u.turns + (u.turns === 1 ? ' turn' : ' turns') : (u.timer || '\u2014'));
                if (u.timer && u.turns !== null) addRow(box, 'Timer', u.timer);
                if (u.email) addRow(box, 'Email', u.email);
                if (u.id) addRow(box, 'User ID', u.id);
                if (u.since) addRow(box, 'Member since', u.since);
                if (u.ip) addRow(box, 'Current IP', u.ip);
            }
            paintLock();
        }

        /* ===== v36: KHOA CUNG VIP / Free =====
           Key VIP chi mo san pham VIP, key Free chi mo san pham Free. Chua co key (plan = Member) thi de index.php tu xu ly.
           Chan o MOI duong mo tool: nut GET, va ca link trong menu an (#menu [data-tool-id]) bang 1 bo lang nghe pha "capture".
           (Day la lop chan o giao dien. Chan that van phai nam o server: ds_open_gate_* trong db.php.) */
        function kindOf(det) { return /vip/i.test(det.getAttribute('data-kind') || '') ? 'VIP' : 'Free'; }
        function planBlock(kind, plan) {
            var p = plan === undefined ? (IS_GUEST ? '' : readUser().plan) : plan;
            if (p === 'Free' && kind === 'VIP') return 'This is a VIP tool. A Free key cannot open it. Enter a VIP key to continue.';
            if (p === 'VIP' && kind === 'Free') return 'This is a Free tool. A VIP key cannot open it. Enter a Free key to continue.';
            return '';
        }
        function paintLock() {
            if (IS_GUEST) return;
            var p = readUser().plan;
            document.querySelectorAll('.ds-det').forEach(function (d) {
                var g = d.querySelector('.ds-get');
                if (g) g.classList.toggle('is-mismatch', planBlock(kindOf(d), p) !== '');
            });
        }
        document.addEventListener('click', function (e) {
            if (IS_GUEST) return;
            var a = e.target && e.target.closest ? e.target.closest('#menu [data-tool-id]') : null;
            if (!a) return;
            var det = document.getElementById('ds-det-' + a.getAttribute('data-tool-id'));
            if (!det) return;
            var msg = planBlock(kindOf(det));
            if (!msg) return;
            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) e.stopImmediatePropagation();
            needKey(msg);
        }, true);

        /* menu avatar */
        var avBtn = document.getElementById('ds-avatar'), um = document.getElementById('ds-um'), umOv = document.getElementById('ds-um-ov');
        function umOpen(on) {
            if (!um || !avBtn) return;
            if (on) paintUser();
            um.hidden = !on;
            if (umOv) umOv.hidden = !on;
            avBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
        }
        if (avBtn && um) {
            avBtn.addEventListener('click', function (e) { e.stopPropagation(); umOpen(um.hidden); });
            if (umOv) umOv.addEventListener('click', function () { umOpen(false); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') umOpen(false); });
        }
        document.querySelectorAll('.ds-um [data-go], .ds-mepage [data-go], .ds-histpage [data-go]').forEach(function (b) {
            b.addEventListener('click', function () { setView(b.getAttribute('data-go')); });
        });

        /* ===== Thong bao "tool da dong, nhap key" (v26): hien dep, TU BIEN MAT ===== */
        var needTm = 0;
        function needEl() { return document.getElementById('ds-need'); }
        function hideNeed() {
            var n = needEl();
            if (!n || n.hidden) return;
            clearTimeout(needTm);
            n.classList.remove('is-in');
            n.classList.add('is-out');
            setTimeout(function () {
                if (n.classList.contains('is-out')) { n.hidden = true; n.classList.remove('is-out'); }
            }, 340);
        }
        function hideNeedNow() {
            var n = needEl();
            clearTimeout(needTm);
            if (!n) return;
            n.hidden = true;
            n.classList.remove('is-in');
            n.classList.remove('is-out');
        }
        function showNeed(msg) {
            var n = needEl();
            if (!n) return;
            /* v34: thong bao tuy chon (vd: key Free khong mo duoc tool VIP). Khong truyen => chu mac dinh. */
            var nt = n.querySelector('.ds-need__t');
            if (nt) {
                if (!nt.getAttribute('data-def')) nt.setAttribute('data-def', nt.textContent);
                nt.textContent = msg ? msg : nt.getAttribute('data-def');
            }
            clearTimeout(needTm);
            n.classList.remove('is-out');
            n.classList.remove('is-in');
            n.hidden = false;
            void n.offsetWidth;
            n.classList.add('is-in');
            needTm = setTimeout(hideNeed, NEED_MS);
        }

        /* v30: thong bao (toast) luon sat le trai, cung hang voi chu "Activate a key".
           CSS da ep canh trai; day la lop du phong: do vi tri that cua icon toast so voi tieu de,
           neu van lech (do CSS cua index.php) thi keo ve dung le trai bang inline !important. */
        function alignToast() {
            var t = document.querySelector('.ds-keyhead .toast'), ttl = document.querySelector('.ds-keyhead .ds-sec__title');
            if (!t || !ttl) return;
            function run() {
                if (!t.parentNode) return;
                t.style.removeProperty('left');
                t.style.removeProperty('position');
                var ic = t.querySelector('.toast__icon') || t.firstElementChild || t;
                var d = ic.getBoundingClientRect().left - ttl.getBoundingClientRect().left;
                if (d > 1.5 || d < -1.5) {
                    t.style.setProperty('position', 'relative', 'important');
                    t.style.setProperty('left', (-d).toFixed(1) + 'px', 'important');
                }
            }
            requestAnimationFrame(function () { requestAnimationFrame(run); });
            setTimeout(run, 380);
        }

        /* v28: thong bao (toast) cua index.php hien o bat ky man nao => chuyen sang tab Keys de thay no ngay duoi dong "used up" */
        function watchToast() {
            var ta = document.querySelector('.toast-anchor');
            if (!ta) return;
            function shown() { var t = ta.querySelector('.toast'); return !!(t && clean(t.textContent) !== ''); }
            function react() {
                if (!shown()) return;
                hideNeedNow();
                if (!TOAST_KEYS) return;
                if (dsHoldKeys()) return;
                if (!root.classList.contains('ds-keys')) {
                    setView('keys', true);
                } else if ((window.pageYOffset || 0) > 60) {
                    try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (x) { window.scrollTo(0, 0); }
                }
                alignToast();
            }
            if (window.MutationObserver) new MutationObserver(react).observe(ta, { childList: true, subtree: true, characterData: true });
            react();
        }

        /* ===== Khoa mo tool: sau REOPEN_MS giay phai nhap key lai =====
           Mo lan dau: cho phep. Trong REOPEN_MS giay: mo lai khong mat luot. Sau do: chuyen sang tab Keys, chi mo lai khi da nhap key moi.
           (Day la lop chan o giao dien. Chan that o server: ds_open_gate_* trong db.php) */
        function gKey() { return 'ds-gate:' + (SRV_USER.id || readUser().name || 'user'); }
        function gGet() { try { var g = JSON.parse(localStorage.getItem(gKey()) || 'null'); return (g && typeof g === 'object') ? g : {}; } catch (e) { return {}; } }
        function gSet(g) { try { localStorage.setItem(gKey(), JSON.stringify(g)); } catch (e) {} }
        function gateState() {
            var g = gGet(), now = Date.now();
            if (!g.o) return 'fresh';
            if (g.k && g.k > g.o) return 'fresh';
            return (now - g.o < REOPEN_MS) ? 'reopen' : 'locked';
        }
        function gateStart() { var g = gGet(); g.o = Date.now(); gSet(g); }
        function keyEntered() {
            var g = gGet(); g.k = Date.now(); gSet(g);
            hideNeed();
        }
        function keyHasText() {
            var ok = false;
            document.querySelectorAll('.page-top > section.card .field').forEach(function (f) { if (String(f.value || '').trim().length >= 4) ok = true; });
            return ok;
        }
        document.addEventListener('submit', function (e) {
            var f = e.target;
            if (f && f.closest && f.closest('.page-top > section.card') && keyHasText()) keyEntered();
        }, true);
        document.addEventListener('click', function (e) {
            var b = e.target && e.target.closest ? e.target.closest('.page-top > section.card .btn') : null;
            if (b && keyHasText()) keyEntered();
        }, true);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target && e.target.classList && e.target.classList.contains('field') && keyHasText()) keyEntered();
        }, true);
        /* v26: bat dau go key thi thong bao tu bien mat */
        document.addEventListener('input', function (e) {
            if (e.target && e.target.classList && e.target.classList.contains('field')) hideNeed();
        }, true);
        /* v24: chi ghi lich su / tinh luot khi tool mo THANH CONG (co key, tru luot hoac trang roi di mo tool)
           v36: co key ma bam GET mo duoc la LUU NGAY vao History (khong cho dem luot nua).
                Sau 3 giay neu van thay thong bao loi MOI (het luot, sai key...) thi go dong do ra. Luot giam nhieu hon 1 thi cap nhat dung so luot. */
        var dsHideAt = 0;
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') dsHideAt = Date.now(); });
        window.addEventListener('pagehide', function () { dsHideAt = Date.now(); });
        function toastText() {
            var tl = document.querySelectorAll('.toast'), t = null; for (var ti = 0; ti < tl.length; ti++) { if (tl[ti].offsetHeight > 0) { t = tl[ti]; break; } } return t ? clean(t.textContent) : '';
        }
        function dsToastShown() { return toastText() !== ''; }
        function hRemove(t) {
            if (SRV_HIST) return;
            hSave(hLoad().filter(function (x) { return x.t !== t; }));
            if (root.classList.contains('ds-hist')) renderHist();
        }
        function hPatch(t, n) {
            if (SRV_HIST) return;
            var a = hLoad();
            for (var i = 0; i < a.length; i++) { if (a[i].t === t) { a[i].n = n; break; } }
            hSave(a);
            if (root.classList.contains('ds-hist')) renderHist();
        }
        /*DS-V44-OPEN*/
        /*DS-P25B-PATCH*/
        function dsToastKind() {
            var ta = document.querySelector('.toast-anchor'), k = '';
            if (!ta) return k;
            var ts = ta.querySelectorAll('.toast');
            for (var i = 0; i < ts.length; i++) {
                var m = /toast--(success|error|warning|info)/.exec(ts[i].className || '');
                if (m) k = m[1];
            }
            return k;
        }

        /* Ð?c s? lu?t hi?n t?i t? m?i ngu?n trên giao di?n d? ph?n h?i 0ms không b? do */
        function dsCurrentTurns() {
            var tc = document.getElementById('turn-counter');
            if (tc) {
                var m = /\d+/.exec(tc.textContent || '');
                if (m) return parseInt(m[0], 10);
            }
            var u = readUser();
            if (u.turns !== null && !isNaN(u.turns)) return u.turns;
            var ut = document.getElementById('ds-um-turns');
            if (ut) {
                var m2 = /\d+/.exec(ut.textContent || '');
                if (m2) return parseInt(m2[0], 10);
            }
            try {
                var cfgEl = document.getElementById('app-config');
                if (cfgEl) {
                    var cfg = JSON.parse(cfgEl.textContent);
                    if (cfg && cfg.turns !== undefined && !isNaN(parseInt(cfg.turns, 10))) {
                        return parseInt(cfg.turns, 10);
                    }
                }
            } catch (e) {}
            return 0;
        }

        /* Chu?i ngày YYYY-MM-DD theo gi? máy d? ki?m tra sang ngày m?i */
        function dsDateKey(ms) {
            var d = ms ? new Date(ms) : new Date();
            var y = d.getFullYear();
            var m = d.getMonth() + 1;
            var day = d.getDate();
            return y + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
        }

        /* Ki?m tra s?n ph?m (id) hôm nay dã du?c ghi vào l?ch s? m? tool chua */
        function dsAlreadyLoggedToday(toolId, toolName) {
            var today = dsDateKey(Date.now());
            var list = hLoad();
            for (var i = 0; i < list.length; i++) {
                var item = list[i];
                if (!item || item.k !== 'out') continue;
                if (dsDateKey(item.t) !== today) continue;
                if (toolId && parseInt(item.id, 10) === parseInt(toolId, 10)) return true;
                if (toolName && item.nm && String(item.nm) === String(toolName)) return true;
            }
            return false;
        }

        function dsHoldKeys() {
            var at = window.__dsGetAt || 0;
            if (!at || Date.now() - at > 4000) return false;
            var k = dsToastKind();
            if (k === 'success') return true;
            if (k === 'error' || k === 'warning' || k === 'info') {
                if (dsPend) { dsPend.failed = true; dsPendEnd(dsPend); }
                return false;
            }
            return false;
        }

        function dsWatchOpen() {
            var p = dsPend;
            if (!p) return;
            var n = 0, iv = setInterval(function () {
                n++;
                if (p.done || p.failed) { clearInterval(iv); return; }
                var u = dsCurrentTurns();
                if (p.u0 !== null && u !== null && u < p.u0) {
                    clearInterval(iv);
                    dsCommit(p, p.u0 - u);
                    return;
                }
                if (n >= 16) {
                    clearInterval(iv);
                    if (!p.done) { p.failed = true; dsPendEnd(p); }
                }
            }, 200);
        }

        var dsPend = null;
        function dsPendLive(p) { return !!(p && !p.done && !p.failed && Date.now() - p.c0 < 12000); }
        function dsPendEnd(p) {
            if (!p) return;
            if (p.mo) { try { p.mo.disconnect(); } catch (x) {} p.mo = null; }
            if (dsPend === p) dsPend = null;
        }

        /* Ch? ghi l?ch s? khi th?c s? qua link g?c VÀ trong ngày hôm nay s?n ph?m này chua hi?n l?n nào */
        function dsCommit(p, customDiff) {
            if (!dsPendLive(p)) return;
            if (root.classList.contains('ds-keys')) { p.failed = true; dsPendEnd(p); return; }
            p.done = true;
            window.__dsGetOk = true;
            var tid = parseInt(p.id, 10) || 0;
            var tnm = p.nm || 'Tool';
            if (!dsAlreadyLoggedToday(tid, tnm)) {
                var diff = customDiff && customDiff > 0 ? customDiff : 1;
                var u = dsCurrentTurns();
                if (p.u0 !== null && u !== null && u < p.u0) {
                    diff = Math.max(1, p.u0 - u);
                }
                hAdd({
                    t: Date.now(),
                    k: 'out',
                    n: diff,
                    id: tid,
                    nm: tnm,
                    pl: p.pl || 'free',
                    day: dsDateKey(Date.now())
                });
            }
            if (p.state === 'fresh' && !IS_GUEST) gateStart();
            dsPendEnd(p);
        }

        window.addEventListener('pagehide', function () {
            if (dsPend && !root.classList.contains('ds-keys')) dsCommit(dsPend, 1);
        });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden' && dsPend && !root.classList.contains('ds-keys')) dsCommit(dsPend, 1);
        });
        try {
            var dsWo = window.open;
            if (typeof dsWo === 'function') {
                window.open = function () {
                    try {
                        var url = arguments[0] ? String(arguments[0]) : '';
                        if (dsPend && url && !/^javascript:/i.test(url)) dsCommit(dsPend, 1);
                    } catch (x) {}
                    return dsWo.apply(window, arguments);
                };
            }
        } catch (x) {}
        try {
            var dsAc = HTMLAnchorElement.prototype.click;
            HTMLAnchorElement.prototype.click = function () {
                try {
                    if (dsPend && !this.hasAttribute('data-tool-id') && !(this.closest && this.closest('#menu'))) {
                        var h = this.getAttribute('href') || '';
                        if (h !== '' && h.charAt(0) !== '#' && !/^javascript:/i.test(h)) dsCommit(dsPend, 1);
                    }
                } catch (x) {}
                return dsAc.apply(this, arguments);
            };
        } catch (x) {}

        function dsVerifyOpen(id, state) {
            if (dsPend) dsPendEnd(dsPend);
            var det = document.getElementById('ds-det-' + id);
            var p = {
                c0: Date.now(),
                id: id,
                state: state,
                u0: dsCurrentTurns(),
                nm: det ? (det.getAttribute('data-name') || 'Tool') : 'Tool',
                pl: (det && /vip/i.test(det.getAttribute('data-kind') || '')) ? 'vip' : 'free',
                done: false,
                failed: false,
                seen: 0,
                mo: null
            };
            dsPend = p;
            var ta = document.querySelector('.toast-anchor');
            if (ta && window.MutationObserver) {
                p.mo = new MutationObserver(function (list) {
                    if (p.done || p.failed) return;
                    var any = false;
                    list.forEach(function (r) {
                        Array.prototype.forEach.call(r.addedNodes, function (n) {
                            if (n.nodeType === 1 && clean(n.textContent) !== '') any = true;
                        });
                    });
                    if (!any) return;
                    p.seen = Date.now();
                    var dsK = dsToastKind();
                    if (dsK === 'error' || dsK === 'warning') {
                        p.failed = true;
                        dsPendEnd(p);
                        if (!root.classList.contains('ds-keys')) {
                            setView('keys', true);
                            window.scrollTo(0, 0);
                        }
                    }
                });
                p.mo.observe(ta, { childList: true, subtree: true });
            }
            setTimeout(function () { if (!p.done) { p.failed = true; dsPendEnd(p); } }, 12000);
        }
        function dsVerifyOpenOld() {}
        function needKey(msg) {
            setView('keys', true);
            window.scrollTo(0, 0);
            showNeed(msg);
        }

        /* ===== Lich su luot (nhan + da dung) ===== */
        var histF = 'all';
        var KEYSVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2m-4 4 3 3m-6-1 2 2"/></svg>';
        var TOOLSVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>';
        var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        function hKey() { return 'ds-hist:' + (SRV_USER.id || readUser().name || 'guest'); }
        function hLoad() {
            if (SRV_HIST) return SRV_HIST.slice();
            try { var a = JSON.parse(localStorage.getItem(hKey()) || '[]'); return Array.isArray(a) ? a : []; } catch (e) { return []; }
        }
        function hSave(a) { try { localStorage.setItem(hKey(), JSON.stringify(a.slice(-200))); } catch (e) {} }
        function hAdd(entry) {
            if (SRV_HIST) return;
            var a = hLoad(); a.push(entry); hSave(a);
            if (root.classList.contains('ds-hist')) renderHist();
        }
        /* bam OPEN: -1 luot; bam lai cung tool trong REOPEN_MS thi mo lai khong mat luot */
        function logUse(id) {
            var a = hLoad(), now = Date.now(), reopen = false, det = document.getElementById('ds-det-' + id);
            for (var i = a.length - 1; i >= 0 && now - a[i].t < REOPEN_MS; i--) {
                if (a[i].k === 'out' && String(a[i].id) === String(id) && a[i].n > 0) { reopen = true; break; }
            }
            hAdd({ t: now, k: 'out', n: reopen ? 0 : 1, id: parseInt(id, 10) || 0, nm: det ? det.getAttribute('data-name') : '', pl: det && /vip/i.test(det.getAttribute('data-kind') || '') ? 'vip' : 'free' });
        }
        /* so luot tang len (nhap key, duoc cong luot) => ghi "Turns received" va coi nhu da nhap key */
        /*DS-P26-LOGIN*/
        window.__dsLogIn = function (n, pl, customLabel) {
            try {
                if (IS_GUEST || SRV_HIST) return;
                var nn = parseInt(n, 10);
                if (isNaN(nn) || nn < 1) nn = 1;
                var now = Date.now();
                if (window.__dsLastIn && now - window.__dsLastIn < 3500) return;
                window.__dsLastIn = now;
                var planStr = String(pl || '').toLowerCase();
                if (planStr !== 'vip' && planStr !== 'free') {
                    var uPlan = String(readUser().plan || '').toLowerCase();
                    planStr = (uPlan === 'vip') ? 'vip' : 'free';
                }
                var label = customLabel || (planStr === 'vip' ? 'VIP Key Activated' : 'Free Key Activated');
                window.__dsAllowIn = true;
                try {
                    hAdd({ t: now, k: 'in', n: nn, nm: label, pl: planStr, a: 1, day: dsDateKey(now) });
                } finally {
                    window.__dsAllowIn = false;
                }
                keyEntered();
            } catch (e) {}
        };
        function trackTurns() {
            if (IS_GUEST || SRV_HIST) return;
            var u = readUser();
            if (u.turns === null) return;
            var k = 'ds-turns:' + (SRV_USER.id || u.name || 'user');
            try { localStorage.setItem(k, String(u.turns)); } catch (e) {}
        }
        function fmtTime(ms) {
            var d = new Date(ms), h = d.getHours(), m = d.getMinutes(), ap = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            return h + ':' + (m < 10 ? '0' : '') + m + ' ' + ap;
        }
        function dayLabel(ms) {
            var d = new Date(ms), t = new Date();
            var a = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime(), b = new Date(t.getFullYear(), t.getMonth(), t.getDate()).getTime();
            var diff = Math.round((b - a) / 86400000);
            if (diff === 0) return 'Today';
            if (diff === 1) return 'Yesterday';
            return MON[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        }
        function mk(tag, cls, txt) { var el = document.createElement(tag); if (cls) el.className = cls; if (txt !== undefined) el.textContent = txt; return el; }
        function renderHist() {
            var list = document.getElementById('ds-hlist');
            if (!list) return;
            var all = hLoad().sort(function (a, b) { return b.t - a.t; }), tin = 0, tout = 0, opens = 0;
            all.forEach(function (e) { if (e.k === 'in') tin += e.n; else { tout += e.n; opens++; } });
            setTxt('ds-hs-in', '+' + tin); setTxt('ds-hs-out', '\u2212' + tout); setTxt('ds-hs-open', String(opens));
            /* v26: so luot con lai canh tieu de (chi hien khi doc duoc) */
            var left = document.getElementById('ds-hs-left'), lu = readUser();
            if (left) {
                if (lu.turns !== null) { left.textContent = lu.turns + (lu.turns === 1 ? ' turn left' : ' turns left'); left.hidden = false; }
                else { left.hidden = true; }
            }
            var items = all.filter(function (e) { return histF === 'all' || e.k === histF; });
            list.textContent = '';
            var empty = document.getElementById('ds-hempty'), note = document.getElementById('ds-hnote'), clr = document.getElementById('ds-hclear');
            if (empty) empty.hidden = items.length > 0;
            if (note) note.hidden = !!SRV_HIST || all.length === 0;
            if (clr) clr.hidden = !!SRV_HIST || all.length === 0;
            var last = '', grp = null;
            items.forEach(function (e) {
                var lab = dayLabel(e.t);
                if (lab !== last) { list.appendChild(mk('div', 'ds-hday', lab)); grp = mk('div', 'ds-hgrp'); list.appendChild(grp); last = lab; }
                var isIn = e.k === 'in', det = (!isIn && e.id) ? document.getElementById('ds-det-' + e.id) : null;
                var row = mk('div', 'ds-h' + (det ? ' is-link' : '')), ic = mk('div', 'ds-h__ic' + (isIn ? ' is-in' : ''));
                var info = mk('div', 'ds-h__info'), title, sub;
                if (isIn) {
                    ic.innerHTML = KEYSVG;
                    title = e.nm || 'Turns received';
                    sub = fmtTime(e.t) + (e.pl === 'vip' ? ' \u00b7 VIP key' : (e.pl === 'free' ? ' \u00b7 Free key' : ''));
                } else {
                    var src = det ? (det.querySelector('img') || {}).src : '';
                    if (src) { var im = document.createElement('img'); im.alt = ''; im.width = 44; im.height = 44; im.decoding = 'async'; im.src = src; ic.appendChild(im); } else { ic.innerHTML = TOOLSVG; }
                    title = e.nm || (det ? det.getAttribute('data-name') : '') || 'Tool';
                    var kind = det ? det.getAttribute('data-kind') : (e.pl === 'vip' ? 'VIP tool' : 'Free tool');
                    sub = fmtTime(e.t) + ' \u00b7 ' + kind + (e.n === 0 ? ' \u00b7 Reopened' : '');
                }
                info.appendChild(mk('strong', '', title)); info.appendChild(mk('span', '', sub));
                var amt = mk('b', 'ds-h__amt ' + (e.n === 0 ? 'is-zero' : (isIn ? 'is-in' : 'is-out')), (e.n === 0 ? '' : (isIn ? '+' : '\u2212')) + e.n + (e.n === 1 ? ' turn' : ' turns'));
                row.appendChild(ic); row.appendChild(info); row.appendChild(amt);
                if (det) row.addEventListener('click', function () { goDetail(String(e.id)); });
                grp.appendChild(row);
            });
        }
        document.querySelectorAll('.ds-hchip').forEach(function (c) {
            c.addEventListener('click', function () {
                histF = c.getAttribute('data-f');
                document.querySelectorAll('.ds-hchip').forEach(function (x) { x.classList.toggle('is-on', x === c); });
                renderHist();
            });
        });
        var hClr = document.getElementById('ds-hclear');
        if (hClr) hClr.addEventListener('click', function () {
            if (window.confirm('Clear all history on this device?')) { hSave([]); renderHist(); }
        });
        /* theo doi user-bar: luot thay doi (nhap key / cong luot) thi cap nhat menu + lich su */
        function watchUser() {
            var bar = document.querySelector('.user-bar');
            if (!bar || !window.MutationObserver) return;
            var tm = 0;
            new MutationObserver(function () {
                clearTimeout(tm);
                tm = setTimeout(function () {
                    trackTurns();
                    paintLock();
                    if (um && !um.hidden) paintUser();
                    if (root.classList.contains('ds-hist')) renderHist();
                }, 600);
            }).observe(bar, { subtree: true, childList: true, characterData: true });
        }

        /* ===== Chuyen man Home / Keys / Tools / History / Me / Detail ===== */
        var enteredDetail = false;
        function paint() {
            var c = root.classList, keys = c.contains('ds-keys'), tl = c.contains('ds-tools'), hs = c.contains('ds-hist'), me = c.contains('ds-me');
            nHome.classList.toggle('is-active', !keys && !tl && !hs && !me);
            nKeys.classList.toggle('is-active', keys);
            nTools.classList.toggle('is-active', tl);
            if (nHist) nHist.classList.toggle('is-active', hs);
            if (nMe) nMe.classList.toggle('is-active', me);
            paintLock();
        }
        function curView() {
            var c = root.classList;
            return c.contains('ds-tools') ? 'tools' : (c.contains('ds-keys') ? 'keys' : (c.contains('ds-hist') ? 'hist' : (c.contains('ds-me') ? 'me' : 'home')));
        }
        function applyView(v) {
            if (v === 'me' && IS_GUEST) v = 'home';
            root.classList.toggle('ds-keys', v === 'keys');
            root.classList.toggle('ds-tools', v === 'tools');
            root.classList.toggle('ds-hist', v === 'hist');
            root.classList.toggle('ds-me', v === 'me');
            if (v === 'hist') renderHist();
            if (v === 'me') paintUser();
        }
        function showDetail(id) {
            hidePkg();
            applyView('home');
            root.classList.add('ds-detail');
            document.querySelectorAll('.ds-det').forEach(function (d) { d.classList.toggle('is-on', d.getAttribute('data-tool') === String(id)); });
            paint();
            window.scrollTo(0, 0);
        }
        function hideDetail() {
            root.classList.remove('ds-detail');
            document.querySelectorAll('.ds-det.is-on').forEach(function (d) { d.classList.remove('is-on'); });
        }
        /* trang goi: Package 1 (VIP) / Package 2 (Free) / Package 3 (iOS) */
        function showPkg(n) {
            hideDetail();
            applyView('home');
            root.classList.add('ds-pkg');
            document.querySelectorAll('.ds-pkgpage').forEach(function (d) { d.classList.toggle('is-on', d.getAttribute('data-pkg') === String(n)); });
            paint();
            window.scrollTo(0, 0);
        }
        function hidePkg() {
            root.classList.remove('ds-pkg');
            document.querySelectorAll('.ds-pkgpage.is-on').forEach(function (d) { d.classList.remove('is-on'); });
        }
        function norm(t) {
            t = String(t || '');
            try { t = t.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (e) {}
            return t.replace(/[\u0111\u0110]/g, 'd').toLowerCase();
        }
        /* link rieng cua tool: ?devnvios=ID-ten-tool (van doc duoc link cu ?ds_tool=ID va #tool-ID) */
        function currentId() {
            var q = /[?&](?:devnvios|ds_tool)=(\d+)/.exec(window.location.search || '');
            if (q) return q[1];
            var m = /^#tool-(\d+)$/.exec(window.location.hash || '');
            return m ? m[1] : null;
        }
        function slugOf(name) {
            var t = String(name || '');
            try { t = t.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (e) {}
            return t.replace(/[\u0111\u0110]/g, 'd').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        }
        function toolUrl(id) {
            var d = document.getElementById('ds-det-' + id), slug = slugOf(d ? d.getAttribute('data-name') : '');
            return window.location.origin + window.location.pathname + '?devnvios=' + id + (slug ? '-' + slug : '');
        }
        function cleanUrl() {
            try {
                var u = new URL(window.location.href);
                u.searchParams.delete('devnvios'); u.searchParams.delete('ds_tool'); u.searchParams.delete('ds_s'); u.searchParams.delete('package');
                window.history.replaceState(null, '', u.pathname + u.search);
            } catch (e) {}
        }
        function currentPkg() {
            var q = /[?&]package=(\d+)/.exec(window.location.search || '');
            return q ? q[1] : null;
        }
        function pkgUrl(n) { return window.location.origin + window.location.pathname + '?package=' + n; }
        /* initial = lan mo trang dau tien: dung id do server/head script da chap san */
        function route(initial) {
            var id = (initial && window.__dsBoot) ? String(window.__dsBoot) : currentId();
            var pk = (initial && window.__dsPkg) ? String(window.__dsPkg) : currentPkg();
            if (id && document.getElementById('ds-det-' + id)) {
                showDetail(id);
                if (initial) { try { window.history.replaceState({ ds: 0 }, '', toolUrl(id)); } catch (e) {} }
            } else if (pk && document.getElementById('ds-pkgpage-' + pk)) {
                showPkg(pk);
                if (initial) { try { window.history.replaceState({ ds: 0 }, '', pkgUrl(pk)); } catch (e) {} }
            } else {
                var st = window.history.state, v = (st && st.view) ? st.view : ((root.classList.contains('ds-detail') || root.classList.contains('ds-pkg')) ? 'home' : curView());
                hideDetail();
                hidePkg();
                applyView(v);
                paint();
            }
            if (!initial) {
                var hs = window.history.state;
                if (hs && typeof hs.y === 'number') window.scrollTo(0, hs.y);
            }
            saveState();
        }
        function pushView(url, ds) {
            var cur = window.history.state || {};
            window.history.replaceState({ view: curView(), y: Math.round(window.pageYOffset || 0), ds: cur.ds || 0 }, '', window.location.href);
            window.history.pushState({ ds: ds }, '', url);
        }
        function goDetail(id) {
            rememberY();
            enteredDetail = true;
            try { pushView(toolUrl(id), 1); } catch (e) { window.location.hash = 'tool-' + id; }
            route(false);
        }
        function goPkg(n) {
            rememberY();
            enteredDetail = true;
            try { pushView(pkgUrl(n), 1); } catch (e) {}
            route(false);
        }
        /* v34: nho vi tri cuon cua tung man; bam View xem san pham roi bam Home / Keys / ... thi tro ve DUNG cho cu */
        var viewY = {};
        function inSub() { return root.classList.contains('ds-detail') || root.classList.contains('ds-pkg'); }
        function rememberY() {
            if (inSub()) return; /* dang o trang san pham / goi: giu nguyen vi tri cua man ben duoi */
            viewY[curView()] = Math.round(window.pageYOffset || document.documentElement.scrollTop || 0);
        }
        /* v36: quay lai TU TU. Tu trang san pham / goi bam Home / Keys / ...: noi dung mo dan (160ms) roi doi man, vi tri cuon cu duoc dat lai
           trong luc noi dung dang an, sau do hien len cham (0.75s, truot len nhe) nho lop ds-softin cua script ben duoi. */
        var REDUCE = false;
        try { REDUCE = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) {}
        var swapTk = 0;
        function fadeEls() {
            var raw = [document.querySelector('.page-top'), document.querySelector('.site-about'), document.querySelector('.site-footer')];
            return raw.filter(function (el) { return el && !raw.some(function (o) { return o && o !== el && o.contains(el); }); });
        }
        function cancelFade() {
            swapTk++;
            fadeEls().forEach(function (el) { el.classList.remove('ds-fadeout'); });
        }
        function gentle(fn) {
            var els = fadeEls();
            if (true || REDUCE || !els.length) { fn(); return; }
            var tk = ++swapTk;
            els.forEach(function (el) { el.classList.add('ds-fadeout'); });
            setTimeout(function () {
                if (tk !== swapTk) return;
                fn();
                els.forEach(function (el) { el.classList.remove('ds-fadeout'); });
            }, 90);
        }
        function setView(v, instant) {
            var same = !inSub() && curView() === v;
            var wasSub = inSub();
            cancelFade();
            rememberY();
            umOpen(false);
            /* v35: doi man => bo khoang trong danh cho ban phim + bo focus o nhap (chong ket "khoang trong cuc lon") */
            root.classList.remove('ds-typing');
            try { var ae = document.activeElement; if (ae && /^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName)) ae.blur(); } catch (x) {}
            function run() {
                if (v !== 'keys') hideNeedNow();
                cleanUrl();
                hideDetail();
                hidePkg();
                applyView(v);
                try { sessionStorage.setItem('ds-view', v); } catch (e) {}
                paint();
                if (same) {
                    /* bam lai tab dang o: cuon len dau trang mem mai */
                    if ((window.pageYOffset || 0) > 2) { try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (x) { window.scrollTo(0, 0); } }
                } else {
                    window.scrollTo(0, viewY[v] || 0);
                }
                saveState();
            }
            if (wasSub && !instant) gentle(run); else run();
        }
        nHome.addEventListener('click', function (e) { e.preventDefault(); setView('home'); });
        nKeys.addEventListener('click', function () { setView('keys'); });
        nTools.addEventListener('click', function () { setView('tools'); });
        if (nHist) nHist.addEventListener('click', function () { setView('hist'); });
        if (nMe) nMe.addEventListener('click', function () { setView('me'); });
        window.addEventListener('popstate', function () { route(false); });
        window.addEventListener('hashchange', function () { route(false); });

        /* loc All / VIP / Free o trang Tools (chi co khi bat DS_TOOLS_PAGE_LIST) */
        document.querySelectorAll('.ds-chip').forEach(function (c) {
            c.addEventListener('click', function () {
                var f = c.getAttribute('data-f');
                document.querySelectorAll('.ds-chip').forEach(function (x) { x.classList.toggle('is-on', x === c); });
                document.querySelectorAll('.ds-tlist .ds-row').forEach(function (r) { r.hidden = !(f === 'all' || r.getAttribute('data-type') === f); });
            });
        });

        /* nut View o danh sach va tool goi y => mo trang chi tiet */
        document.querySelectorAll('.ds-row').forEach(function (row) {
            row.querySelector('.ds-open').addEventListener('click', function () { goDetail(row.getAttribute('data-tool')); });
        });
        document.querySelectorAll('.ds-rel__card').forEach(function (card) {
            card.addEventListener('click', function () { goDetail(card.getAttribute('data-go')); });
        });

        /* Package: bam tieu de Package o Home => trang goi co o tim kiem + toan bo tool cua goi */
        document.querySelectorAll('.ds-pkgbtn').forEach(function (b) {
            b.addEventListener('click', function () { goPkg(b.getAttribute('data-pkg')); });
        });
        document.querySelectorAll('.ds-pkgpage').forEach(function (pg) {
            var inp = pg.querySelector('.ds-search__in'), rows = pg.querySelectorAll('.ds-row'), cnt = pg.querySelector('.ds-pkgcount'), empty = pg.querySelector('.ds-pkgempty');
            rows.forEach(function (r) { var t = r.querySelector('strong'); r.setAttribute('data-q', norm(t ? t.textContent : '')); });
            function apply() {
                var q = norm(inp.value).trim(), n = 0;
                rows.forEach(function (r) {
                    var hit = !q || r.getAttribute('data-q').indexOf(q) !== -1;
                    r.hidden = !hit;
                    if (hit) n++;
                });
                cnt.textContent = n + ' ' + (n === 1 ? TOOL_1 : TOOL_N);
                empty.hidden = n !== 0;
            }
            inp.addEventListener('input', apply);
            inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') inp.blur(); });
            /* nut Back cua trang goi chi co khi bat DS_SHOW_BACK */
            var pb = pg.querySelector('.ds-pkgback');
            if (pb) pb.addEventListener('click', function () {
                if (enteredDetail && window.history.state && window.history.state.ds) { window.history.back(); }
                else { cleanUrl(); route(false); }
            });
        });

        /* trang chi tiet: Back (neu bat), OPEN (dung nguyen logic cu), Share */
        document.querySelectorAll('.ds-det').forEach(function (det) {
            var id = det.getAttribute('data-tool');
            var more = det.querySelector('.ds-more'), fl = det.querySelector('.ds-feat');
            if (more && fl) more.addEventListener('click', function () {
                var c = fl.classList.toggle('is-collapsed');
                more.textContent = c ? 'See more \u25BE' : 'See less \u25B4';
            });
            var bk = det.querySelector('.ds-det__back');
            if (bk) bk.addEventListener('click', function () {
                if (enteredDetail && window.history.state && window.history.state.ds) { window.history.back(); }
                else { cleanUrl(); route(false); }
            });
            det.querySelector('.ds-get').addEventListener('click', function (ev) {
                if (IS_GUEST) return;
                var blk = planBlock(kindOf(det));
                if (blk) {
                    ev.preventDefault();
                    needKey(blk);
                    return;
                }
                var curTurns = dsCurrentTurns();
                var gs = gateState();
                /* N?u không có lu?t (<= 0) và không trong th?i gian m? l?i: chuy?n th?ng qua Keys ngay l?p t?c (0ms), không g?i menu gây do, không ghi l?ch s? */
                if (curTurns <= 0 && gs !== 'reopen') {
                    ev.preventDefault();
                    if (dsPend) { dsPend.failed = true; dsPendEnd(dsPend); }
                    needKey();
                    return;
                }
                if (gs === 'locked' && curTurns <= 0) {
                    ev.preventDefault();
                    if (dsPend) { dsPend.failed = true; dsPendEnd(dsPend); }
                    needKey();
                    return;
                }
                var link = document.querySelector('#menu [data-tool-id="' + id + '"]');
                if (!link) { needKey(); return; }
                if (link.classList.contains('is-busy')) return;
                if (link.classList.contains('is-locked') && curTurns <= 0) {
                    ev.preventDefault();
                    needKey();
                    return;
                }
                window.__dsGetAt = Date.now();
                window.__dsGetOk = false;
                window.clearTimeout(window.__dsHoldTm);
                dsVerifyOpen(id, curTurns > 0 ? 'fresh' : gs);
                dsWatchOpen();
                link.click();
            });
            var sh = det.querySelector('.ds-share');
            sh.addEventListener('click', function () {
                var url = toolUrl(id), text = (det.getAttribute('data-name') || document.title) + ' - ' + (det.getAttribute('data-kind') || '');
                if (navigator.share) { navigator.share({ text: text, url: url }).catch(function () {}); }
                else if (navigator.clipboard) { navigator.clipboard.writeText(text + ' ' + url).catch(function () { window.prompt('Copy link', text + ' ' + url); }); }
                else { window.prompt('Copy link', text + ' ' + url); }
            });
        });

        /* icon loi => dung icon du phong */
        document.querySelectorAll('.ds-imgfb').forEach(function (img) {
            img.addEventListener('error', function () {
                var f = 'https://cdn-icons-png.flaticon.com/512/2589/2589174.png';
                if (img.src !== f) img.src = f;
            });
        });

        /* trang thai khoa / dang xu ly cua menu cu duoc phan chieu sang danh sach va nut OPEN */
        function mirror() {
            document.querySelectorAll('.ds-row, .ds-det').forEach(function (row) {
                var link = document.querySelector('#menu [data-tool-id="' + row.getAttribute('data-tool') + '"]');
                if (!link) return;
                var locked = link.classList.contains('is-locked'), busy = link.classList.contains('is-busy');
                if (row.classList.contains('ds-row')) {
                    row.classList.toggle('is-locked', locked);
                    row.classList.toggle('is-busy', busy);
                } else {
                    var g = row.querySelector('.ds-get');
                    g.classList.toggle('is-locked', locked);
                    g.classList.toggle('is-busy', busy);
                }
            });
        }
        var list = menu.querySelector('.menu__tools');
        if (list) new MutationObserver(mirror).observe(list, { subtree: true, attributes: true, attributeFilter: ['class'] });
        mirror();

        /* luu trang thai (man dang xem, tool dang mo, vi tri cuon) de reload xong van o nguyen cho cu */
        var armed = false, saved = null;
        try { saved = JSON.parse(sessionStorage.getItem('ds-st') || 'null'); } catch (e) {}
        function detailId() { var d = document.querySelector('.ds-det.is-on'); return d ? d.getAttribute('data-tool') : ''; }
        function saveState() {
            if (!armed) return;
            try { sessionStorage.setItem('ds-st', JSON.stringify({ p: window.location.pathname, v: curView(), d: detailId(), y: Math.round(window.pageYOffset || 0), t: Date.now() })); } catch (e) {}
        }
        function arm() { if (armed) return; armed = true; saveState(); }
        window.addEventListener('pagehide', saveState);
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') saveState(); });
        /* reload luon ve dau trang: khong khoi phuc vi tri cuon cu */
        function restoreScroll() {
            var touched = false;
            function top() { if (!touched) window.scrollTo(0, 0); }
            function stop() { touched = true; arm(); }
            ['touchstart', 'wheel', 'mousedown', 'keydown'].forEach(function (ev) { window.addEventListener(ev, stop, { passive: true, once: true }); });
            top();
            setTimeout(top, 120);
            window.addEventListener('load', top, { once: true });
            setTimeout(arm, 800);
        }

        /* ===== Keo xuong de tai lai =====
           touchmove (khong-the-dong, can de chan cuon khi keo) CHI duoc gan khi ngon tay cham xuong luc dang o dau trang,
           va go ngay khi biet do khong phai cu keo-de-tai => cuon binh thuong khong bi giat. */
        (function () {
            var ptr = document.getElementById('ds-ptr');
            if (!ptr) return;
            var svg = ptr.querySelector('svg'), arc = ptr.querySelector('.ds-ptr__arc');
            var CIRC = 56.55, TH = 64, MAXD = 112, HOLD = 54;
            var y0 = 0, x0 = 0, dist = 0, active = false, tracking = false, busy = false, els = [];
            function atTop() { return (window.pageYOffset || document.documentElement.scrollTop || 0) <= 0; }
            function collect() {
                var cand = [], hdr = document.querySelector('.site-header');
                if (hdr) { var n = hdr.nextElementSibling; while (n) { cand.push(n); n = n.nextElementSibling; } }
                ['.user-bar', '.page-top', '.site-about', '.site-footer'].forEach(function (q) { var el = document.querySelector(q); if (el) cand.push(el); });
                var skip = /^(SCRIPT|STYLE|LINK|NOSCRIPT)$/;
                cand = cand.filter(function (el, i) {
                    if (skip.test(el.tagName) || el === ptr || cand.indexOf(el) !== i) return false;
                    if (el.classList.contains('ds-nav') || el.classList.contains('menu') || el.classList.contains('menu-overlay') || el.classList.contains('toast-anchor') || el.classList.contains('ds-um-ov')) return false;
                    var pos = ''; try { pos = window.getComputedStyle(el).position; } catch (e) {}
                    return pos !== 'fixed' && el.offsetHeight > 0;
                });
                els = cand.filter(function (a) { return !cand.some(function (b) { return b !== a && b.contains(a); }); });
            }
            function render(d, anim) {
                var tr = anim ? 'transform .24s cubic-bezier(.2, .8, .2, 1)' : 'none';
                els.forEach(function (el) { el.style.transition = tr; el.style.transform = d ? 'translate3d(0,' + d + 'px,0)' : ''; });
                var p = Math.min(1, d / TH);
                ptr.style.visibility = (d > 0 || busy) ? 'visible' : 'hidden';
                ptr.style.transition = anim ? 'transform .24s cubic-bezier(.2, .8, .2, 1), opacity .2s' : 'none';
                ptr.style.opacity = busy ? '1' : String(Math.min(1, d / 30));
                ptr.style.transform = 'translate3d(0,' + d + 'px,0) scale(' + (0.6 + 0.4 * p).toFixed(3) + ')';
                if (!busy) {
                    arc.style.strokeDashoffset = String((CIRC * (1 - 0.78 * p)).toFixed(2));
                    svg.style.transform = 'rotate(' + Math.round(d * 5) + 'deg)';
                }
            }
            function clearAll() {
                els.forEach(function (el) { el.style.transition = ''; el.style.transform = ''; });
                ptr.style.visibility = 'hidden'; ptr.style.opacity = '0'; ptr.style.transition = 'none';
            }
            function off() { document.removeEventListener('touchmove', onMove, false); }
            function onMove(e) {
                if (!tracking || busy) { off(); return; }
                var dy = e.touches[0].clientY - y0, dx = e.touches[0].clientX - x0;
                if (!active) {
                    if (dy < -4 || Math.abs(dx) > 12 && Math.abs(dx) > Math.abs(dy)) { tracking = false; off(); return; }
                    if (dy < 8) return;
                    if (!atTop()) { tracking = false; off(); return; }
                    collect(); active = true;
                }
                if (e.cancelable) e.preventDefault();
                dist = Math.min(MAXD, Math.max(0, dy - 8) * 0.5);
                render(dist, false);
            }
            document.addEventListener('touchstart', function (e) {
                off();
                tracking = false; active = false;
                if (busy || !e.touches || e.touches.length !== 1 || root.classList.contains('ds-typing')) return;
                var t = e.target;
                if (t && t.closest && t.closest('.ds-rel, .ds-prev, .ds-stats, .ds-slider, .ds-chips2, input, textarea, select, #menu, .menu, .ds-um, .ds-um-ov')) return;
                if (!atTop()) return;
                y0 = e.touches[0].clientY; x0 = e.touches[0].clientX; dist = 0; tracking = true;
                document.addEventListener('touchmove', onMove, { passive: false });
            }, { passive: true });
            function release() {
                off();
                tracking = false;
                if (!active) return;
                active = false;
                if (dist >= TH) {
                    busy = true;
                    svg.style.transform = ''; arc.style.strokeDashoffset = String((CIRC * 0.25).toFixed(2));
                    ptr.classList.add('is-spin');
                    render(HOLD, true);
                    setTimeout(function () { window.location.reload(); }, 450);
                } else {
                    render(0, true);
                    setTimeout(clearAll, 280);
                }
            }
            document.addEventListener('touchend', release, { passive: true });
            document.addEventListener('touchcancel', release, { passive: true });
        })();

        paintUser();
        trackTurns();
        route(true);
        paint();
        restoreScroll();
        watchUser();
        watchToast();
    }

    /* du main() co loi hay thoat som thi trang van duoc hien lai */
    try { main(); } finally { root.classList.add('ds-ready'); }
})();
</script>
<script>
/* v22: moi lan doi man (View, Home, Keys, Tools, History, Me, goi) noi dung hien dan len nhe nhang
   v36: lop ds-softin chay cham hon (0.75s), nen go lop sau 950ms */
(function () {
    var root = document.documentElement;
    var raw = [document.querySelector('.page-top'), document.querySelector('.site-about'), document.querySelector('.site-footer')];
    var els = raw.filter(function (el) { return el && !raw.some(function (o) { return o && o !== el && o.contains(el); }); });
    if (!els.length || !window.MutationObserver) return;
    var KEYS = ['ds-keys', 'ds-tools', 'ds-hist', 'ds-me', 'ds-detail', 'ds-pkg'];
    function sig() {
        var s = KEYS.filter(function (k) { return root.classList.contains(k); }).join(' ');
        var d = document.querySelector('.ds-det.is-on'), p = document.querySelector('.ds-pkgpage.is-on');
        return s + '|' + (d ? d.id : '') + '|' + (p ? p.id : '');
    }
    var last = sig(), timer = 0;
    function soft() {
        var now = sig();
        if (now === last) return;
        last = now;
        els.forEach(function (el) { el.classList.remove('ds-softin'); void el.offsetWidth; el.classList.add('ds-softin'); });
        clearTimeout(timer);
        timer = setTimeout(function () { els.forEach(function (el) { el.classList.remove('ds-softin'); }); }, 950);
    }
    var mo = new MutationObserver(soft);
    mo.observe(root, { attributes: true, attributeFilter: ['class'] });
    ['ds-detwrap', 'ds-pkgwrap'].forEach(function (id) {
        var w = document.getElementById(id);
        if (w) mo.observe(w, { attributes: true, attributeFilter: ['class'], subtree: true });
    });
})();
</script>
<script>
/* v23: lop nen mo trong suot cho khung dau trang san pham (lay tu anh icon tool) */
(function () {
    document.querySelectorAll('.ds-det__top').forEach(function (top) {
        if (top.querySelector('.ds-det__bg')) return;
        var im = top.querySelector('.ds-det__head img');
        if (!im) return;
        var bg = document.createElement('span');
        bg.className = 'ds-det__bg';
        bg.setAttribute('aria-hidden', 'true');
        function set() {
            var u = im.currentSrc || im.src;
            if (u) bg.style.backgroundImage = 'url("' + String(u).replace(/"/g, '%22') + '")';
        }
        set();
        im.addEventListener('load', set);
        im.addEventListener('error', set);
        top.insertBefore(bg, top.firstChild);
    });
})();
</script>
<script>
/* v24: khoa cung khung, cuon muot + bo khoang trong thua tren khung san pham
   v30: class "ds-scrolling" gan tren HEADER (khong gan len <html> nua) => luc bat dau / ket thuc cuon
        trinh duyet chi tinh lai style cua header, khong phai tinh lai ca trang => het giat khi cuon xuong / cuon len */
(function () {
    var root = document.documentElement, st = 0, on = false, hdr = null;
    function target() { if (!hdr) hdr = document.querySelector('.site-header'); return hdr || root; }
    window.addEventListener('scroll', function () {
        /* v33: KHONG bat/tat class tren header khi cuon nua (moi lan bat/tat la header bi ve lai => khung khung khuc, hoa de len). Chi ghi co bao. */
        window.__dsScrolling = true;
        clearTimeout(st);
        st = setTimeout(function () { window.__dsScrolling = false; }, 140);
    }, { passive: true });

    function fixGap() {
        var hd = document.querySelector('.site-header');
        document.querySelectorAll('.ds-det__top').forEach(function (el) { el.style.marginTop = ''; });
        var top = document.querySelector('.ds-det.is-on .ds-det__top');
        if (!hd || !top || !root.classList.contains('ds-detail')) return;
        var gap = top.getBoundingClientRect().top + (window.pageYOffset || 0) - hd.offsetHeight;
        if (gap > 1 && gap < 400) top.style.marginTop = (-gap) + 'px';
    }
    function later() {
        requestAnimationFrame(function () { requestAnimationFrame(fixGap); });
        setTimeout(fixGap, 160);
    }
    if (window.MutationObserver) {
        var mo = new MutationObserver(later);
        mo.observe(root, { attributes: true, attributeFilter: ['class'] });
        var w = document.getElementById('ds-detwrap');
        if (w) mo.observe(w, { attributes: true, attributeFilter: ['class'], subtree: true });
    }
    window.addEventListener('load', later);
    window.addEventListener('resize', later);
    window.addEventListener('orientationchange', later);
    later();
})();
</script>
<script>
/* v25 */
(function () {
    /* 1) OPEN -> GET (co bieu tuong tai) */
    var DL = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 21h14"/></svg>';
    document.querySelectorAll('.ds-get').forEach(function (b) { b.innerHTML = DL + 'GET'; });
    document.querySelectorAll('.ds-det__txt').forEach(function (p) {
        if (!p.children.length && /tap OPEN again/.test(p.textContent)) p.textContent = p.textContent.replace(/tap OPEN again/g, 'tap GET again');
    });

    /* 2) chuc nang: chi hien 3 dong dau, bam more moi hien them */
    document.querySelectorAll('.ds-det').forEach(function (det) {
        var ul = det.querySelector('.ds-feat');
        if (!ul || ul.getAttribute('data-ds-more')) return;
        ul.setAttribute('data-ds-more', '1');
        if (ul.children.length <= 3) return;
        ul.classList.add('is-collapsed');
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ds-more';
        b.textContent = 'more \u25BE';
        ul.after(b);
        b.addEventListener('click', function () {
            var c = ul.classList.toggle('is-collapsed');
            b.textContent = c ? 'more \u25BE' : 'less \u25B4';
        });
    });

    /* 3) bam logo: tai lai ca web, ve thang trang chu */
    var FRESH = false;
    function purge() {
        if (!FRESH) return;
        try { sessionStorage.removeItem('ds-st'); sessionStorage.removeItem('ds-view'); } catch (e) {}
    }
    window.addEventListener('pagehide', purge);
    document.addEventListener('visibilitychange', purge);
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest ? e.target.closest('.site-header .brand, .site-header .ds-logo, .site-header .brand__logo') : null;
        if (!t) return;
        e.preventDefault();
        var a = t.closest('a'), href = (a && a.getAttribute('href')) || '/';
        if (href.charAt(0) === '#' || /^javascript:/i.test(href)) href = '/';
        FRESH = true;
        try { sessionStorage.removeItem('ds-st'); sessionStorage.removeItem('ds-view'); sessionStorage.removeItem('ds-quiet'); } catch (x) {}
        var same = false;
        try { same = new URL(href, window.location.href).href === window.location.href; } catch (y) {}
        if (same) window.location.reload(); else window.location.href = href;
    }, true);

    /* 4) nhap key / hien thong bao: lan tai lai ke tiep khong hien vong tron nap */
    function quiet() { try { sessionStorage.setItem('ds-quiet', String(Date.now())); } catch (e) {} }
    document.addEventListener('submit', quiet, true);
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('.page-top > section.card .btn') : null;
        if (b) quiet();
    }, true);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target && e.target.classList && e.target.classList.contains('field')) quiet();
    }, true);
    var ta = document.querySelector('.toast-anchor');
    if (ta && window.MutationObserver) {
        new MutationObserver(function () {
            var t = ta.querySelector('.toast');
            if (t && t.offsetHeight > 0 && (t.textContent || '').replace(/\s+/g, '') !== '') quiet();
        }).observe(ta, { childList: true, subtree: true, attributes: true, characterData: true });
    }
})();
</script>
<script>
/* v31: trang chu kieu shopdsgm.vn: dat khung hero vao cho banner cu, banner anh tu chuyen, hang the nho tu chay + keo duoc */
(function () {
    var hero = document.getElementById('ds-hero');
    if (!hero) return;
    var top = document.querySelector('.page-top');
    var banner = document.querySelector('.page-top .banner') || document.querySelector('.banner');
    if (banner && banner.parentNode) banner.parentNode.insertBefore(hero, banner);
    else if (top) top.insertBefore(hero, top.firstChild);
    else return;
    hero.classList.add('is-on');

    var hdr = document.querySelector('.site-header');
    function pageScrolling() { return !!window.__dsScrolling || !!(hdr && hdr.classList.contains('ds-scrolling')); }

    /* ---- v32: nhich ca khung IPA - TOOLS len sat header ----
       Do khoang cach that tu day header toi dau khung (tinh ca padding / margin cua index.php) roi keo len con DS_HERO_TOP px.
       Chay lai khi doi man (hero chi do duoc khi dang hien), khi doi kich thuoc, khi font / anh nap xong.
       v35: DO BANG BO CUC (offsetTop, khong phu thuoc vi tri cuon) thay vi getBoundingClientRect. Truoc day khi cuon cham day trang
       (iPhone nay / bat lai thanh Safari) toa do man hinh luc do bi lech => tinh sai => khung IPA - TOOLS bi day len roi keo xuong.
       Gio: khong do trong luc dang cuon; do xong thi on dinh, khong con thay doi nua. */
    var rootEl = document.documentElement;
    var HERO_TOP = parseInt(hero.getAttribute('data-top'), 10);
    if (isNaN(HERO_TOP)) HERO_TOP = 22;
    var curM = 0, lastW = window.innerWidth, ftT = 0;
    function docTop(el) { var y = 0; while (el) { y += el.offsetTop || 0; el = el.offsetParent; } return y; }
    function fixTop() {
        if (!hdr || hero.offsetParent === null || rootEl.classList.contains('ds-typing')) return;
        if (window.__dsScrolling) { fixTopLater(); return; } /* dang cuon => do lai sau khi dung cuon */
        var pt = hero.parentNode;
        if (pt && pt.style && pt.style.transform) return; /* dang keo de tai lai */
        var gap = docTop(hero) - hdr.offsetHeight;
        var d = HERO_TOP - gap;
        if (!isFinite(d) || Math.abs(d) < 0.5) return;
        var next = (parseFloat(hero.style.marginTop) || 0) + d;
        if (Math.abs(next) > 240) return;
        curM = next;
        hero.style.marginTop = next.toFixed(1) + 'px';
    }
    function fixTopLater() {
        clearTimeout(ftT);
        ftT = setTimeout(function () { requestAnimationFrame(fixTop); }, 140);
    }
    fixTop();
    setTimeout(fixTop, 120);
    window.addEventListener('load', fixTopLater, { once: true });
    /* tren iPhone thanh Safari an / hien cung ban su kien resize lien tuc => chi lam lai khi DO RONG doi that su (xoay man hinh) */
    window.addEventListener('resize', function () { if (window.innerWidth !== lastW) { lastW = window.innerWidth; fixTopLater(); } });
    window.addEventListener('orientationchange', fixTopLater);
    if (window.MutationObserver) new MutationObserver(fixTopLater).observe(rootEl, { attributes: true, attributeFilter: ['class'] });

    /* ---- v32: anh banner hien NGAY ----
       Anh dau da duoc tai san o <head>. O day: bao cho man hinh nap (window.__dsHeroWait) cho anh dau tai + giai ma xong roi moi dong vong tron,
       roi moi tai anh 2, 3 (de khong tranh bang thong voi anh dau). Man hinh khac (chi tiet tool, goi, Keys...) thi khong can cho.
       v37: lay ti le THAT cua anh dau (--ds-ar) de khung banner khong cat chu 2 ben (object-fit: contain, xem CSS v37). */
    (function () {
        var im0 = hero.querySelector('.ds-slide img');
        var onHome = !['ds-keys', 'ds-tools', 'ds-hist', 'ds-me', 'ds-detail', 'ds-pkg'].some(function (c) { return rootEl.classList.contains(c); });
        var rest = hero.querySelectorAll('img[data-src]'), restDone = false;
        var slEl = document.getElementById('ds-slider');
        function setAR() {
            if (!im0 || !slEl || !im0.naturalWidth || !im0.naturalHeight) return;
            slEl.style.setProperty('--ds-ar', im0.naturalWidth + ' / ' + im0.naturalHeight);
        }
        if (im0) { im0.addEventListener('load', setAR); setAR(); }
        function loadRest() {
            if (restDone) return;
            restDone = true;
            Array.prototype.forEach.call(rest, function (im) { var s = im.getAttribute('data-src'); if (s) { im.removeAttribute('data-src'); im.src = s; } });
        }
        function done() { setAR(); window.__dsHeroWait = false; loadRest(); }
        if (!im0 || !onHome) { window.__dsHeroWait = false; setTimeout(loadRest, 1500); return; }
        function decoded() { if (im0.decode) { im0.decode().then(done, done); } else { done(); } }
        if (im0.complete && im0.naturalWidth) decoded();
        else if (im0.complete) done();
        else { im0.addEventListener('load', decoded, { once: true }); im0.addEventListener('error', done, { once: true }); }
        setTimeout(loadRest, 3000);
    })();

    /* ---- banner anh: tu chuyen sau N giay, vuot qua lai chuyen nhanh, vong lap ---- */
    (function () {
        var sl = document.getElementById('ds-slider');
        if (!sl) return;
        var tr = sl.querySelector('.ds-slider__track'), box = sl.querySelector('.ds-slider__dots');
        var n = tr.children.length;
        if (n < 1) return;
        var SEC = Math.max(2000, (parseFloat(hero.getAttribute('data-sec')) || 5) * 1000);
        if (n > 1) {
            var cl = tr.children[0].cloneNode(true);
            cl.setAttribute('aria-hidden', 'true');
            tr.appendChild(cl);
        }
        var dots = [];
        if (n > 1) {
            for (var i = 0; i < n; i++) { var d = document.createElement('i'); d.className = 'ds-dot'; box.appendChild(d); dots.push(d); }
        } else { box.style.display = 'none'; }
        var idx = 0, touching = false, timer = 0, jumpT = 0, raf = 0;
        function w() { return tr.clientWidth; }
        function paintDots() { var k = idx % n; for (var j = 0; j < dots.length; j++) dots[j].classList.toggle('is-on', j === k); }
        function onScroll() {
            if (raf) return;
            raf = requestAnimationFrame(function () {
                raf = 0;
                var ww = w();
                if (!ww) return;
                idx = Math.round(tr.scrollLeft / ww);
                paintDots();
                clearTimeout(jumpT);
                if (n > 1 && idx >= n) {
                    jumpT = setTimeout(function () {
                        if (touching) return;
                        tr.style.scrollSnapType = 'none';
                        tr.scrollLeft = 0;
                        idx = 0;
                        paintDots();
                        requestAnimationFrame(function () { tr.style.scrollSnapType = ''; });
                    }, 140);
                }
            });
        }
        function go(i) { var ww = w(); if (!ww) return; try { tr.scrollTo({ left: i * ww, behavior: 'smooth' }); } catch (e) { tr.scrollLeft = i * ww; } }
        function tick() {
            if (n < 2 || touching || document.hidden || !w() || pageScrolling()) return;
            go(idx + 1);
        }
        function start() { clearInterval(timer); if (n > 1) timer = setInterval(tick, SEC); }
        function sync() {
            var ww = w();
            if (!ww) return;
            /* v32: da dung cho roi thi khong dung vao (tranh doi snap + ep bo cuc luc dang cuon trang len) */
            if (Math.abs(tr.scrollLeft - (idx % n) * ww) < 1.5) return;
            tr.style.scrollSnapType = 'none';
            tr.scrollLeft = (idx % n) * ww;
            requestAnimationFrame(function () { tr.style.scrollSnapType = ''; });
        }
        tr.addEventListener('scroll', onScroll, { passive: true });
        function down() { touching = true; clearInterval(timer); }
        function up() { touching = false; start(); setTimeout(onScroll, 220); }
        tr.addEventListener('touchstart', down, { passive: true });
        tr.addEventListener('touchend', up, { passive: true });
        tr.addEventListener('touchcancel', up, { passive: true });
        tr.addEventListener('mousedown', down);
        window.addEventListener('mouseup', function () { if (touching) up(); });
        /* v32: chi canh lai khi do rong banner doi that su (thanh Safari an / hien ban resize lien tuc, khong can canh lai) */
        var lastSW = w();
        window.addEventListener('resize', function () { var ww = w(); if (ww !== lastSW) { lastSW = ww; sync(); } });
        document.addEventListener('visibilitychange', function () { if (!document.hidden) start(); });
        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (en) { if (en[0].isIntersecting) sync(); }).observe(sl);
        }
        paintDots();
        start();
    })();

    /* ---- v32: hang the nho tu chay ngang bang CSS (xem .ds-chips2__track), KHONG con keo qua keo lai.
       (Code keo cu da bo.) Day chi la bo tam dung khi hang the khong nam tren man hinh => tiet kiem pin. ---- */
    (function () {
        var vp = document.getElementById('ds-chips2');
        if (!vp || !('IntersectionObserver' in window)) return;
        new IntersectionObserver(function (en) {
            vp.classList.toggle('is-off', !en[0].isIntersecting);
        }).observe(vp);
    })();
})();
</script>
<script>
/* v33: do chieu cao that cua thanh tab de cuoi trang khong con khoang trong thua
   v36: chi cap nhat khi chieu cao doi hon 2px va KHONG trong luc dang cuon (khong con nhay chieu cao trang khi dang cuon toi day)
   v37: CSS cuoi trang nay dung khoang trong CO DINH (xem khoi v37), so do duoc o day khong con anh huong chieu cao trang. */
(function () {
    var nav = document.querySelector('.ds-nav'), root = document.documentElement, last = 0;
    if (!nav) return;
    function m() {
        if (window.__dsScrolling) { setTimeout(m, 220); return; }
        var h = Math.round(nav.getBoundingClientRect().height);
        if (h > 20 && h < 200 && Math.abs(h - last) > 2) { last = h; root.style.setProperty('--ds-nav-real', h + 'px'); }
    }
    m();
    window.addEventListener('load', m, { once: true });
    setTimeout(m, 300);
    var lw = window.innerWidth;
    window.addEventListener('resize', function () { if (window.innerWidth !== lw) { lw = window.innerWidth; m(); } });
    window.addEventListener('orientationchange', function () { setTimeout(m, 200); });
})();
</script>
<script>
/* v36: BO cai chan keo nay (rubber band) o day trang cua v35.
   Cai chan do goi preventDefault tren touchmove ngay khi cham o day trang => iOS phai doi luong chinh xu ly cuon, lam khung khung khuc / cuon 2 lan o day.
   Thay bang CSS: html, body { overscroll-behavior-y: none } (da co o khoi v21) + dat lai bang JS cho chac. Cac vung cuon ngang
   (banner, the, anh xem truoc...) va o nhap van dung binh thuong. */
(function () {
    try {
        document.documentElement.style.overscrollBehaviorY = 'none';
        if (document.body) document.body.style.overscrollBehaviorY = 'none';
    } catch (e) {}
})();
</script>

<?php if (!(defined('DS_SCROLL_SHELL') && !DS_SCROLL_SHELL)): ?>
<script>
/* v38: khung cuon rieng. Chuyen noi dung trang vao 1 khung cuon (#ds-scroll) de thanh Safari khong con an / hien khi cuon
   (chinh viec do lam chieu cao man hinh nhay => bat len xuong o day trang). Toan bo code cu doc / dat vi tri cuon bang
   window.pageYOffset / window.scrollTo => duoc chuyen huong sang khung moi, khong phai sua gi them. */
(function () {
    try {
        var root = document.documentElement, body = document.body;
        if (!body || document.getElementById('ds-scroll')) return;
        var sc = document.createElement('div');
        sc.id = 'ds-scroll';

        var SKIP_TAG = /^(SCRIPT|STYLE|LINK|NOSCRIPT|TEMPLATE|META)$/;
        var SKIP_CLS = ['site-header', 'ds-nav', 'menu', 'menu-overlay', 'toast-anchor', 'ds-um-ov', 'ds-ptr'];
        function stays(el) {
            if (el.nodeType !== 1 || SKIP_TAG.test(el.tagName) || el.id === 'ds-boot') return true;
            for (var i = 0; i < SKIP_CLS.length; i++) if (el.classList.contains(SKIP_CLS[i])) return true;
            var pos = '';
            try { pos = window.getComputedStyle(el).position; } catch (e) {}
            return pos === 'fixed';
        }
        var kids = Array.prototype.slice.call(body.childNodes), first = null, n = 0;
        kids.forEach(function (el) {
            if (el.nodeType === 3 && !/\S/.test(el.nodeValue)) return;
            if (el.nodeType !== 1 || stays(el)) return;
            if (!first) { first = el; body.insertBefore(sc, el); }
            sc.appendChild(el); n++;
        });
        if (!n) { if (sc.parentNode) sc.parentNode.removeChild(sc); return; }

        /* chuyen huong vi tri / lenh cuon cua trang sang khung moi */
        function getY() { return sc.scrollTop || 0; }
        try { Object.defineProperty(window, 'pageYOffset', { configurable: true, get: getY }); } catch (e) {}
        try { Object.defineProperty(window, 'scrollY', { configurable: true, get: getY }); } catch (e) {}
        window.scrollTo = function (a, b) {
            var t = 0, smooth = false;
            if (a && typeof a === 'object') { t = a.top || 0; smooth = a.behavior === 'smooth'; } else { t = b || 0; }
            try { sc.scrollTo({ top: t, behavior: smooth ? 'smooth' : 'auto' }); } catch (e) { sc.scrollTop = t; }
        };
        window.scroll = window.scrollTo;
        /* cac bo lang nghe "scroll" cua window cu van nhan duoc su kien */
        var fire = 0;
        sc.addEventListener('scroll', function () {
            if (fire) return;
            fire = requestAnimationFrame(function () { fire = 0; try { window.dispatchEvent(new Event('scroll')); } catch (e) {} });
        }, { passive: true });

        root.classList.add('ds-shell');
        sc.scrollTop = 0;
    } catch (e) {
        /* co loi gi cung khong lam hong trang: go khung va quay ve cach cuon cu */
        try {
            var s = document.getElementById('ds-scroll');
            if (s) { while (s.firstChild) s.parentNode.insertBefore(s.firstChild, s); s.parentNode.removeChild(s); }
            document.documentElement.classList.remove('ds-shell');
        } catch (x) {}
    }
})();
</script>
<?php endif; ?>

<?php devnvios_theme_v39(); dsx_out(); dsx_text_flush(); }

//V39-BEGIN
function devnvios_theme_v39(): void
{
    ?>
<style id="ds-patch-v25">
    /* v41 (patch_v25 ban cuoi): !important + do uu tien cao de khong bi cac khoi CSS cu de len */
    :root:root { --ds-end-gap: 20px; --ds-w41: #fff; }
    :root:root[data-theme="light"] { --ds-w41: #000; }

    /* ---- San pham: icon 56, ten 14, mo ta 12.5, nut View 66x28 ---- */
    html:root:root:root .ds-row { gap: 15px !important; padding: 12px 0 !important; }
    html:root:root:root .ds-row img { width: 56px !important; height: 56px !important; border-radius: 13px !important; }
    html:root:root:root .ds-row__info strong { font-size: 14px !important; font-weight: 700 !important; line-height: 1.3 !important; }
    html:root:root:root .ds-row__info span { margin-top: 1px !important; font-size: 12.5px !important; line-height: 1.3 !important; }
    html:root:root:root:not([data-theme="light"]) .ds-row__info span:not(.is-vip) { color: #909095 !important; }
    html:root:root:root .ds-open { box-sizing: border-box !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; min-width: 66px !important; height: 28px !important; padding: 0 14px !important; font-size: 13.5px !important; font-weight: 700 !important; line-height: 1 !important; border-radius: 999px !important; }
    html:root:root:root:not([data-theme="light"]) .ds-open { color: #5a98ef !important; background: #05162a !important; }

    /* ---- Package 1 / 2 / 3 ngoai trang chu ---- */
    html:root:root:root .ds-products .ds-sec__title { margin: 24px 0 3px !important; font-size: 18px !important; font-weight: 700 !important; line-height: 1.25 !important; letter-spacing: -.01em !important; }
    html:root:root:root .ds-products .ds-sec__sub { margin: 0 0 19px !important; font-size: 14px !important; line-height: 1.4 !important; }
    html:root:root:root:not([data-theme="light"]) .ds-products .ds-sec__sub { color: #909095 !important; }
    html:root:root:root .ds-pkgbtn { gap: 9px !important; }
    html:root:root:root .ds-pkgbtn svg { width: 15px !important; height: 15px !important; }
    html:root:root:root .ds-pkgtitle { font-size: 30px !important; }
    html:root:root:root .ds-pkgsub { font-size: 14px !important; }

    /* ---- Trang chi tiet san pham ---- */
    html:root:root:root .ds-det__top { padding: 18px 16px 20px !important; }
    html:root:root:root .ds-det__head { gap: 14px !important; }
    html:root:root:root .ds-det__head img { width: 80px !important; height: 80px !important; border-radius: 19px !important; }
    html:root:root:root .ds-det__main h1 { font-size: 21px !important; }
    html:root:root:root .ds-det__main p { margin: 2px 0 10px !important; font-size: 13.5px !important; }
    html:root:root:root .ds-get { height: 31px !important; padding: 0 18px !important; font-size: 13px !important; letter-spacing: .03em !important; }
    html:root:root:root .ds-get svg { width: 15px !important; height: 15px !important; margin-right: 5px !important; }
    html:root:root:root .ds-share { width: 31px !important; height: 31px !important; }
    html:root:root:root .ds-share svg { width: 15px !important; height: 15px !important; }

    /* ===== KHOA VIP / FREE =====
       Key Free  => san pham VIP xam, chi bam duoc View, GET khong bam duoc.
       Key VIP   => san pham Free xam, chi bam duoc View, GET khong bam duoc. */
    html:root:root:root .ds-row.ds-gray img, html:root:root:root .ds-rel__card.ds-gray img { -webkit-filter: grayscale(1) !important; filter: grayscale(1) !important; opacity: .45 !important; }
    html:root:root:root .ds-gray .ds-row__info strong, html:root:root:root .ds-gray .ds-rel__info strong { color: var(--ds-p) !important; }
    html:root:root:root .ds-gray .ds-row__info span, html:root:root:root .ds-gray .ds-rel__info span { color: var(--ds-p) !important; opacity: .8; }
    html:root:root:root .ds-get.is-mismatch, html:root:root:root .ds-get[disabled] { color: #8e8e93 !important; background: #2c2c2e !important; opacity: 1 !important; pointer-events: none !important; cursor: default !important; }
    html:root:root:root[data-theme="light"] .ds-get.is-mismatch, html:root:root:root[data-theme="light"] .ds-get[disabled] { color: #6b7280 !important; background: #e5e7eb !important; }

    /* ===== Phan cuoi trang ===== */
    html:root:root:root .site-about,
    html:root:root:root.ds-keys .site-about, html:root:root:root.ds-tools .site-about,
    html:root:root:root.ds-hist .site-about, html:root:root:root.ds-me .site-about { margin-top: 46px !important; padding-top: 26px !important; padding-bottom: 0 !important; }
    html:root:root:root .ds-docs { margin: 0 0 26px !important; }
    html:root:root:root .ds-docs__blk { margin: 0 0 22px !important; }
    html:root:root:root .ds-docs__h { gap: 8px !important; margin: 0 0 10px !important; font-size: 14.5px !important; font-weight: 700 !important; letter-spacing: .03em !important; line-height: 1.25 !important; color: var(--ds-h) !important; }
    html:root:root:root .ds-docs__h svg { width: 17px !important; height: 17px !important; }
    html:root:root:root .ds-docs__h--brand { margin-bottom: 8px !important; font-size: 18px !important; letter-spacing: -.01em !important; }
    html:root:root:root .ds-docs__p { font-size: 14px !important; font-weight: 400 !important; line-height: 1.45 !important; }
    html:root:root:root:not([data-theme="light"]) .ds-docs__p, html:root:root:root:not([data-theme="light"]) .ds-docs .ds-doclink { color: #8e8e93 !important; }
    html:root:root:root .ds-docs > .ds-docs__h { margin: 0 0 6px !important; }
    html:root:root:root .ds-docs .ds-doclink { padding: 6px 0 !important; font-size: 14px !important; font-weight: 400 !important; line-height: 1.3 !important; }
    html:root:root:root .ds-docs .ds-doclink:last-child { padding-bottom: 0 !important; }
    html:root:root:root .site-footer { margin: 0 !important; padding: 22px 0 0 !important; line-height: 1.3 !important; }
    html:root:root:root .site-footer, html:root:root:root .site-footer p, html:root:root:root .site-footer a, html:root:root:root .site-footer span { font-size: 11.5px !important; }
    html:root:root:root .site-footer p { margin: 0 !important; padding: 0 !important; line-height: 1.35 !important; }
    html:root:root:root .site-footer p + p { margin-top: 2px !important; }
    html.ds-shell.ds-shell.ds-shell body { padding: 0 !important; }
    html.ds-shell.ds-shell.ds-shell #ds-scroll { padding-bottom: calc(var(--ds-nav-real, var(--nav-h)) + var(--ds-end-gap)) !important; }
    html.ds-shell.ds-shell.ds-shell.ds-typing #ds-scroll { padding-bottom: 46vh !important; }

    /* ===== Thong bao: CHI 1 o thong bao. "This tool closed" va toast dung chung 1 cho, cai moi thay ngay cai cu ===== */
    html:root:root:root .ds-need { overflow: hidden; }
    html:root:root:root .ds-need.is-in { animation: ds-need-in40 .3s cubic-bezier(.22, .61, .36, 1) both !important; }
    html:root:root:root .ds-need.is-out { animation: ds-need-out40 .3s cubic-bezier(.4, 0, .2, 1) forwards !important; }
    @keyframes ds-need-in40 { from { opacity: 0; max-height: 0; margin-top: 0; } to { opacity: 1; max-height: 70px; margin-top: 10px; } }
    @keyframes ds-need-out40 { from { opacity: 1; max-height: 70px; margin-top: 10px; } to { opacity: 0; max-height: 0; margin-top: 0; } }
    #ds-flash { position: fixed; left: 50%; bottom: calc(var(--ds-nav-real, 66px) + 18px); z-index: 1300; padding: 9px 16px; font-size: 13px; font-weight: 700; color: var(--ds-h); white-space: nowrap; pointer-events: none; background: var(--ds-field); border: 1px solid var(--ds-line2); border-radius: 999px; opacity: 0; -webkit-transform: translate3d(-50%, 10px, 0); transform: translate3d(-50%, 10px, 0); transition: opacity .3s ease, transform .3s cubic-bezier(.22, .61, .36, 1); }
    #ds-flash.is-on { opacity: 1; -webkit-transform: translate3d(-50%, 0, 0); transform: translate3d(-50%, 0, 0); }
    @media (prefers-reduced-motion: reduce) {
        html:root:root:root .ds-need.is-in, html:root:root:root .ds-need.is-out { animation: none !important; }
        #ds-flash { transition: none; }
    }

    /* ===== Doi man =====
       Keys / History / Me / chi tiet: hien dan 0.2 giay (nhu cu).
       VE HOME: tung phan tu dang nam tren man hinh (tieu de, banner, hang the, Package, tung san pham) hien dan len NHE + NHANH,
       moi phan tu tre them ~26ms (xem script "ve Home" ben duoi). Khong lam mo ca khung trang => khong khung. */
    @keyframes ds-fade40 { from { opacity: .4; } to { opacity: 1; } }
    @keyframes ds-back41 { from { opacity: 0; transform: translate3d(0, 12px, 0); } to { opacity: 1; transform: none; } }
    html:root:root:root .ds-softin { animation: none !important; }
    html:root:root:root.ds-keys .ds-softin, html:root:root:root.ds-hist .ds-softin, html:root:root:root.ds-me .ds-softin, html:root:root:root.ds-detail .ds-softin { animation: ds-fade40 .2s ease-out both !important; }
    html:root:root:root .ds-back { animation: ds-back41 .42s cubic-bezier(.22, .61, .36, 1) both !important; animation-delay: calc(var(--ds-i, 0) * 26ms) !important; }
    html:root:root:root .ds-fadeout { opacity: 1 !important; transition: none !important; }
    @media (prefers-reduced-motion: reduce) { html:root:root:root .ds-softin, html:root:root:root .ds-back { animation: none !important; } }

    /* ===== MAU CHU: toi = trang, sang = den =====
       Lich su (+1 turn, -1 turn, so thong ke), Me (Member / VIP / Free, Active), menu avatar (Hello, ten, Member / VIP / Free) */
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__amt,
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__amt.is-in,
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__amt.is-out,
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__amt.is-zero,
    html:root:root:root .ds-histpage .ds-hstat b,
    html:root:root:root .ds-histpage .ds-hstat b.is-in,
    html:root:root:root .ds-histpage .ds-hstat b.is-out,
    html:root:root:root .ds-histpage .ds-badge,
    html:root:root:root .ds-um__row b,
    html:root:root:root .ds-mepage .ds-badge,
    html:root:root:root .ds-mepage .ds-badge.is-vip,
    html:root:root:root .ds-mepage .ds-badge.is-ok,
    html:root:root:root .ds-um__hello,
    html:root:root:root .ds-um__name,
    html:root:root:root .ds-um__who .ds-badge,
    html:root:root:root .ds-um__who .ds-badge.is-vip { color: var(--ds-w41) !important; }

    /* ===== HISTORY kieu Receipts & Orders (giong shopdsgm.vn), phang, khong phat sang, khong do bong ===== */
    html:root:root:root .ds-histpage [hidden] { display: none !important; }
    html:root:root:root .ds-histpage, html:root:root:root .ds-histpage *, html:root:root:root .ds-toolspage, html:root:root:root .ds-toolspage * { text-shadow: none !important; }
    html:root:root:root .ds-kick { margin: 18px 0 6px; font-size: 12.5px; font-weight: 800; letter-spacing: .17em; text-transform: uppercase; color: var(--blue); }
    html:root:root:root .ds-histpage .ds-hhead { margin-top: 0 !important; align-items: center; }
    html:root:root:root .ds-histpage .ds-pkgtitle, html:root:root:root .ds-toolspage .ds-pkgtitle { margin: 0 !important; font-size: 30px !important; font-weight: 800 !important; letter-spacing: -.03em !important; line-height: 1.15 !important; }
    html:root:root:root .ds-histpage > .ds-pkgsub { display: none !important; }
    html:root:root:root .ds-histpage .ds-hsearch { height: 42px; margin: 16px 0 0; border-radius: 14px; }
    html:root:root:root .ds-histpage .ds-hrule { height: 1px; margin: 20px 0; background: var(--ds-line2); }
    html:root:root:root .ds-histpage .ds-hstat { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 0 0 14px; padding: 0; overflow: visible; background: transparent; border: 0; border-radius: 0; }
    html:root:root:root .ds-histpage .ds-hstat > div, html:root:root:root .ds-histpage .ds-hstat > div:first-child { display: flex; flex-direction: column-reverse; gap: 3px; padding: 11px 13px 12px; background: var(--ds-card); border: 1px solid var(--ds-line); border-radius: 16px; }
    html:root:root:root .ds-histpage .ds-hstat span { margin: 0; font-size: 11px; font-weight: 600; letter-spacing: .07em; text-transform: uppercase; color: var(--ds-p); }
    html:root:root:root .ds-histpage .ds-hstat b { font-size: 20px; font-weight: 700; line-height: 1.15; letter-spacing: -.01em; }
    html:root:root:root .ds-histpage .ds-seg, html:root:root:root .ds-toolspage .ds-chips { margin: 0 0 4px; padding: 3px; background: var(--ds-field); border-radius: 14px; gap: 2px; }
    html:root:root:root .ds-histpage .ds-seg .ds-hchip, html:root:root:root .ds-toolspage .ds-chip { flex: 1 1 0; height: 32px; padding: 0 6px; font-size: 13px; font-weight: 600; color: var(--ds-p); background: transparent; border: 0; border-radius: 11px; box-shadow: none; }
    html:root:root:root .ds-histpage .ds-seg .ds-hchip.is-on, html:root:root:root .ds-toolspage .ds-chip.is-on { color: var(--ds-h); background: var(--ds-card); box-shadow: none; }
    html:root:root:root .ds-histpage .ds-hday { margin: 20px 0 8px 2px; font-size: 12px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: var(--ds-p); }
    html:root:root:root .ds-histpage .ds-hgrp { overflow: hidden; background: var(--ds-card); border: 1px solid var(--ds-line); border-radius: 20px; }
    html:root:root:root .ds-histpage .ds-hgrp::before { display: none !important; }
    html:root:root:root .ds-histpage .ds-hgrp .ds-h { position: relative; gap: 13px; padding: 12px 14px; border-top: 0 !important; border-bottom: 0 !important; }
    html:root:root:root .ds-histpage .ds-hgrp .ds-h::before { content: ""; position: absolute; top: 0; left: 71px; right: 0; height: 1px; background: var(--ds-line); }
    html:root:root:root .ds-histpage .ds-hgrp .ds-h:first-child::before, html:root:root:root .ds-histpage .ds-hgrp .ds-h.ds-h-first::before { display: none; }
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__ic { width: 44px; height: 44px; color: var(--ds-p); background: var(--ds-field) !important; background-image: none !important; border-radius: 12px; box-shadow: none !important; }
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__ic.is-in { color: var(--ds-ok); background: var(--ds-ok-soft) !important; }
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__ic img { width: 44px; height: 44px; border-radius: 12px; }
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__info strong { font-size: 15px; font-weight: 600; letter-spacing: -.005em; }
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__info span { margin-top: 2px; font-size: 12.5px; color: var(--ds-p); }
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__amt { height: auto; padding: 4px 9px; font-size: 12.5px; font-weight: 700; line-height: 1.2; border-radius: 8px; font-variant-numeric: tabular-nums; }
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__amt.is-in { background: var(--ds-ok-soft) !important; }
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__amt.is-out { background: var(--ds-red-soft) !important; }
    html:root:root:root .ds-histpage .ds-hgrp .ds-h__amt.is-zero { background: var(--ds-field) !important; }
    html:root:root:root .ds-histpage .ds-hnores { margin: 26px 0 0; font-size: 14px; text-align: center; color: var(--ds-p); }
    html:root:root:root .ds-histpage .ds-hempty, html:root:root:root .ds-toolspage .ds-empty { margin: 0; padding: 40px 20px 44px; text-align: center; background: transparent; border: 1px solid var(--ds-line2); border-radius: 30px; }
    html:root:root:root .ds-histpage .ds-hempty .ds-empty__ico, html:root:root:root .ds-toolspage .ds-empty__ico { width: 60px; height: 60px; margin: 0 auto 20px; color: var(--blue); background: #04152a; border-radius: 18px; }
    html:root:root:root[data-theme="light"] .ds-histpage .ds-hempty .ds-empty__ico, html:root:root:root[data-theme="light"] .ds-toolspage .ds-empty__ico { background: var(--ds-soft); }
    html:root:root:root .ds-histpage .ds-hempty .ds-empty__ico svg, html:root:root:root .ds-toolspage .ds-empty__ico svg { width: 34px; height: 34px; stroke-width: 2; }
    html:root:root:root .ds-histpage .ds-hempty h2 { margin: 0 0 6px; font-size: 21px; font-weight: 800; letter-spacing: -.01em; color: var(--ds-h); }
    html:root:root:root .ds-histpage .ds-hempty p, html:root:root:root .ds-toolspage .ds-empty p { margin: 0; font-size: 15px; line-height: 1.45; color: var(--ds-p); }
    html:root:root:root .ds-histpage .ds-hgo { display: none !important; }

    /* ===== TOOLS: cai co le chi lac qua lac lai, KHONG con khung / nen quanh co le ===== */
    html:root:root:root .ds-toolspage .ds-empty { margin-top: 16px; }
    html:root:root:root .ds-toolspage .ds-empty h2 { display: none; }
    html:root:root:root .ds-toolspage .ds-empty__ico, html:root:root:root[data-theme="light"] .ds-toolspage .ds-empty__ico { background: transparent !important; border: 0 !important; border-radius: 0 !important; box-shadow: none !important; }
    html:root:root:root .ds-toolspage .ds-empty__ico svg { transform-origin: 24% 80%; -webkit-animation: ds-wrench 1.8s ease-in-out infinite; animation: ds-wrench 1.8s ease-in-out infinite; }
    @keyframes ds-wrench { 0%, 100% { transform: rotate(-16deg); } 50% { transform: rotate(16deg); } }
    html:root:root:root .ds-toolspage .ds-chips { margin: 16px 0 6px; }
    html:root:root:root .ds-toolspage .ds-tlist { margin-top: 8px; }
    @media (prefers-reduced-motion: reduce) { html:root:root:root .ds-toolspage .ds-empty__ico svg { animation: none; } }

    /* ===== Logo lap loang: 1.9 giay / luot ===== */
    html:root:root:root .ds-logo .ds-sw, html:root:root:root .ds-logo .ds-swi { animation-duration: 1.9s !important; }

    /* ===== AVATAR: hien DUNG anh da dan link, khong pha mau, khong nen xanh ===== */
    html:root:root:root .ds-avatar { background: none !important; border: 0 !important; border-radius: 0 !important; box-shadow: none !important; color: var(--ds-h); }
    html:root:root:root .ds-avatar .ds-av__img, html:root:root:root:not([data-theme="light"]) .ds-avatar .ds-av__img { mix-blend-mode: normal !important; -webkit-filter: none !important; filter: none !important; background: none !important; border-radius: 0 !important; object-fit: contain; }
    html:root:root:root .ds-avatar.has-img > span, html:root:root:root .ds-um__av.has-img > span, html:root:root:root .ds-me__av.has-img > span { visibility: hidden; }
    html:root:root:root .ds-um__av, html:root:root:root .ds-me__av { color: var(--ds-h) !important; background: var(--ds-field) !important; }
    html:root:root:root .ds-um__av .ds-av__img, html:root:root:root .ds-me__av .ds-av__img { background: none !important; mix-blend-mode: normal !important; }

    /* ===== ME (tai khoan): gon, phang, khong phat sang, khong do bong ===== */
    html:root:root:root .ds-mepage { padding-bottom: 2px; }
    html:root:root:root .ds-mepage, html:root:root:root .ds-mepage * { text-shadow: none !important; }
    html:root:root:root .ds-me__top { display: flex; align-items: center; gap: 14px; margin: 0 !important; padding: 16px 0 4px !important; background: transparent !important; border: 0 !important; border-radius: 0 !important; }
    html:root:root:root .ds-me__av { width: 56px; height: 56px; font-size: 24px; font-weight: 700; border-radius: 16px; box-shadow: none !important; }
    html:root:root:root .ds-me__who h1 { margin: 0 0 6px; font-size: 20px; font-weight: 700; letter-spacing: -.015em; line-height: 1.2; }
    html:root:root:root .ds-me__badges { gap: 6px; }
    html:root:root:root .ds-mepage .ds-badge { height: 20px; padding: 0 8px; font-size: 11px; font-weight: 600; line-height: 20px; border-radius: 6px; }
    html:root:root:root .ds-mepage .ds-me__h { margin: 22px 0 6px 2px; font-size: 11.5px; font-weight: 600; letter-spacing: .07em; text-transform: uppercase; color: var(--ds-p); }
    html:root:root:root .ds-mepage .ds-lcard { background: var(--ds-card); border: 1px solid var(--ds-line); border-radius: 14px; box-shadow: none !important; }
    html:root:root:root .ds-mepage .ds-lrow { min-height: 44px; padding: 0 14px; font-size: 14px; font-weight: 500; }
    html:root:root:root .ds-mepage .ds-lrow.is-static > span { font-size: 14px; font-weight: 400; color: var(--ds-p); }
    html:root:root:root .ds-mepage .ds-lrow.is-static > b { font-size: 14px; font-weight: 600; color: var(--ds-h); font-variant-numeric: tabular-nums; }
    html:root:root:root .ds-mepage .ds-lrow svg { width: 14px; height: 14px; stroke: var(--ds-p); opacity: .7; }
    html:root:root:root .ds-mepage button.ds-lrow:active, html:root:root:root .ds-mepage a.ds-lrow:active { background: var(--ds-field); }
    html:root:root:root .ds-mepage .ds-sel { height: 30px; padding: 0 26px 0 10px; font-weight: 500; border-radius: 8px; }
    html:root:root:root .ds-mepage .ds-secwarn { margin: 12px 0 0; padding: 10px 12px; font-size: 13px; border-radius: 12px; }
    html:root:root:root .ds-mepage .ds-logout { height: 44px; margin: 20px 0 4px; font-size: 14px; font-weight: 600; border-radius: 14px; }
</style>
<script>
/* v41 */
(function () {
    var root = document.documentElement;
    try { root.classList.remove('ds-appscroll'); } catch (e) {}

    var ht = document.querySelector('.ds-hero__title');
    if (ht) ht.textContent = <?= dsx_js(dsx_cfg('ds_hero_title', 'Apps')) ?>;

    var sc = document.getElementById('ds-scroll');
    function curY() { return sc ? (sc.scrollTop || 0) : (window.pageYOffset || document.documentElement.scrollTop || 0); }
    if (sc) {
        try { Object.defineProperty(window, 'pageYOffset', { configurable: true, get: function () { return sc.scrollTop || 0; } }); } catch (e) {}
        try { Object.defineProperty(window, 'scrollY', { configurable: true, get: function () { return sc.scrollTop || 0; } }); } catch (e) {}
    }
    var lastSc = 0;
    (sc || window).addEventListener('scroll', function () { lastSc = Date.now(); }, { passive: true });
    document.addEventListener('touchstart', function (e) {
        if (curY() <= 12 && Date.now() - lastSc < 500) e.stopPropagation();
    }, { passive: true, capture: true });

    var nav = document.querySelector('.ds-nav');
    function setNav() {
        if (!nav) return;
        var h = Math.round(nav.getBoundingClientRect().height);
        if (h > 20 && h < 200) root.style.setProperty('--ds-nav-real', h + 'px');
    }
    setNav();
    window.addEventListener('load', setNav, { once: true });
    setTimeout(setNav, 400);
    setTimeout(setNav, 1500);
    window.addEventListener('resize', setNav);
    window.addEventListener('orientationchange', function () { setTimeout(setNav, 250); });
})();
</script>
<script>
/* v41: VE HOME.
   (1) FIX tut len tut xuong (tu "Apps" xuong toi "JOIN"): truoc day khung Apps chi duoc canh lai SAU 140ms khi hien ra
       (nhat la sau khi nhap key web nap lai o tab Keys, hero dang an nen chua canh) => nhin thay khung nhay len / xuong roi moi dung.
       Gio canh NGAY LUC DOI MAN, truoc khi trinh duyet ve hinh, nen khong con nhay.
   (2) Hien lai NHE NHANG + NHANH: tung phan tu dang nam tren man hinh (Apps, banner, hang the, JOIN, Package, tung san pham)
       mo dan + truot len 12px, moi phan tu tre them 26ms. Phan tu o ngoai man hinh khong hoat anh (nhe). */
(function () {
    try {
        var root = document.documentElement;
        var hero = document.getElementById('ds-hero');
        var hdr = document.querySelector('.site-header');
        var VK = ['ds-keys', 'ds-tools', 'ds-hist', 'ds-me', 'ds-detail', 'ds-pkg'];
        function isHome() { return !VK.some(function (k) { return root.classList.contains(k); }); }
        var was = isHome(), tm = 0, used = [];
        var HT = hero ? parseInt(hero.getAttribute('data-top'), 10) : 22;
        if (isNaN(HT)) HT = 22;
        var MQ = false;
        try { MQ = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) {}

        function docTop(el) { var y = 0; while (el) { y += el.offsetTop || 0; el = el.offsetParent; } return y; }
        function fixHero() {
            if (!hero || !hdr || hero.offsetParent === null || root.classList.contains('ds-typing')) return;
            var pt = hero.parentNode;
            if (pt && pt.style && pt.style.transform) return;
            var sl = document.getElementById('ds-slider'), im = hero.querySelector('.ds-slide img');
            if (sl && im && im.naturalWidth && im.naturalHeight) sl.style.setProperty('--ds-ar', im.naturalWidth + ' / ' + im.naturalHeight);
            var gap = docTop(hero) - hdr.offsetHeight, d = HT - gap;
            if (!isFinite(d) || Math.abs(d) < 0.5) return;
            var next = (parseFloat(hero.style.marginTop) || 0) + d;
            if (Math.abs(next) > 240) return;
            hero.style.marginTop = next.toFixed(1) + 'px';
        }

        var SEL = '.ds-hero__title,.ds-hero__desc,.ds-slider,.ds-chips2,.group-link,.ds-products .ds-sec__title,.ds-products .ds-sec__sub,.ds-products .ds-row';
        function clearBack() {
            used.forEach(function (el) { el.classList.remove('ds-back'); el.style.removeProperty('--ds-i'); });
            used = [];
        }
        function replay() {
            if (MQ) return;
            clearTimeout(tm);
            clearBack();
            var els = document.querySelectorAll(SEL), vh = window.innerHeight || 800, top = hdr ? hdr.offsetHeight : 0, i = 0, pick = [];
            for (var k = 0; k < els.length; k++) {
                var el = els[k];
                if (el.offsetParent === null) continue;
                var r = el.getBoundingClientRect();
                if (r.bottom <= top || r.top >= vh) continue;
                el.style.setProperty('--ds-i', String(Math.min(i, 12)));
                pick.push(el);
                i++;
            }
            void root.offsetWidth;
            pick.forEach(function (el) { el.classList.add('ds-back'); });
            used = pick;
            tm = setTimeout(clearBack, 1100);
        }

        function onChange() {
            var now = isHome();
            if (now) {
                fixHero();
                if (!was) replay();
            }
            was = now;
        }
        if (window.MutationObserver) new MutationObserver(onChange).observe(root, { attributes: true, attributeFilter: ['class'] });
    } catch (e) {}
})();
</script>
<script>
/* Khoa VIP / Free. Key Free: san pham VIP xam + GET tat. Key VIP: san pham Free xam + GET tat. View van bam binh thuong. */
(function () {
    try {
        function plan() {
            var b = document.getElementById('ds-um-plan'), t = b ? String(b.textContent || '').trim() : '';
            if (t === 'VIP' || t === 'Free') return t;
            var el = document.querySelector('.user-bar__plan');
            if (el) {
                var x = el.textContent || '';
                if (/vip/i.test(x) || el.classList.contains('is-vip')) return 'VIP';
                if (/free/i.test(x) || el.classList.contains('is-free')) return 'Free';
            }
            return '';
        }
        function cardKind(c) { var s = c.querySelector('.ds-row__info span, .ds-rel__info span'); return (s && s.classList.contains('is-vip')) ? 'VIP' : 'Free'; }
        function detKind(d) { return /vip/i.test(d.getAttribute('data-kind') || '') ? 'VIP' : 'Free'; }
        function bad(kind, p) { return (p === 'Free' && kind === 'VIP') || (p === 'VIP' && kind === 'Free'); }
        function paint() {
            var p = plan();
            document.querySelectorAll('.ds-row, .ds-rel__card').forEach(function (c) { c.classList.toggle('ds-gray', bad(cardKind(c), p)); });
            document.querySelectorAll('.ds-det').forEach(function (d) {
                var g = d.querySelector('.ds-get');
                if (!g) return;
                var m = bad(detKind(d), p);
                g.classList.toggle('is-mismatch', m);
                if (m) { g.setAttribute('disabled', 'disabled'); g.setAttribute('aria-disabled', 'true'); }
                else { g.removeAttribute('disabled'); g.removeAttribute('aria-disabled'); }
            });
        }
        var tm = 0;
        function later() { clearTimeout(tm); tm = setTimeout(paint, 60); }
        paint();
        setTimeout(paint, 300);
        setTimeout(paint, 1200);
        if (window.MutationObserver) {
            var mo = new MutationObserver(later), o = { childList: true, characterData: true, subtree: true, attributes: true };
            var b = document.getElementById('ds-um-plan'); if (b) mo.observe(b, o);
            var ub = document.querySelector('.user-bar'); if (ub) mo.observe(ub, o);
        }
        document.addEventListener('click', function (e) {
            var g = e.target && e.target.closest ? e.target.closest('.ds-get.is-mismatch, .ds-get[disabled]') : null;
            if (!g) return;
            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) e.stopImmediatePropagation();
        }, true);
    } catch (e) {}
})();
</script>
<script>
/* THONG BAO v44 (DS_V44_TOAST): chi 1 o thong bao duy nhat, KHONG con hien 2 lan.

   Nguyen nhan cu: index.php chen thong bao 2 lan lien tiep (cach nhau ~0.2 giay) va xoa sach khung giua 2 lan.

   Ban cu coi lan 2 la thong bao MOI nen cho hieu ung hien len lai tu dau => nhin thay thong bao hien, mo di, hien lai.

   Ban nay: cung noi dung (hoac den trong 1.5 giay) thi KHONG tao thong bao moi, giu nguyen thong bao dang hien;

   neu index.php xoa mat thong bao dang hien thi dat no lai ngay (cung nhip, khong lo khung trong). */

(function () {

    try {

        var anchor = document.querySelector('.toast-anchor');

        window.__dsTddOk = true;

        if (!anchor || !anchor.closest('.ds-keyhead') || !window.MutationObserver) { document.documentElement.classList.remove('ds-tdd'); return; }

        var root = document.documentElement, need = document.getElementById('ds-need');

        var SHOW = 3800, RAPID = 1500, cur = null, tm = 0, lastTxt = '', lastAt = 0, spawnAt = 0;

        var hold = document.createElement('div');

        hold.setAttribute('aria-hidden', 'true');

        hold.style.cssText = 'position:fixed;left:-9999px;top:0;width:320px;visibility:hidden;pointer-events:none;';

        document.body.appendChild(hold);

        function clean(s) { return String(s || '').replace(/\s+/g, ' ').trim(); }

        function im(el, p, v) { el.style.setProperty(p, v, 'important'); }

        var TR = 'opacity .3s ease, transform .3s cubic-bezier(.22, .61, .36, 1), max-height .3s cubic-bezier(.22, .61, .36, 1), margin-top .3s cubic-bezier(.22, .61, .36, 1)';



        function dropAll() {

            clearTimeout(tm);

            cur = null;

            Array.prototype.slice.call(anchor.querySelectorAll('[data-ds-c]')).forEach(function (n) {

                n.__gone = true;

                if (n.parentNode) n.parentNode.removeChild(n);

            });

        }

        function killNeed() {

            if (!need) return;

            need.hidden = true;

            need.classList.remove('is-in');

            need.classList.remove('is-out');

        }

        function enter(c) {

            im(c, 'animation', 'none'); im(c, 'overflow', 'hidden'); im(c, 'transition', 'none');

            im(c, 'opacity', '0'); im(c, 'transform', 'translate3d(0,-6px,0)'); im(c, 'max-height', '0px'); im(c, 'margin-top', '0px');

            anchor.appendChild(c);

            var h = c.scrollHeight;

            void c.offsetWidth;

            im(c, 'transition', TR);

            im(c, 'opacity', '1'); im(c, 'transform', 'none'); im(c, 'max-height', (Math.max(h, 24) + 2) + 'px'); im(c, 'margin-top', '10px');

            setTimeout(function () { if (!c.__gone) { c.style.removeProperty('max-height'); c.style.removeProperty('overflow'); } }, 380);

        }

        function leave(c) {

            if (!c || c.__gone || !c.parentNode) return;

            c.__gone = true;

            if (cur === c) cur = null;

            var h = c.offsetHeight;

            im(c, 'overflow', 'hidden'); im(c, 'transition', 'none'); im(c, 'max-height', h + 'px');

            void c.offsetWidth;

            im(c, 'transition', TR);

            im(c, 'opacity', '0'); im(c, 'transform', 'translate3d(0,-4px,0)'); im(c, 'max-height', '0px'); im(c, 'margin-top', '0px');

            setTimeout(function () { if (c.parentNode) c.parentNode.removeChild(c); }, 360);

        }

                /* v46 (muot): thong bao hien / tat MOT NHIP: cao + mo + truot chay cung luc, bat dau o khung hinh ke tiep.
           Truoc day thong bao hien la day ca trang xuong 30px trong 1 khung hinh (giat). */
        var TR46 = 'opacity .34s cubic-bezier(.22, .61, .36, 1), transform .34s cubic-bezier(.22, .61, .36, 1), max-height .34s cubic-bezier(.22, .61, .36, 1), margin-top .34s cubic-bezier(.22, .61, .36, 1)';
        function enter(c, a46) {
            var an46 = a46 || anchor;
            im(c, 'animation', 'none'); im(c, 'transition', 'none'); im(c, 'overflow', 'hidden');
            im(c, 'opacity', '0'); im(c, 'transform', 'translate3d(0,-6px,0)'); im(c, 'max-height', '0px'); im(c, 'margin-top', '0px');
            im(c, 'will-change', 'opacity, transform');
            an46.appendChild(c);
            var h = c.scrollHeight;
            requestAnimationFrame(function () {
                if (c.__gone) return;
                im(c, 'transition', TR46);
                im(c, 'opacity', '1'); im(c, 'transform', 'none'); im(c, 'max-height', (Math.max(h, 24) + 2) + 'px'); im(c, 'margin-top', '10px');
                setTimeout(function () {
                    if (!c.__gone) { c.style.removeProperty('max-height'); c.style.removeProperty('overflow'); c.style.removeProperty('will-change'); }
                }, 420);
            });
        }
        function leave(c) {
            if (!c || c.__gone || !c.parentNode) return;
            c.__gone = true;
            if (typeof cur !== 'undefined' && cur === c) cur = null;
            var h = c.offsetHeight;
            im(c, 'overflow', 'hidden'); im(c, 'transition', 'none'); im(c, 'max-height', h + 'px'); im(c, 'will-change', 'opacity, transform');
            void c.offsetWidth;
            requestAnimationFrame(function () {
                im(c, 'transition', TR46);
                im(c, 'opacity', '0'); im(c, 'transform', 'translate3d(0,-4px,0)'); im(c, 'max-height', '0px'); im(c, 'margin-top', '0px');
            });
            setTimeout(function () { if (c.parentNode) c.parentNode.removeChild(c); }, 460);
        }
                /* DS-PATCH-V49 (thong bao Keys): hien / tat NGAY, khong cho khung hinh, khong hen gio. Cao + mo + truot chay cung nhip (1 lan chot style roi chay luon). */
        var TR49 = 'opacity .3s cubic-bezier(.22, .61, .36, 1), transform .3s cubic-bezier(.22, .61, .36, 1), max-height .3s cubic-bezier(.22, .61, .36, 1), margin-top .3s cubic-bezier(.22, .61, .36, 1)';
        function enter(c, a49) {
            var an49 = a49 || anchor;
            im(c, 'animation', 'none'); im(c, 'transition', 'none'); im(c, 'overflow', 'hidden');
            im(c, 'opacity', '0'); im(c, 'transform', 'translate3d(0,-6px,0)'); im(c, 'max-height', '0px'); im(c, 'margin-top', '0px');
            an49.appendChild(c);
            var h = c.scrollHeight;
            im(c, 'transition', TR49);
            im(c, 'opacity', '1'); im(c, 'transform', 'none'); im(c, 'max-height', (Math.max(h, 24) + 2) + 'px'); im(c, 'margin-top', '10px');
            setTimeout(function () { if (!c.__gone) { c.style.removeProperty('max-height'); c.style.removeProperty('overflow'); } }, 340);
        }
        function leave(c) {
            if (!c || c.__gone || !c.parentNode) return;
            c.__gone = true;
            if (typeof cur !== 'undefined' && cur === c) cur = null;
            var h = c.offsetHeight;
            im(c, 'overflow', 'hidden'); im(c, 'transition', 'none'); im(c, 'max-height', h + 'px');
            void c.offsetWidth;
            im(c, 'transition', TR49);
            im(c, 'opacity', '0'); im(c, 'transform', 'translate3d(0,-4px,0)'); im(c, 'max-height', '0px'); im(c, 'margin-top', '0px');
            setTimeout(function () { if (c.parentNode) c.parentNode.removeChild(c); }, 340);
        }
        function arm(c) { clearTimeout(tm); tm = setTimeout(function () { leave(c); }, SHOW); }

        function spawn(o) {

            var txt = clean(o.textContent), now = Date.now();

            o.setAttribute('data-ds-o', '1');

            hold.textContent = '';

            hold.appendChild(o);

            if (cur && !cur.__gone) {

                /* thong bao dang hien: neu index.php xoa mat no thi dat lai, khong tao cai moi */

                if (!cur.parentNode) { try { anchor.appendChild(cur); } catch (x) {} }

                if (txt === lastTxt && now - lastAt < SHOW) {

                    /* cung noi dung: chi gia han, KHONG hien them cai thu 2 */

                    lastAt = now;

                    arm(cur);

                    return;

                }

                if (now - spawnAt < RAPID) {

                    /* khac noi dung nhung den ngay sau cai dau (cung 1 lan bam): doi noi dung tai cho, khong chay lai hieu ung hien len */

                    cur.className = o.className;

                    cur.innerHTML = o.innerHTML;

                    lastTxt = txt; lastAt = now;

                    arm(cur);

                    return;

                }

            }

            (function () { var old = cur; clearTimeout(tm); if (old && !old.__gone && old.parentNode) { cur = null; leave(old); } else { dropAll(); } })();
            killNeed();
            var c = o.cloneNode(true);

            c.setAttribute('data-ds-c', '1');

            c.removeAttribute('data-ds-o');

            lastTxt = txt; lastAt = now; spawnAt = now; cur = c;

            enter(c);

            arm(c);

        }

        function pickToast(n) {

            if (n.nodeType !== 1 || n.getAttribute('data-ds-c') || n.getAttribute('data-ds-o')) return null;

            var t = n.classList.contains('toast') ? n : n.querySelector('.toast');

            if (!t || t.getAttribute('data-ds-c') || t.getAttribute('data-ds-o') || !clean(t.textContent)) return null;

            return t;

        }

        new MutationObserver(function (list) {

            list.forEach(function (r) {

                Array.prototype.forEach.call(r.removedNodes, function (n) {

                    /* index.php xoa thong bao dang hien (khong phai do minh go): dat lai ngay trong cung nhip, khong lo khung trong */

                    if (n.nodeType === 1 && n === cur && !n.__gone) {

                        try { anchor.appendChild(n); } catch (x) {}

                    }

                });

                Array.prototype.forEach.call(r.addedNodes, function (n) {

                    var t = pickToast(n);

                    if (t) spawn(t);

                });

            });

        }).observe(anchor, { childList: true, subtree: true });



        if (need) {

            new MutationObserver(function () {

                if (!need.hidden && need.classList.contains('is-in')) dropAll();

            }).observe(need, { attributes: true, attributeFilter: ['hidden', 'class'] });

        }

        new MutationObserver(function () {

            if (!root.classList.contains('ds-keys')) { dropAll(); killNeed(); }

        }).observe(root, { attributes: true, attributeFilter: ['class'] });



        var last = null;

        Array.prototype.forEach.call(anchor.querySelectorAll('.toast'), function (t) { if (pickToast(t)) last = t; });

        if (last) spawn(last);

    } catch (e) { try { document.documentElement.classList.remove('ds-tdd'); } catch (x) {} }

})();
</script>
<script>
/* HISTORY kieu Receipts & Orders (giong shopdsgm.vn). Doi chu o day neu muon. */
(function () {
    try {
        var TXT = {
            kick: <?= dsx_js(ds_t('hist_kick')) ?>,
            title: <?= dsx_js(ds_t('hist_title')) ?>,
            search: <?= dsx_js(ds_t('search')) ?>,
            emptyT: <?= dsx_js(ds_t('hist_empty_t')) ?>,
            emptyP: <?= dsx_js(ds_t('hist_empty_p')) ?>,
            noRes: <?= dsx_js(ds_t('hist_nores')) ?>
        };
        var hp = document.getElementById('ds-histpage');
        if (!hp) return;
        var head = hp.querySelector('.ds-hhead'), stat = hp.querySelector('.ds-hstat'), list = document.getElementById('ds-hlist');
        if (!head || !stat || !list || hp.querySelector('.ds-hsearch')) return;

        var ttl = hp.querySelector('.ds-pkgtitle');
        if (ttl) ttl.textContent = TXT.title;

        var k = document.createElement('div');
        k.className = 'ds-kick';
        k.textContent = TXT.kick;
        hp.insertBefore(k, head);

        var lab = document.createElement('label');
        lab.className = 'ds-search ds-hsearch';
        lab.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input class="ds-search__in" type="search" placeholder="' + TXT.search + '" aria-label="Search history" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="search">';
        var rule = document.createElement('div');
        rule.className = 'ds-hrule';
        hp.insertBefore(lab, stat);
        hp.insertBefore(rule, stat);
        var inp = lab.querySelector('input');

        var eico = hp.querySelector('.ds-hempty .ds-empty__ico'), eh = hp.querySelector('.ds-hempty h2'), ep = hp.querySelector('.ds-hempty p');
        if (eico) eico.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.2 8 8 4h8l1.8 4"/><rect x="4" y="8" width="16" height="12.5" rx="2.4"/><path d="M9 12.2a3 3 0 0 0 6 0"/></svg>';
        if (eh) eh.textContent = TXT.emptyT;
        if (ep) ep.textContent = TXT.emptyP;

        var nores = document.createElement('p');
        nores.className = 'ds-hnores';
        nores.textContent = TXT.noRes;
        nores.hidden = true;
        list.after(nores);

        function norm(t) {
            t = String(t || '');
            try { t = t.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (e) {}
            return t.replace(/[\u0111\u0110]/g, 'd').toLowerCase();
        }
        function apply() {
            var q = norm(inp.value).trim(), kids = list.children, any = false, total = 0;
            for (var i = 0; i < kids.length; i++) {
                var el = kids[i];
                if (!el.classList.contains('ds-hgrp')) continue;
                var rows = el.querySelectorAll('.ds-h'), vis = 0, first = true;
                for (var j = 0; j < rows.length; j++) {
                    var r = rows[j], hit = !q || norm(r.textContent).indexOf(q) !== -1;
                    r.hidden = !hit;
                    r.classList.remove('ds-h-first');
                    if (hit) { vis++; if (first) { r.classList.add('ds-h-first'); first = false; } }
                }
                el.hidden = !vis;
                var prev = el.previousElementSibling;
                if (prev && prev.classList.contains('ds-hday')) prev.hidden = !vis;
                total += rows.length;
                if (vis) any = true;
            }
            nores.hidden = !(q && total > 0 && !any);
        }
        inp.addEventListener('input', apply);
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') inp.blur(); });
        if (window.MutationObserver) new MutationObserver(apply).observe(list, { childList: true });
        apply();

        var seg = hp.querySelector('.ds-seg');
        function txt(id) { var el = document.getElementById(id); return el ? el.textContent : ''; }
        function syncEmpty() {
            var none = txt('ds-hs-in') === '+0' && txt('ds-hs-out') === '\u22120';
            stat.hidden = none;
            if (seg) seg.hidden = none;
        }
        var raf = 0;
        if (window.MutationObserver) {
            new MutationObserver(function () {
                if (raf) return;
                raf = requestAnimationFrame(function () { raf = 0; syncEmpty(); });
            }).observe(hp, { childList: true, subtree: true, characterData: true });
        }
        syncEmpty();
    } catch (e) {}
})();
</script>
<script>
/* TOOLS: them dong nho + tieu de to giong History */
(function () {
    try {
        var tp = document.getElementById('ds-toolspage');
        if (!tp || tp.querySelector('.ds-kick')) return;
        var k = document.createElement('div'); k.className = 'ds-kick'; k.textContent = <?= dsx_js(ds_t('tools_kick')) ?>;
        var h = document.createElement('h1'); h.className = 'ds-pkgtitle'; h.textContent = <?= dsx_js(ds_t('tools')) ?>;
        tp.insertBefore(h, tp.firstChild);
        tp.insertBefore(k, h);
    } catch (e) {}
})();
</script>
<script>
/* Tab History: icon tui don hang (giong tab Orders trong anh), thay icon dong ho */
(function () {
    try {
        var BAG = '<path d="M6.2 8 8 4h8l1.8 4"/><rect x="4" y="8" width="16" height="12.5" rx="2.4"/><path d="M9 12.2a3 3 0 0 0 6 0"/>';
        document.querySelectorAll('#ds-hist svg, .ds-um__item[data-go="hist"] svg').forEach(function (sv) { sv.innerHTML = BAG; });
    } catch (e) {}
})();
</script>
<script>
/* Avatar: co anh that thi an chu cai du phong (khong lo chu U / nen xanh phia sau) */
(function () {
    try {
        document.querySelectorAll('.ds-av__img').forEach(function (im) {
            var box = im.parentNode;
            if (!box || !box.classList) return;
            function ok() { box.classList.add('has-img'); }
            function bad() { box.classList.remove('has-img'); }
            im.addEventListener('load', ok);
            im.addEventListener('error', bad);
            if (im.complete && im.naturalWidth > 0) ok();
        });
    } catch (e) {}
})();
</script>
<script>
/* ME: gop "Security" + "Activity" thanh 1 khoi cho gon (cac nut giu nguyen chuc nang) */
(function () {
    try {
        var mp = document.getElementById('ds-mepage');
        if (!mp) return;
        var secH = null, actH = null;
        Array.prototype.forEach.call(mp.querySelectorAll('.ds-me__h'), function (h) {
            var t = String(h.textContent || '').trim();
            if (t === 'Security') secH = h; else if (t === 'Activity') actH = h;
        });
        if (!secH || !actH) return;
        var secC = secH.nextElementSibling, actC = actH.nextElementSibling;
        if (!secC || !actC || !secC.classList.contains('ds-lcard') || !actC.classList.contains('ds-lcard')) return;
        while (actC.firstChild) secC.appendChild(actC.firstChild);
        actC.parentNode.removeChild(actC);
        actH.parentNode.removeChild(actH);
        secH.textContent = 'Security & activity';
    } catch (e) {}
})();
</script>
<script>
/* Nut chia se. Chi chia se LINK san pham. Khong co bang chia se => sao chep link va bao "Link copied". */
(function () {
    try {
        var fl = null, ft = 0;
        function flash(msg) {
            if (!fl) { fl = document.createElement('div'); fl.id = 'ds-flash'; fl.setAttribute('role', 'status'); document.body.appendChild(fl); }
            fl.textContent = msg;
            fl.classList.remove('is-on');
            void fl.offsetWidth;
            fl.classList.add('is-on');
            clearTimeout(ft);
            ft = setTimeout(function () { fl.classList.remove('is-on'); }, 1900);
        }
        function slugOf(name) {
            var t = String(name || '');
            try { t = t.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (e) {}
            return t.replace(/[\u0111\u0110]/g, 'd').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        }
        function legacyCopy(t) {
            var a = document.createElement('textarea');
            a.value = t;
            a.setAttribute('readonly', '');
            a.style.cssText = 'position:fixed;top:0;left:0;opacity:0;font-size:16px';
            document.body.appendChild(a);
            a.select();
            try { a.setSelectionRange(0, t.length); } catch (e) {}
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(a);
            return ok;
        }
        function copy(t) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(t).then(function () { flash('Link copied'); }, function () {
                    if (legacyCopy(t)) flash('Link copied'); else window.prompt('Copy link', t);
                });
            } else if (legacyCopy(t)) { flash('Link copied'); }
            else { window.prompt('Copy link', t); }
        }
        document.addEventListener('click', function (e) {
            var b = e.target && e.target.closest ? e.target.closest('.ds-share') : null;
            if (!b) return;
            var det = b.closest('.ds-det');
            if (!det) return;
            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) e.stopImmediatePropagation();
            var id = det.getAttribute('data-tool'), name = det.getAttribute('data-name') || document.title, slug = slugOf(name);
            var url = window.location.origin + window.location.pathname + '?devnvios=' + id + (slug ? '-' + slug : '');
            if (navigator.share) {
                navigator.share({ title: name, url: url }).catch(function (err) {
                    if (err && err.name === 'AbortError') return;
                    copy(url);
                });
            } else { copy(url); }
        }, true);
    } catch (e) {}
})();
</script>
<!--DS-V42-BEGIN-->
<style id="ds-patch-v42">
    /* ===== v42 =====
       1) Avatar tren header: BO TRON (hinh tron). Dung id #ds-avatar nen thang moi khoi CSS cu (cac khoi cu dat border-radius: 0 / overflow: visible). */
    html body #ds-avatar { overflow: hidden !important; border-radius: 50% !important; -webkit-clip-path: circle(50% at 50% 50%); clip-path: circle(50% at 50% 50%); background: var(--ds-field) !important; border: 0 !important; box-shadow: none !important; }
    html body #ds-avatar .ds-av__img { border-radius: 50% !important; object-fit: cover !important; object-position: center !important; background: none !important; mix-blend-mode: normal !important; -webkit-filter: none !important; filter: none !important; }

    /* 2) CHE DO TOI: chu trang. "+1 turn", "-1 turn", "0 turns", so thong ke, huy hieu Member / VIP / Free, so luot trong menu avatar */
    html:not([data-theme="light"]) #ds-histpage .ds-h__amt,
    html:not([data-theme="light"]) #ds-histpage .ds-hstat b,
    html:not([data-theme="light"]) #ds-histpage .ds-badge,
    html:not([data-theme="light"]) #ds-um .ds-badge,
    html:not([data-theme="light"]) #ds-um .ds-um__row b,
    html:not([data-theme="light"]) #ds-um .ds-um__hello,
    html:not([data-theme="light"]) #ds-um .ds-um__name,
    html:not([data-theme="light"]) #ds-mepage .ds-badge { color: #fff !important; }

    /* 3) CHE DO SANG (nen trang): chu DEN */
    html[data-theme="light"] #ds-histpage .ds-h__amt,
    html[data-theme="light"] #ds-histpage .ds-hstat b,
    html[data-theme="light"] #ds-histpage .ds-badge,
    html[data-theme="light"] #ds-um .ds-badge,
    html[data-theme="light"] #ds-um .ds-um__row b,
    html[data-theme="light"] #ds-um .ds-um__hello,
    html[data-theme="light"] #ds-um .ds-um__name,
    html[data-theme="light"] #ds-mepage .ds-badge { color: #000 !important; }
</style>
<!--DS-V42-END-->
<!--DS-V43-BEGIN-->
<style id="ds-patch-v43">
    /* ===== v43 ===== */

    /* 1) TOOLS: bo khung quanh cai co le (con co le lac + dong chu) */
    html:root:root:root .ds-toolspage .ds-empty { border: 0 !important; border-radius: 0 !important; background: transparent !important; box-shadow: none !important; }

    /* 2) Bam View: KHONG lam toi trang nua (truoc day trang bi ha xuong 40% roi sang lai, chong len cac dong tool dang mo 50% => toi roi toi them) */
    html:root:root:root.ds-detail .ds-softin { animation: none !important; opacity: 1 !important; }
    /*    Ve Home: dong tool bi khoa / dang xu ly mo 50%, hieu ung hien len cung ket thuc o 50% (khong sang 100% roi toi lai khi go hieu ung) */
    @keyframes ds-back43-lock { from { opacity: 0; transform: translate3d(0, 12px, 0); } to { opacity: .5; transform: none; } }
    html:root:root:root .ds-row.is-locked.ds-back, html:root:root:root .ds-row.is-busy.ds-back { animation-name: ds-back43-lock !important; }

    /* 3) Logo lap loa NHIEU hon: dai sang rong hon, chay nhanh hon, 2 dai noi tiep nhau (dai thu 2 do JS them) */
    html:root:root:root .ds-logo .ds-sw, html:root:root:root .ds-logo .ds-swi { animation-duration: 1.6s !important; }
    html:root:root:root .ds-logo .ds-sw {
        -webkit-mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0) 8%, rgba(0, 0, 0, .55) 28%, #000 50%, rgba(0, 0, 0, .55) 72%, rgba(0, 0, 0, 0) 92%) !important;
        mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0) 8%, rgba(0, 0, 0, .55) 28%, #000 50%, rgba(0, 0, 0, .55) 72%, rgba(0, 0, 0, 0) 92%) !important;
    }
    html:root:root:root .ds-logo .ds-swi { opacity: 1 !important; }
    html:root:root:root .ds-logo .ds-sw2, html:root:root:root .ds-logo .ds-sw2 .ds-swi { animation-delay: -.8s !important; }
</style>
<script>
/* v43: logo lap loa nhieu hon: them dai sang thu 2 (chay lech nua vong) vao moi logo da co lop lap loa */
(function () {
    try {
        Array.prototype.forEach.call(document.querySelectorAll('.ds-logo'), function (lg) {
            var a = lg.querySelector('.ds-sw');
            if (!a || lg.querySelector('.ds-sw2')) return;
            var b = a.cloneNode(true);
            b.className = 'ds-sw ds-sw2';
            lg.appendChild(b);
        });
    } catch (e) {}
})();
</script>
<!--DS-V43-END-->
<!--DS-V44-BEGIN-->
<style id="ds-patch-v44">
    /* ===== v44: nut GET + nut chia se giong anh mau (Free Fire / Package 1) =====

       GET: vien thuoc xanh #306edb, cao 30, rong ~82, icon tai xuong (mui ten + khay) + chu GET dam, chu hoi cach nhau.

       Chia se: hinh tron 32, nen xam dam #2c2b31, icon chia se xanh (mui ten len + khay), nam sat le phai cung hang voi GET.

       Chi doi 2 nut nay, khong dung vao phan khac. */

    html:root:root:root:root:root body .ds-det .ds-get { box-sizing: border-box !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; gap: 8px !important; min-width: 82px !important; height: 30px !important; margin: 0 !important; padding: 0 17px 0 15px !important; font-size: 13px !important; font-weight: 800 !important; letter-spacing: .05em !important; line-height: 1 !important; color: #fff !important; background: #306edb !important; border: 0 !important; border-radius: 999px !important; box-shadow: none !important; }

    html:root:root:root:root:root body .ds-det .ds-get svg { flex: 0 0 auto !important; width: 17px !important; height: 17px !important; margin: 0 !important; fill: none !important; stroke: currentColor !important; stroke-width: 2.1 !important; stroke-linecap: round !important; stroke-linejoin: round !important; }

    html:root:root:root:root:root body .ds-det .ds-get.is-mismatch, html:root:root:root:root:root body .ds-det .ds-get[disabled] { color: #8e8e93 !important; background: #2c2c2e !important; }

    html:root:root:root:root:root[data-theme="light"] body .ds-det .ds-get.is-mismatch, html:root:root:root:root:root[data-theme="light"] body .ds-det .ds-get[disabled] { color: #6b7280 !important; background: #e5e7eb !important; }



    html:root:root:root:root:root body .ds-det .ds-share { box-sizing: border-box !important; display: flex !important; align-items: center !important; justify-content: center !important; flex: 0 0 auto !important; width: 32px !important; height: 32px !important; min-width: 32px !important; padding: 0 !important; color: #3c7af3 !important; background: #2c2b31 !important; border: 0 !important; border-radius: 50% !important; box-shadow: none !important; }

    html:root:root:root:root:root body .ds-det .ds-share svg { width: 18px !important; height: 18px !important; fill: none !important; stroke: currentColor !important; stroke-width: 1.9 !important; stroke-linecap: round !important; stroke-linejoin: round !important; }

    html:root:root:root:root:root[data-theme="light"] body .ds-det .ds-share { color: #306edb !important; background: #e5e7eb !important; }
</style>
<script>
/* v44: icon nut GET (mui ten xuong + khay) va nut chia se (mui ten len + khay) giong anh mau */

(function () {

    try {

        var DL = '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M3 15v3.5A2.5 2.5 0 0 0 5.5 21h13a2.5 2.5 0 0 0 2.5-2.5V15"/>';

        var UP = '<path d="M12 3v13"/><path d="m8 7 4-4 4 4"/><path d="M4.5 11.5V19a2.5 2.5 0 0 0 2.5 2.5h10a2.5 2.5 0 0 0 2.5-2.5v-7.5"/>';

        function paint() {

            Array.prototype.forEach.call(document.querySelectorAll('.ds-get svg'), function (s) { if (s.getAttribute('data-ds44') !== '1') { s.innerHTML = DL; s.setAttribute('data-ds44', '1'); } });

            Array.prototype.forEach.call(document.querySelectorAll('.ds-share svg'), function (s) { if (s.getAttribute('data-ds44') !== '1') { s.innerHTML = UP; s.setAttribute('data-ds44', '1'); } });

        }

        paint();

        setTimeout(paint, 300);

    } catch (e) {}

})();
</script>
<!--DS-V44-END-->
<!--DS-P25-KEY-BEGIN-->
<style id="ds-logo-shine-fix">
    /* Fix logo devnvios.io.vn luôn l?p loá liên t?c, không bao gi? b? pause hay ?n khi cu?n / thoát web */
    html:root:root:root:root .ds-logo .ds-sw,
    html:root:root:root:root .site-header .ds-sw,
    html:root:root:root:root .site-header.ds-scrolling .ds-sw,
    html:root:root:root:root.ds-scrolling .ds-sw {
        display: block !important;
        opacity: 1 !important;
        visibility: visible !important;
        -webkit-animation: ds-sw35 1.6s linear infinite !important;
        animation: ds-sw35 1.6s linear infinite !important;
        -webkit-animation-play-state: running !important;
        animation-play-state: running !important;
    }
    html:root:root:root:root .ds-logo .ds-swi,
    html:root:root:root:root .site-header .ds-swi,
    html:root:root:root:root .site-header.ds-scrolling .ds-swi,
    html:root:root:root:root.ds-scrolling .ds-swi {
        display: block !important;
        opacity: 1 !important;
        visibility: visible !important;
        -webkit-animation: ds-swi35 1.6s linear infinite !important;
        animation: ds-swi35 1.6s linear infinite !important;
        -webkit-animation-play-state: running !important;
        animation-play-state: running !important;
    }
    html:root:root:root:root .ds-logo .ds-sw2,
    html:root:root:root:root .ds-logo .ds-sw2 .ds-swi {
        -webkit-animation-delay: -.8s !important;
        animation-delay: -.8s !important;
    }
</style>
<!--DS-SMOOTH-JS-BEGIN-->
<script id="ds-logo-revive">
/* spinner-v2-logo: giu lap loa logo on dinh.
   - Chi khoi dong lai MOT lan khi quay lai web (hoac sau khi trang bi dong bang lau), co lam mo dan, khong khoi dong lai khi focus / cham / go chu.
   - Chua tai xong thi shine an; thoat web thi an + tam dung (do ton GPU). */
(function () {
    try {
        if (window.__dsLg2) return;
        window.__dsLg2 = 1;
        var root = document.documentElement, last = 0, tmA = 0, tmB = 0, hid = false, beat = Date.now();
        function ensure() {
            try {
                document.querySelectorAll('.brand__logo, .ds-brandblock img:not(.ds-swi)').forEach(function (img) {
                    var p = img.parentNode;
                    if (!p) return;
                    if (!p.classList || !p.classList.contains('ds-logo')) {
                        var w = document.createElement('span'), sw = document.createElement('i'), c = document.createElement('img');
                        w.className = 'ds-logo'; sw.className = 'ds-sw'; sw.setAttribute('aria-hidden', 'true');
                        c.className = 'ds-swi'; c.alt = ''; c.setAttribute('aria-hidden', 'true'); c.src = img.currentSrc || img.src;
                        p.insertBefore(w, img); w.appendChild(img); sw.appendChild(c); w.appendChild(sw); p = w;
                    }
                    var a = p.querySelector('.ds-sw');
                    if (a && !p.querySelector('.ds-sw2')) { var b = a.cloneNode(true); b.className = 'ds-sw ds-sw2'; p.appendChild(b); }
                    var src = img.currentSrc || img.src;
                    p.querySelectorAll('.ds-swi').forEach(function (si) { if (src && si.src !== src) si.src = src; });
                });
            } catch (e) {}
        }
        function off() { root.classList.add('ds-lg-off'); }
        function on() { root.classList.remove('ds-lg-off'); }
        function restart() {
            ensure();
            var list = document.querySelectorAll('.ds-logo .ds-sw, .ds-logo .ds-swi');
            Array.prototype.forEach.call(list, function (el) {
                el.style.setProperty('animation', 'none', 'important');
                el.style.setProperty('-webkit-animation', 'none', 'important');
            });
            void root.offsetWidth;
            Array.prototype.forEach.call(list, function (el) {
                el.style.removeProperty('animation');
                el.style.removeProperty('-webkit-animation');
            });
        }
        function comeback() {
            var n = Date.now();
            if (n - last < 1200) return;
            last = n;
            clearTimeout(tmA); clearTimeout(tmB);
            off();
            on(); window.__dsLgT = Date.now(); requestAnimationFrame(function () { restart(); }); /* v48: quay lai web la lap loa ngay, khong an / khong cho */
        }
        ensure();
        if (document.readyState !== 'complete') {
            off();
            window.addEventListener('load', function () { ensure(); setTimeout(on, 250); });
            setTimeout(on, 2500);
        }
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') { hid = true; root.classList.add('ds-hid'); }
            else { root.classList.remove('ds-hid'); if (hid) { hid = false; comeback(); } }
        });
        window.addEventListener('pagehide', function () { hid = true; });
        window.addEventListener('pageshow', function (e) { ensure(); if (e.persisted || hid) { hid = false; comeback(); } });
        /* trang bi iOS dong bang lau ma khong ban su kien: khoang cach giua 2 lan dem > 9 giay => coi nhu vua quay lai */
        setInterval(function () {
            var n = Date.now();
            if (n - beat > 9000 && document.visibilityState === 'visible') { comeback(); }
            beat = n;
        }, 3000);
    } catch (e) {}
})();
</script>
<!--DS-SMOOTH-JS-END-->

<script id="ds-logo-early">
/* patch spinner: lap loa logo chay SOM (khong cho su kien load), bat dau khi logo da tai xong => het giat 1 phat luc vao web */
(function () {
    try {
        if (window.__dsLgEarly) return;
        window.__dsLgEarly = 1;
        var d = document.documentElement, t0 = Date.now(), done = 0;
        function go() { if (done) return; done = 1; d.classList.remove('ds-lg-off'); }
        function ready() {
            var l = document.querySelectorAll('.ds-logo img');
            if (!l.length) return false;
            for (var i = 0; i < l.length; i++) { if (!l[i].complete) return false; }
            return true;
        }
        function step() {
            if (done) return;
            if (document.visibilityState === 'hidden') { setTimeout(step, 300); return; }
            if (document.readyState !== 'loading' && ready()) { go(); return; }
            if (Date.now() - t0 > 1800) { go(); return; }
            setTimeout(step, 40);
        }
        step();
    } catch (e) {}
})();
</script>
<script id="ds-patch-p25-key">
/* patch_v25:
   - Gi?i h?n 1 tài kho?n / 1 d?a ch? IP th?t ch? du?c kích ho?t Key 1 l?n trong ngày hôm nay, qua ngày mai m?i du?c nh?p ti?p.
   - Hi?n thông báo d?u X d? gi?ng h?t thông báo sai key khi c? nh?p l?i trong ngày (dù dang xu?t, t?o tài kho?n m?i hay b?t ?n danh). */
(function () {
    try {
        if (window.__dsKeyAjax) return;
        window.__dsKeyAjax = true;
        var SERVER_LOCKED_TODAY = <?php echo ds_is_daily_key_locked() ? "true" : "false"; ?>; var LOCK_MS = <?php echo (int) ds_daily_key_hours() * 3600 * 1000; ?>; var UNLOCK_EPOCH = <?php echo function_exists('dsx_cfg') ? (int) dsx_cfg('ds_key_unlock_epoch', '0') : 0; ?>;
        var SERVER_TODAY_STR    = "<?php echo date("Y-m-d"); ?>";
        var DAILY_LIMIT_MSG = "You have already activated a key recently. Please try again in <?php echo (int) ds_daily_key_hours(); ?> hours.";
        var LIMITED_PLANS = <?php echo json_encode(defined('KEY_DAILY_LIMIT_TYPES') ? array_map('strtolower', (array) KEY_DAILY_LIMIT_TYPES) : array('vip', 'free')); ?>; /* patch: v25-theme-fix */
        function planLimited(p) { return LIMITED_PLANS.indexOf(String(p || '').toLowerCase()) !== -1; }
        var busy = false, tm = 0, SHOW = 3800;
        var TR = 'opacity .3s ease, transform .3s cubic-bezier(.22, .61, .36, 1), max-height .3s cubic-bezier(.22, .61, .36, 1), margin-top .3s cubic-bezier(.22, .61, .36, 1)';
        var SO = '<svg class="toast__icon" viewBox="0 0 24 24" aria-hidden="true">';
        var ICONS = {
            success: SO + '<path class="draw" pathLength="1" d="M5.5 12.8l4.2 4.2 8.8-9.5"/></svg>',
            error: SO + '<path class="draw" pathLength="1" d="M7 7l10 10"/><path class="draw" pathLength="1" d="M17 7L7 17" style="animation-delay:.25s"/></svg>',
            warning: SO + '<g class="bell"><path d="M6 16.6v-5a6 6 0 0 1 12 0v5l1.6 2H4.4z" fill="currentColor" stroke-width="1.6"/><path d="M10 21.2a2.1 2.1 0 0 0 4 0"/></g>'
                + '<path class="wave" d="M2.4 10.8a9.8 9.8 0 0 1 2.3-5.4"/><path class="wave" d="M21.6 10.8a9.8 9.8 0 0 0-2.3-5.4"/></svg>',
            info: SO + '<path class="bang-stem" d="M12 5.2v8.6"/><path class="bang-dot" d="M12 18.6h.01"/></svg>'
        };

        function todayStr() {
            var d = new Date();
            return d.getFullYear() + '-' + (d.getMonth() + 1 < 10 ? '0' : '') + (d.getMonth() + 1) + '-' + (d.getDate() < 10 ? '0' : '') + d.getDate();
        }
        function isLocalLockedToday() {
            /* lock by time (LOCK_MS), not by calendar day. Old 'YYYY-MM-DD' values are ignored. */
            var now = Date.now(), t = 0;
            try { t = parseInt(localStorage.getItem('ds-daily-key-lock'), 10) || 0; } catch (e) {}
            if (t > 1e12 && t > UNLOCK_EPOCH && now - t < LOCK_MS && now >= t - 60000) return true;
            try {
                var m = /(?:^|;\s*)ds_kday=(\d+)/.exec(document.cookie || '');
                if (m) {
                    t = parseInt(m[1], 10) || 0;
                    if (t > 1e12 && t > UNLOCK_EPOCH && now - t < LOCK_MS && now >= t - 60000) return true;
                }
            } catch (e) {}
            return false;
        }
        function setLocalLockedToday(keyVal) {
            SERVER_LOCKED_TODAY = true;
            var td = String(Date.now());
            try {
                localStorage.setItem('ds-daily-key-lock', td);
                if (keyVal) {
                    var usedKeys = JSON.parse(localStorage.getItem('ds-used-keys') || '{}') || {};
                    usedKeys[String(keyVal).trim().toUpperCase()] = td;
                    localStorage.setItem('ds-used-keys', JSON.stringify(usedKeys));
                }
            } catch (e) {}
            try {
                document.cookie = 'ds_kday=' + td + '; path=/; max-age=' + Math.round(LOCK_MS / 1000) + '; SameSite=Lax';
            } catch (e) {}
            try {
                var fd = new FormData();
                if (keyVal) fd.append('key', keyVal);
                fetch(window.location.pathname + '?ds_mark_key_ok=1', { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
            } catch (e) {}
        }
        function isKeyAlreadyUsed(keyVal) {
            if (!keyVal) return false;
            try {
                var usedKeys = JSON.parse(localStorage.getItem('ds-used-keys') || '{}') || {};
                var t = parseInt(usedKeys[String(keyVal).trim().toUpperCase()], 10) || 0;
                return t > 1e12 && t > UNLOCK_EPOCH && Date.now() - t < LOCK_MS;
            } catch (e) { return false; }
        }

        /*PATCH-V25-FIX3*/
        var lockMemo = null;
        function lockFetch() {
            return fetch(window.location.pathname + '?ds_check_key_lock=1', { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) { lockMemo = { t: Date.now(), d: d }; return d; });
        }
        function dsLockCheck() {
            if (lockMemo && Date.now() - lockMemo.t < 20000) {
                var d0 = lockMemo.d;
                return Promise.resolve({ json: function () { return d0; } });
            }
            return lockFetch().then(function (d) { return { json: function () { return d; } }; });
        }
        document.addEventListener('focusin', function (e) {
            var t = e.target;
            if (!t || !t.closest || !t.closest('form[data-key-form]')) return;
            if (lockMemo && Date.now() - lockMemo.t < 20000) return;
            lockFetch().catch(function () {});
        }, true);
        function appCfg() {
            try { return JSON.parse(document.getElementById('app-config').textContent) || {}; } catch (e) { return {}; }
        }
        function fmt(c, key, vars) {
            var tpl = c && c.notices ? c.notices[key] : '';
            if (!tpl) return '';
            var v = { n: 0, limit: c.freeLimit, turns: c.turns, plan: c.keyType, minutes: c.lockMinutes };
            for (var k in vars) { if (Object.prototype.hasOwnProperty.call(vars, k)) v[k] = vars[k]; }
            return String(tpl).replace(/\{(\w+)\}/g, function (m, name) { return (name in v) ? v[name] : m; });
        }
        function im(el, p, v) { el.style.setProperty(p, v, 'important'); }
        function counterVal() {
            var el = document.getElementById('turn-counter'), m = el ? /\d+/.exec(el.textContent || '') : null;
            return m ? parseInt(m[0], 10) : 0;
        }
        function updateDomTurns(newTurns, newPlan) {
            var tc = document.getElementById('turn-counter');
            if (tc && !isNaN(newTurns)) {
                tc.textContent = String(tc.textContent || '').replace(/\d+/, String(newTurns));
                if (!/\d+/.test(tc.textContent || '')) tc.textContent = String(newTurns);
            }
            var ut = document.getElementById('ds-um-turns');
            if (ut && !isNaN(newTurns)) ut.textContent = String(newTurns);
            if (newPlan) {
                var pNorm = /vip/i.test(newPlan) ? 'VIP' : 'Free';
                var up = document.getElementById('ds-um-plan'), mp = document.getElementById('ds-me-plan');
                if (up) { up.textContent = pNorm; up.className = 'ds-badge' + (pNorm === 'VIP' ? ' is-vip' : ''); }
                if (mp) { mp.textContent = pNorm; mp.className = 'ds-badge' + (pNorm === 'VIP' ? ' is-vip' : ''); }
                var ubp = document.querySelector('.user-bar__plan');
                if (ubp) {
                    ubp.textContent = pNorm;
                    ubp.classList.toggle('is-vip', pNorm === 'VIP');
                    ubp.classList.toggle('is-free', pNorm !== 'VIP');
                }
            }
            document.querySelectorAll('.ds-row.is-locked, .ds-get.is-locked').forEach(function (el) {
                el.classList.remove('is-locked');
            });
            document.querySelectorAll('#menu [data-tool-id].is-locked').forEach(function (el) {
                el.classList.remove('is-locked');
            });
        }

        function clearOld(anchor) {
            clearTimeout(tm);
            Array.prototype.slice.call(anchor.querySelectorAll('.toast')).forEach(function (n) {
                if (n.getAttribute('data-ds-c') && !n.__gone && n.parentNode && n.offsetHeight > 0) { leave(n); return; }
                n.__gone = true;
                if (n.parentNode) n.parentNode.removeChild(n);
            });
            var need = document.getElementById('ds-need');
            if (need) { need.hidden = true; need.classList.remove('is-in'); need.classList.remove('is-out'); }
        }
        function enter(c, anchor) {
            im(c, 'animation', 'none'); im(c, 'overflow', 'hidden'); im(c, 'transition', 'none');
            im(c, 'opacity', '0'); im(c, 'transform', 'translate3d(0,-6px,0)'); im(c, 'max-height', '0px'); im(c, 'margin-top', '0px');
            anchor.appendChild(c);
            var h = c.scrollHeight;
            void c.offsetWidth;
            im(c, 'transition', TR);
            im(c, 'opacity', '1'); im(c, 'transform', 'none'); im(c, 'max-height', (Math.max(h, 24) + 2) + 'px'); im(c, 'margin-top', '10px');
            setTimeout(function () { if (!c.__gone) { c.style.removeProperty('max-height'); c.style.removeProperty('overflow'); } }, 380);
        }
        function leave(c) {
            if (!c || c.__gone || !c.parentNode) return;
            c.__gone = true;
            var h = c.offsetHeight;
            im(c, 'overflow', 'hidden'); im(c, 'transition', 'none'); im(c, 'max-height', h + 'px');
            void c.offsetWidth;
            im(c, 'transition', TR);
            im(c, 'opacity', '0'); im(c, 'transform', 'translate3d(0,-4px,0)'); im(c, 'max-height', '0px'); im(c, 'margin-top', '0px');
            setTimeout(function () { if (c.parentNode) c.parentNode.removeChild(c); }, 360);
        }
        function show(text, kind) {
            var de0 = document.documentElement, ac0 = document.activeElement;
            var typing0 = de0.classList.contains('ds-typing') || !!(ac0 && /^(INPUT|TEXTAREA)$/.test(ac0.tagName));
            clearTimeout(window.__dsShowTm);
            if (typing0) {
                try { if (ac0 && ac0.blur) ac0.blur(); } catch (e) {}
                de0.classList.remove('ds-typing');
                window.__dsShowTm = setTimeout(function () { showNow(text, kind); }, 260);
                return;
            }
            showNow(text, kind);
        }
        function showNow(text, kind) {
            var anchor = document.querySelector('.toast-anchor');
            if (!anchor || !text) return;
            clearOld(anchor);
            kind = ICONS[kind] ? kind : 'info';
            var c = document.createElement('div');
            c.className = 'toast toast--' + kind;
            c.setAttribute('data-ds-c', '1');
            c.setAttribute('role', (kind === 'error' || kind === 'warning') ? 'alert' : 'status');
            c.innerHTML = ICONS[kind];
            var lines = String(text).split('\n'), title = lines.shift(), rest = lines.join('\n').trim();
            var body = document.createElement('div'), t = document.createElement('strong');
            body.className = 'toast__body';
            t.className = 'toast__title';
            t.textContent = title;
            body.appendChild(t);
            if (rest) { var x = document.createElement('span'); x.className = 'toast__text'; x.textContent = rest; body.appendChild(x); }
            c.appendChild(body);
            enter(c, anchor);
            tm = setTimeout(function () { leave(c); }, SHOW);
        }
                /* v46 (muot): thong bao hien / tat MOT NHIP: cao + mo + truot chay cung luc, bat dau o khung hinh ke tiep.
           Truoc day thong bao hien la day ca trang xuong 30px trong 1 khung hinh (giat). */
        var TR46 = 'opacity .34s cubic-bezier(.22, .61, .36, 1), transform .34s cubic-bezier(.22, .61, .36, 1), max-height .34s cubic-bezier(.22, .61, .36, 1), margin-top .34s cubic-bezier(.22, .61, .36, 1)';
        function enter(c, a46) {
            var an46 = a46 || anchor;
            im(c, 'animation', 'none'); im(c, 'transition', 'none'); im(c, 'overflow', 'hidden');
            im(c, 'opacity', '0'); im(c, 'transform', 'translate3d(0,-6px,0)'); im(c, 'max-height', '0px'); im(c, 'margin-top', '0px');
            im(c, 'will-change', 'opacity, transform');
            an46.appendChild(c);
            var h = c.scrollHeight;
            requestAnimationFrame(function () {
                if (c.__gone) return;
                im(c, 'transition', TR46);
                im(c, 'opacity', '1'); im(c, 'transform', 'none'); im(c, 'max-height', (Math.max(h, 24) + 2) + 'px'); im(c, 'margin-top', '10px');
                setTimeout(function () {
                    if (!c.__gone) { c.style.removeProperty('max-height'); c.style.removeProperty('overflow'); c.style.removeProperty('will-change'); }
                }, 420);
            });
        }
        function leave(c) {
            if (!c || c.__gone || !c.parentNode) return;
            c.__gone = true;
            if (typeof cur !== 'undefined' && cur === c) cur = null;
            var h = c.offsetHeight;
            im(c, 'overflow', 'hidden'); im(c, 'transition', 'none'); im(c, 'max-height', h + 'px'); im(c, 'will-change', 'opacity, transform');
            void c.offsetWidth;
            requestAnimationFrame(function () {
                im(c, 'transition', TR46);
                im(c, 'opacity', '0'); im(c, 'transform', 'translate3d(0,-4px,0)'); im(c, 'max-height', '0px'); im(c, 'margin-top', '0px');
            });
            setTimeout(function () { if (c.parentNode) c.parentNode.removeChild(c); }, 460);
        }
                /* DS-PATCH-V49 (thong bao Keys): hien / tat NGAY, khong cho khung hinh, khong hen gio. Cao + mo + truot chay cung nhip (1 lan chot style roi chay luon). */
        var TR49 = 'opacity .3s cubic-bezier(.22, .61, .36, 1), transform .3s cubic-bezier(.22, .61, .36, 1), max-height .3s cubic-bezier(.22, .61, .36, 1), margin-top .3s cubic-bezier(.22, .61, .36, 1)';
        function enter(c, a49) {
            var an49 = a49 || anchor;
            im(c, 'animation', 'none'); im(c, 'transition', 'none'); im(c, 'overflow', 'hidden');
            im(c, 'opacity', '0'); im(c, 'transform', 'translate3d(0,-6px,0)'); im(c, 'max-height', '0px'); im(c, 'margin-top', '0px');
            an49.appendChild(c);
            var h = c.scrollHeight;
            im(c, 'transition', TR49);
            im(c, 'opacity', '1'); im(c, 'transform', 'none'); im(c, 'max-height', (Math.max(h, 24) + 2) + 'px'); im(c, 'margin-top', '10px');
            setTimeout(function () { if (!c.__gone) { c.style.removeProperty('max-height'); c.style.removeProperty('overflow'); } }, 340);
        }
        function leave(c) {
            if (!c || c.__gone || !c.parentNode) return;
            c.__gone = true;
            if (typeof cur !== 'undefined' && cur === c) cur = null;
            var h = c.offsetHeight;
            im(c, 'overflow', 'hidden'); im(c, 'transition', 'none'); im(c, 'max-height', h + 'px');
            void c.offsetWidth;
            im(c, 'transition', TR49);
            im(c, 'opacity', '0'); im(c, 'transform', 'translate3d(0,-4px,0)'); im(c, 'max-height', '0px'); im(c, 'margin-top', '0px');
            setTimeout(function () { if (c.parentNode) c.parentNode.removeChild(c); }, 340);
        }

        /* DS-PATCH-V49: hien thong bao ngay trong cung khung hinh (truoc day cho them 16ms sau khi bo focus o nhap key) */
        function show(text, kind) {
            clearTimeout(window.__dsShowTm);
            showNow(text, kind);
            var ac0 = document.activeElement;
            if (ac0 && /^(INPUT|TEXTAREA)$/.test(ac0.tagName)) {
                try { ac0.blur(); } catch (x) {}
                document.documentElement.classList.remove('ds-typing');
            }
        }
        /* DS-PATCH-V49: doc ket qua key ngay khi khoi app-config xuat hien trong luong tra ve (khong cho het ca trang), phan con lai van doc het de khong cat ket noi */
        function readCfg(res) {
            var RE49 = /<script type="application\/json" id="app-config">([\s\S]*?)<\/script>/;
            if (!res.body || !res.body.getReader || !window.TextDecoder) {
                return res.text().then(function (h) { return { url: res.url || '', html: h }; });
            }
            return new Promise(function (resolve, reject) {
                var rd = res.body.getReader(), dec = new TextDecoder('utf-8'), buf = '', out = false;
                function pump() {
                    rd.read().then(function (st) {
                        if (st.done) {
                            if (!out) { out = true; resolve({ url: res.url || '', html: buf + dec.decode() }); }
                            return;
                        }
                        if (out) { pump(); return; }
                        buf += dec.decode(st.value, { stream: true });
                        if (RE49.test(buf)) { out = true; resolve({ url: res.url || '', html: buf }); buf = ''; }
                        pump();
                    }).catch(function (err) { if (!out) { out = true; reject(err); } });
                }
                pump();
            });
        }
        function showKey(c, key, vars) {
            var tx = fmt(c, key, vars);
            if (tx) show(tx, (c.kinds && c.kinds[key]) || 'info');
        }
        function finish(form) {
            try { form.reset(); } catch (e) {}
            try { var a = document.activeElement; if (a && /^(INPUT|TEXTAREA)$/.test(a.tagName)) a.blur(); } catch (e) {}
        }

        function refreshState(expected) {
            var done = false;
            function ok() { return counterVal() === expected; }
            [450, 1800, 3600].forEach(function (ms, i) {
                setTimeout(function () {
                    if (done) return;
                    if (i > 0 && ok()) { done = true; return; }
                    try { document.dispatchEvent(new Event('visibilitychange')); } catch (e) {}
                }, ms);
            });
        }

        function detectFormPlan(form) {
            var card = form.closest('section.card');
            if (card) {
                var ttl = card.querySelector('.card__title');
                if (ttl && /vip/i.test(ttl.textContent || '')) return 'vip';
                if (ttl && /free/i.test(ttl.textContent || '')) return 'free';
            }
            var inp = form.querySelector('input[name]');
            if (inp && /vip/i.test(inp.name || '')) return 'vip';
            return 'free';
        }

        function run(form, btn) {
            if (!form || busy) return;
            var c = appCfg();
            var field = form.querySelector('input.field') || form.querySelector('input[type="text"]');
            var val = field ? String(field.value || '').trim() : '';

            if (!c.loggedIn) { showKey(c, 'not_logged_in'); finish(form); return; }
            if (!val) {
                if (form.reportValidity) form.reportValidity(); else if (field) field.focus();
                return;
            }

            /* Ch?n ngay b?ng d?u X d? (error) n?u Ð?a ch? IP th?t ho?c Tài kho?n dã kích ho?t Key trong ngày hôm nay, ho?c Key dã t?ng nh?p */
            if (planLimited(detectFormPlan(form)) && (SERVER_LOCKED_TODAY || isLocalLockedToday() || isKeyAlreadyUsed(val))) {
                show(DAILY_LIMIT_MSG, 'error');
                finish(form);
                return;
            }

            var turnsBefore = counterVal();
            if (!(c.adminExempt && c.admin) && turnsBefore > 0) { showKey(c, 'has_turn', { turns: turnsBefore }); finish(form); return; }

            var guessedPlan = detectFormPlan(form);
            if (btn) { btn.style.opacity = '.6'; }
            busy = true;
            var fd = new FormData(form);
            if (btn && btn.name) fd.append(btn.name, btn.value || '');
            var ctl = window.AbortController ? new AbortController() : null;
            var to = setTimeout(function () { if (ctl) ctl.abort(); }, 15000);
            var okReload = false;
            try { var a0 = document.activeElement; if (a0 && /^(INPUT|TEXTAREA)$/.test(a0.tagName)) a0.blur(); } catch (e) {}

            /* Ki?m tra khóa IP th?t trên server tru?c khi cho phép kích ho?t */
            (planLimited(guessedPlan) ? dsLockCheck() : Promise.resolve({ json: function () { return { locked: false }; } }))
                .then(function (r) { return r.json(); })
                .catch(function () { return { locked: false }; })
                .then(function (st) {
                    if (st && st.locked) {
                        SERVER_LOCKED_TODAY = true;
                        show(DAILY_LIMIT_MSG, 'error');
                        finish(form);
                        return null;
                    }
                    return fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store', redirect: 'follow', signal: ctl ? ctl.signal : undefined });
                })
                .then(function (res) {
                    if (!res) return null;
                    return readCfg(res);
                })
                .then(function (r) {
                    if (!r) return;
                    var m = /<script type="application\/json" id="app-config">([\s\S]*?)<\/script>/.exec(r.html), c2 = null;
                    if (m) { try { c2 = JSON.parse(m[1]); } catch (e) { c2 = null; } }
                    if (!c2) { okReload = true; return; }
                    var n = c2.notice, status = '';
                    try { status = new URL(r.url, window.location.href).searchParams.get('status') || ''; } catch (e) {}
                    if (n && n.text) show(n.text, n.kind); else showKey(c, 'network_error');
                    finish(form);

                    var turnsAfter = parseInt(c2.turns, 10) || 0;
                    var isSuccess = (status === 'success') || (n && n.kind === 'success') || (turnsAfter > turnsBefore);
                    if (isSuccess) {
                        /* Khóa ngay IP th?t + Tài kho?n + Key cho ngày hôm nay */
                        if (planLimited(c2.keyType ? String(c2.keyType) : guessedPlan)) { setLocalLockedToday(val); }
                        var addedTurns = turnsAfter > turnsBefore ? (turnsAfter - turnsBefore) : (turnsAfter > 0 ? turnsAfter : 1);
                        var finalPlan = c2.keyType ? String(c2.keyType).toLowerCase() : guessedPlan;
                        var labelName = (/vip/i.test(finalPlan) ? 'VIP Key Activated' : 'Free Key Activated');
                        updateDomTurns(turnsAfter, finalPlan);
                        refreshState(turnsAfter);
                        try {
                            if (window.__dsLogIn) window.__dsLogIn(addedTurns, finalPlan, labelName);
                        } catch (e) {}
                    }
                })
                .catch(function () { showKey(c, 'network_error'); finish(form); })
                .then(function () {
                    clearTimeout(to);
                    busy = false;
                    if (btn) { btn.style.opacity = ''; }
                    if (okReload) window.location.reload();
                });
        }

        document.addEventListener('click', function (e) {
            var b = e.target && e.target.closest ? e.target.closest('form[data-key-form] button[type="submit"], form[data-key-form] input[type="submit"]') : null;
            if (!b) return;
            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) e.stopImmediatePropagation();
            run(b.form || b.closest('form'), b);
        }, true);
        document.addEventListener('submit', function (e) {
            var f = e.target;
            if (!f || !f.matches || !f.matches('form[data-key-form]')) return;
            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) e.stopImmediatePropagation();
            run(f, e.submitter || f.querySelector('button[type="submit"]'));
        }, true);
    } catch (e) {}
})();
</script>
<!--DS-P25-KEY-END-->
<!--DS-DOCS-SYNC-BEGIN-->
<script id="ds-docs-sync">
(function () {
    try {
        var KEY = 'ds-docs-acc', root = document.documentElement, tm = 0;
        function clean(t) { return String(t == null ? '' : t).replace(/\s+/g, ' ').trim(); }
        function snap() {
            var lg = document.querySelector('.site-header .brand__logo'), logo = lg ? (lg.currentSrc || lg.src || '') : '';
            if (!logo) return;
            var s = { logo: logo, logged: !root.classList.contains('ds-guest'), name: '', plan: 'Member', turns: null, avatar: '' };
            if (s.logged) {
                var n = document.getElementById('ds-um-name'); s.name = clean(n ? n.textContent : '');
                var p = document.getElementById('ds-um-plan'), pt = clean(p ? p.textContent : '');
                s.plan = (pt === 'VIP' || pt === 'Free') ? pt : 'Member';
                var t = document.getElementById('ds-um-turns'), m = t ? /\d+/.exec(t.textContent || '') : null;
                if (m) s.turns = parseInt(m[0], 10);
                var ai = document.querySelector('#ds-avatar .ds-av__img');
                if (ai && ai.getAttribute('src')) s.avatar = ai.getAttribute('src');
            }
            try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {}
        }
        function later() { clearTimeout(tm); tm = setTimeout(snap, 700); }
        setTimeout(snap, 400);
        setTimeout(snap, 1800);
        window.addEventListener('load', later, { once: true });
        window.addEventListener('pagehide', snap);
        if (window.MutationObserver) {
            var t2 = document.getElementById('ds-um-turns'), b2 = document.querySelector('.user-bar');
            if (t2) new MutationObserver(later).observe(t2, { childList: true, characterData: true, subtree: true });
            if (b2) new MutationObserver(later).observe(b2, { childList: true, characterData: true, subtree: true });
        }
    } catch (e) {}
})();
</script>
<!--DS-DOCS-SYNC-END-->

<!--DS-P26-BEGIN-->
<style id="ds-patch-v28">
    html.ds-shell #ds-scroll { transition: padding-bottom .3s cubic-bezier(.22, .61, .36, 1); }
    @media (prefers-reduced-motion: reduce) { html.ds-shell #ds-scroll { transition: none; } }
</style>
<style id="ds-patch-p26">
    html:root:root:root:root.ds-guest #ds-keys { display: none !important; }
    @keyframes ds-smooth26 { from { opacity: 0; transform: translate3d(0, 10px, 0); } to { opacity: 1; transform: none; } }
    html:root:root:root:root.ds-keys .ds-softin,
    html:root:root:root:root.ds-tools .ds-softin,
    html:root:root:root:root.ds-hist .ds-softin,
    html:root:root:root:root.ds-me .ds-softin { animation: ds-smooth26 .25s cubic-bezier(.22, .61, .36, 1) both !important; }
    html:root:root:root:root .ds-nav__item { transition: background-color .18s ease, color .18s ease !important; }
</style>
<script id="ds-patch-p26-js">
(function () {
    try {
        var root = document.documentElement;
        if (!root.classList.contains('ds-guest')) return;
        function loginUrl() {
            var a = document.querySelector('.ds-nav a.ds-nav__item[href]:not(#ds-home)');
            var h = a ? a.getAttribute('href') : '';
            return (h && h.charAt(0) !== '#') ? h : '/dang-nhap';
        }
        document.addEventListener('click', function (e) {
            var g = e.target && e.target.closest ? e.target.closest('.ds-get') : null;
            if (!g) return;
            e.preventDefault();
            e.stopPropagation();
            if (e.stopImmediatePropagation) e.stopImmediatePropagation();
            window.location.href = loginUrl();
        }, true);
        var leaving = false;
        function leaveKeys() {
            if (leaving || !root.classList.contains('ds-keys')) return;
            leaving = true;
            try { sessionStorage.setItem('ds-view', 'home'); } catch (e) {}
            var h = document.getElementById('ds-home');
            if (h) h.click(); else root.classList.remove('ds-keys');
            leaving = false;
        }
        leaveKeys();
        if (window.MutationObserver) {
            new MutationObserver(leaveKeys).observe(root, { attributes: true, attributeFilter: ['class'] });
        }
    } catch (e) {}
})();
</script>
<script id="ds-patch-p26-load">
/* Khi trang t?i l?i sau khi kích ho?t key thành công => ghi vào L?ch s? & khóa IP ngày hôm dó */
(function () {
    try {
        var el = document.getElementById('app-config');
        var c = el ? JSON.parse(el.textContent) : null;
        if (c && c.loggedIn && c.notice && c.notice.kind === 'success' && (parseInt(c.turns, 10) || 0) > 0) {
            var plan = c.keyType ? String(c.keyType).toLowerCase() : 'free';
            var label = /vip/i.test(plan) ? 'VIP Key Activated' : 'Free Key Activated';
            if (window.__dsLogIn) window.__dsLogIn(parseInt(c.turns, 10) || 1, plan, label);
        }
    } catch (e) {}
})();
</script>
<script id="ds-patch-p26-clean">
/* B?o v? L?ch s?:
   1. Lo?i b? toàn b? các dòng l?ch s? l?i ghi 0 lu?t (n <= 0)
   2. M?i s?n ph?m b?m GET qua link g?c ch? xu?t hi?n dúng 1 l?n trong cùng 1 ngày (sang ngày mai b?m qua link g?c m?i hi?n l?i) */
(function () {
    try {
        function dKey(ms) {
            var d = ms ? new Date(ms) : new Date();
            return d.getFullYear() + '-' + (d.getMonth() + 1 < 10 ? '0' : '') + (d.getMonth() + 1) + '-' + (d.getDate() < 10 ? '0' : '') + d.getDate();
        }
        function dedupeList(arr, oldInMap, allowInNow) {
            var seenOutDay = {};
            return arr.filter(function (x) {
                if (!x || !x.n || parseInt(x.n, 10) <= 0) return false;
                if (x.k === 'in') {
                    if (oldInMap && oldInMap[x.t]) return true;
                    if (x.a === 1) return true;
                    if (allowInNow) { x.a = 1; return true; }
                    return false;
                }
                /* V?i l?ch s? m? tool ('out'): ch? gi? dúng 1 l?n / 1 s?n ph?m / 1 ngày */
                var day = x.day || dKey(x.t);
                var prodKey = day + '|' + (x.id ? ('id:' + x.id) : ('nm:' + (x.nm || '')));
                if (seenOutDay[prodKey]) return false;
                seenOutDay[prodKey] = 1;
                return true;
            });
        }
        if (!window.__dsHistGuard) {
            window.__dsHistGuard = true;
            var rawSet = Storage.prototype.setItem;
            Storage.prototype.setItem = function (k, v) {
                try {
                    if (typeof k === 'string' && k.indexOf('ds-hist:') === 0) {
                        var arr = JSON.parse(v);
                        if (Array.isArray(arr)) {
                            var old = [], seen = {};
                            try { old = JSON.parse(this.getItem(k) || '[]') || []; } catch (e) {}
                            old.forEach(function (x) { if (x && x.k === 'in') seen[x.t] = 1; });
                            arr = dedupeList(arr, seen, !!window.__dsAllowIn);
                            v = JSON.stringify(arr);
                        }
                    }
                } catch (e) {}
                return rawSet.call(this, k, v);
            };
        }
        for (var i = 0; i < localStorage.length; i++) {
            var key = localStorage.key(i);
            if (!key || key.indexOf('ds-hist:') !== 0) continue;
            var list = null;
            try { list = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { continue; }
            if (!Array.isArray(list)) continue;
            var out = dedupeList(list, null, false);
            if (out.length !== list.length) {
                Storage.prototype.setItem.call(localStorage, key, JSON.stringify(out));
            }
        }
    } catch (e) {}
})();
</script>
<style id="ds-patch-p27">
    /* patch_v25 ban 2: thong bao nhap key chi dung opacity + transform, het giat */
    @keyframes ds-need-in40 { from { opacity: 0; transform: translate3d(0, -6px, 0); } to { opacity: 1; transform: none; } }
    @keyframes ds-need-out40 { from { opacity: 1; } to { opacity: 0; } }
    html:root:root:root:root:root .ds-need.is-in { animation-duration: .22s !important; }
    html:root:root:root:root:root .ds-need.is-out { animation-duration: .22s !important; }
</style>
<style id="ds-patch-p28">
    /* patch_v25 ban 3: banner anh keo tay 2 chieu vo han (dung transform, khong dung cuon ngang) */
    html:root:root:root .ds-slider.ds-sl2 .ds-slider__track { overflow: hidden !important; scroll-snap-type: none !important; touch-action: pan-y; cursor: grab; }
    html:root:root:root .ds-slider.ds-sl2 .ds-slide { scroll-snap-align: none; scroll-snap-stop: normal; will-change: transform; -webkit-backface-visibility: hidden; backface-visibility: hidden; }
    html:root:root:root .ds-slider.ds-sl2.is-drag .ds-slider__track { cursor: grabbing; }
</style>
<script id="ds-patch-p28-js">
/*PATCH-V25-FIX3*/
(function () {
    try {
        var sl = document.getElementById('ds-slider'), hero = document.getElementById('ds-hero');
        if (!sl || sl.classList.contains('ds-sl2')) return;
        var old = sl.querySelector('.ds-slider__track');
        if (!old) return;
        var slides = [];
        Array.prototype.forEach.call(old.children, function (el) {
            if (el.getAttribute('aria-hidden') !== 'true') slides.push(el);
        });
        var n = slides.length;
        if (n < 1) return;
        var tr = old.cloneNode(false);
        slides.forEach(function (el) { tr.appendChild(el); });
        old.parentNode.replaceChild(tr, old);
        sl.classList.add('ds-sl2');
        if (n < 2) return;

        var SEC = Math.max(2000, (parseFloat(hero ? hero.getAttribute('data-sec') : '') || 3.5) * 1000);
        var pos = 0, anim = 0, dragging = false, moved = false, pid = -1, sx = 0, sp = 0, lx = 0, lt = 0, vx = 0, timer = 0, loaded = false;

        function loadAll() {
            if (loaded) return;
            loaded = true;
            slides.forEach(function (s) {
                var im = s.querySelector('img[data-src]');
                if (!im) return;
                var u = im.getAttribute('data-src');
                im.removeAttribute('data-src');
                if (u) im.src = u;
            });
        }
        function render() {
            for (var i = 0; i < n; i++) {
                var off = i - pos;
                off -= n * Math.round(off / n);
                slides[i].style.transform = 'translate3d(' + ((off - i) * 100).toFixed(3) + '%,0,0)';
            }
        }
        function ease(t) { return 1 - Math.pow(1 - t, 3); }
        function animateTo(target, ms) {
            cancelAnimationFrame(anim);
            var from = pos, t0 = null;
            function step(ts) {
                if (t0 === null) t0 = ts;
                var k = Math.min(1, (ts - t0) / ms);
                pos = from + (target - from) * ease(k);
                render();
                if (k < 1) { anim = requestAnimationFrame(step); }
                else { anim = 0; pos = ((target % n) + n) % n; render(); }
            }
            anim = requestAnimationFrame(step);
        }
        function visible() {
            if (!hero || hero.offsetParent === null) return false;
            var r = sl.getBoundingClientRect();
            return r.bottom > 0 && r.top < (window.innerHeight || 800);
        }
        function tick() {
            if (dragging || anim || document.hidden || window.__dsScrolling || !visible()) return;
            loadAll();
            animateTo(Math.round(pos) + 1, 520);
        }
        function startAuto() { clearInterval(timer); timer = setInterval(tick, SEC); }

        tr.addEventListener('pointerdown', function (e) {
            if (e.isPrimary === false || (e.pointerType === 'mouse' && e.button !== 0)) return;
            cancelAnimationFrame(anim);
            anim = 0;
            clearInterval(timer);
            loadAll();
            dragging = true; moved = false; pid = e.pointerId;
            sx = lx = e.clientX; sp = pos; lt = e.timeStamp; vx = 0;
            sl.classList.add('is-drag');
        });
        tr.addEventListener('pointermove', function (e) {
            if (!dragging || e.pointerId !== pid) return;
            var W = tr.clientWidth || 1, dx = e.clientX - sx;
            if (!moved) {
                if (Math.abs(dx) < 4) return;
                moved = true;
                try { tr.setPointerCapture(pid); } catch (x) {}
            }
            var dt = e.timeStamp - lt;
            if (dt > 0) vx = 0.6 * vx + 0.4 * ((e.clientX - lx) / dt);
            lx = e.clientX; lt = e.timeStamp;
            pos = sp - dx / W;
            render();
        });
        function end(e) {
            if (!dragging) return;
            if (e && e.pointerId !== pid) return;
            dragging = false;
            sl.classList.remove('is-drag');
            try { tr.releasePointerCapture(pid); } catch (x) {}
            var W = tr.clientWidth || 1;
            if (e && e.timeStamp - lt > 90) vx = 0;
            var d = pos - sp, tot = d - (vx / W) * 260, from = Math.round(sp), step = 0;
            if (tot > 0.18) step = 1; else if (tot < -0.18) step = -1;
            animateTo(from + step, 340);
            startAuto();
        }
        tr.addEventListener('pointerup', end);
        tr.addEventListener('pointercancel', end);
        tr.addEventListener('dragstart', function (e) { e.preventDefault(); });

        render();
        startAuto();
    } catch (e) {}
})();
</script>
<!--DS-P26-END-->
<!--DS-AVTFRAME-BEGIN-->
<style id="ds-avt-frame">
:root{--ds-fr-url:url("https://i.postimg.cc/4dLyBFwv/75453AF3-E3D5-4EAC-9404-7B3F2A205738.png");--ds-fr-k:1.85;--ds-fr-dy:-48.5%}
html body #ds-acc{position:relative;margin-left:8px}
html body #ds-acc::after{content:'';position:absolute;left:50%;top:50%;z-index:3;pointer-events:none;width:calc(var(--hbtn,32px)*var(--ds-fr-k));height:calc(var(--hbtn,32px)*var(--ds-fr-k));transform:translate(-50%,var(--ds-fr-dy));background:var(--ds-fr-url) center/contain no-repeat;-webkit-filter:drop-shadow(0 0 3px rgba(139,92,246,.55));filter:drop-shadow(0 0 3px rgba(139,92,246,.55));animation:ds-fr-pulse 2.8s ease-in-out infinite alternate;will-change:opacity}
html body #ds-me-av{overflow:visible!important;border-radius:50%!important;margin:0 12px 0 24px}
html body #ds-me-av .ds-av__img{border-radius:50%!important;object-fit:cover!important}
html body #ds-me-av::after{content:'';position:absolute;left:50%;top:50%;z-index:3;pointer-events:none;width:calc(56px*var(--ds-fr-k));height:calc(56px*var(--ds-fr-k));transform:translate(-50%,var(--ds-fr-dy));background:var(--ds-fr-url) center/contain no-repeat;-webkit-filter:drop-shadow(0 0 3px rgba(139,92,246,.55));filter:drop-shadow(0 0 3px rgba(139,92,246,.55));animation:ds-fr-pulse 2.8s ease-in-out infinite alternate;will-change:opacity}
html body #ds-mepage .ds-me__top{padding-top:30px!important;padding-bottom:22px!important}
@keyframes ds-fr-pulse{from{opacity:.86}to{opacity:1}}
@media(prefers-reduced-motion:reduce){html body #ds-acc::after,html body #ds-me-av::after{animation:none}}
</style>
<!--DS-AVTFRAME-END-->
<!--DS-SPINNER-V2-BEGIN-->
<style id="ds-spinner-v2">
/* khung app (dau trang chi tiet) nho lai */
html:root:root:root:root:root:root:root body .ds-det .ds-det__top{padding:12px 16px 14px!important}
html:root:root:root:root:root:root:root body .ds-det .ds-det__head{gap:12px!important}
html:root:root:root:root:root:root:root body .ds-det .ds-det__head img{width:66px!important;height:66px!important;border-radius:16px!important}
html:root:root:root:root:root:root:root body .ds-det .ds-det__main h1{font-size:18.5px!important}
html:root:root:root:root:root:root:root body .ds-det .ds-det__main p{margin:1px 0 8px!important;font-size:12.5px!important}
html:root:root:root:root:root:root:root body .ds-det .ds-det__date{margin-top:1px!important;font-size:11.5px!important}
html:root:root:root:root:root:root:root body .ds-det .ds-get{height:27px!important;min-width:72px!important;padding:0 14px 0 12px!important;gap:6px!important;font-size:12px!important}
html:root:root:root:root:root:root:root body .ds-det .ds-get svg{width:15px!important;height:15px!important}
html:root:root:root:root:root:root:root body .ds-det .ds-share{width:28px!important;height:28px!important;min-width:28px!important}
html:root:root:root:root:root:root:root body .ds-det .ds-share svg{width:16px!important;height:16px!important}
/* status gon lai khi co anh xem truoc */
html:root:root:root:root:root:root:root .ds-det--prev .ds-stats{padding:12px 0!important}
html:root:root:root:root:root:root:root .ds-det--prev .ds-stat{padding:0 4px!important;min-width:0!important}
html:root:root:root:root:root:root:root .ds-det--prev .ds-stat small{font-size:10px!important;letter-spacing:.09em!important}
html:root:root:root:root:root:root:root .ds-det--prev .ds-stat b{margin:3px 0 1px!important;font-size:15px!important;letter-spacing:-.01em!important;overflow:hidden!important;text-overflow:ellipsis!important}
html:root:root:root:root:root:root:root .ds-det--prev .ds-stat span{font-size:11px!important;overflow:hidden!important;text-overflow:ellipsis!important}
/* anh xem truoc: moi anh vua khung, khong lo anh ke ben */
html:root:root:root:root:root:root:root .ds-prevbox,html:root:root:root:root:root:root:root .ds-det--prev .ds-prevbox{margin:0 0 24px!important}
html:root:root:root:root:root:root:root .ds-prev{gap:12px!important;padding:0!important;scroll-padding:0!important;scroll-snap-type:x mandatory!important;border-radius:20px}
html:root:root:root:root:root:root:root .ds-prev::after{display:none!important}
html:root:root:root:root:root:root:root .ds-prev img,html:root:root:root:root:root:root:root .ds-det--prev .ds-prev img{flex:0 0 100%!important;width:100%!important;min-width:100%!important;max-width:100%!important;height:auto!important;min-height:140px!important;max-height:min(62vh,440px)!important;aspect-ratio:auto!important;object-fit:contain!important;background:var(--ds-field)!important;scroll-snap-align:start!important;scroll-snap-stop:always!important;cursor:zoom-in}
/* bam vao anh: khung xem anh (lightbox). Moi chuyen dong cham + muot; khong lam mo nen (nang may) */
.ds-lb{position:fixed;left:0;top:0;right:0;bottom:0;z-index:2147483000;display:none;flex-direction:column;align-items:center;justify-content:center;background:rgba(0,0,0,.92);opacity:0;-webkit-transition:opacity .38s ease;transition:opacity .38s ease;touch-action:none;overscroll-behavior:contain;-webkit-user-select:none;user-select:none;-webkit-tap-highlight-color:transparent}
.ds-lb.is-on{display:flex}
.ds-lb.is-in{opacity:1}
.ds-lb button{padding:0;font:inherit;color:#fff;cursor:pointer}
.ds-lb__x{position:absolute;top:calc(env(safe-area-inset-top,0px) + 14px);right:16px;display:flex;align-items:center;justify-content:center;width:46px;height:46px;background:rgba(44,44,48,.92);border:1px solid rgba(255,255,255,.16);border-radius:50%}
.ds-lb__x svg,.ds-lb__n svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round}
.ds-lb__f{position:relative;box-sizing:border-box;display:flex;align-items:center;justify-content:center;width:calc(100vw - 40px);max-width:640px;padding:16px 0;overflow:hidden;background:#000;border:1px solid rgba(255,255,255,.18);border-radius:26px}
.ds-lb__f img{display:block;width:100%;height:auto;max-height:calc(100vh - 230px);max-height:calc(100dvh - 230px);object-fit:contain;will-change:opacity,transform}
.ds-lb__n{position:absolute;top:50%;margin-top:-23px;display:flex;align-items:center;justify-content:center;width:46px;height:46px;background:rgba(40,40,44,.92);border:0;border-radius:50%}
.ds-lb__n--p{left:10px}
.ds-lb__n--n{right:10px}
.ds-lb__n[hidden]{display:none}
.ds-lb__c{margin-top:14px;padding:6px 16px;font-size:17px;font-weight:500;letter-spacing:.02em;color:#fff;background:rgba(0,0,0,.5);border-radius:999px}
/* bam Package / View: cac phan tu hien dan, truot len nhe, moi phan tu tre them mot chut (giong luc ve Home nhung em hon) */
@keyframes ds-gin2{from{opacity:0;-webkit-transform:translate3d(0,14px,0);transform:translate3d(0,14px,0)}}
html:root:root:root:root:root:root:root .ds-gin2{-webkit-animation:ds-gin2 .65s cubic-bezier(.22,.61,.36,1) backwards!important;animation:ds-gin2 .65s cubic-bezier(.22,.61,.36,1) backwards!important;-webkit-animation-delay:calc(var(--ds-gi,0) * 45ms)!important;animation-delay:calc(var(--ds-gi,0) * 45ms)!important}
@media (prefers-reduced-motion:reduce){html:root:root:root:root:root:root:root .ds-gin2{-webkit-animation:none!important;animation:none!important}}

/* spinner-v2-logo: shine chi bat khi trang da tai xong va dang hien; an dan khi thoat web, hien dan khi quay lai */
html:root:root:root:root:root:root:root.ds-lg-off .ds-logo .ds-sw{opacity:0!important}
html:root:root:root:root:root:root:root .ds-logo .ds-sw{-webkit-transition:opacity .6s ease!important;transition:opacity .6s ease!important}
html:root:root:root:root:root:root:root.ds-lg-off .ds-logo .ds-sw{-webkit-transition-duration:.3s!important;transition-duration:.3s!important}
html:root:root:root:root:root:root:root.ds-lg-off .ds-logo .ds-sw,html:root:root:root:root:root:root:root.ds-lg-off .ds-logo .ds-swi{-webkit-animation-play-state:paused!important;animation-play-state:paused!important}
html:root:root:root:root:root:root:root.ds-hid .ds-chips2__track{-webkit-animation-play-state:paused!important;animation-play-state:paused!important}
html:root:root:root:root:root:root:root.ds-hid body #ds-acc::after,html:root:root:root:root:root:root:root.ds-hid body #ds-me-av::after{-webkit-animation-play-state:paused!important;animation-play-state:paused!important}
</style>
<script id="ds-spinner-v2-js">
(function () {
    try {
        if (window.__dsLb2) return;
        window.__dsLb2 = true;
        var lb, img, cnt, bp, bn, list = [], at = 0, sx = 0, sy = 0, strip = null, busy = false, tmA = 0, tmB = 0, tmC = 0;
        function build() {
            if (lb) return;
            lb = document.createElement('div');
            lb.className = 'ds-lb';
            lb.setAttribute('role', 'dialog');
            lb.setAttribute('aria-modal', 'true');
            lb.innerHTML = '<button type="button" class="ds-lb__x" aria-label="Close"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>'
                + '<div class="ds-lb__f"><img alt="" referrerpolicy="no-referrer" draggable="false">'
                + '<button type="button" class="ds-lb__n ds-lb__n--p" aria-label="Previous"><svg viewBox="0 0 24 24"><path d="m15 5-7 7 7 7"/></svg></button>'
                + '<button type="button" class="ds-lb__n ds-lb__n--n" aria-label="Next"><svg viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></svg></button></div>'
                + '<div class="ds-lb__c"></div>';
            document.body.appendChild(lb);
            img = lb.querySelector('.ds-lb__f img');
            cnt = lb.querySelector('.ds-lb__c');
            bp = lb.querySelector('.ds-lb__n--p');
            bn = lb.querySelector('.ds-lb__n--n');
            lb.addEventListener('click', function (e) {
                var t = e.target;
                if (t === lb || (t.closest && t.closest('.ds-lb__x'))) { close(); return; }
                if (t.closest && t.closest('.ds-lb__n--p')) { go(-1); return; }
                if (t.closest && t.closest('.ds-lb__n--n')) { go(1); return; }
            });
            lb.addEventListener('touchstart', function (e) {
                e.stopPropagation();
                if (e.touches && e.touches.length === 1) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }
            }, { passive: true });
            lb.addEventListener('touchmove', function (e) { e.stopPropagation(); if (e.cancelable) e.preventDefault(); }, { passive: false });
            lb.addEventListener('touchend', function (e) {
                e.stopPropagation();
                var t = e.changedTouches && e.changedTouches[0];
                if (!t) return;
                var dx = t.clientX - sx, dy = t.clientY - sy;
                if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.2) go(dx < 0 ? 1 : -1);
            }, { passive: true });
        }
        function show() {
            img.src = list[at];
            cnt.textContent = (at + 1) + ' / ' + list.length;
            bp.hidden = at <= 0;
            bn.hidden = at >= list.length - 1;
            [at - 1, at + 1].forEach(function (i) { if (list[i]) { var n = new Image(); n.src = list[i]; } });
        }
        /* chuyen anh: anh cu mo dan + truot nhe (0.3s), anh moi hien dan + truot vao (0.5s). Cho anh tai xong roi moi hien => khong giat */
        function go(d) {
            var n = at + d;
            if (busy || n < 0 || n >= list.length) return;
            busy = true;
            at = n;
            clearTimeout(tmA); clearTimeout(tmB); clearTimeout(tmC);
            var dir = d > 0 ? 1 : -1;
            img.style.transition = 'opacity .3s ease, transform .3s ease';
            img.style.opacity = '0';
            img.style.transform = 'translate3d(' + (-18 * dir) + 'px,0,0)';
            tmA = setTimeout(function () {
                img.style.transition = 'none';
                img.style.transform = 'translate3d(' + (18 * dir) + 'px,0,0)';
                var done = false;
                function run() {
                    if (done) return;
                    done = true;
                    img.onload = img.onerror = null;
                    void img.offsetWidth;
                    img.style.transition = 'opacity .5s ease, transform .55s cubic-bezier(.22,.61,.36,1)';
                    img.style.opacity = '1';
                    img.style.transform = 'none';
                    tmB = setTimeout(function () { busy = false; }, 520);
                }
                img.onload = img.onerror = run;
                show();
                if (img.complete && img.naturalWidth) run();
                tmC = setTimeout(run, 1800);
            }, 300);
        }
        function open(i) {
            build();
            clearTimeout(tmA); clearTimeout(tmB); clearTimeout(tmC);
            busy = false;
            at = i;
            img.style.transition = 'none';
            img.style.opacity = '1';
            img.style.transform = 'none';
            show();
            lb.classList.add('is-on');
            void lb.offsetWidth;
            lb.classList.add('is-in');
        }
        function close(now) {
            if (!lb || !lb.classList.contains('is-on')) return;
            lb.classList.remove('is-in');
            if (strip) {
                var ims = strip.querySelectorAll('img');
                if (ims[at]) { strip.scrollLeft = ims[at].getBoundingClientRect().left - strip.getBoundingClientRect().left + strip.scrollLeft; }
            }
            if (now) { lb.classList.remove('is-on'); return; }
            setTimeout(function () { if (!lb.classList.contains('is-in')) lb.classList.remove('is-on'); }, 400);
        }
        document.addEventListener('click', function (e) {
            var t = e.target, im = t && t.closest ? t.closest('.ds-prev img') : null;
            if (!im) return;
            strip = im.parentNode;
            var ims = strip.querySelectorAll('img');
            list = [];
            for (var i = 0; i < ims.length; i++) { list.push(ims[i].currentSrc || ims[i].src); }
            var idx = Array.prototype.indexOf.call(ims, im);
            if (idx < 0 || !list.length) return;
            open(idx);
        });
        document.addEventListener('keydown', function (e) {
            if (!lb || !lb.classList.contains('is-on')) return;
            if (e.key === 'Escape') close();
            else if (e.key === 'ArrowLeft') go(-1);
            else if (e.key === 'ArrowRight') go(1);
        });
        window.addEventListener('popstate', function () { close(true); });
        window.addEventListener('pagehide', function () { close(true); });
    } catch (e) {}
})();

/* bam Package / View (hoac sang san pham khac): cac phan tu dang nam tren man hinh hien dan + truot len nhe, moi phan tu tre them 45ms */
(function () {
    try {
        if (window.__dsGin2) return;
        window.__dsGin2 = true;
        var root = document.documentElement, used = [], tm = 0, queued = false, want = '';
        var MQ = false;
        try { MQ = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) {}
        var SEL = {
            pkg: '.ds-pkgpage.is-on .ds-pkgback, .ds-pkgpage.is-on .ds-pkgtitle, .ds-pkgpage.is-on .ds-pkgsub, .ds-pkgpage.is-on .ds-search, .ds-pkgpage.is-on .ds-pkgcount, .ds-pkgpage.is-on .ds-row',
            det: '.ds-det.is-on .ds-det__back, .ds-det.is-on .ds-det__head > img, .ds-det.is-on .ds-det__main h1, .ds-det.is-on .ds-det__main p, .ds-det.is-on .ds-det__act, .ds-det.is-on > :not(.ds-det__top):not(.ds-det__bg)'
        };
        function kind() { return root.classList.contains('ds-pkg') ? 'pkg' : (root.classList.contains('ds-detail') ? 'det' : ''); }
        function clear() {
            used.forEach(function (el) { el.classList.remove('ds-gin2'); el.style.removeProperty('--ds-gi'); });
            used = [];
        }
        function enter(k) {
            if (MQ || !k) return;
            clearTimeout(tm);
            clear();
            var hdr = document.querySelector('.site-header'), top = hdr ? hdr.offsetHeight : 0, vh = window.innerHeight || 800;
            var els = document.querySelectorAll(SEL[k]), i = 0, pick = [];
            for (var n = 0; n < els.length; n++) {
                var el = els[n];
                if (el.offsetParent === null) continue;
                var r = el.getBoundingClientRect();
                if (r.bottom <= top || r.top >= vh) continue;
                el.style.setProperty('--ds-gi', String(Math.min(i, 12)));
                pick.push(el);
                i++;
            }
            void root.offsetWidth;
            pick.forEach(function (el) { el.classList.add('ds-gin2'); });
            used = pick;
            tm = setTimeout(clear, 1500);
        }
        function plan(k) {
            want = k;
            if (queued) return;
            queued = true;
            Promise.resolve().then(function () { queued = false; var k2 = want; want = ''; if (k2 && kind() === k2) enter(k2); });
        }
        var last = kind();
        if (window.MutationObserver) {
            new MutationObserver(function () {
                var k = kind();
                if (k && k !== last) plan(k);
                last = k;
            }).observe(root, { attributes: true, attributeFilter: ['class'] });
            ['ds-detwrap', 'ds-pkgwrap'].forEach(function (id) {
                var w = document.getElementById(id);
                if (!w) return;
                new MutationObserver(function (recs) {
                    for (var j = 0; j < recs.length; j++) {
                        var t = recs[j].target;
                        if (t && t.classList && t.classList.contains('is-on') && !/(^|\s)is-on(\s|$)/.test(recs[j].oldValue || '')) { plan(id === 'ds-pkgwrap' ? 'pkg' : 'det'); return; }
                    }
                }).observe(w, { attributes: true, attributeOldValue: true, attributeFilter: ['class'], subtree: true });
            });
        }
    } catch (e) {}
})();


/* spinner-v2-logo: giu lap loa logo on dinh.
   - Chi khoi dong lai MOT lan khi quay lai web (hoac sau khi trang bi dong bang lau), co lam mo dan, khong khoi dong lai khi focus / cham / go chu.
   - Chua tai xong thi shine an; thoat web thi an + tam dung (do ton GPU). */
(function () {
    try {
        if (window.__dsLg2) return;
        window.__dsLg2 = 1;
        var root = document.documentElement, last = 0, tmA = 0, tmB = 0, hid = false, beat = Date.now();
        function ensure() {
            try {
                document.querySelectorAll('.brand__logo, .ds-brandblock img:not(.ds-swi)').forEach(function (img) {
                    var p = img.parentNode;
                    if (!p) return;
                    if (!p.classList || !p.classList.contains('ds-logo')) {
                        var w = document.createElement('span'), sw = document.createElement('i'), c = document.createElement('img');
                        w.className = 'ds-logo'; sw.className = 'ds-sw'; sw.setAttribute('aria-hidden', 'true');
                        c.className = 'ds-swi'; c.alt = ''; c.setAttribute('aria-hidden', 'true'); c.src = img.currentSrc || img.src;
                        p.insertBefore(w, img); w.appendChild(img); sw.appendChild(c); w.appendChild(sw); p = w;
                    }
                    var a = p.querySelector('.ds-sw');
                    if (a && !p.querySelector('.ds-sw2')) { var b = a.cloneNode(true); b.className = 'ds-sw ds-sw2'; p.appendChild(b); }
                    var src = img.currentSrc || img.src;
                    p.querySelectorAll('.ds-swi').forEach(function (si) { if (src && si.src !== src) si.src = src; });
                });
            } catch (e) {}
        }
        function off() { root.classList.add('ds-lg-off'); }
        function on() { root.classList.remove('ds-lg-off'); }
        function restart() {
            ensure();
            var list = document.querySelectorAll('.ds-logo .ds-sw, .ds-logo .ds-swi');
            Array.prototype.forEach.call(list, function (el) {
                el.style.setProperty('animation', 'none', 'important');
                el.style.setProperty('-webkit-animation', 'none', 'important');
            });
            void root.offsetWidth;
            Array.prototype.forEach.call(list, function (el) {
                el.style.removeProperty('animation');
                el.style.removeProperty('-webkit-animation');
            });
        }
        function comeback() {
            var n = Date.now();
            if (n - last < 1200) return;
            last = n;
            clearTimeout(tmA); clearTimeout(tmB);
            off();
            on(); window.__dsLgT = Date.now(); requestAnimationFrame(function () { restart(); }); /* v48: quay lai web la lap loa ngay, khong an / khong cho */
        }
        ensure();
        if (document.readyState !== 'complete') {
            off();
            window.addEventListener('load', function () { ensure(); setTimeout(on, 250); });
            setTimeout(on, 2500);
        }
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') { hid = true; root.classList.add('ds-hid'); }
            else { root.classList.remove('ds-hid'); if (hid) { hid = false; comeback(); } }
        });
        window.addEventListener('pagehide', function () { hid = true; });
        window.addEventListener('pageshow', function (e) { ensure(); if (e.persisted || hid) { hid = false; comeback(); } });
        /* trang bi iOS dong bang lau ma khong ban su kien: khoang cach giua 2 lan dem > 9 giay => coi nhu vua quay lai */
        setInterval(function () {
            var n = Date.now();
            if (n - beat > 9000 && document.visibilityState === 'visible') { comeback(); }
            beat = n;
        }, 3000);
    } catch (e) {}
})();
</script>
<!--DS-SPINNER-V2-END-->
<!--DS-PATCH-V46-BEGIN-->
<style id="ds-patch-v46">
/* v46 (1): header - BO KHUNG avatar (anh vien + vong sang + sao). Avatar tron, cao bang nut EN. Trang ME giu nguyen. */
html:root:root:root:root body #ds-acc { --hbtn: inherit; margin-left: 0; }
html:root:root:root:root body #ds-acc::before,
html:root:root:root:root body #ds-acc::after { content: none !important; display: none !important; -webkit-animation: none !important; animation: none !important; }
html:root:root:root:root body #ds-acc .ds-spk { display: none !important; }
html:root:root:root:root body #ds-avatar { box-sizing: border-box; width: var(--hbtn, 32px); height: var(--hbtn, 32px); min-width: var(--hbtn, 32px); flex: 0 0 auto; border-radius: 50% !important; overflow: hidden !important; }
html:root:root:root:root body #ds-avatar .ds-av__img { border-radius: 50% !important; }

/* v46 (2): thong bao "chua co key" (X do) hien / tat muot: cao + mo + truot chay cung nhip, khong nhay do cao cuoi */
@keyframes ds-need-in46 {
    from { opacity: 0; max-height: 0; margin-top: 0; -webkit-transform: translate3d(0, -6px, 0); transform: translate3d(0, -6px, 0); -webkit-clip-path: inset(0 0 100% 0); clip-path: inset(0 0 100% 0); }
    to   { opacity: 1; max-height: var(--ds-need-h, 64px); margin-top: 10px; -webkit-transform: none; transform: none; -webkit-clip-path: inset(0 0 0 0); clip-path: inset(0 0 0 0); }
}
@keyframes ds-need-out46 {
    from { opacity: 1; max-height: var(--ds-need-h, 64px); margin-top: 10px; -webkit-transform: none; transform: none; -webkit-clip-path: inset(0 0 0 0); clip-path: inset(0 0 0 0); }
    to   { opacity: 0; max-height: 0; margin-top: 0; -webkit-transform: translate3d(0, -4px, 0); transform: translate3d(0, -4px, 0); -webkit-clip-path: inset(0 0 100% 0); clip-path: inset(0 0 100% 0); }
}
html:root:root:root:root:root:root:root .ds-need.is-in {
    -webkit-animation: ds-need-in46 .34s cubic-bezier(.22, .61, .36, 1) backwards !important;
    animation: ds-need-in46 .34s cubic-bezier(.22, .61, .36, 1) backwards !important;
}
html:root:root:root:root:root:root:root .ds-need.is-out {
    -webkit-animation: ds-need-out46 .3s cubic-bezier(.4, 0, .2, 1) forwards !important;
    animation: ds-need-out46 .3s cubic-bezier(.4, 0, .2, 1) forwards !important;
}
@media (prefers-reduced-motion: reduce) {
    html:root:root:root:root:root:root:root .ds-need.is-in, html:root:root:root:root:root:root:root .ds-need.is-out { -webkit-animation: none !important; animation: none !important; }
}
</style>
<script id="ds-patch-v46-js">
(function () {
    try {
        if (window.__dsP46) return;
        window.__dsP46 = 1;
        /* tat cac lan khoi dong lai logo thua cua v45 (lam logo giat luc vao lai web); chi con 1 luong khoi dong lai trong luc logo dang an */
        window.__dsLg3 = 1;
        var root = document.documentElement;

        /* thong bao "chua co key": do chieu cao that de hieu ung mo / dong khop dung, khong nhay o cuoi */
        var nd = document.getElementById('ds-need');
        if (nd && window.MutationObserver) {
            new MutationObserver(function () {
                if (nd.hidden) return;
                var h = nd.scrollHeight;
                if (h > 0) nd.style.setProperty('--ds-need-h', h + 'px');
            }).observe(nd, { attributes: true, attributeFilter: ['class', 'hidden'] });
        }

        /* logo: neu vi ly do nao do (hide / show thanh nhanh) logo bi an qua 4 giay khi dang xem web thi tu hien lai */
        var offAt = 0;
        setInterval(function () {
            if (document.visibilityState !== 'visible') { offAt = 0; return; }
            if (root.classList.contains('ds-lg-off')) {
                if (!offAt) offAt = Date.now();
                else if (Date.now() - offAt > 4000) { root.classList.remove('ds-lg-off'); offAt = 0; }
            } else { offAt = 0; }
        }, 1000);
    } catch (e) {}
})();
</script>
<!--DS-PATCH-V46-END-->
<!--DS-PATCH-V47-BEGIN-->
<style id="ds-patch-v47">
/* v47 (1): trang ME - BO KHUNG avatar (anh vien + vong sang + sao). Avatar tron 56px, khong con le trai du cho khung. */
html:root:root:root:root body #ds-me-av::before,
html:root:root:root:root body #ds-me-av::after { content: none !important; display: none !important; -webkit-animation: none !important; animation: none !important; }
html:root:root:root:root body #ds-me-av .ds-spk { display: none !important; }
html:root:root:root:root body #ds-me-av { margin: 0 !important; width: 56px; height: 56px; overflow: hidden !important; border-radius: 50% !important; }
html:root:root:root:root body #ds-me-av .ds-av__img { border-radius: 50% !important; }
html:root:root:root:root body #ds-mepage .ds-me__top { padding-top: 16px !important; padding-bottom: 4px !important; }

/* v47 (2): vong tron load (luc vao web / tai lai / quay lai): CHI xoay binh thuong, KHONG phat sang (khong filter, khong bong, khong lop sang) */
html:root:root:root:root #ds-boot,
html:root:root:root:root #ds-boot *,
html:root:root:root:root #ds-boot::before,
html:root:root:root:root #ds-boot::after,
html:root:root:root:root #ds-boot *::before,
html:root:root:root:root #ds-boot *::after,
html:root:root:root:root .ds-ptr,
html:root:root:root:root .ds-ptr * { -webkit-filter: none !important; filter: none !important; box-shadow: none !important; text-shadow: none !important; }
html:root:root:root:root #ds-boot::before,
html:root:root:root:root #ds-boot::after,
html:root:root:root:root #ds-boot *::before,
html:root:root:root:root #ds-boot *::after { content: none !important; display: none !important; }
html:root:root:root:root #ds-boot [class*="glow"],
html:root:root:root:root #ds-boot [class*="halo"],
html:root:root:root:root #ds-boot [class*="shine"],
html:root:root:root:root #ds-boot [class*="spark"] { display: none !important; }
html:root:root:root:root #ds-boot svg,
html:root:root:root:root #ds-boot svg * { opacity: 1 !important; }
</style>
<!--DS-PATCH-V47-END-->
<!--DS-PATCH-V48-BEGIN-->
<script id="ds-patch-v48-js">
(function () {
    try {
        if (window.__dsP48) return;
        window.__dsP48 = 1;
        /* logo devnvios.io.vn: thoat trinh duyet / lau moi vao lai la LAP LOA NGAY (khong an, khong cho). Them cac duong phat hien "vua quay lai" nhanh hon. */
        var root = document.documentElement, beat = Date.now(), busy = 0;
        function restart() {
            var list = document.querySelectorAll('.ds-logo .ds-sw, .ds-logo .ds-swi'), i;
            for (i = 0; i < list.length; i++) {
                list[i].style.setProperty('animation', 'none', 'important');
                list[i].style.setProperty('-webkit-animation', 'none', 'important');
            }
            void root.offsetWidth;
            for (i = 0; i < list.length; i++) {
                list[i].style.removeProperty('animation');
                list[i].style.removeProperty('-webkit-animation');
            }
        }
        function back() {
            if (Date.now() - (window.__dsLgT || 0) < 600) return;
            window.__dsLgT = Date.now();
            root.classList.remove('ds-lg-off');
            if (busy) return;
            busy = 1;
            requestAnimationFrame(function () { busy = 0; restart(); });
        }
        window.addEventListener('focus', back);
        window.addEventListener('pageshow', function (e) { if (e.persisted) back(); });
        /* iOS dong bang trang lau ma khong ban su kien: lan dem tiep theo cach > 2.5 giay => vua quay lai */
        setInterval(function () {
            var n = Date.now();
            if (n - beat > 2500 && document.visibilityState === 'visible') back();
            beat = n;
        }, 1000);
    } catch (e) {}
})();
</script>
<!--DS-PATCH-V48-END-->
<!--DS-PATCH-V49-BEGIN-->
<style id="ds-patch-v49">
/* v49: trang Package giong DS: nut "< Home" o tren, chu Package nho lai (26px) */
html:root:root:root:root .ds-pkgpage .ds-pkgback { margin-top: 7px; }
html:root:root:root:root .ds-pkgpage .ds-pkgtitle { font-size: 26px !important; letter-spacing: -.02em; line-height: 1.15; margin-top: 0; }
</style>
<!--DS-PATCH-V49-END-->
<!--DS-SNOW-BEGIN-->
<style id="ds-snow-css">
    #ds-snow { position: fixed; -webkit-transform: translateZ(0); transform: translateZ(0); left: 0; top: 0; right: 0; bottom: 0; z-index: 0; overflow: hidden; pointer-events: none; -webkit-user-select: none; user-select: none; }
    #ds-snow .dss-f { position: absolute; top: 0; margin-top: -32px; pointer-events: none; will-change: transform; animation-name: dss-fall; animation-timing-function: linear; animation-iteration-count: infinite; animation-play-state: running; }
    #ds-snow .dss-d { display: block; will-change: transform; backface-visibility: hidden; -webkit-backface-visibility: hidden; pointer-events: none; background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Cg fill='none' stroke='%23e6f2ff' stroke-width='6.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M50 50V7M50 17L37.7 8.4M50 17L62.3 8.4M50 29L41 22.7M50 29L59 22.7'/%3E%3Cpath transform='rotate(60 50 50)' d='M50 50V7M50 17L37.7 8.4M50 17L62.3 8.4M50 29L41 22.7M50 29L59 22.7'/%3E%3Cpath transform='rotate(120 50 50)' d='M50 50V7M50 17L37.7 8.4M50 17L62.3 8.4M50 29L41 22.7M50 29L59 22.7'/%3E%3Cpath transform='rotate(180 50 50)' d='M50 50V7M50 17L37.7 8.4M50 17L62.3 8.4M50 29L41 22.7M50 29L59 22.7'/%3E%3Cpath transform='rotate(240 50 50)' d='M50 50V7M50 17L37.7 8.4M50 17L62.3 8.4M50 29L41 22.7M50 29L59 22.7'/%3E%3Cpath transform='rotate(300 50 50)' d='M50 50V7M50 17L37.7 8.4M50 17L62.3 8.4M50 29L41 22.7M50 29L59 22.7'/%3E%3C/g%3E%3Ccircle cx='50' cy='50' r='7' fill='%23e6f2ff'/%3E%3C/svg%3E") center / 100% 100% no-repeat; transition: opacity .35s ease; animation-name: dss-sway; animation-timing-function: ease-in-out; animation-iteration-count: infinite; animation-direction: alternate; animation-play-state: running; }
    html[data-theme="light"] #ds-snow .dss-d { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Cg fill='none' stroke='%233f7fd8' stroke-width='6.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M50 50V7M50 17L37.7 8.4M50 17L62.3 8.4M50 29L41 22.7M50 29L59 22.7'/%3E%3Cpath transform='rotate(60 50 50)' d='M50 50V7M50 17L37.7 8.4M50 17L62.3 8.4M50 29L41 22.7M50 29L59 22.7'/%3E%3Cpath transform='rotate(120 50 50)' d='M50 50V7M50 17L37.7 8.4M50 17L62.3 8.4M50 29L41 22.7M50 29L59 22.7'/%3E%3Cpath transform='rotate(180 50 50)' d='M50 50V7M50 17L37.7 8.4M50 17L62.3 8.4M50 29L41 22.7M50 29L59 22.7'/%3E%3Cpath transform='rotate(240 50 50)' d='M50 50V7M50 17L37.7 8.4M50 17L62.3 8.4M50 29L41 22.7M50 29L59 22.7'/%3E%3Cpath transform='rotate(300 50 50)' d='M50 50V7M50 17L37.7 8.4M50 17L62.3 8.4M50 29L41 22.7M50 29L59 22.7'/%3E%3C/g%3E%3Ccircle cx='50' cy='50' r='7' fill='%233f7fd8'/%3E%3C/svg%3E"); }
    @keyframes dss-fall { from { transform: translate3d(0, 0, 0); } to { transform: translate3d(0, 110vh, 0); } }
    @keyframes dss-sway0 { from { transform: translate3d(-6px, 0, 0) rotate(-18deg); } to { transform: translate3d(6px, 0, 0) rotate(18deg); } }
    @keyframes dss-sway1 { from { transform: translate3d(-9px, 0, 0) rotate(-26deg); } to { transform: translate3d(9px, 0, 0) rotate(26deg); } }
    @keyframes dss-sway2 { from { transform: translate3d(-12px, 0, 0) rotate(-35deg); } to { transform: translate3d(12px, 0, 0) rotate(35deg); } }
    @keyframes dss-sway3 { from { transform: translate3d(-16px, 0, 0) rotate(-45deg); } to { transform: translate3d(16px, 0, 0) rotate(45deg); } }
    #ds-snow .dss-w0 { animation-name: dss-sway0; }
    #ds-snow .dss-w1 { animation-name: dss-sway1; }
    #ds-snow .dss-w2 { animation-name: dss-sway2; }
    #ds-snow .dss-w3 { animation-name: dss-sway3; }
</style>
<script>
(function () {
    try {
        var body = document.body;
        if (!body || document.getElementById('ds-snow')) return;
        /* Chinh tai day: SPEED = toc do roi (lon hon = nhanh hon), OPA = do dam (nho hon = nhat hon), DENSITY = so bong (nho hon = it hon) */
        var SPEED = 1.35, OPA = 0.55, DENSITY = 17;
        var W = Math.max(window.innerWidth || 0, 320);
        var phone = W < 820;
        var weak = false;
        try { weak = (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 4) || (navigator.deviceMemory && navigator.deviceMemory <= 2); } catch (e) {}
        /* So bong toi da: dien thoai 15 (may yeu 10), may tinh 24 (may yeu 16) */
        var MAXN = phone ? (weak ? 14 : 20) : (weak ? 20 : 26);
        var N = Math.max(8, Math.min(MAXN, Math.round(W / DENSITY)));
        function rnd(a, b) { return a + Math.random() * (b - a); }
        function shuffle(a) { for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; } return a; }
        var cls = [
            { s: [8, 12], D: [16, 24], o: [.6, .8] },
            { s: [13, 20], D: [12, 18], o: [.78, .95] },
            { s: [21, 28], D: [9, 14], o: [.85, 1] }
        ];
        var dpr = Math.min(window.devicePixelRatio || 1, 3);
        function sprite(px, col) {
            var c = document.createElement('canvas');
            c.width = px; c.height = px;
            var g = c.getContext('2d');
            if (!g) return '';
            g.scale(px / 100, px / 100);
            g.translate(50, 50);
            g.strokeStyle = col; g.fillStyle = col; g.lineWidth = 6.5; g.lineCap = 'round'; g.lineJoin = 'round';
            for (var a = 0; a < 6; a++) {
                g.beginPath();
                g.moveTo(0, 0); g.lineTo(0, -43);
                g.moveTo(0, -33); g.lineTo(-12.3, -41.6);
                g.moveTo(0, -33); g.lineTo(12.3, -41.6);
                g.moveTo(0, -21); g.lineTo(-9, -27.3);
                g.moveTo(0, -21); g.lineTo(9, -27.3);
                g.stroke();
                g.rotate(Math.PI / 3);
            }
            g.beginPath(); g.arc(0, 0, 7, 0, Math.PI * 2); g.fill();
            return c.toDataURL('image/png');
        }
        try {
            var ok = true;
            var common = 'background-color:transparent;border-radius:0;background-repeat:no-repeat;background-position:center;background-size:100% 100%;';
            var css = '';
            for (var q = 0; q < 0; q++) {
                var px = Math.ceil(cls[q].s[1] * dpr);
                var dk = sprite(px, '#e6f2ff'), lt = sprite(px, '#3f7fd8');
                if (!dk || !lt) { ok = false; break; }
                css += 'html #ds-snow .dss-d.dss-t' + q + '{' + common + 'background-image:url("' + dk + '")}';
                css += 'html[data-theme="light"] #ds-snow .dss-d.dss-t' + q + '{' + common + 'background-image:url("' + lt + '")}';
            }
            if (ok) {
                var st = document.createElement('style');
                st.id = 'ds-snow-sprites';
                st.textContent = css;
                document.head.appendChild(st);
            }
        } catch (e) {}
        var xs = [], ps = [], i;
        for (i = 0; i < N; i++) { xs.push(i); ps.push(i); }
        shuffle(xs); shuffle(ps);
        var h = '';
        for (i = 0; i < N; i++) {
            var r = Math.random(), k = r < .4 ? 0 : (r < .8 ? 1 : 2), c = cls[k];
            var s = rnd(c.s[0], c.s[1]), D = rnd(c.D[0], c.D[1]) / SPEED, o = rnd(c.o[0], c.o[1]) * OPA;
            var x = (xs[i] + Math.random()) / N * 100;
            var dl = -((ps[i] + Math.random()) / N) * D;
            var sw = rnd(6, 16), sd = rnd(3.5, 7), sdl = -rnd(0, sd), rot = rnd(15, 45);
            h += '<div class="dss-f" style="left:' + x.toFixed(2) + '%;width:' + s.toFixed(1) + 'px;height:' + s.toFixed(1) + 'px;animation-duration:' + D.toFixed(2) + 's;animation-delay:' + dl.toFixed(2) + 's">' +
                '<i class="dss-d dss-t' + k + ' dss-w' + Math.floor(Math.random() * 4) + '" style="width:100%;height:100%;opacity:' + o.toFixed(2) + ';--x:' + sw.toFixed(1) + 'px;--r:' + rot.toFixed(0) + 'deg;animation-duration:' + sd.toFixed(2) + 's;animation-delay:' + sdl.toFixed(2) + 's"></i></div>';
        }
        var box = document.createElement('div');
        box.id = 'ds-snow';
        box.setAttribute('aria-hidden', 'true');
        box.innerHTML = h;
        body.insertBefore(box, body.firstChild);

        /* Tuyet chi la CSS animation: khong co vong lap JS, khong an/mo, cuon kieu gi cung roi deu. Chi khoi dong lai hieu ung khi quay lai trang sau khi bi an (iOS hay lam dung animation) */
        var hiddenAt = 0;
        function kick() {
            try { box.style.display = 'none'; void box.offsetHeight; box.style.display = ''; } catch (e) {}
        }
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { hiddenAt = Date.now(); return; }
            if (hiddenAt && Date.now() - hiddenAt > 1500) kick();
            hiddenAt = 0;
        });
        window.addEventListener('pageshow', function (e) { if (e && e.persisted) kick(); });
    } catch (e) {}
})();
</script>
<!--DS-SNOW-END-->

<!--DS-PC-BEGIN-->
<style id="ds-pc">.ds-pcx{display:none!important}
@media (min-width:1024px){
:root:root{--ds-sbw:208px;--hd:56px;--nav-h:0px;--ds-padx:max(32px,calc((100vw - var(--ds-sbw) - 1120px)/2))}
html:root:root:root:root:root:root:root:root:root:root body{padding:0!important}
html:root:root:root:root:root:root:root:root:root:root .site-header{display:flex!important;align-items:center!important;justify-content:space-between!important;left:0!important;width:100%!important;padding:0 28px 0 18px!important;border:0!important;background:linear-gradient(var(--ds-hd-line),var(--ds-hd-line)) left var(--ds-sbw) bottom/calc(100% - var(--ds-sbw)) 1px no-repeat,linear-gradient(90deg,transparent var(--ds-sbw),var(--ds-hd-bg) var(--ds-sbw))!important;-webkit-backdrop-filter:none!important;backdrop-filter:none!important;pointer-events:none}
html:root:root:root:root:root:root:root:root:root:root .site-header>*{pointer-events:auto}
html:root:root:root:root:root:root:root:root:root:root .site-header::before{content:"Home"!important;display:block!important;position:absolute;left:calc(var(--ds-sbw) + var(--ds-padx));top:50%;transform:translateY(-50%);font-size:17px;font-weight:700;color:var(--ds-h);pointer-events:none}
html.ds-detail:root:root:root:root:root:root:root:root:root .site-header::before{content:"Product details"!important}
html.ds-keys:root:root:root:root:root:root:root:root:root .site-header::before{content:"Keys"!important}
html.ds-tools:root:root:root:root:root:root:root:root:root .site-header::before{content:"Tools"!important}
html.ds-hist:root:root:root:root:root:root:root:root:root .site-header::before{content:"History"!important}
html.ds-me:root:root:root:root:root:root:root:root:root .site-header::before{content:"Profile"!important}
html.ds-pkg:root:root:root:root:root:root:root:root:root .site-header::before{content:"Package"!important}
html:root:root:root:root:root:root:root:root:root:root .site-header .brand{max-width:calc(var(--ds-sbw) - 30px)!important}
html:root:root:root:root:root:root:root:root:root:root .site-header .ds-logo{max-width:calc(var(--ds-sbw) - 30px)!important}
html:root:root:root:root:root:root:root:root:root:root .site-header .brand__logo{max-width:calc(var(--ds-sbw) - 30px)!important}
html:root:root:root:root:root:root:root:root:root:root .site-header .ds-hd{margin-left:auto}
html:root:root:root:root:root:root:root:root:root:root .ds-nav,html.ds-typing:root:root:root:root:root:root:root:root:root .ds-nav{display:flex!important;flex-direction:column;left:0!important;right:auto!important;top:0!important;bottom:0!important;width:var(--ds-sbw)!important;height:auto!important;box-sizing:border-box;padding:calc(var(--hd) + 10px) 12px 14px!important;z-index:800!important;transform:none!important;border-radius:0!important;background:var(--ds-card)!important;border:0!important;border-right:1px solid var(--ds-line)!important;overflow-y:auto;contain:none!important}
html:root:root:root:root:root:root:root:root:root:root .ds-nav::before,html:root:root:root:root:root:root:root:root:root:root .ds-nav::after{display:none!important}
html:root:root:root:root:root:root:root:root:root:root .ds-nav__in{display:flex!important;flex-direction:column;gap:2px;width:100%;order:1}
html:root:root:root:root:root:root:root:root:root:root .ds-nav__item{flex:0 0 auto!important;flex-direction:row!important;justify-content:flex-start!important;gap:11px!important;width:100%;box-sizing:border-box;padding:9px 11px!important;font-size:13.5px!important;font-weight:600!important;text-align:left;border-radius:9px!important;color:var(--ds-item)}
html:root:root:root:root:root:root:root:root:root:root .ds-nav__item:hover{background:var(--ds-field)!important}
html:root:root:root:root:root:root:root:root:root:root .ds-nav__item.is-active{background:var(--ds-pill)!important;color:var(--ds-link)!important}
html:root:root:root:root:root:root:root:root:root:root .ds-nav .ds-ico{width:17px!important;height:17px!important}
html:root:root:root:root:root:root:root:root:root:root #ds-scroll{left:var(--ds-sbw)!important;padding:var(--hd) var(--ds-padx) 48px!important}
html.ds-typing:root:root:root:root:root:root:root:root:root #ds-scroll{padding-bottom:48px!important}
/* o tim + danh muc + the tai khoan o thanh ben (JS them) */
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__s,html:root:root:root:root:root:root:root:root:root:root .ds-pcx__c{display:block!important}html:root:root:root:root:root:root:root:root:root:root .ds-pcx__u{display:flex!important}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__s{order:0;position:relative;margin:0 0 12px}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__s svg{position:absolute;left:10px;top:50%;width:14px;height:14px;margin-top:-7px;fill:none;stroke:var(--ds-p);stroke-width:2;stroke-linecap:round}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__s input{box-sizing:border-box;width:100%;height:34px;padding:0 10px 0 31px;font-size:13px!important;color:var(--ds-h);background:var(--ds-field)!important;border:1px solid var(--ds-line);border-radius:9px;outline:0}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__s input:focus{border-color:var(--blue)}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__c{order:2;margin-top:16px}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__c h4{margin:0 0 6px;padding:0 6px;font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ds-p)}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__i{display:flex;align-items:center;gap:9px;width:100%;box-sizing:border-box;padding:7px 8px;font-size:12.5px;font-weight:600;text-align:left;color:var(--ds-item);background:transparent;border:0;border-radius:8px;cursor:pointer}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__i:hover{background:var(--ds-field)}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__i.is-on{background:var(--ds-pill);color:var(--ds-link)}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__i i{flex:0 0 13px;width:13px;height:13px;border:1.6px solid var(--ds-link);border-radius:50%}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__i span{flex:1 1 0;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__i svg{flex:0 0 12px;width:12px;height:12px;fill:none;stroke:var(--ds-p);stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__i--k{padding-left:20px}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__u{order:3;margin-top:auto;display:flex;align-items:center;gap:10px;width:100%;box-sizing:border-box;padding:9px 10px;text-align:left;background:var(--ds-field);border:0;border-radius:12px;cursor:pointer}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__u b.a{display:flex;flex:0 0 32px;align-items:center;justify-content:center;width:32px;height:32px;font-size:14px;font-weight:800;color:#fff;background:var(--blue);border-radius:9px}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__u div{min-width:0}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__u strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12.5px;font-weight:700;color:var(--ds-h)}
html:root:root:root:root:root:root:root:root:root:root .ds-pcx__u small{display:block;font-size:11.5px;font-weight:700;color:var(--ds-ok)}
html:root:root:root:root:root:root:root:root:root:root .ds-pc-hide{display:none!important}
/* trang chu: san pham chia 2-3 cot */
html:root:root:root:root:root:root:root:root:root:root .ds-products{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));column-gap:28px}
html:root:root:root:root:root:root:root:root:root:root .ds-products>h2,html:root:root:root:root:root:root:root:root:root:root .ds-products>p{grid-column:1/-1}
html:root:root:root:root:root:root:root:root:root:root .ds-hero{max-width:980px}
html:root:root:root:root:root:root:root:root:root:root .ds-pkglist,html:root:root:root:root:root:root:root:root:root:root .ds-tlist{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));column-gap:28px}
/* trang chi tiet san pham giong shopdsgm */
html:root:root:root:root:root:root:root:root:root:root .ds-det{padding-bottom:30px}
html:root:root:root:root:root:root:root:root:root:root .ds-det__top{position:relative;z-index:0;overflow:visible!important;isolation:auto!important;background:transparent!important;border:0!important;padding:30px 0 28px!important;margin:0!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det__top::after{display:none!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det__top::before{content:"";position:absolute;z-index:-1;top:0;bottom:0;left:calc(var(--ds-padx) * -1);right:calc(var(--ds-padx) * -1);background:linear-gradient(100deg,rgba(150,205,255,.42),rgba(235,240,246,.55) 55%,rgba(255,255,255,0) 100%)}
:root[data-theme="dark"] .ds-det__top::before{background:linear-gradient(100deg,rgba(59,158,255,.2),rgba(30,30,36,.5) 60%,rgba(0,0,0,0))}
html:root:root:root:root:root:root:root:root:root:root .ds-det__head{align-items:center!important;gap:26px!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det__head>img{flex:0 0 auto;width:92px!important;height:92px!important;border-radius:21px!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det__main h1{font-size:26px!important;font-weight:800!important;line-height:1.2!important;margin:0!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det__main>p{margin:6px 0 14px!important;font-size:15px!important;color:var(--ds-p)!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det__act,html:root:root:root:root:root:root:root:root:root:root .ds-det--prev .ds-det__act{display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:10px!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det--prev .ds-share{margin-left:0!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det .ds-get{height:32px!important;min-width:82px!important;font-size:13px!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det .ds-share{width:32px!important;height:32px!important;min-width:32px!important}
html:root:root:root:root:root:root:root:root:root:root .ds-stats{display:flex!important;flex-wrap:nowrap!important;overflow:visible!important;padding:22px 0!important;margin:0!important;border-bottom:1px solid var(--ds-line)}
html:root:root:root:root:root:root:root:root:root:root .ds-det--prev .ds-stat,html:root:root:root:root:root:root:root:root:root:root .ds-stat{flex:1 1 0!important;min-width:0!important;padding:0 10px!important;text-align:center}
html:root:root:root:root:root:root:root:root:root:root .ds-stat small{font-size:10.5px!important;letter-spacing:.12em!important}
html:root:root:root:root:root:root:root:root:root:root .ds-stat b{font-size:16px!important}
html:root:root:root:root:root:root:root:root:root:root .ds-stat span{font-size:12px!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det h2{margin:34px 0 14px!important;font-size:17px!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det--prev .ds-prevbox{margin:0 0 6px!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det--prev .ds-prev{padding:2px 0 10px!important;gap:12px!important;scroll-snap-type:none!important}
html:root:root:root:root:root:root:root:root:root:root .ds-prev img,html:root:root:root:root:root:root:root:root:root:root .ds-det--prev .ds-prev img{flex:0 0 auto!important;width:auto!important;min-width:0!important;max-width:none!important;height:180px!important;min-height:0!important;max-height:none!important;border-radius:14px!important;object-fit:cover!important}
html:root:root:root:root:root:root:root:root:root:root .ds-det__txt{max-width:760px;font-size:13.5px!important;color:var(--ds-p)}
html:root:root:root:root:root:root:root:root:root:root .ds-det--prev .ds-prevbox+h2{padding-top:34px!important}
html:root:root:root:root:root:root:root:root:root:root .ds-info{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:40px}
html:root:root:root:root:root:root:root:root:root:root .ds-rel__card{flex:0 0 330px!important}
}
</style>
<script id="ds-pc-js">(function(){
 var $=function(s,r){return (r||document).querySelector(s)},$$=function(s,r){return [].slice.call((r||document).querySelectorAll(s))};
 var CH='<svg viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></svg>';
 function esc(t){return String(t).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
 function build(){
  var nav=$('.ds-nav');if(!nav||$('.ds-pcx',nav))return;
  var s=document.createElement('label');s.className='ds-pcx ds-pcx__s';
  s.innerHTML='<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" placeholder="Search..." autocomplete="off" spellcheck="false">';
  nav.insertBefore(s,nav.firstChild);
  s.querySelector('input').addEventListener('input',function(){
   var q=this.value.trim().toLowerCase();
   $$('.ds-row').forEach(function(r){var n=$('strong',r);r.classList.toggle('ds-pc-hide',!!q&&!(n&&n.textContent.toLowerCase().indexOf(q)>-1))});
   $$('.ds-pcx__i--k').forEach(function(b){b.classList.toggle('ds-pc-hide',!!q&&b.textContent.toLowerCase().indexOf(q)<0)});
  });
  var c=document.createElement('div');c.className='ds-pcx ds-pcx__c';
  var h='<h4>Products</h4>';
  $$('.ds-products .ds-pkgbtn').forEach(function(b,i){h+='<button type="button" class="ds-pcx__i" data-p="'+i+'"><span>'+esc(b.textContent.trim())+'</span>'+CH+'</button>'});
  var seen={};
  $$('.ds-products .ds-row').forEach(function(r){var id=r.getAttribute('data-tool');if(seen[id])return;seen[id]=1;var n=$('strong',r);h+='<button type="button" class="ds-pcx__i ds-pcx__i--k" data-t="'+esc(id)+'"><i></i><span>'+esc(n?n.textContent:'')+'</span></button>'});
  c.innerHTML=h;nav.appendChild(c);
  c.addEventListener('click',function(e){
   var b=e.target.closest?e.target.closest('.ds-pcx__i'):null;if(!b)return;
   if(b.hasAttribute('data-p')){var pb=$$('.ds-products .ds-pkgbtn')[+b.getAttribute('data-p')];if(pb)pb.click();}
   else{var r=$('.ds-products .ds-row[data-tool="'+b.getAttribute('data-t')+'"]');var o=r&&$('.ds-open',r);if(o)o.click();else if(r)r.click();}
  });
  if($('#ds-acc')){
   var u=document.createElement('button');u.type='button';u.className='ds-pcx ds-pcx__u';u.innerHTML='<b class="a">U</b><div><strong>Member</strong><small></small></div>';
   nav.appendChild(u);u.addEventListener('click',function(){var m=$('#ds-me');if(m)m.click();});
  }
  sync();
 }
 function sync(){
  var u=$('.ds-pcx__u');
  if(u){var n=$('#ds-um-name'),t=$('#ds-um-turns'),a=$('#ds-av-l');
   if(n)$('strong',u).textContent=n.textContent;
   if(t)$('small',u).textContent=t.textContent+' turns';
   if(a)$('b.a',u).textContent=a.textContent;}
  var cur=document.documentElement.classList.contains('ds-detail')?(($('.ds-det.is-on')||{getAttribute:function(){return ''}}).getAttribute('data-tool')):'';
  $$('.ds-pcx__i--k').forEach(function(b){b.classList.toggle('is-on',!!cur&&b.getAttribute('data-t')===cur)});
 }
 function start(){build();sync();}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
 window.addEventListener('load',start);
 try{new MutationObserver(sync).observe(document.documentElement,{attributes:true,attributeFilter:['class']});}catch(e){}
 setTimeout(sync,800);setTimeout(sync,2500);
})();</script>
<!--DS-PC-END--><?php }
//V39-END
