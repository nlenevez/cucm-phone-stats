-- cucm-phone-stats schema (MySQL 8.0+ / MariaDB 10.3+)
-- Idempotent: safe to re-apply with bin/cucm-initdb.php.
-- Views use window functions (LAG, named WINDOW).
-- The runtime account needs only SELECT, INSERT and UPDATE.

SET NAMES utf8mb4;

-- One collection run.
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

-- Phone inventory, one row per device name.
CREATE TABLE IF NOT EXISTS phones (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_name       VARCHAR(64)     NOT NULL,
  mac_address       CHAR(12)        NULL,
  description       VARCHAR(255)    NULL,
  model             VARCHAR(64)     NULL,
  product           VARCHAR(64)     NULL,
  device_class      VARCHAR(32)     NULL,
  -- comma-separated list of every line appearance
  dir_number        VARCHAR(512)    NULL,
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

-- Per sweep: CUCM registration state.
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

-- Per sweep, per port: link state and counters.
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
  -- sum of CPS_ERROR_COLUMNS
  errors_total        BIGINT UNSIGNED NULL,
  unmapped_labels     INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ps_sweep_phone_port (sweep_id, phone_id, port),
  KEY idx_ps_phone_port (phone_id, port),
  KEY idx_ps_errors (errors_total),
  CONSTRAINT fk_ps_sweep FOREIGN KEY (sweep_id) REFERENCES sweeps (id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_phone FOREIGN KEY (phone_id) REFERENCES phones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per sweep, per adapter: verbatim label/value pairs or the failure.
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

-- Per sweep: the switch and port the phone reports via CDP/LLDP.
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
  -- collapsed answer: CDP preferred, LLDP fallback
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

-- Imported "show interface status" output, one row per switch per distinct output.
CREATE TABLE IF NOT EXISTS switch_captures (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  switch_name    VARCHAR(255)    NOT NULL,
  -- cps_switch_key(), matched against v_uplink_keys.switch_key
  switch_key     VARCHAR(255)    NOT NULL,
  switch_ip      VARCHAR(45)     NULL,
  source_file    VARCHAR(512)    NULL,
  captured_at    DATETIME        NOT NULL,
  imported_at    DATETIME        NOT NULL,
  content_sha256 CHAR(64)        NOT NULL,
  ports_parsed   INT UNSIGNED    NOT NULL DEFAULT 0,
  lines_unparsed INT UNSIGNED    NOT NULL DEFAULT 0,
  raw_output     MEDIUMTEXT      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sc_switch_content (switch_key, content_sha256),
  KEY idx_sc_switch_time (switch_key, captured_at),
  KEY idx_sc_ip (switch_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parsed ports of a switch capture.
CREATE TABLE IF NOT EXISTS switch_ports (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  capture_id   BIGINT UNSIGNED NOT NULL,
  port         VARCHAR(64)     NOT NULL,
  -- cps_if_key(), e.g. "Gi1/0/12" -> "gi1/0/12"
  port_key     VARCHAR(64)     NOT NULL,
  name         VARCHAR(64)     NULL,
  status       VARCHAR(32)     NULL,
  vlan         VARCHAR(32)     NULL,
  duplex_raw   VARCHAR(16)     NULL,
  speed_raw    VARCHAR(16)     NULL,
  port_type    VARCHAR(64)     NULL,
  duplex       ENUM('full','half') NULL,
  speed_mbps   INT UNSIGNED    NULL,
  duplex_auto  TINYINT         NOT NULL DEFAULT 0,
  speed_auto   TINYINT         NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sp_capture_port (capture_id, port_key),
  CONSTRAINT fk_sp_capture FOREIGN KEY (capture_id) REFERENCES switch_captures (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-phone CUCM configuration from AXL (uuid uppercase, no braces).
CREATE TABLE IF NOT EXISTS phone_config (
  phone_id                  BIGINT UNSIGNED NOT NULL,
  common_phone_profile_uuid CHAR(36)        NULL,
  common_phone_profile_name VARCHAR(128)    NULL,
  axl_server                VARCHAR(255)    NULL,
  updated_at                DATETIME        NOT NULL,
  PRIMARY KEY (phone_id),
  KEY idx_pc_cpp (common_phone_profile_uuid),
  CONSTRAINT fk_pc_phone FOREIGN KEY (phone_id) REFERENCES phones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Common Phone Profile changes by batch, for review and --undo.
CREATE TABLE IF NOT EXISTS profile_changes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch        VARCHAR(32)     NOT NULL,
  phone_id     BIGINT UNSIGNED NOT NULL,
  device_name  VARCHAR(64)     NOT NULL,
  from_uuid    CHAR(36)        NOT NULL,
  to_uuid      CHAR(36)        NOT NULL,
  axl_server   VARCHAR(255)    NULL,
  sweep_id     BIGINT UNSIGNED NULL,
  ok           TINYINT         NOT NULL DEFAULT 0,
  applied      TINYINT         NOT NULL DEFAULT 0,
  error        TEXT            NULL,
  changed_at   DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pch_batch (batch),
  KEY idx_pch_phone (phone_id),
  CONSTRAINT fk_pch_phone FOREIGN KEY (phone_id) REFERENCES phones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CUCM model enum => name, mirrored from the cluster's typemodel table.
CREATE TABLE IF NOT EXISTS device_models (
  enum        INT UNSIGNED NOT NULL,
  name        VARCHAR(128) NOT NULL,
  updated_at  DATETIME     NOT NULL,
  PRIMARY KEY (enum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Reporting views
-- ---------------------------------------------------------------------------

-- Latest finished sweep (ties on started_at broken by id).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_latest_sweep AS
SELECT * FROM sweeps WHERE finished_at IS NOT NULL ORDER BY started_at DESC, id DESC LIMIT 1;

-- Access port speed/duplex differs from the network port (both ports up).
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_port_speed_mismatch AS
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

-- Ports with a non-zero error counter.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_port_errors AS
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

-- Error deltas between consecutive sweeps; a drop is a counter reset, not a negative delta.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_port_error_deltas AS
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

-- Ports whose error counters grew since the previous sweep.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_port_errors_increasing AS
SELECT * FROM v_port_error_deltas WHERE errors_delta > 0;

-- ---------------------------------------------------------------------------
-- Switch-side comparison
-- ---------------------------------------------------------------------------

-- uplinks with match keys. MUST match cps_switch_key()/cps_if_key() in lib/switch.php.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_uplink_keys AS
SELECT
  k.id, k.sweep_id, k.phone_id, k.switch_name, k.switch_port, k.switch_ip,
  k.neighbour_source, k.switch_key,
  CONCAT(
    CASE
      WHEN k.pfx LIKE 'twe%' THEN 'twe'
      WHEN k.pfx LIKE 'tw%'  THEN 'tw'
      WHEN k.pfx LIKE 'te%'  THEN 'te'
      WHEN k.pfx LIKE 'gi%'  THEN 'gi'
      WHEN k.pfx LIKE 'fa%'  THEN 'fa'
      WHEN k.pfx LIKE 'fi%'  THEN 'fi'
      WHEN k.pfx LIKE 'fo%'  THEN 'fo'
      WHEN k.pfx LIKE 'hu%'  THEN 'hu'
      WHEN k.pfx LIKE 'ap%'  THEN 'ap'
      WHEN k.pfx LIKE 'po%'  THEN 'po'
      WHEN k.pfx LIKE 'et%'  THEN 'et'
      WHEN k.pfx LIKE 'vl%'  THEN 'vl'
      ELSE k.pfx
    END,
    SUBSTRING(k.v, CHAR_LENGTH(k.pfx) + 1)
  ) AS port_key
FROM (
  SELECT u.*,
         LOWER(REPLACE(u.switch_port, ' ', '')) AS v,
         REGEXP_REPLACE(LOWER(REPLACE(u.switch_port, ' ', '')), '[0-9].*$', '') AS pfx,
         CASE
           WHEN TRIM(u.switch_name) REGEXP '^[0-9]+([.][0-9]+){3}$' THEN TRIM(u.switch_name)
           ELSE LOWER(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(u.switch_name, '(', 1), '.', 1)))
         END AS switch_key
  FROM uplinks u
  WHERE u.switch_name IS NOT NULL AND u.switch_port IS NOT NULL
) k;

-- The newest capture of each switch.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_switch_latest_capture AS
SELECT id, switch_name, switch_key, switch_ip, source_file, captured_at, ports_parsed
FROM (
  SELECT c.id, c.switch_name, c.switch_key, c.switch_ip, c.source_file, c.captured_at, c.ports_parsed,
         ROW_NUMBER() OVER (PARTITION BY c.switch_key ORDER BY c.captured_at DESC, c.id DESC) AS rn
  FROM switch_captures c
) x
WHERE x.rn = 1;

-- Phone uplink vs the switch's newest capture of that port. result: match, speed,
-- duplex, speed+duplex, no-capture, no-port, switch-not-connected, phone-link-down, unknown.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_switch_port_compare AS
SELECT
  u.sweep_id,
  s.started_at,
  p.id                AS phone_id,
  p.device_name,
  p.description,
  p.model,
  r.ip_address,
  u.switch_name,
  u.switch_port,
  u.neighbour_source,
  u.switch_key,
  u.port_key,
  COALESCE(n.link_up, e.link_up)       AS phone_link_up,
  COALESCE(n.speed_mbps, e.speed_mbps) AS phone_speed_mbps,
  COALESCE(n.duplex, e.duplex)         AS phone_duplex,
  COALESCE(cn.id, ci.id)               AS capture_id,
  COALESCE(cn.captured_at, ci.captured_at) AS captured_at,
  COALESCE(spn.port, spi.port)             AS switch_if,
  -- first word only; the full description is in switch_ports.name
  SUBSTRING_INDEX(TRIM(COALESCE(spn.name, spi.name)), ' ', 1) AS switch_if_name,
  COALESCE(spn.status, spi.status)           AS switch_status,
  COALESCE(spn.vlan, spi.vlan)             AS switch_vlan,
  COALESCE(spn.speed_raw, spi.speed_raw)        AS switch_speed,
  COALESCE(spn.duplex_raw, spi.duplex_raw)       AS switch_duplex,
  COALESCE(spn.speed_mbps, spi.speed_mbps)       AS switch_speed_mbps,
  COALESCE(spn.duplex, spi.duplex)           AS switch_duplex_norm,
  COALESCE(spn.speed_auto, spi.speed_auto)       AS switch_speed_auto,
  COALESCE(spn.duplex_auto, spi.duplex_auto)      AS switch_duplex_auto,
  CASE
    WHEN cn.id IS NULL AND ci.id IS NULL THEN 'no-capture'
    WHEN spn.id IS NULL AND spi.id IS NULL THEN 'no-port'
    WHEN COALESCE(spn.status, spi.status) IS NULL THEN 'unknown'
    WHEN COALESCE(spn.status, spi.status) <> 'connected' THEN 'switch-not-connected'
    WHEN COALESCE(n.link_up, e.link_up) IS NULL THEN 'unknown'
    WHEN COALESCE(n.link_up, e.link_up) <> 1 THEN 'phone-link-down'
    WHEN COALESCE(spn.speed_mbps, spi.speed_mbps) IS NULL OR COALESCE(spn.duplex, spi.duplex) IS NULL
      OR COALESCE(n.speed_mbps, e.speed_mbps) IS NULL
      OR COALESCE(n.duplex, e.duplex) IS NULL THEN 'unknown'
    WHEN COALESCE(spn.speed_mbps, spi.speed_mbps) <> COALESCE(n.speed_mbps, e.speed_mbps)
     AND COALESCE(spn.duplex, spi.duplex)     <> COALESCE(n.duplex, e.duplex)      THEN 'speed+duplex'
    WHEN COALESCE(spn.speed_mbps, spi.speed_mbps) <> COALESCE(n.speed_mbps, e.speed_mbps) THEN 'speed'
    WHEN COALESCE(spn.duplex, spi.duplex)     <> COALESCE(n.duplex, e.duplex)      THEN 'duplex'
    ELSE 'match'
  END AS result
FROM v_uplink_keys u
JOIN phones p ON p.id = u.phone_id
JOIN sweeps s ON s.id = u.sweep_id
LEFT JOIN registrations r ON r.sweep_id = u.sweep_id AND r.phone_id = u.phone_id
LEFT JOIN port_stats n ON n.sweep_id = u.sweep_id AND n.phone_id = u.phone_id AND n.port = 'network'
LEFT JOIN port_stats e ON e.sweep_id = u.sweep_id AND e.phone_id = u.phone_id AND e.port = 'ethernet'
LEFT JOIN v_switch_latest_capture cn ON cn.switch_key = u.switch_key
LEFT JOIN v_switch_latest_capture ci ON cn.id IS NULL AND ci.switch_ip IS NOT NULL AND ci.switch_ip = u.switch_ip
LEFT JOIN switch_ports spn ON spn.capture_id = cn.id AND spn.port_key = u.port_key
LEFT JOIN switch_ports spi ON spi.capture_id = ci.id AND spi.port_key = u.port_key;

-- Switch and phone disagree on speed or duplex.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW v_switch_port_mismatch AS
SELECT * FROM v_switch_port_compare WHERE result IN ('speed', 'duplex', 'speed+duplex');
