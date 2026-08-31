-- Loan Management System enhancement migration
-- Existing schema compatibility update for dynamic loan balances and reporting.

ALTER TABLE loan_applicants
    MODIFY full_name VARCHAR(255) NOT NULL,
    MODIFY phone_number VARCHAR(50) NOT NULL,
    MODIFY national_id VARCHAR(100) NOT NULL,
    MODIFY email VARCHAR(255) NULL,
    MODIFY address TEXT NULL,
    MODIFY loan_purpose TEXT NULL,
    MODIFY employment_status VARCHAR(100) NULL,
    MODIFY monthly_income DECIMAL(12,2) NULL;



ALTER TABLE loans
    CHANGE COLUMN loan_duration_months loan_duration_months INT NOT NULL,
    CHANGE COLUMN total_payment total_payment DECIMAL(12,2) NOT NULL,
    CHANGE COLUMN issue_date issue_date DATE NOT NULL,
    CHANGE COLUMN status status VARCHAR(30) NOT NULL DEFAULT 'Active',
    MODIFY loan_amount DECIMAL(12,2) NOT NULL,
    MODIFY interest_rate DECIMAL(6,2) NOT NULL,
    MODIFY monthly_payment DECIMAL(12,2) NOT NULL,
    MODIFY due_date DATE NOT NULL;

-- If your existing loans table still uses applicant_id, keep it for compatibility.
-- For a fresh schema, use client_id as the foreign key name:
-- ALTER TABLE loans CHANGE COLUMN applicant_id client_id INT NOT NULL;

CREATE INDEX IF NOT EXISTS idx_loans_client_status ON loans (applicant_id, status);
CREATE INDEX IF NOT EXISTS idx_loans_issue_date ON loans (issue_date);
CREATE INDEX IF NOT EXISTS idx_repayments_loan_date ON repayments (loan_id, payment_date);

ALTER TABLE loans
    ADD COLUMN IF NOT EXISTS outstanding_principal DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS accrued_interest DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS interest_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS principal_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS total_payments DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS outstanding_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS last_interest_calculated_at DATE NULL;

ALTER TABLE repayments
    ADD COLUMN IF NOT EXISTS interest_portion DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS principal_portion DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS remaining_principal DECIMAL(12,2) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS remaining_interest DECIMAL(12,2) NOT NULL DEFAULT 0;

-- Payment verification: new repayments must be verified before they affect a loan balance.
ALTER TABLE repayments
    ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS verification_status VARCHAR(20) NOT NULL DEFAULT 'Verified',
    ADD COLUMN IF NOT EXISTS verified_by INT NULL,
    ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(500) NULL;

CREATE UNIQUE INDEX idx_repayments_payment_reference ON repayments (payment_reference);
CREATE INDEX idx_repayments_verification_status ON repayments (verification_status, payment_date);

UPDATE loans
SET
    outstanding_principal = CASE
        WHEN outstanding_principal = 0 THEN GREATEST(loan_amount - COALESCE((SELECT SUM(payment_amount) FROM repayments WHERE repayments.loan_id = loans.loan_id), 0), 0)
        ELSE outstanding_principal
    END,
    accrued_interest = CASE
        -- Historic fixed-repayment totals must never be converted into accrued
        -- interest.  New interest is accrued dynamically from principal only.
        WHEN accrued_interest = 0 THEN 0
        ELSE accrued_interest
    END,
    total_payments = CASE
        WHEN total_payments = 0 THEN COALESCE((SELECT SUM(payment_amount) FROM repayments WHERE repayments.loan_id = loans.loan_id), 0)
        ELSE total_payments
    END,
    outstanding_balance = CASE
        WHEN outstanding_balance = 0 THEN GREATEST((loan_amount - COALESCE((SELECT SUM(payment_amount) FROM repayments WHERE repayments.loan_id = loans.loan_id), 0)) + accrued_interest, 0)
        ELSE outstanding_balance
    END,
    -- Pre-existing repayments have no interest/principal split, so restart
    -- accrual from migration day rather than inventing historic interest.
    last_interest_calculated_at = COALESCE(last_interest_calculated_at, CURDATE());

-- MySQL versions before 8.0.29 do not support ADD COLUMN IF NOT EXISTS.
-- If your MySQL version rejects that syntax, run SHOW COLUMNS first and add only missing columns manually.
