<?php
declare(strict_types=1);
// A ServiceManager::run() argument that must never appear on a command line.
// sudo logs the full argv to auth.log/journal, Helpers::sysExec() logs the
// command to panel.log, and /proc/<pid>/cmdline is readable by every local
// user: a secret goes as the literal "@stdin" placeholder instead, its value
// NUL-terminated on the script's stdin (scripts/secret-args.sh rebuilds argv).
final class Secret {
    public function __construct(public readonly string $value) {}
}
