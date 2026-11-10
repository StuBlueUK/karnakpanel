<?php
declare(strict_types=1);
/**
 * Errors — the panel's last line of defence (security sweep 2026-11-08 M7).
 *
 * Since the box's php.ini is not ours to trust (a dev install or a mis-set
 * pool can leave display_errors=On), the front controller forces it off BEFORE
 * the session starts, so an uncaught Throwable or a fatal error can never
 * render a stack trace — with file paths and SQL — to a browser or an API
 * client. Detail goes to the logs (panel.log + the pool's PHP error log); the
 * client gets one generic 500, JSON on `/api/*`, plain text elsewhere.
 *
 * Deliberately NOT controlled by `app.env`: hiding errors must not depend on a
 * config value an operator can flip by accident, and "production" in the
 * sample config was decorative until now.
 */
class Errors {
    private static bool $handled = false;
    /** Force production error behaviour and install both handlers. */
    public static function install(): void {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
        set_exception_handler([self::class, 'uncaught']);
        register_shutdown_function([self::class, 'shutdown']);
    }
    /** set_exception_handler callback — any Throwable. */
    public static function uncaught(Throwable $e): void {
        self::$handled = true;
        self::log('uncaught '.get_class($e).': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        self::respond();
    }
    /**
     * Fatals (E_ERROR/PARSE/CORE/COMPILE, E_USER_ERROR) never reach the
     * exception handler; error_get_last() still has them here. A normal exit
     * leaves a non-fatal last error (or none), so this stays quiet.
     */
    public static function shutdown(): void {
        if (self::$handled) return;
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        self::$handled = true;
        self::log('fatal: '.$e['message'].' @ '.$e['file'].':'.$e['line']);
        self::respond();
    }
    /** Server-side only. error_log() works even if the app is half-loaded. */
    private static function log(string $line): void {
        error_log('karnakpanel: '.$line);
        if (class_exists('Helpers', false)) {
            try { Helpers::log($line); } catch (Throwable $e) {}
        }
    }
    /** Generic answer — no exception text, path, query or stack, ever. */
    private static function respond(): void {
        if (!headers_sent()) {
            http_response_code(500);
            if (self::isApi()) {
                header('Content-Type: application/json');
                echo '{"error":"internal server error"}';
                exit(1);
            }
            header('Content-Type: text/plain; charset=utf-8');
        }
        // Headers already sent (a view rendered before the fault): the status
        // cannot change any more, but the client still gets no detail.
        echo self::isApi() ? '{"error":"internal server error"}' : "Internal Server Error\n";
        exit(1);
    }
    private static function isApi(): bool {
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return str_starts_with($path, '/api/');
    }
}
