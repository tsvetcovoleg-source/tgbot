-- Таблица для ставок команд из диплинка /start {game_id}_lot.
-- Выполните SQL в MySQL/phpMyAdmin.

CREATE TABLE IF NOT EXISTS `game_lot_bets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `game_id` BIGINT UNSIGNED NOT NULL,
    `team_name` VARCHAR(255) NULL DEFAULT NULL,
    `bet_option` VARCHAR(20) NULL DEFAULT NULL COMMENT 'Варианты: +1 / 0, +2 / -2',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_game_lot_bets_game` (`game_id`),
    INDEX `idx_game_lot_bets_user` (`user_id`),
    CONSTRAINT `fk_game_lot_bets_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_game_lot_bets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
