-- قاعدة بيانات المستودع فقط. لا يوجد أي FK على جداول مشروع الزميل.

CREATE TABLE IF NOT EXISTS stock (
    product_id  INT UNSIGNED NOT NULL PRIMARY KEY,
    quantity    INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_stock_quantity CHECK (quantity >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reservation_key CHAR(36) NULL,
    order_id    INT UNSIGNED NULL,
    status      ENUM('RESERVED', 'CONFIRMED', 'RELEASED', 'RETURNED') NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reservations_order (order_id),
    UNIQUE KEY uq_reservations_key (reservation_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservation_items (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reservation_id  BIGINT UNSIGNED NOT NULL,
    product_id      INT UNSIGNED NOT NULL,
    quantity        INT UNSIGNED NOT NULL,
    CONSTRAINT chk_item_quantity CHECK (quantity > 0),
    CONSTRAINT fk_items_reservation FOREIGN KEY (reservation_id)
        REFERENCES reservations(id) ON DELETE CASCADE,
    KEY idx_items_reservation (reservation_id),
    KEY idx_items_product (product_id)
) ENGINE=InnoDB;
