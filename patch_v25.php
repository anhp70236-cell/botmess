<?php
/* patch_v25.php (ban 3) - chi va devnvios_theme.php
   1) Thong bao nhap key nhanh hon: kiem tra khoa ngay duoc lam san luc bam vao o nhap key (bot 1 luot goi server luc bam kich hoat),
      nut kich hoat mo di ngay khi bam de thay co phan hoi.
   2) Banner anh trang chu: tu chuyen (mac dinh 3.5 giay, chuyen anh 0.5 giay), keo tay di / ve KHONG BAO GIO HET (vong lap 2 chieu).
   - Khong xoa gi cua code cu. Tu sao luu: devnvios_theme.php.bak-truoc-patch-v25-fix3
   - Chay 2 lan van an toan. Chi ghi file khi moi doan khop + kiem tra cu phap khong loi. */

$MARK = 'PATCH-V25-FIX3';
$BAK  = '.bak-truoc-patch-v25-fix3';
$base = (isset($argv[1]) && $argv[1] !== '') ? rtrim($argv[1], "/\\") : __DIR__;

function say($s) { echo $s . PHP_EOL; }

function parse_edits($blob) {
    $blob = str_replace("\r\n", "\n", $blob);
    $want = preg_match_all('/^@@ /m', $blob, $tmp);
    $n = preg_match_all('/^@@ (.+?) \| (\d+)\n<<<\n(.*?)\n===\n(.*?)\n>>>$/ms', $blob, $m, PREG_SET_ORDER);
    if ($n !== $want) { say('LOI NOI BO: khong doc het cac doan va (' . $n . '/' . $want . ')'); exit(1); }
    $out = [];
    foreach ($m as $x) { $out[] = ['label' => $x[1], 'count' => (int) $x[2], 'find' => $x[3], 'repl' => $x[4]]; }
    return $out;
}

function lint_ok($file) {
    if (!function_exists('exec')) { say('  (khong chay duoc php -l, bo qua kiem tra cu phap)'); return true; }
    $old = getcwd();
    chdir(dirname($file));
    $name = basename($file);
    if (strpos(PHP_BINARY, ' ') === false) {
        $cmd = PHP_BINARY . ' -l ' . $name;
    } else {
        $cmd = '""' . PHP_BINARY . '" -l ' . $name . '"';
    }
    $o = []; $rc = 1;
    @exec($cmd . ' 2>&1', $o, $rc);
    chdir($old);
    if ($rc === 0) { return true; }
    $txt = implode(' | ', $o);
    if (stripos($txt, 'Parse error') === false && stripos($txt, 'Errors parsing') === false && stripos($txt, 'syntax error') === false) {
        say('  (khong chay duoc php -l: ' . $txt . ') -> bo qua buoc kiem tra cu phap');
        return true;
    }
    say('  Ket qua php -l: ' . $txt);
    return false;
}

function patch_file($path, $blob, $mark, $bakSuffix) {
    say('== ' . basename($path));
    if (!is_file($path)) { say('  [LOI] Khong thay file nay. Dat patch_v25.php cung thu muc voi no.'); return false; }
    $raw = file_get_contents($path);
    if ($raw === false) { say('  [LOI] Khong doc duoc file.'); return false; }
    if (strpos($raw, $mark) !== false) { say('  [BO QUA] File nay da duoc va ban nay truoc do.'); return true; }
    $crlf = (strpos($raw, "\r\n") !== false);
    $src = $crlf ? str_replace("\r\n", "\n", $raw) : $raw;

    $fail = 0;
    foreach (parse_edits($blob) as $e) {
        $c = substr_count($src, $e['find']);
        if ($c !== $e['count']) { say('  [KHONG KHOP] ' . $e['label'] . ' (can ' . $e['count'] . ', thay ' . $c . ')'); $fail++; continue; }
        $src = str_replace($e['find'], $e['repl'], $src);
        say('  [OK] ' . $e['label']);
    }
    if ($fail) { say('  => KHONG ghi file nay (' . $fail . ' doan khong khop). File giu nguyen.'); return false; }

    $out = $crlf ? str_replace("\n", "\r\n", $src) : $src;
    $tmp = $path . '.v25tmp.php';
    file_put_contents($tmp, $out);
    if (!lint_ok($tmp)) { @unlink($tmp); say('  => Loi cu phap sau khi va, KHONG ghi file. File giu nguyen.'); return false; }
    @unlink($tmp);

    $bak = $path . $bakSuffix;
    if (!file_exists($bak)) { copy($path, $bak); say('  Da sao luu: ' . basename($bak)); }
    if (file_put_contents($path, $out) === false) { say('  [LOI] Khong ghi duoc file.'); return false; }
    say('  => DA GHI file.');
    return true;
}

/* ====================== devnvios_theme.php ====================== */
$THEME = <<<'PATCH'
@@ banner: mac dinh tu chuyen anh sau 3.5 giay (neu admin da dat so giay rieng thi so do van duoc uu tien) | 1
<<<
defined('DS_HERO_SECONDS') ? max(2, (float) DS_HERO_SECONDS) : 5;
===
defined('DS_HERO_SECONDS') ? max(2, (float) DS_HERO_SECONDS) : 3.5;
>>>

@@ thong bao key: dung ket qua kiem tra khoa ngay da lam san (bot 1 luot goi server) | 1
<<<
fetch(window.location.pathname + '?ds_check_key_lock=1', { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
===
dsLockCheck()
>>>

@@ thong bao key: ham kiem tra khoa ngay + lam san khi bam vao o nhap key | 1
<<<
function appCfg() {
===
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
>>>

@@ thong bao key: nut kich hoat mo di ngay khi bam | 1
<<<
var guessedPlan = detectFormPlan(form);
===
var guessedPlan = detectFormPlan(form);
            if (btn) { btn.style.opacity = '.6'; }
>>>

@@ thong bao key: tra nut ve binh thuong khi xong | 1
<<<
if (okReload) window.location.reload();
===
if (btn) { btn.style.opacity = ''; }
                    if (okReload) window.location.reload();
>>>

@@ banner trang chu: vong lap 2 chieu + tu chuyen + keo tay khong bao gio het | 1
<<<
<!--DS-P26-END-->
===
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
>>>
PATCH;

say('patch_v25 (ban 3) - thu muc: ' . $base);
$b = patch_file($base . DIRECTORY_SEPARATOR . 'devnvios_theme.php', $THEME, $MARK, $BAK);
say('');
say($b ? 'XONG. Dong han tab trinh duyet roi mo lai web de thay thay doi.' : 'CO LOI o tren. Gui lai cho minh dong [KHONG KHOP] hoac LOI de minh chinh.');
