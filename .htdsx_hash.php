<?php
/* dsx-hash-v1 : tu dong chen script an duong dan, KHONG sua file goc */
if (is_file(__DIR__ . '/security_shield.php')) {
    require_once __DIR__ . '/security_shield.php';
    sh_early_guard();
}
if (!function_exists('dsx_hash_cb')) {
    function dsx_hash_cb($buf, $phase = 0) {
        static $done = false;
        if ($done || !is_string($buf) || $buf === '') { return $buf; }
        if (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'empty') { $done = true; return $buf; }
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) { $done = true; return $buf; }
        foreach (headers_list() as $h) {
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) { $done = true; return $buf; }
        }
        if (strpos($buf, 'dsx-hash-v1') !== false) { $done = true; return $buf; }
        $js = <<<'DSXJS'
/*dsx-hash-v1*/
(function () {
  if (window.__dsxH) { return; }
  window.__dsxH = 1;
  try { if (window.top !== window.self) { return; } } catch (e) { return; }
  var S;
  try { S = window.sessionStorage; S.setItem("dsx_t", "1"); S.removeItem("dsx_t"); } catch (e) { return; }
  var L = window.location, H = window.history;
  var RP = L.pathname, RS = L.search, KEY = "dsx_map";
  var CONS = "bcdfghjklmnpqrstvwxz";

  function load() {
    try { var m = JSON.parse(S.getItem(KEY) || "[]"); return (m instanceof Array) ? m : []; } catch (e) { return []; }
  }
  function save(m) {
    try { S.setItem(KEY, JSON.stringify(m.slice(-30))); } catch (e) {}
  }

  /* 1) Dang o /#ma  (F5, Back): tra ma -> neu la trang khac thi chuyen ve dung trang */
  var cur = (L.hash || "").replace(/^#/, "");
  if (/^[bcdfghjklmnpqrstvwxz]{6,8}$/.test(cur)) {
    var m0 = load();
    for (var i = 0; i < m0.length; i++) {
      if (m0[i][0] === cur) {
        if (m0[i][1] !== RP + RS && String(m0[i][1]).indexOf("#") < 0) { L.replace(m0[i][1]); return; }
        break;
      }
    }
  } else if (cur !== "") {
    return; /* hash that cua trang (tab...) -> khong dung vao */
  }

  /* 2) Giu cho form / link / fetch / XHR khong co dia chi van tro ve DUNG trang that */
  function fixOne(f) {
    try {
      if (f && f.getAttribute && !f.getAttribute("action")) {
        var meth = (f.getAttribute("method") || "get").toLowerCase();
        f.setAttribute("action", meth === "post" ? RP + RS : RP);
      }
    } catch (e) {}
  }
  document.addEventListener("submit", function (e) {
    var f = e.target;
    if (f && f.tagName === "FORM") { fixOne(f); }
  }, true);
  try {
    var os = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () { fixOne(this); return os.apply(this, arguments); };
  } catch (e) {}
  document.addEventListener("click", function (e) {
    try {
      var a = e.target;
      while (a && a.nodeType === 1 && a.tagName !== "A") { a = a.parentNode; }
      if (a && a.nodeType === 1 && a.tagName === "A") {
        var h = a.getAttribute("href");
        if (h !== null && (h === "" || h.charAt(0) === "?")) { a.setAttribute("href", RP + (h === "" ? RS : h)); }
      }
    } catch (x) {}
  }, true);
  function fx(u) {
    if (typeof u === "string" && (u === "" || u.charAt(0) === "?")) { return RP + (u === "" ? RS : u); }
    return u;
  }
  try {
    if (window.fetch) {
      var of = window.fetch;
      window.fetch = function (i, o) {
        try { i = fx(i); } catch (e) {}
        return of.call(this, i, o);
      };
    }
  } catch (e) {}
  try {
    var ox = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function () {
      var a = [].slice.call(arguments);
      try { a[1] = fx(a[1]); } catch (e) {}
      return ox.apply(this, a);
    };
  } catch (e) {}

  /* 3) Doi thanh dia chi thanh /#maNgauNhien (sau khi HTML da parse xong) */
  var fired = false;
  function hide() {
    if (fired) { return; }
    fired = true;
    try {
      var m = load(), code = "", t = 0;
      do {
        code = "";
        var n = 6 + Math.floor(Math.random() * 3);
        for (var k = 0; k < n; k++) { code += CONS.charAt(Math.floor(Math.random() * CONS.length)); }
        t++;
      } while (t < 20 && m.some(function (e) { return e[0] === code; }));
      m.push([code, RP + RS]);
      save(m);
      H.replaceState(H.state, "", "/" + RS + "#" + code);
    } catch (e) {}
  }
  if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", hide); } else { hide(); }
})();
DSXJS;
        $snGuard = basename((string) strtok((string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : ''), '?'));
        $guardTag = ($snGuard !== 'admin_cp.php' && $snGuard !== 'maintenance_access.php' && function_exists('sh_client_guard_script'))
            ? sh_client_guard_script()
            : '';
        $tag = '<script data-dsx="hash-v1">' . $js . '</script>';
        if (preg_match('/<head(?:\s[^>]*)?>/i', $buf, $mm, PREG_OFFSET_CAPTURE)) {
            $pos = $mm[0][1] + strlen($mm[0][0]);
            $win = substr($buf, $pos, 2048);
            if (preg_match('/<meta\s[^>]*charset[^>]*>/i', $win, $mc, PREG_OFFSET_CAPTURE)) {
                $pos = $pos + $mc[0][1] + strlen($mc[0][0]);
            }
            $buf = substr($buf, 0, $pos) . $guardTag . $tag . substr($buf, $pos);
        } elseif (($p = strripos($buf, '</body>')) !== false) {
            $buf = substr($buf, 0, $p) . $guardTag . $tag . substr($buf, $p);
        } else {
            return $buf;
        }
        $done = true;
        if (!headers_sent()) {
            @header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            @header('Pragma: no-cache');
            @header_remove('Content-Length');
        }
        return $buf;
    }
}
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && empty($GLOBALS['dsx_hash_on'])) {
    $GLOBALS['dsx_hash_on'] = 1;
    ob_start('dsx_hash_cb', 1048576);
}
