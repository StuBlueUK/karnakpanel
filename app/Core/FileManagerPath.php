<?php
declare(strict_types=1);
/**
 * File-manager path rules. One path component, a relative directory, the
 * panel-owned dot paths that are never touched, and containment resolution
 * that refuses symlinks anywhere on the way. Mirrors FM_NAME_RE /
 * fm_panel_path in scripts/fm-common.sh — keep them in step.
 */
class FileManagerPath {
    // File-manager names: one path component. Dotfiles, spaces and ()+@,~
    // allowed; no leading '-', no trailing space, never "." / "..".
    // Keep in sync with FM_NAME_RE in scripts/fm-common.sh.
    public static function name(string $n): bool {
        return $n !== '.' && $n !== '..' && !str_ends_with($n, ' ')
            && (bool)preg_match('/\A[a-zA-Z0-9_.][a-zA-Z0-9_. ()+@,~-]{0,127}\z/', $n);
    }
    // A relative dir path for the file manager (same charset + '/', no "..").
    public static function rel(string $r): bool {
        return !str_contains($r, '..') && !str_starts_with($r, '/')
            && (bool)preg_match('#\A[a-zA-Z0-9_. ()+@,~/-]{0,1024}\z#', $r);
    }
    // Panel-owned dot paths at the home root (fm_panel_path in fm-common.sh):
    // never listed for actions, edited, downloaded or written.
    public static function panelPath(string $rel, string $name = ''): bool {
        $first = explode('/', trim($rel.'/'.$name, '/'))[0];
        return in_array($first, ['.ssh', '.php-sessions', '.skypanel-cron', '.trash'], true);
    }
    // File-manager containment (answers the Semgrep symlink/prefix family):
    // realpath()+str_starts_with($t,$base) is NOT containment — a sibling
    // home sharing the string prefix (/home/bob2 vs /home/bob) passes, and
    // realpath() resolves (then hides) symlinks, so is_link() on the result
    // is a dead check. This walks every component with lstat and refuses
    // symlinks ANYWHERE in the user path, then enforces a trailing-slash
    // boundary. Returns the canonical in-base path, or null on any dodge.
    // Note: the manager therefore doesn't follow user symlinks at all
    // (apps may still use them; manage those targets via FTP/SSH).
    public static function resolve(string $base, string $rel, string $name): ?string {
        $base = rtrim($base, '/');
        if ($base === '' || $base === '/') return null;
        if (str_contains($rel, "\0") || str_contains($name, "\0")) return null;
        if (str_contains($rel, '..')) return null;
        // a "." name would resolve to the PARENT dir (delete = whole folder)
        if ($name === '.' || $name === '..') return null;
        $parts = array_values(array_filter(
            explode('/', trim($rel, '/').'/'.$name),
            fn($x) => $x !== '' && $x !== '.'
        ));
        if ($parts === []) return $base;
        $cur = $base;
        $n = count($parts);
        foreach ($parts as $i => $part) {
            if ($part === '..') return null;
            $cur .= '/'.$part;
            if (is_link($cur)) return null;
            if ($i < $n - 1 && !is_dir($cur)) return null;
        }
        if ($cur !== $base && !str_starts_with($cur, $base.'/')) return null;
        return $cur;
    }
}
