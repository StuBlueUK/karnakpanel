<?php
declare(strict_types=1);
// Reseller white-label: panel name, logo, accent colour and a support link,
// shown to the reseller (as a preview) and to every client they own. Admins
// and the admin's direct clients always see the stock KarnakPanel look.
// The login page stays stock: nobody is known before login (per-reseller
// hostnames would need DNS + SSL + an OLS listener each).
// Logo lives in the DB (base64 PNG), never on disk: public/ is web-served
// and the live panel tree is replaced on every self-update.
class Branding {
    public const LOGO_MAX_UPLOAD = 1048576;   // bytes accepted from the browser
    public const LOGO_MAX_STORED = 262144;    // bytes after re-encoding
    private static array $cache = [];

    // brand row (without the logo blob) that applies to $user, or null
    public static function forUser(array $user): ?array {
        $id = (int)($user['id'] ?? 0);
        if ($id <= 0) return null;
        if (array_key_exists($id, self::$cache)) return self::$cache[$id];
        $rid = 0;
        try {
            if (($user['role'] ?? '') === 'reseller') {
                $rid = $id;
            } elseif (($user['role'] ?? '') === 'user' && !empty($user['owner_id'])) {
                $o = Database::query("SELECT id, role FROM users WHERE id=?", [(int)$user['owner_id']])->fetch();
                if ($o && $o['role'] === 'reseller') $rid = (int)$o['id'];
            }
            $b = $rid ? self::find($rid) : null;
        } catch (Throwable $e) { $b = null; }
        if ($b && $b['name'] === '' && $b['accent'] === '' && $b['logo_hash'] === '' && $b['support_url'] === '' && $b['support_email'] === '') $b = null;
        return self::$cache[$id] = $b ?: null;
    }
    public static function find(int $resellerId): ?array {
        $b = Database::query("SELECT reseller_id,name,accent,support_url,support_email,logo_hash FROM branding WHERE reseller_id=?", [$resellerId])->fetch();
        return $b ?: null;
    }
    public static function logo(int $resellerId): ?string {
        $r = Database::query("SELECT logo FROM branding WHERE reseller_id=?", [$resellerId])->fetch();
        $png = ($r && $r['logo'] !== null) ? base64_decode((string)$r['logo'], true) : false;
        return $png === false ? null : $png;
    }
    // Validate + re-encode an uploaded image to a small PNG. Re-encoding drops
    // anything that isn't pixels (metadata, appended payloads). SVG refused:
    // opened on the panel origin it can run script. Returns [png, null] or
    // [null, error].
    public static function processLogo(string $path): array {
        $size = @filesize($path);
        if ($size === false || $size <= 0) return [null, 'Logo upload failed'];
        if ($size > self::LOGO_MAX_UPLOAD) return [null, 'Logo too large (max 1 MB)'];
        $info = @getimagesize($path);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            return [null, 'Logo must be a PNG, JPEG, WebP or GIF image'];
        }
        [$w, $h] = $info;
        // checked BEFORE decoding: a tiny file can declare a huge canvas
        if ($w < 1 || $h < 1 || $w > 4000 || $h > 4000) return [null, 'Logo dimensions must be at most 4000×4000 px'];
        $src = @imagecreatefromstring((string)file_get_contents($path));
        if ($src === false) return [null, 'Logo could not be read as an image'];
        $scale = min(1.0, 128 / $h, 512 / $w);   // fits the top bar; never upscaled
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start(); imagepng($dst, null, 9); $png = (string)ob_get_clean();
        if ($png === '' || strlen($png) > self::LOGO_MAX_STORED) return [null, 'Logo too detailed after resizing — try a simpler image'];
        return [$png, null];
    }
    // accent overrides for the stylesheet's hardcoded sky blues (top bar,
    // buttons, file pickers); text flips dark on light accents
    public static function css(string $hex): string {
        if (!preg_match('/^#[0-9a-f]{6}$/i', $hex)) return '';
        [$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 1), 2));
        $mix = fn(float $f, int $t) => sprintf('#%02x%02x%02x', (int)round($r + ($t - $r) * $f), (int)round($g + ($t - $g) * $f), (int)round($b + ($t - $b) * $f));
        $dark = $mix(0.25, 0); $light = $mix(0.45, 255);
        $fg = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#0b1220' : '#fff';
        $a = strtolower($hex);
        return ":root,[data-theme=\"light\"]{--sky:$a;--sky2:$light}"
             . ".topbar{background:linear-gradient(90deg,$dark,$a 60%,$light);color:$fg}"
             . ".topbar .hlink,.topbar .brand{color:$fg}"
             . ".btn:not(.danger),input[type=file]::file-selector-button{background:linear-gradient(90deg,$dark,$a);color:$fg}"
             . ".impbar .btn{background:#0b1220;color:#fff}";   // impersonation bar keeps its own look
    }
}
