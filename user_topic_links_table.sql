CREATE TABLE IF NOT EXISTS user_topic_links (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    message_thread_id BIGINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_topic_links_user (user_id),
    UNIQUE KEY uq_user_topic_links_thread (message_thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
