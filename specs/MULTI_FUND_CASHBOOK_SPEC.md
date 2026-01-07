# Multi-Fund and Cashbook Tracking

**Version:** 1.0 Draft  
**Date:** January 2026  
**Status:** For Review

---

## 1. Overview

### 1.1 Problem Statement

Charities often have a many-to-many relationship between their logical funds and their physical cashbooks (bank accounts, petty cash tins, etc.):

- A **fund** may span multiple cashbooks (e.g., a division uses both a shared bank account and its own petty cash)
- A **cashbook** may contain multiple funds (e.g., a shared bank account holds money for the parent charity and two divisions)

The current data model assigns each transaction to exactly one fund and optionally one cashbook. This prevents:

- Splitting a single transaction across multiple funds
- Splitting a single transaction across multiple cashbooks
- Accurately tracking fund balances when funds share cashbooks
- Reconciling cashbooks that contain multiple funds

### 1.2 Example Scenario

A charity has:

| Fund | Description |
|------|-------------|
| Parent | The main charity |
| Division A | Has its own bank account |
| Division B | Shares bank account with Parent |
| Division C | Shares bank account with Parent, also has own petty cash |

| Cashbook | Contains Funds |
|----------|---------------|
| Division A Bank | Division A only |
| Shared Bank | Parent, Division B, Division C |
| Division C Petty Cash | Division C only |

### 1.3 Goals

1. Track the balance of each **fund** (how much money does Division C have?)
2. Track the balance of each **cashbook** (how much is in the Shared Bank account?)
3. Reconcile cashbooks against bank statements
4. Ensure the numbers always balance: sum of all funds = sum of all cashbooks

---

## 2. Data Model Changes

### 2.1 Current Model

```
transactions
├── fund_id (required, single)
├── cash_book_id (optional, single)
└── ... other fields
```

### 2.2 Proposed Model

```
transactions (the accounting event)
├── transaction_fund_entries (1 or more)
│   ├── fund_id
│   └── amount
└── transaction_cashbook_entries (0 or more)
    ├── cash_book_id
    ├── amount
    ├── is_reconciled
    └── reconciled_at
```

### 2.3 New Tables

#### transaction_fund_entries

Records which funds are affected by a transaction and by how much.

| Column | Type | Description |
|--------|------|-------------|
| id | INT UNSIGNED | Primary key |
| transaction_id | INT UNSIGNED | FK to transactions |
| fund_id | INT UNSIGNED | FK to funds |
| amount | DECIMAL(15,2) | Positive for receipts, negative for payments |

**Constraints:**
- Every transaction must have at least one fund entry
- Sum of fund entry amounts must equal the transaction amount (with appropriate sign)

#### transaction_cashbook_entries

Records which cashbooks are affected by a transaction and by how much.

| Column | Type | Description |
|--------|------|-------------|
| id | INT UNSIGNED | Primary key |
| transaction_id | INT UNSIGNED | FK to transactions |
| cash_book_id | INT UNSIGNED | FK to cash_books |
| amount | DECIMAL(15,2) | Positive for money in, negative for money out |
| is_reconciled | BOOLEAN | Whether this entry has been reconciled |
| reconciled_at | DATETIME | When reconciliation occurred |

**Constraints:**
- Cashbook entries are optional (e.g., inter-fund transfers have none)
- Sum of cashbook entry amounts must equal sum of fund entry amounts

### 2.4 Columns to Remove from transactions

After migration:
- `fund_id` (moved to transaction_fund_entries)
- `cash_book_id` (moved to transaction_cashbook_entries)
- `is_reconciled` (moved to transaction_cashbook_entries)
- `reconciled_at` (moved to transaction_cashbook_entries)

---

## 3. Transaction Types

### 3.1 Simple Transaction (Single Fund, Single Cashbook)

**Example:** Division A receives a £100 donation into their bank account.

```
Transaction: "Donation from Smith"
├── Fund entries: Division A +£100
└── Cashbook entries: Division A Bank +£100
```

### 3.2 Split by Fund (Multiple Funds, Single Cashbook)

**Example:** Parent pays a £100 invoice from Shared Bank; £60 is Parent's expense, £40 is Division B's.

```
Transaction: "Shared utilities invoice"
├── Fund entries: Parent -£60, Division B -£40
└── Cashbook entries: Shared Bank -£100
```

### 3.3 Split by Cashbook (Single Fund, Multiple Cashbooks)

**Example:** Division C pays £50, partly from petty cash and partly from the shared bank.

```
Transaction: "Equipment purchase"
├── Fund entries: Division C -£50
└── Cashbook entries: Division C Petty Cash -£20, Shared Bank -£30
```

### 3.4 Inter-Fund Transfer

**Example:** Division B repays £40 loan to Parent. No actual money moves between bank accounts.

```
Transaction: "Internal transfer - loan repayment"
├── Fund entries: Division B -£40, Parent +£40
└── Cashbook entries: (none)
```

**Note:** Sum of fund entries is £0, sum of cashbook entries is £0. The constraint holds.

### 3.5 Inter-Cashbook Transfer

**Example:** Division C moves £100 from Shared Bank to Petty Cash. Fund balance unchanged.

```
Transaction: "Cash withdrawal for petty cash"
├── Fund entries: Division C -£100, Division C +£100 (or: single entry of £0)
└── Cashbook entries: Shared Bank -£100, Division C Petty Cash +£100
```

**Alternative representation:** Two transactions (withdrawal and deposit) linked together.

### 3.6 Complex Split (Multiple Funds AND Multiple Cashbooks)

**Example:** Division C pays a £100 bill: £30 from petty cash, £70 from shared bank. Of the total, £60 is Division C's expense, £40 is Parent contributing.

```
Transaction: "Shared equipment purchase"
├── Fund entries: Division C -£60, Parent -£40
└── Cashbook entries: Division C Petty Cash -£30, Shared Bank -£70
```

The constraint holds: fund entries sum to -£100, cashbook entries sum to -£100. ✓

**Note:** This is the most complex case. The data model handles it correctly, but the UI should treat this as an "advanced split" workflow since it will be rare. Most transactions will be single-fund, single-cashbook.

---

## 4. Balance Calculations

### 4.1 Fund Balance

```
Fund balance = Fund opening balance
             + SUM(transaction_fund_entries.amount) 
               WHERE fund_id = X
               AND transaction.deleted_at IS NULL
```

### 4.2 Cashbook Balance

```
Cashbook balance = Cashbook opening balance
                 + SUM(transaction_cashbook_entries.amount)
                   WHERE cash_book_id = X
                   AND transaction.deleted_at IS NULL
                   AND transaction.transaction_date >= cashbook.opening_balance_date
```

### 4.3 Integrity Check

At all times:
```
SUM(all fund balances) = SUM(all cashbook balances) + unassigned amount
```

Where "unassigned amount" is the sum of fund entries for transactions with no cashbook entries (valid when cashbooks module is disabled or for inter-fund transfers).

---

## 5. Cashbooks Module Lifecycle

### 5.1 Module Disabled

When the cashbooks module is disabled:
- Transactions only require fund entries
- Cashbook entries are not created
- Fund balances are fully tracked
- No reconciliation features available

### 5.2 Enabling the Module

When a charity enables the cashbooks module, they are presented with a setup wizard:

#### Step 1: Create Cashbooks

User creates their cashbooks (bank accounts, petty cash, etc.) with:
- Name
- Type (bank, cash, online, other)
- Optional: account number, sort code, description

#### Step 2: Choose Historical Data Approach

**Option A: Start Fresh**

"Enter today's balances and track from now on"

- User enters current balance and today's date for each cashbook
- Historical transactions remain unassigned to cashbooks
- Cashbook balance = opening balance + entries from opening date onwards
- Clean starting point, no historical reconciliation needed

**Option B: Assign Historical Transactions**

"Map your funds to cashbooks and assign historical transactions"

- User maps each fund to a default cashbook (or "multiple/varies")
- System auto-assigns historical transactions based on mapping
- Summary shown: "423 transactions assigned, 7 need manual review"
- User can still set an opening balance date for pre-records era

#### Step 3: Confirmation

- Summary of cashbooks created
- Summary of how historical data was handled
- Link to reconciliation workflow

### 5.3 Disabling the Module

When the cashbooks module is disabled:
- Cashbook UI is hidden
- Existing cashbook entries are retained in the database
- New transactions do not create cashbook entries
- Re-enabling the module restores access to existing data

### 5.4 Re-enabling the Module

When a charity re-enables the cashbooks module after a period of it being disabled, they are presented with a wizard that acknowledges the gap:

#### Gap Period Detection

The system detects:
- When cashbooks was last active (last transaction with cashbook entries, or last reconciliation date)
- How many transactions have been created since then without cashbook entries

**Example message:** "You have 127 transactions since cashbooks was last active on 30 June. Last reconciled balances: Shared Bank £4,523.17, Petty Cash £85.00"

#### Choose How to Handle the Gap

**Option A: Assign them now**

"Map transactions from the gap period to cashbooks"

- Same fund→cashbook mapping wizard as initial setup
- Auto-assigns gap period transactions based on mapping
- Summary: "124 transactions assigned, 3 need manual review"
- Opening balances remain unchanged

**Option B: Skip and start fresh from today**

"Update opening balances to today's actual balances"

- User enters current actual balance for each cashbook
- Opening balance date updated to today
- Gap period transactions become "pre-history" (before opening balance date)
- Those transactions will never appear as unassigned

**Confirmation message:** "This will update your cashbook opening balances to today's actual balances. The 127 transactions from July-October will not be included in cashbook tracking (they're still recorded against funds)."

**Option C: I'll assign them manually later**

"Leave transactions unassigned for now"

- Opening balances unchanged
- Gap period transactions appear in "unassigned" queue
- User can assign them over time via transaction editing

### 5.5 Transactions Without Cashbook Entries

Transactions may have no cashbook entries when:
1. Cashbooks module was disabled when transaction was created
2. Transaction is an inter-fund transfer (no money moved between accounts)
3. Historical transaction from before the "start fresh" date

These transactions are valid but excluded from cashbook balance calculations and reconciliation.

### 5.6 Unassigned Transaction Logic

A transaction is considered "unassigned" (and should appear in the unassigned queue) only if:

1. It has no cashbook entries, AND
2. It is NOT an inter-fund transfer (sum of fund entries ≠ 0), AND
3. Its transaction date is ON or AFTER the earliest cashbook opening_balance_date

Transactions before all cashbooks' opening balance dates are considered "pre-history" - they are covered by the opening balances and should NOT appear as unassigned.

**Query logic:**

```sql
SELECT t.*
FROM transactions t
WHERE t.charity_id = ?
  AND t.deleted_at IS NULL
  AND t.transaction_date >= (
      SELECT MIN(opening_balance_date) 
      FROM cash_books 
      WHERE charity_id = t.charity_id AND is_active = TRUE
  )
  AND NOT EXISTS (
      SELECT 1 FROM transaction_cashbook_entries tce 
      WHERE tce.transaction_id = t.id
  )
  AND (
      SELECT SUM(amount) FROM transaction_fund_entries tfe 
      WHERE tfe.transaction_id = t.id
  ) != 0  -- Excludes inter-fund transfers
```

This ensures:
- "Start fresh" users never see historical transactions as unassigned
- "Skip and start fresh" re-enablers never see gap period as unassigned
- Only genuinely unassigned transactions within the tracking period appear

---

## 6. User Interface Changes

### 6.1 Transaction Entry

**Simple mode (default):**
- Single fund dropdown (as today)
- Single cashbook dropdown (if module enabled)
- Covers 90%+ of transactions

**Split mode:**
- Toggle or link: "Split across funds/cashbooks"
- Expandable section showing:
  - Fund allocation rows (fund + amount)
  - Cashbook allocation rows (cashbook + amount)
- Running totals showing remaining to allocate
- Validation: amounts must sum to transaction total

### 6.2 Transaction Listing

Additional columns (when cashbooks enabled):
- Cashbook(s) - may show multiple or "Multiple" with tooltip
- Reconciled status

Additional filters:
- Cashbook
- Unassigned to cashbook

### 6.3 Fund Reports

Existing reports continue to work:
- Fund balance uses sum of transaction_fund_entries
- Category breakdowns work as before (category is on transaction, not entry)

New capability:
- "By cashbook" breakdown within a fund report

### 6.4 Cashbook Reports

New reports when module enabled:
- Cashbook balance over time
- Cashbook transaction listing
- Reconciliation status
- Unreconciled transactions

### 6.5 Reconciliation Workflow

Enhanced for split transactions:
- Show individual cashbook entries, not whole transactions
- Entry-level reconciliation marking
- Clear indication when a transaction is split

### 6.6 Unassigned Transactions Queue

When cashbooks module is enabled, provide an "Unassigned Transactions" view:

**Access:**
- Link from cashbooks dashboard: "X transactions need cashbook assignment"
- Filter option in main transaction listing
- Alert/badge in navigation if count > 0

**Display:**
- List of transactions matching unassigned criteria (see section 5.6)
- Shows: date, description, amount, fund(s), "Assign" action
- Bulk assignment option: select multiple → assign to cashbook

**Bulk Assignment:**
- Select transactions (checkbox)
- "Assign selected to..." dropdown
- Confirm: "Assign 15 transactions to Shared Bank?"
- Creates cashbook entries for each

**Empty State:**
- "All transactions are assigned to cashbooks ✓"
- Or if no cashbooks exist: "Create a cashbook to start tracking"

**Note:** This queue will be empty for users who chose "Start fresh" or "Skip and start fresh" since those options set opening balance dates that exclude historical/gap transactions.

---

## 7. Validation Rules

### 7.1 Transaction Validation

| Rule | Description |
|------|-------------|
| At least one fund entry | Every transaction must affect at least one fund |
| Fund entries sum correctly | Sum of fund entry amounts must equal transaction amount |
| Cashbook entries sum correctly | If cashbook entries exist, their sum must equal sum of fund entries |
| Consistent signs | All fund entries should have consistent signs (all positive or all negative) unless it's a transfer |

### 7.2 Transfer Validation

For inter-fund or inter-cashbook transfers:
- Sum of all entries (fund or cashbook) must be zero
- At least two entries required

---

## 8. Migration Strategy

### 8.1 Database Migration

1. Create new tables (`transaction_fund_entries`, `transaction_cashbook_entries`)
2. Migrate existing data:
   - For each transaction with `fund_id`, create one `transaction_fund_entry`
   - For each transaction with `cash_book_id`, create one `transaction_cashbook_entry`
3. Retain old columns temporarily for rollback capability
4. After validation period, drop old columns

### 8.2 Code Migration

1. Update repositories to read from new tables
2. Update transaction creation to write to new tables
3. Update reports to aggregate from new tables
4. Update reconciliation to work with entries

---

## 9. Permissions

No new permissions required. Existing permissions apply:

| Permission | Applies to |
|------------|-----------|
| transactions_view | View fund and cashbook entries |
| transactions_create | Create entries |
| transactions_edit | Edit entries |
| transactions_delete | Delete transactions (cascades to entries) |
| cashbooks_view | View cashbook information |
| cashbooks_reconcile | Mark entries as reconciled |

---

## 10. Future Considerations

- **Budgets per fund:** Track spending against fund-specific budgets
- **Fund transfers with approval:** Workflow for inter-fund transfers requiring sign-off
- **Cashbook-level permissions:** Restrict users to specific cashbooks
- **Automated bank feed assignment:** Smart matching of imported transactions to funds

---

## Appendix A: Example Queries

### Fund Balance

```sql
SELECT 
    f.name,
    f.opening_balance + COALESCE(SUM(tfe.amount), 0) AS balance
FROM funds f
LEFT JOIN transaction_fund_entries tfe ON tfe.fund_id = f.id
LEFT JOIN transactions t ON t.id = tfe.transaction_id AND t.deleted_at IS NULL
WHERE f.charity_id = ?
GROUP BY f.id;
```

### Cashbook Balance

```sql
SELECT 
    cb.name,
    cb.opening_balance + COALESCE(SUM(tce.amount), 0) AS balance
FROM cash_books cb
LEFT JOIN transaction_cashbook_entries tce ON tce.cash_book_id = cb.id
LEFT JOIN transactions t ON t.id = tce.transaction_id 
    AND t.deleted_at IS NULL
    AND t.transaction_date >= cb.opening_balance_date
WHERE cb.charity_id = ?
GROUP BY cb.id;
```

### Integrity Check

```sql
SELECT 
    (SELECT COALESCE(SUM(opening_balance), 0) FROM funds WHERE charity_id = ?) +
    (SELECT COALESCE(SUM(amount), 0) FROM transaction_fund_entries tfe 
     JOIN transactions t ON t.id = tfe.transaction_id 
     WHERE t.charity_id = ? AND t.deleted_at IS NULL) AS total_funds,
     
    (SELECT COALESCE(SUM(opening_balance), 0) FROM cash_books WHERE charity_id = ?) +
    (SELECT COALESCE(SUM(amount), 0) FROM transaction_cashbook_entries tce
     JOIN transactions t ON t.id = tce.transaction_id
     JOIN cash_books cb ON cb.id = tce.cash_book_id
     WHERE t.charity_id = ? AND t.deleted_at IS NULL
     AND t.transaction_date >= cb.opening_balance_date) AS total_cashbooks;
```

---

## Appendix B: Document History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | Jan 2026 | | Initial draft |
