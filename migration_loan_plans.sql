-- ============================================================
-- Loan Management System — Loan Plans & Reporting Migration
-- ============================================================
-- Run this AFTER database_updates.sql (it depends on the
-- `loans` and `loan_applicants` columns created there).
--
-- IMPORTANT: MySQL versions before 8.0.29 do not support
-- "ADD COLUMN IF NOT EXISTS" / "ADD CONSTRAINT IF NOT EXISTS".
-- If your server rejects a statement below, run
--   SHOW COLUMNS FROM loans;
--   SHOW COLUMNS FROM loan_applicants;
-- first and only run the ALTER statements for columns that
-- are actually missing.
-- ============================================================

-- 1. Loan Plans table --------------------------------------------------
CREATE TABLE IF NOT EXISTS loan_plans (
    plan_id                 INT AUTO_INCREMENT PRIMARY KEY,
    plan_name               VARCHAR(150)   NOT NULL,
    loan_amount             DECIMAL(12,2)  NOT NULL,
    interest_rate           DECIMAL(6,2)   NOT NULL,
    repayment_period_months INT            NOT NULL,
    monthly_installment     DECIMAL(12,2)  NOT NULL,
    description             TEXT           NULL,
    is_active                TINYINT(1)     NOT NULL DEFAULT 1,
    created_at               TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. Link loans to the plan that was assigned to them -------------------
ALTER TABLE loans
    ADD COLUMN IF NOT EXISTS plan_id INT NULL AFTER loan_amount;

-- Only add the foreign key if it does not already exist.
-- (Wrap in a procedure so re-running the script is safe.)
SET @fk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'loans'
      AND CONSTRAINT_NAME = 'fk_loans_plan'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE loans ADD CONSTRAINT fk_loans_plan FOREIGN KEY (plan_id) REFERENCES loan_plans(plan_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Track when an applicant's status was decided (approved/rejected) ---
-- Used for monthly / yearly "Total Rejected" reporting.
ALTER TABLE loan_applicants
    ADD COLUMN IF NOT EXISTS decided_at TIMESTAMP NULL AFTER application_status;

-- 4. Helpful indexes for month-based filtering ---------------------------
CREATE INDEX IF NOT EXISTS idx_applicants_created_at ON loan_applicants (created_at);
CREATE INDEX IF NOT EXISTS idx_applicants_decided_at ON loan_applicants (decided_at);
CREATE INDEX IF NOT EXISTS idx_applicants_phone ON loan_applicants (phone_number);
CREATE INDEX IF NOT EXISTS idx_loans_plan ON loans (plan_id);

-- 5. Starter loan plans (edit or delete these to suit the business) -----
INSERT INTO loan_plans (plan_name, loan_amount, interest_rate, repayment_period_months, monthly_installment, description, is_active)
SELECT * FROM (SELECT
    'Quick Cash - 3 Month'  AS plan_name,
    10000.00                AS loan_amount,
    10.00                   AS interest_rate,
    3                       AS repayment_period_months,
    ROUND((10000.00 / 3) + (10000.00 * 10.00 / 100), 2) AS monthly_installment,
    'Short-term small loan for urgent needs.' AS description,
    1                       AS is_active
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM loan_plans WHERE plan_name = 'Quick Cash - 3 Month');

INSERT INTO loan_plans (plan_name, loan_amount, interest_rate, repayment_period_months, monthly_installment, description, is_active)
SELECT * FROM (SELECT
    'Standard - 6 Month', 30000.00, 12.00, 6,
    ROUND((30000.00 / 6) + (30000.00 * 12.00 / 100), 2),
    'General purpose loan with moderate repayment period.', 1
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM loan_plans WHERE plan_name = 'Standard - 6 Month');

INSERT INTO loan_plans (plan_name, loan_amount, interest_rate, repayment_period_months, monthly_installment, description, is_active)
SELECT * FROM (SELECT
    'Business Growth - 12 Month', 100000.00, 15.00, 12,
    ROUND((100000.00 / 12) + (100000.00 * 15.00 / 100), 2),
    'Larger loan for business or long-term needs.', 1
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM loan_plans WHERE plan_name = 'Business Growth - 12 Month');
