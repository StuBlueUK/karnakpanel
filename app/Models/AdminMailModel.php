<?php
declare(strict_types=1);
/** AdminMailModel — the blacklist_status table the mail page reads. */
class AdminMailModel {
    public static function blacklistRows(): array {
        return Database::query(
            "SELECT * FROM blacklist_status ORDER BY FIELD(state,'listed','unchecked','clean'), kind, target, name"
        )->fetchAll();
    }
}
