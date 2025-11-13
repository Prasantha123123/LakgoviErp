-- ========================================
-- Repacking Module SQL Schema
-- Database: lakgovi_erp
-- ========================================
-- Run this file to add repacking functionality to your ERP system
-- Execute: Import this file in phpMyAdmin or run via MySQL command line

USE lakgovi_erp;

-- --------------------------------------------------------
-- Table structure for table `repacking`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `repacking` (
  `id` int NOT NULL AUTO_INCREMENT,
  `repack_code` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `repack_date` date NOT NULL,
  `source_item_id` int NOT NULL COMMENT 'Finished product being repacked (e.g., Papadam 5kg)',
  `source_batch_code` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Original batch code for traceability',
  `source_quantity` decimal(18,6) NOT NULL COMMENT 'Quantity of source product used',
  `source_unit_id` int NOT NULL,
  `repack_item_id` int NOT NULL COMMENT 'New repacked product (e.g., Papadam 50g)',
  `repack_quantity` decimal(18,6) NOT NULL COMMENT 'Number of repacked units produced',
  `repack_unit_id` int NOT NULL,
  `repack_unit_size` decimal(18,6) NOT NULL COMMENT 'Size of each repack unit (e.g., 50g, 100g, 0.05kg)',
  `location_id` int NOT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `repack_code` (`repack_code`),
  KEY `idx_repack_date` (`repack_date`),
  KEY `idx_source_item` (`source_item_id`),
  KEY `idx_repack_item` (`repack_item_id`),
  KEY `idx_source_batch` (`source_batch_code`),
  KEY `fk_repacking_source_item` (`source_item_id`),
  KEY `fk_repacking_repack_item` (`repack_item_id`),
  KEY `fk_repacking_source_unit` (`source_unit_id`),
  KEY `fk_repacking_repack_unit` (`repack_unit_id`),
  KEY `fk_repacking_location` (`location_id`),
  KEY `fk_repacking_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Repacking transactions: converting finished products into smaller units';

-- --------------------------------------------------------
-- Table structure for table `repacking_materials`
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `repacking_materials` (
  `id` int NOT NULL AUTO_INCREMENT,
  `repacking_id` int NOT NULL,
  `item_id` int NOT NULL COMMENT 'Raw material used during repacking (e.g., packaging bags, labels, tape)',
  `quantity` decimal(18,6) NOT NULL,
  `unit_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_repack_materials_repacking` (`repacking_id`),
  KEY `fk_repack_materials_item` (`item_id`),
  KEY `fk_repack_materials_unit` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Additional materials consumed during repacking process';

-- --------------------------------------------------------
-- Foreign Key Constraints
-- --------------------------------------------------------

ALTER TABLE `repacking`
  ADD CONSTRAINT `fk_repacking_source_item` FOREIGN KEY (`source_item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_repacking_repack_item` FOREIGN KEY (`repack_item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_repacking_source_unit` FOREIGN KEY (`source_unit_id`) REFERENCES `units` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_repacking_repack_unit` FOREIGN KEY (`repack_unit_id`) REFERENCES `units` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_repacking_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_repacking_created_by` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `repacking_materials`
  ADD CONSTRAINT `fk_repack_materials_repacking` FOREIGN KEY (`repacking_id`) REFERENCES `repacking` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_repack_materials_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_repack_materials_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE RESTRICT;

-- --------------------------------------------------------
-- Update stock_ledger enum to support repacking transactions
-- --------------------------------------------------------

ALTER TABLE `stock_ledger` 
  MODIFY COLUMN `transaction_type` ENUM(
    'opening_stock',
    'grn',
    'mrn',
    'mrn_reversal',
    'mrn_return',
    'return_reversal',
    'production_in',
    'production_out',
    'sales',
    'sales_return',
    'repack_in',
    'repack_out'
  ) COLLATE utf8mb4_general_ci NOT NULL;

-- --------------------------------------------------------
-- Create views for repacking reports
-- --------------------------------------------------------

CREATE OR REPLACE VIEW `v_repacking_details` AS
SELECT 
    r.id,
    r.repack_code,
    r.repack_date,
    r.source_item_id,
    si.code AS source_item_code,
    si.name AS source_item_name,
    r.source_batch_code,
    r.source_quantity,
    su.symbol AS source_unit_symbol,
    r.repack_item_id,
    ri.code AS repack_item_code,
    ri.name AS repack_item_name,
    r.repack_quantity,
    r.repack_unit_size,
    ru.symbol AS repack_unit_symbol,
    CONCAT(r.repack_unit_size, ' ', ru.symbol) AS unit_size_display,
    l.name AS location_name,
    r.notes,
    u.username AS created_by_name,
    r.created_at,
    r.updated_at
FROM repacking r
LEFT JOIN items si ON si.id = r.source_item_id
LEFT JOIN items ri ON ri.id = r.repack_item_id
LEFT JOIN units su ON su.id = r.source_unit_id
LEFT JOIN units ru ON ru.id = r.repack_unit_id
LEFT JOIN locations l ON l.id = r.location_id
LEFT JOIN admin_users u ON u.id = r.created_by
ORDER BY r.repack_date DESC, r.created_at DESC;

CREATE OR REPLACE VIEW `v_repacking_with_materials` AS
SELECT 
    rm.repacking_id,
    rm.id AS material_id,
    i.code AS material_code,
    i.name AS material_name,
    rm.quantity AS material_quantity,
    u.symbol AS material_unit_symbol
FROM repacking_materials rm
LEFT JOIN items i ON i.id = rm.item_id
LEFT JOIN units u ON u.id = rm.unit_id;

-- --------------------------------------------------------
-- Sample data (optional - remove if not needed)
-- --------------------------------------------------------

-- Insert sample repacking transaction
-- INSERT INTO `repacking` 
-- (`repack_code`, `repack_date`, `source_item_id`, `source_batch_code`, `source_quantity`, 
--  `source_unit_id`, `repack_item_id`, `repack_quantity`, `repack_unit_id`, `repack_unit_size`, 
--  `location_id`, `notes`, `created_by`) 
-- VALUES 
-- ('RP000001', CURDATE(), 140, 'BATCH001', 5.000000, 1, 141, 100.000000, 1, 0.050000, 1, 
--  'Repacked Papadam 5kg into 50g packs', 6);

-- --------------------------------------------------------
-- Completion Message
-- --------------------------------------------------------

SELECT 'Repacking module tables created successfully!' AS Status;
SELECT 'Tables created: repacking, repacking_materials' AS Info;
SELECT 'Views created: v_repacking_details, v_repacking_with_materials' AS Views;
SELECT 'stock_ledger updated with repack_in and repack_out transaction types' AS Updates;
