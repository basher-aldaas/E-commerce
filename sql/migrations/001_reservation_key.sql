-- Adds reservation_key and the RETURNED status. Existing rows keep their values.
ALTER TABLE reservations
    ADD COLUMN reservation_key CHAR(36) NULL AFTER id,
    MODIFY COLUMN order_id INT UNSIGNED NULL,
    MODIFY COLUMN status ENUM('RESERVED', 'CONFIRMED', 'RELEASED', 'RETURNED') NOT NULL,
    ADD UNIQUE KEY uq_reservations_key (reservation_key);
