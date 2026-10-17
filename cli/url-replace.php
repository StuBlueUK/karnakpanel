#!/usr/bin/env php
<?php
// url-replace.php <old> <new> — serialized-safe string replace for SQL dumps.
// stdin in, replaced dump on stdout. Plain str_replace corrupts PHP-serialized
// values (s:<len>:"..." lengths go stale); this walks serialized strings by
// their DECLARED (still valid — input is pre-replace) lengths and replaces
// inside each, recomputing lengths. Malformed segments fall back to plain
// replace for the remainder (never silently corrupt).
[$self, $old, $new] = $argv + [null, null, null];
if ($old === null || $old === '' || $new === null) { fwrite(STDERR, "usage: url-replace.php <old> <new> < dump.sql\n"); exit(1); }
$in = file_get_contents('php://stdin');
if ($in === false) exit(1);
if (strpos($in, $old) === false) { fwrite(STDOUT, $in); exit(0); }
$out = '';
$pos = 0;
$n = strlen($in);
while (preg_match('/s:(\d+):"/', $in, $m, PREG_OFFSET_CAPTURE, $pos)) {
    $start = $m[0][1];
    $len = (int)$m[1][0];
    $segStart = $start + strlen($m[0][0]);
    // well-formed check: exactly $len bytes then '";'
    if ($segStart + $len + 2 > $n || $in[$segStart + $len] !== '"' || $in[$segStart + $len + 1] !== ';') break;
    $out .= str_replace($old, $new, substr($in, $pos, $start - $pos));
    $seg = str_replace($old, $new, substr($in, $segStart, $len));
    $out .= 's:' . strlen($seg) . ':"' . $seg . '";';
    $pos = $segStart + $len + 2;
}
$out .= str_replace($old, $new, substr($in, $pos));
fwrite(STDOUT, $out);
