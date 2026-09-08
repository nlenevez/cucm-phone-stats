-- cucm-phone-stats schema (MySQL 8.0+ / MariaDB 10.3+)
--
-- Shape of the data:
--   sweeps        one collection run
--   phones        stable inventory, one row per device name, updated in place
--   registrations per sweep: where the phone was and what CUCM thought of it
--   port_stats    per sweep, per port: normalised link state + counters
--   page_fetches  per sweep, per adapter: the verbatim label/value pairs and
--                 every failure, including the ones that produced no stats
--
-- The trend views use window functions (LAG, and a named WINDOW clause), so
-- the floor is MySQL 8.0 or MariaDB 10.2. Verified on MySQL 8.0.46 and
-- 8.4.11 (the deployment target), and on MariaDB 10.3.
--
-- Nothing here deletes rows. The runtime account therefore needs only
-- SELECT, INSERT and UPDATE; see "Database setup" in the README.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sweeps (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at        DATETIME        NOT NULL,
  finished_at       DATETIME        NULL,
  source            VARCHAR(32)     NOT NULL DEFAULT 'risport',
  cucm_servers      TEXT            NULL,
  tool_version      VARCHAR(32)     NULL,
  phones_discovered INT UNSIGNED    NOT NULL DEFAULT 0,
  phones_polled     INT UNSIGNED    NOT NULL DEFAULT 0,
  phones_ok         INT UNSIGNED    NOT NULL DEFAULT 0,
  phones_failed     INT UNSIGNED    NOT NULL DEFAULT 0,
  notes             TEXT            NULL,
  PRIMARY KEY (id),
  KEY idx_sweeps_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS phones (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_name       VARCHAR(64)     NOT NULL,
  mac_address       CHAR(12)        NULL,
  description       VARCHAR(255)    NULL,
  model             VARCHAR(64)     NULL,
  product           VARCHAR(64)     NULL,
  device_class      VARCHAR(32)     NULL,
  dir_number        VARCHAR(64)     NULL,
  serial_number     VARCHAR(64)     NULL,
  model_number      VARCHAR(64)     NULL,
  hardware_revision VARCHAR(64)     NULL,
  first_seen        DATETIME        NOT NULL,
  last_seen         DATETIME        NOT NULL,
  last_ip           VARCHAR(45)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_phones_device_name (device_name),
  KEY idx_phones_mac (mac_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS registrations (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sweep_id       BIGINT UNSIGNED NOT NULL,
  phone_id       BIGINT UNSIGNED NOT NULL,
  ip_address     VARCHAR(45)     NULL,
  ip_addresses   JSON            NULL,
  status         VARCHAR(32)     NULL,
  status_reason  VARCHAR(32)     NULL,
  node_name      VARCHAR(128)    NULL,
  ris_server     VARCHAR(255)    NULL,
  protocol       VARCHAR(16)     NULL,
  active_load    VARCHAR(64)     NULL,
  inactive_load  VARCHAR(64)     NULL,
  http_supported TINYINT         NULL,
  ris_timestamp  VARCHAR(32)     NULL,
  source         VARCHAR(16)     NOT NULL DEFAULT 'risport',
  PRIMARY KEY (id),
  UNIQUE KEY uq_reg_sweep_phone (sweep_id, phone_id),
  KEY idx_reg_phone (phone_id),
  CONSTRAINT fk_reg_sweep FOREIGN KEY (sweep_id) REFERENCES sweeps (id) ON DELETE CASCADE,
  CONSTRAINT fk_reg_phone FOREIGN KEY (phone_id) REFERENCES phones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS port_stats (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sweep_id            BIGINT UNSIGNED NOT NULL,
  phone_id            BIGINT UNSIGNED NOT NULL,
  port                ENUM('network','access','ethernet') NOT NULL,
  collected_at        DATETIME        NOT NULL,
  link_up             TINYINT         NULL,
  speed_mbps          INT UNSIGNED    NULL,
  duplex              ENUM('full','half') NULL,
  -- receive
  rx_total_pkt        BIGINT UNSIGNED NULL,
  rx_total_bytes      BIGINT UNSIGNED NULL,
  rx_crc_err          BIGINT UNSIGNED NULL,
  rx_align_err        BIGINT UNSIGNED NULL,
  rx_short_err        BIGINT UNSIGNED NULL,
  rx_long_err         BIGINT UNSIGNED NULL,
  rx_jabber           BIGINT UNSIGNED NULL,
  rx_overrun          BIGINT UNSIGNED NULL,
  rx_multicast        BIGINT UNSIGNED NULL,
  rx_broadcast        BIGINT UNSIGNED NULL,
  rx_unicast          BIGINT UNSIGNED NULL,
  -- transmit
  tx_total_pkt        BIGINT UNSIGNED NULL,
  tx_total_bytes      BIGINT UNSIGNED NULL,
  tx_collisions       BIGINT UNSIGNED NULL,
  tx_late_collision   BIGINT UNSIGNED NULL,
  tx_excess_collision BIGINT UNSIGNED NULL,
  tx_excess_defer     BIGINT UNSIGNED NULL,
  tx_excess_length    BIGINT UNSIGNED NULL,
  tx_deferred         BIGINT UNSIGNED NULL,
  tx_multicast        BIGINT UNSIGNED NULL,
  tx_broadcast        BIGINT UNSIGNED NULL,
  tx_unicast          BIGINT UNSIGNED NULL,
  -- rollup of the CPS_ERROR_COLUMNS counters, computed at collection time
  errors_total        BIGINT UNSIGNED NULL,
  unmapped_labels     INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ps_sweep_phone_port (sweep_id, phone_id, port),
  KEY idx_ps_phone_port (phone_id, port),
  KEY idx_ps_errors (errors_total),
  CONSTRAINT fk_ps_sweep FOREIGN KEY (sweep_id) REFERENCES sweeps (id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_phone FOREIGN KEY (phone_id) REFERENCES phones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every adapter fetched, successful or not, with the verbatim pairs. This is
-- the safety net for label drift: a counter the normaliser does not know is
-- still here afterwards, so a new firmware does not cost a second sweep.
CREATE TABLE IF NOT EXISTS page_fetches (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sweep_id    BIGINT UNSIGNED NOT NULL,
  phone_id    BIGINT UNSIGNED NOT NULL,
  adapter     VARCHAR(48)     NOT NULL,
  ok          TINYINT         NOT NULL DEFAULT 0,
  http_code   INT             NULL,
  error_kind  VARCHAR(16)     NULL,
  error       TEXT            NULL,
  pairs       JSON            NULL,
  fetched_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pf_sweep_phone_adapter (sweep_id, phone_id, adapter),
  KEY idx_pf_kind (error_kind),
  CONSTRAINT fk_pf_sweep FOREIGN KEY (sweep_id) REFERENCES sweeps (id) ON DELETE CASCADE,
  CONSTRAINT fk_pf_phone FOREIGN KEY (phone_id) REFERENCES phones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What the phone itself says it is plugged into, per sweep.
--
-- A separate table rather than columns on `phones` or `registrations`: this
-- is the phone's own view via CDP/LLDP, not CUCM's, and a phone moves desks.
-- Keeping it per-sweep means the reports can say which switch port a fault
-- was seen on at the time, not merely where the handset is today.
--
-- Being a new table also means an existing database picks it up from
-- CREATE TABLE IF NOT EXISTS with no migration; adding columns to a table
-- that already exists would silently do nothing.
CREATE TABLE IF NOT EXISTS uplinks (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sweep_id               BIGINT UNSIGNED NOT NULL,
  phone_id               BIGINT UNSIGNED NOT NULL,
  cdp_neighbour          VARCHAR(255) NULL,
  cdp_neighbour_port     VARCHAR(128) NULL,
  cdp_neighbour_ip       VARCHAR(45)  NULL,
  cdp_neighbour_platform VARCHAR(128) NULL,
  lldp_neighbour         VARCHAR(255) NULL,
  lldp_neighbour_port    VARCHAR(128) NULL,
  lldp_neighbour_ip      VARCHAR(45)  NULL,
  -- Collapsed answer: CDP preferred, LLDP as fallback. Stored rather than
  -- derived so every report and any external query agree on the choice.
  switch_name            VARCHAR(255) NULL,
  switch_port            VARCHAR(128) NULL,
  switch_ip              VARCHAR(45)  NULL,
  neighbour_source       ENUM('cdp','lldp') NULL,
  collected_at           DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_up_sweep_phone (sweep_id, phone_id),
  KEY idx_up_switch (switch_name, switch_port),
  KEY idx_up_phone (phone_id),
  CONSTRAINT fk_up_sweep FOREIGN KEY (sweep_id) REFERENCES sweeps (id) ON DELETE CASCADE,
  CONSTRAINT fk_up_phone FOREIGN KEY (phone_id) REFERENCES phones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Reporting views
-- ---------------------------------------------------------------------------

-- started_at is second-resolution, so two sweeps started in the same second
-- tie. Every ordering over sweeps therefore breaks the tie on the
-- auto-increment id, which is monotonic in creation order. Without it, both
-- "the latest sweep" and the LAG() below pick arbitrarily among tied rows.
CREATE OR REPLACE VIEW v_latest_sweep AS
SELECT * FROM sweeps WHERE finished_at IS NOT NULL ORDER BY started_at DESC, id DESC LIMIT 1;

-- REPORT 1: access port speed/duplex does not match the network port.
-- Only phones where BOTH ports are up are compared - an access port with
-- nothing plugged into it is not a mismatch, and counting it as one would
-- bury the real findings under every unused PC port in the estate.
CREATE OR REPLACE VIEW v_port_speed_mismatch AS
SELECT
  n.sweep_id,
  s.started_at,
  p.id            AS phone_id,
  p.device_name,
  p.description,
  p.model,
  r.ip_address,
  u.switch_name,
  u.switch_port,
  u.neighbour_source,
  n.speed_mbps    AS network_speed_mbps,
  n.duplex        AS network_duplex,
  a.speed_mbps    AS access_speed_mbps,
  a.duplex        AS access_duplex,
  CASE
    WHEN NOT (n.speed_mbps <=> a.speed_mbps) AND NOT (n.duplex <=> a.duplex) THEN 'speed+duplex'
    WHEN NOT (n.speed_mbps <=> a.speed_mbps)                                  THEN 'speed'
    ELSE 'duplex'
  END AS mismatch_kind
FROM port_stats n
JOIN port_stats a
  ON a.sweep_id = n.sweep_id AND a.phone_id = n.phone_id AND a.port = 'access'
JOIN phones  p ON p.id = n.phone_id
JOIN sweeps  s ON s.id = n.sweep_id
LEFT JOIN registrations r ON r.sweep_id = n.sweep_id AND r.phone_id = n.phone_id
LEFT JOIN uplinks       u ON u.sweep_id = n.sweep_id AND u.phone_id = n.phone_id
WHERE n.port = 'network'
  AND n.link_up = 1 AND a.link_up = 1
  AND (NOT (n.speed_mbps <=> a.speed_mbps) OR NOT (n.duplex <=> a.duplex));

-- REPORT 2: any port carrying a non-zero error counter.
CREATE OR REPLACE VIEW v_port_errors AS
SELECT
  ps.sweep_id,
  s.started_at,
  p.id AS phone_id,
  p.device_name,
  p.description,
  p.model,
  r.ip_address,
  u.switch_name,
  u.switch_port,
  u.neighbour_source,
  ps.port,
  ps.link_up,
  ps.speed_mbps,
  ps.duplex,
  ps.errors_total,
  ps.rx_crc_err, ps.rx_align_err, ps.rx_short_err, ps.rx_long_err,
  ps.rx_jabber, ps.rx_overrun,
  ps.tx_collisions, ps.tx_late_collision, ps.tx_excess_collision,
  ps.tx_excess_defer, ps.tx_excess_length,
  ps.rx_total_pkt, ps.tx_total_pkt
FROM port_stats ps
JOIN phones p ON p.id = ps.phone_id
JOIN sweeps s ON s.id = ps.sweep_id
LEFT JOIN registrations r ON r.sweep_id = ps.sweep_id AND r.phone_id = ps.phone_id
LEFT JOIN uplinks       u ON u.sweep_id = ps.sweep_id AND u.phone_id = ps.phone_id
WHERE ps.errors_total > 0;

-- REPORT 3 (base): consecutive-sweep deltas per phone and port.
--
-- Phone counters are free-running and reset when the handset reboots, so a
-- delta computed blind goes sharply negative on every reboot and, worse,
-- undercounts the sweep the reboot happened in. A drop in the total is
-- treated as a reset and reported as such rather than as a negative delta,
-- so a rebooting phone shows up as "reset", never as "improving".
CREATE OR REPLACE VIEW v_port_error_deltas AS
SELECT
  ps.sweep_id,
  s.started_at,
  ps.phone_id,
  p.device_name,
  p.description,
  u.switch_name,
  u.switch_port,
  ps.port,
  ps.errors_total,
  LAG(ps.errors_total) OVER w AS prev_errors_total,
  LAG(s.started_at)    OVER w AS prev_started_at,
  CASE
    WHEN LAG(ps.errors_total) OVER w IS NULL              THEN NULL
    WHEN ps.errors_total < LAG(ps.errors_total) OVER w    THEN NULL
    ELSE ps.errors_total - LAG(ps.errors_total) OVER w
  END AS errors_delta,
  CASE
    WHEN LAG(ps.errors_total) OVER w IS NULL           THEN 0
    WHEN ps.errors_total < LAG(ps.errors_total) OVER w THEN 1
    ELSE 0
  END AS counter_reset
FROM port_stats ps
JOIN phones p ON p.id = ps.phone_id
JOIN sweeps s ON s.id = ps.sweep_id
LEFT JOIN uplinks u ON u.sweep_id = ps.sweep_id AND u.phone_id = ps.phone_id
WHERE s.finished_at IS NOT NULL
WINDOW w AS (PARTITION BY ps.phone_id, ps.port ORDER BY s.started_at, s.id);

-- REPORT 3: ports whose error counters actually grew between two sweeps.
CREATE OR REPLACE VIEW v_port_errors_increasing AS
SELECT * FROM v_port_error_deltas WHERE errors_delta > 0;
