-- migrations/001_add_stock_ledger.sql
CREATE TABLE IF NOT EXISTS stock_ledger (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  txn_date          DATETIME(6)     NOT NULL,
  txn_type          VARCHAR(16)     NOT NULL,
  txn_no            VARCHAR(50)     NOT NULL,
  item_id           BIGINT          NOT NULL,
  warehouse_id      BIGINT          NOT NULL,
  project_id        BIGINT          NULL,
  bin_id            BIGINT          NULL,
  batch_id          BIGINT          NULL,
  qty               DECIMAL(18,6)   NOT NULL,
  rate              DECIMAL(18,6)   NOT NULL,
  amount            DECIMAL(18,2)   AS (qty * rate) STORED,
  uom_id            BIGINT          NULL,
  ref_table         VARCHAR(64)     NULL,
  ref_id            BIGINT          NULL,
  created_by        BIGINT          NULL,
  created_at        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_ledger_date (txn_date),
  KEY idx_ledger_item_wh (item_id, warehouse_id),
  KEY idx_ledger_txn (txn_type, txn_no)
);