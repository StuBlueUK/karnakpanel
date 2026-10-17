<?php
// PhpVersions — the ONE list of customer PHP versions (mirror:
// scripts/php-versions.sh; tests/run.php fails if the two drift).
//   SELECTABLE  offered for new sites/plans/switches (8.3 is the default).
//   LEGACY      still served + editable for sites already on it, never
//               offered; value = upstream EOL date (badge on the PHP page).
// "Known" is DETECTED, not listed: any 8.x whose php-fpm binary is on this
// box. A box still carrying 8.1 sites keeps 8.1 working (edits, php.ini,
// rewrites) until php-retire.sh removes it; a version whose packages failed
// to install is simply absent from the pickers.
final class PhpVersions {
    public const SELECTABLE = ['8.3', '8.4', '8.5'];
    public const LEGACY = ['8.2' => '2026-12-31'];
    public const DEFAULT = '8.3';

    /** 8.x versions with php-fpm installed here, ascending. Empty off-box. */
    public static function installed(): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $out = [];
        foreach (glob('/usr/sbin/php-fpm8.[0-9]') ?: [] as $f) {
            if (is_executable($f)) $out[] = substr($f, strlen('/usr/sbin/php-fpm'));
        }
        sort($out);
        return $cache = $out;
    }

    /** Versions existing sites may be on (served, editable). */
    public static function known(): array {
        $inst = self::installed();
        // dev box / no FPM at all: the curated lists, so forms still validate
        return $inst ?: array_merge(array_keys(self::LEGACY), self::SELECTABLE);
    }

    /** Versions offered for NEW sites, plans and switches. */
    public static function selectable(): array {
        $inst = self::installed();
        if (!$inst) return self::SELECTABLE;
        return array_values(array_intersect(self::SELECTABLE, $inst));
    }

    public static function valid(string $v): bool {
        return in_array($v, self::known(), true);
    }

    public static function isSelectable(string $v): bool {
        return in_array($v, self::selectable(), true);
    }

    /** $v when valid (known), else the default — for silent fallbacks. */
    public static function orDefault(?string $v): string {
        return ($v !== null && self::valid($v)) ? $v : self::DEFAULT;
    }

    /** known() in the dotless form the pool scripts take ('83'). */
    public static function knownShort(): array {
        return array_map(fn($v) => str_replace('.', '', $v), self::known());
    }

    /** <option>s for a NEW-site picker: selectable only, default preselected. */
    public static function options(?string $sel = null): string {
        $sel = ($sel !== null && self::isSelectable($sel)) ? $sel : self::DEFAULT;
        $h = '';
        foreach (self::selectable() as $v) {
            $h .= '<option' . ($v === $sel ? ' selected' : '') . '>' . htmlspecialchars($v) . '</option>';
        }
        return $h;
    }

    /** EOL date for a legacy version, else null. */
    public static function eol(string $v): ?string {
        return self::LEGACY[$v] ?? null;
    }
}
