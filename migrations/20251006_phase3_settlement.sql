-- ===============================================
-- EMS ERP - Phase 3: Settlement (Party → Company)
-- ===============================================

-- Headers & lines
CREATE TABLE IF NOT EXISTS settlement_headers (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  customer_id BIGINT NOT NULL,
  mode ENUM('credit_note','purchase_ap','foc') NOT NULL,
  kind ENUM('remnant','scrap','mixed') NOT NULL DEFAULT 'remnant',
  bucket ENUM('RM','SCRAP') NOT NULL DEFAULT 'RM',     -- which inventory account to Dr
  status ENUM('draft','posted','void') NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  total_qty_base DECIMAL(18,6) NOT NULL DEFAULT 0,
  total_amount  DECIMAL(18,2) NOT NULL DEFAULT 0,
  created_by BIGINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posted_at TIMESTAMP NULL,
  INDEX idx_sh_customer (customer_id),
  INDEX idx_sh_status (status)
);

CREATE TABLE IF NOT EXISTS settlement_lines (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  header_id BIGINT NOT NULL,
  item_id BIGINT NOT NULL,
  warehouse_id BIGINT NOT NULL,
  lot_id BIGINT NOT NULL,
  piece_id BIGINT NOT NULL,
  qty_base DECIMAL(18,6) NOT NULL,
  rate DECIMAL(18,6) NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  heat_no VARCHAR(64) NULL,
  plate_no VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_hdr_piece (header_id, piece_id),
  INDEX idx_sl_header (header_id),
  INDEX idx_sl_lot (lot_id),
  INDEX idx_sl_piece (piece_id)
);

-- Ownership history (audit)
CREATE TABLE IF NOT EXISTS ownership_history (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  piece_id BIGINT NOT NULL,
  from_owner_type ENUM('company','customer','vendor_foc') NOT NULL,
  from_owner_id BIGINT NULL,
  to_owner_type ENUM('company','customer','vendor_foc') NOT NULL,
  to_owner_id BIGINT NULL,
  reason VARCHAR(64) NOT NULL,   -- 'settlement'
  ref_table VARCHAR(32) NULL,    -- 'settlement_headers'
  ref_id BIGINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_oh_piece (piece_id),
  INDEX idx_oh_ref (ref_table, ref_id)
);

-- Optional: AR/AP bridge outboxes
CREATE TABLE IF NOT EXISTS ar_interface_outbox (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  customer_id BIGINT NOT NULL,
  doc_type ENUM('CREDIT_NOTE') NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  payload_json JSON NOT NULL,
  status ENUM('queued','posted','error') NOT NULL DEFAULT 'queued',
  attempts INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ar_status (status)
);

CREATE TABLE IF NOT EXISTS ap_interface_outbox (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  vendor_id BIGINT NOT NULL,
  doc_type ENUM('PURCHASE_INVOICE') NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  payload_json JSON NOT NULL,
  status ENUM('queued','posted','error') NOT NULL DEFAULT 'queued',
  attempts INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ap_status (status)
);