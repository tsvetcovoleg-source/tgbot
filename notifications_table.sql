-- Приводим тип первичного ключа пользователей к BIGINT UNSIGNED,
-- чтобы его можно было безопасно ссылать из таблицы подписок.
ALTER TABLE users
    MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;

CREATE TABLE IF NOT EXISTS format_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    format VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_format_subscriptions_user_format (user_id, format),
    INDEX idx_format_subscriptions_user_format (user_id, format),
    CONSTRAINT fk_format_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
