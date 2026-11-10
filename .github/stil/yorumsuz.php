<?php
// Her PHP dosyasinin yorumlari ayni uzunlukta bosluga cevrilmis kopyasi (konumlar korunur).
$kok = $argv[1]; $cikti = $argv[2];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kok, FilesystemIterator::SKIP_DOTS));
$sonuc = [];
foreach ($it as $f) {
    $yol = substr($f->getPathname(), strlen($kok) + 1);
    if (!str_ends_with($yol, '.php') || preg_match('#^(\.git|ajan/vendor|onbellek|gorseller)/#', $yol)) continue;
    $kod = file_get_contents($f->getPathname());
    $out = '';
    foreach (token_get_all($kod) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= preg_replace('/[^\n]/u', ' ', $t[1]);
        } else {
            $out .= is_array($t) ? $t[1] : $t;
        }
    }
    $sonuc[$yol] = $out;
}
file_put_contents($cikti, json_encode($sonuc, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
