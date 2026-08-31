# Loan Management System — Update Notes

## 1. Run the database migration first
Your database already has `database_updates.sql` applied. Now run the new file:

```
migration_loan_plans.sql
```

In phpMyAdmin: open your `loan_management_system` database → SQL tab → paste the
contents of `migration_loan_plans.sql` → Go.

This adds:
- A new `loan_plans` table (with 3 starter plans you can edit/delete).
- A `plan_id` column on `loans` (links a loan to the plan used, if any).
- A `decided_at` column on `loan_applicants` (records when an applicant was
  approved/rejected, used for the monthly/yearly "Rejected" figures).
- A few indexes to keep month-based filtering fast.

If your MySQL version is older than 8.0.29 and a statement errors out, check
the comments at the top of the file — it tells you how to check for the
column/table manually and skip statements that already succeeded.

## 2. What's new in the app

**Loan Plans** (new sidebar link)
- Create, edit, and remove reusable loan plans (name, amount, interest rate,
  repayment period). Monthly installment is calculated automatically.
- On the "Approve Loan Application" page, pick a plan from a dropdown and the
  amount/rate/duration fields auto-fill and lock. Choosing "Custom loan" lets
  you type the numbers manually, exactly like before.
- The assigned plan now shows on the Loans list and on each loan's detail page.

**Applicants page**
- Search now matches name, National ID, **and phone number**.
- New filters: status, registration month/year, loan issue month/year.
- New "❌ Reject" action next to "💰 Approve Loan" (only shown while an
  application is still pending). Rejected applicants show a red badge.
- Stat cards for total applicants, this month's registrations, and total
  rejected.

**Loans page**
- New filters: search (name/ID/phone) and loan issue month/year, alongside
  the existing status filter.
- New "Loan Plan" column.

**Dashboard**
- All-time totals: Total Applicants, Total Active Loans, Total Loan Amount
  Issued.
- Quick-access buttons to Monthly Report, Yearly Report, and Loan Plans.
- "Applicants Registered by Month" table for the selected year (Jan…Dec).
- Fixed the loan performance / repayment trend / status charts, which were
  referencing chart canvases that didn't exist in the page yet.
- Added a "Rejected Applicants" KPI card for the selected month.

**Reports (Monthly & Yearly)**
- Added "Total Rejected Applications" to both report types.
- Yearly monthly-breakdown table now includes a "Rejected" column.
- Print/Export buttons were already in place and are unchanged.

## 3. Files changed or added

```
migration_loan_plans.sql                (new)
backend/helpers.php                     (loan-plan + rejection helpers)
backend/create_loan.php                 (plan assignment support)
backend/add_loan_plan.php               (new)
backend/update_loan_plan.php            (new)
backend/delete_loan_plan.php            (new)
backend/reject_applicant.php            (new)
frontend/loan_plans.php                 (new)
frontend/applicants.php                 (search/filter/reject)
frontend/loans.php                      (filters + plan column)
frontend/approve_loan.php               (plan picker)
frontend/view_loan.php                  (shows assigned plan)
frontend/dashboard.php                  (KPIs, quick links, chart fix)
frontend/reports.php                    (rejected counts)
frontend/*.php (sidebar)                (added "Loan Plans" nav link)
css/style.css                           (added .status-rejected badge)
```

Nothing in the applicant-facing calculation logic (flat interest formula:
`amount + amount × rate × months ÷ 100`, split evenly across the term) was
changed — the new Loan Plan feature reuses that exact formula so existing
loans and reports stay consistent.
