-- Таблица для ставок команд из диплинка /start {game_id}_lot.
-- Важно: в разных БД у `users.id` и `games.id` могут отличаться типы (INT/INT UNSIGNED/BIGINT),
-- поэтому ниже создаём совместимую таблицу БЕЗ внешних ключей, чтобы избежать ошибки #1005 / errno:150.
-- При необходимости FK можно добавить вручную после проверки точных типов колонок в вашей БД.

CREATE TABLE IF NOT EXISTS `game_lot_bets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `game_id` INT NOT NULL,
    `team_name` VARCHAR(255) NULL DEFAULT NULL,
    `bet_option` VARCHAR(20) NULL DEFAULT NULL COMMENT 'Варианты: +1 / 0, +2 / -2',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_game_lot_bets_game` (`game_id`),
    INDEX `idx_game_lot_bets_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Пример добавления FK (запускайте только если типы совпадают 1-в-1):
-- ALTER TABLE `game_lot_bets`
--   ADD CONSTRAINT `fk_game_lot_bets_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
--   ADD CONSTRAINT `fk_game_lot_bets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
