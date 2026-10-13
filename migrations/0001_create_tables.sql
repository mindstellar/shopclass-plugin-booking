-- Booking Calendar tables. Times are UTC.

CREATE TABLE IF NOT EXISTS /*TABLE_PREFIX*/t_booking_listing (
    fk_i_item_id INT UNSIGNED NOT NULL,
    b_enabled TINYINT(1) NOT NULL DEFAULT 0,
    b_auto_confirm TINYINT(1) NOT NULL DEFAULT 0,
    i_min_nights SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    i_slot_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    s_hours VARCHAR(2000) NOT NULL DEFAULT '',
    s_price_note VARCHAR(200) NOT NULL DEFAULT '',
    s_ical_salt CHAR(16) NOT NULL DEFAULT '',
    i_version INT UNSIGNED NOT NULL DEFAULT 1,
    dt_updated DATETIME NOT NULL,
    PRIMARY KEY (fk_i_item_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci';

CREATE TABLE IF NOT EXISTS /*TABLE_PREFIX*/t_booking_blocked (
    fk_i_item_id INT UNSIGNED NOT NULL,
    d_date DATE NOT NULL,
    PRIMARY KEY (fk_i_item_id, d_date)
) ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci';

-- b_hold is 1 while the booking keeps its dates and NULL after, so the unique key stops two
-- live bookings starting at the same moment and lets a declined one be booked again.
CREATE TABLE IF NOT EXISTS /*TABLE_PREFIX*/t_booking (
    pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fk_i_item_id INT UNSIGNED NOT NULL,
    fk_i_user_id INT UNSIGNED NULL,
    s_mode VARCHAR(10) NOT NULL,
    dt_start DATETIME NOT NULL,
    dt_end DATETIME NOT NULL,
    s_status VARCHAR(10) NOT NULL,
    b_hold TINYINT(1) NULL,
    s_name VARCHAR(100) NOT NULL DEFAULT '',
    s_email VARCHAR(150) NOT NULL DEFAULT '',
    s_phone VARCHAR(40) NOT NULL DEFAULT '',
    s_note VARCHAR(1000) NOT NULL DEFAULT '',
    s_closed_by VARCHAR(10) NULL,
    dt_created DATETIME NOT NULL,
    dt_updated DATETIME NOT NULL,
    PRIMARY KEY (pk_i_id),
    UNIQUE KEY uq_booking_start (fk_i_item_id, dt_start, b_hold),
    KEY idx_booking_holds (fk_i_item_id, b_hold, dt_end),
    KEY idx_booking_status (s_status, dt_created),
    KEY idx_booking_user (fk_i_user_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci';
