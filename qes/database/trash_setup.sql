-- Run this once to add the trash table
CREATE TABLE IF NOT EXISTS trash (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_type ENUM('event', 'facility', 'user') NOT NULL,
    item_id INT NULL DEFAULT NULL,
    item_data LONGTEXT NOT NULL,
    deleted_by INT DEFAULT NULL,
    deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    auto_delete_at TIMESTAMP GENERATED ALWAYS AS (DATE_ADD(deleted_at, INTERVAL 30 DAY)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
