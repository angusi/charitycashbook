# Charity Cashbook - Functional Specification

**Version:** 1.0 Draft  
**Date:** January 2026  
**Status:** For Review

---

## 1. Overview

### 1.1 Purpose

Charity Cashbook is a web-based application for small charities to manage their financial records on a receipts and payments basis. It provides:

- Recording of income (receipts) and expenditure (payments)
- Categorisation against charity-defined reporting categories
- Fund accounting (general and restricted funds)
- Donor management with Gift Aid tracking
- Generation of reports suitable for regulatory submission

### 1.2 Scope

The system is designed for:

- Small charities preparing simplified (receipts and payments) accounts
- Charities with gross annual income typically under £250,000
- Multi-charity hosting on a single deployment

Out of scope:

- Accruals accounting
- Invoicing and billing
- Payroll
- Bank feed integration
- Double-entry bookkeeping

### 1.3 Definitions

| Term | Definition |
|------|------------|
| Service | The hosted Charity Cashbook application |
| Super-admin | Administrator of the service itself |
| Charity | A tenant organisation using the service |
| User | A person who accesses the service |
| Internal User | A user's identity within the system (may have multiple OAuth logins) |
| Fund | A ring-fenced pot of money (e.g., General Fund, Building Fund) |
| Reporting Category | A category appearing in published accounts |
| Operational Category | A day-to-day category that maps to a reporting category |
| Receipt | Money coming in |
| Payment | Money going out |
| Transaction | A single receipt or payment |
| Donor | An individual or organisation that has given money |
| Declaration | A Gift Aid declaration authorising tax reclaim |
| GASDS | Gift Aid Small Donations Scheme |

---

## 2. Users and Roles

### 2.1 Role Hierarchy

```
Super-admin (service level)
    │
    └── Charity Admin (per charity)
            │
            ├── Standard User (per charity)
            │
            └── Read-only User (per charity)
```

### 2.2 Super-admin

The super-admin manages the service infrastructure. They:

- Create and configure charities
- Assign the first admin user to each charity
- Monitor system health and diagnostics
- Manage OAuth provider configuration

The super-admin **cannot**:

- View any charity's transactions, reports, or financial data
- View donor personal information
- Make changes to a charity's accounts or configuration
- Access a charity's workspace in any capacity

This separation ensures the integrity of each charity's financial records.

### 2.3 Charity Admin

A charity admin has full control within their charity. They can:

- Configure charity settings (name, registration number, year end)
- Configure authentication settings (allowed providers, domains, session timeout)
- Manage funds, reporting categories, and operational categories
- Manage users and their roles within the charity
- Force users to re-authenticate (individually or all users)
- View and manage full donor details including addresses
- Enter, edit, and delete transactions
- Generate and submit Gift Aid claims
- View all reports

### 2.4 Standard User

A standard user can perform day-to-day data entry. They can:

- Enter and edit transactions
- Link donations to existing donors (sees name only, not address)
- View transactions and reports

They **cannot**:

- Change charity configuration
- Manage other users
- View full donor details (addresses)
- Submit Gift Aid claims

### 2.5 Read-only User

A read-only user can view but not modify. They can:

- View transactions
- View reports

They **cannot**:

- Enter or edit any data
- See donor details beyond what appears in transaction descriptions

### 2.6 Role Assignment

- Super-admin assigns the first charity admin when creating a charity
- Charity admins can then invite and manage additional users
- A user's role is specific to each charity they belong to
- A user may have different roles in different charities

---

## 3. Authentication and Identity

### 3.1 Authentication Method

The system uses OAuth 2.0 for authentication. Supported providers:

- Google
- Microsoft (Azure AD)
- Generic OIDC (configurable)

OAuth applications are registered at the service level by the super-admin. Individual charities do not need to configure OAuth credentials.

### 3.2 Internal User Identity

Each person using the system has an internal user identity. This identity:

- Is independent of any single OAuth provider
- Can be linked to multiple OAuth accounts
- Persists even if one OAuth account is removed

### 3.3 Account Linking

A user may have multiple OAuth accounts (e.g., a work Google account and a personal Microsoft account). The system supports linking these to a single internal identity.

**Linking flow:**

1. User logs in with OAuth identity A (already known to the system)
2. User navigates to account settings and selects "Add login method"
3. User authenticates with OAuth identity B
4. System creates a pending link
5. Email sent to identity A's email address requesting confirmation
6. User clicks confirmation link in email (expires after 24 hours)
7. Link becomes active; both OAuth identities now access the same internal user

**Security measures:**

- User must prove control of both OAuth identities
- Email confirmation required to the existing account
- User can report suspicious linking attempts
- Charities can disable linking for their users

**First-time login with linking:**

1. User authenticates with OAuth identity B (not known to system)
2. System verifies identity B's email is authorised for a charity
3. If that charity allows linking, user is offered a choice:
   - "Create new account"
   - "I already use this service - link to existing account"
4. If linking, user authenticates with existing identity A
5. Email confirmation flow as above

### 3.4 Charity Authentication Configuration

Each charity configures:

| Setting | Description |
|---------|-------------|
| Allowed providers | Which OAuth providers users may use (Google, Microsoft, etc.) |
| Allowed domains | Email domains permitted (e.g., `ourcharity.org.uk`) |
| Allowed emails | Specific email addresses permitted (alternative to domain) |
| Session timeout | Hours before re-authentication required |
| Allow linking | Whether users can link multiple OAuth accounts |

### 3.5 Session Management

- Sessions are per-charity
- Each charity has a configurable session timeout
- After timeout, user must re-authenticate to continue
- **Critical:** Re-authentication must use an OAuth identity that is itself authorised for that charity
- A linked OAuth identity from another domain does not grant access
- A user active in multiple charities may have different session states

**Session lifetime:**

- Overall session persists for 30 days (configurable) even if browser is closed
- Per-charity re-authentication is required based on each charity's timeout setting
- Users can view their active sessions and log out of individual sessions
- "Log out everywhere" option terminates all sessions for that user

**Forced re-authentication:**

Charity Admins can force individual users or all users to re-authenticate for their charity:

- Invalidates the user's charity session immediately
- User must re-authenticate with a valid OAuth identity on next access
- Does not affect the user's overall service session or access to other charities
- Useful when a user may have left the organisation or account compromise is suspected

**Example:**

A user has linked `chris@charitya.org` and `chris@charityb.net`. They belong to both Charity A (which allows `@charitya.org`) and Charity B (which allows `@charityb.net`).

- Session for Charity A expires
- User attempts to continue working in Charity A
- User must re-authenticate with `chris@charitya.org` specifically
- Authenticating with `chris@charityb.net` will not refresh access to Charity A
- If user has been removed from `charitya.org` Google Workspace, they lose access to Charity A even though they can still log into the service via `charityb.net`

This ensures that removing someone from a Google Workspace (or other identity provider) promptly revokes their access to that charity's data.

### 3.6 Super-admin Authentication

Super-admins authenticate via OAuth like other users. However:

- Super-admin status is stored on the internal user, not tied to any charity
- A super-admin can link personal OAuth accounts to retain access even if they leave a charity
- Emergency recovery via CLI tool (requires server access)

### 3.7 CLI Recovery Tool

A command-line tool allows recovery operations when OAuth access is lost:

```
php cli/admin.php add-oauth --user=<email> --provider=<provider> --oauth-email=<email>
php cli/admin.php promote-superadmin --user=<email>
php cli/admin.php list-superadmins
```

This tool:

- Requires shell access to the server
- Is fully audit logged
- Does not provide a web-accessible backdoor

---

## 4. Charity Configuration

### 4.1 Charity Setup

When a super-admin creates a charity, they provide:

- Charity name
- Registration number (e.g., OSCR SC012345) - optional
- First admin user email address

The first admin then configures:

- Financial year end (day and month)
- Authentication settings
- Funds
- Reporting categories
- Operational categories

### 4.2 Financial Year

The financial year end is specified as day and month (e.g., 31 March). This is used:

- To default date ranges on reports
- To calculate which financial year a transaction falls into
- For Gift Aid claim periods

### 4.3 Funds

Funds represent ring-fenced pots of money. Common examples:

- General Fund (unrestricted)
- Building Fund (restricted)
- Grant Project X (restricted)

Each fund has:

- Name
- Type: Unrestricted or Restricted
- Active/Inactive status
- Opening balance (for the first year of use)

Every transaction must be allocated to exactly one fund.

### 4.4 Reporting Categories

Reporting categories are the headings that will appear in the charity's published accounts. The charity defines these to match their regulatory requirements.

Each reporting category has:

- Name (e.g., "Donations", "Grants received", "Charitable activities")
- Type: Receipt or Payment
- Display order (for report formatting)
- Active/Inactive status

Examples for a typical small Scottish charity:

**Receipts:**
1. Donations and legacies
2. Grants
3. Fundraising income
4. Investment income
5. Other income

**Payments:**
1. Charitable activities
2. Fundraising costs
3. Governance costs

The charity has complete control over these categories.

### 4.5 Operational Categories

Operational categories are the day-to-day categories used when entering transactions. Each maps to exactly one reporting category.

Each operational category has:

- Name (e.g., "Regular giving", "One-off donations", "Gift Aid reclaimed")
- Parent reporting category
- Type: inherited from parent (Receipt or Payment)
- Gift Aid eligible by default: Yes/No (for receipt categories only)
- Active/Inactive status

Example mapping:

| Operational Category | → | Reporting Category |
|---------------------|---|-------------------|
| Regular giving | → | Donations and legacies |
| One-off donations | → | Donations and legacies |
| Gift Aid reclaimed | → | Donations and legacies |
| Legacies | → | Donations and legacies |
| Grant from Funder X | → | Grants |
| Hall hire income | → | Other income |
| Programme materials | → | Charitable activities |
| Venue hire | → | Charitable activities |
| Independent examiner | → | Governance costs |

### 4.6 Category Templates (Optional Feature)

When setting up categories, the charity may optionally start from a template:

- "Blank" - no categories, build from scratch
- "Scottish charity (OSCR)" - common headings for OSCR reporting
- "English/Welsh charity" - common headings for Charity Commission

Templates provide a starting point; the charity can modify as needed.

---

## 5. Transactions

### 5.1 Transaction Data

Each transaction records:

| Field | Description | Required |
|-------|-------------|----------|
| Date | Date of the transaction | Yes |
| Type | Receipt or Payment | Yes (derived from category) |
| Amount | Monetary value (positive) | Yes |
| Description | Free text description | Yes |
| Fund | Which fund this relates to | Yes |
| Operational Category | Day-to-day category | Yes |
| Donor | Link to donor record | No (receipts only) |
| Gift Aid Status | See below | No (receipts only) |
| Reference | External reference (e.g., cheque number) | No |

### 5.2 Gift Aid Status

For receipt transactions, the Gift Aid status may be:

| Status | Meaning |
|--------|---------|
| Not applicable | Not a donation (e.g., grant income, sales) |
| Not eligible | Donation but not eligible (no declaration, donor not UK taxpayer) |
| Eligible | Eligible for Gift Aid claim (linked to donor with valid declaration) |
| GASDS | Eligible under Small Donations Scheme (no donor link required) |
| Claimed | Already included in a submitted claim |

### 5.3 Entering Transactions

**Single transaction entry:**

1. User selects Receipt or Payment
2. User enters date, amount, description
3. User selects fund and operational category
4. For receipts, user optionally links to a donor
5. If donor linked and has valid declaration, Gift Aid status defaults to "Eligible"
6. User saves

**Batch entry:**

For efficiency, users can enter multiple transactions in a grid view, tabbing between fields.

### 5.4 Editing Transactions

- Admin and Standard users can edit transactions
- All edits are recorded in the audit trail
- Transactions included in a submitted Gift Aid claim cannot have their Gift Aid status changed without first un-submitting the claim

### 5.5 Deleting Transactions

- Only Admin users can delete transactions
- Deleted transactions are soft-deleted (retained for audit) but hidden from reports
- Transactions in submitted Gift Aid claims cannot be deleted

### 5.6 Transaction Listing

Users can view transactions with filters:

- Date range
- Fund
- Category (operational or reporting)
- Receipt/Payment
- Gift Aid status
- Free text search (description)

Results can be exported to CSV.

---

## 6. Donors and Gift Aid

### 6.1 Donor Records

A donor record stores:

| Field | Description | Visibility |
|-------|-------------|------------|
| Name | Full name or organisation name | Admin, Standard (when linking) |
| Address | Postal address (required for Gift Aid) | Admin only |
| Email | Email address (optional) | Admin only |
| Notes | Free text notes | Admin only |

### 6.2 Gift Aid Declarations

Declarations are managed at two levels:

**Ongoing declarations (stored on donor):**

A donor can have multiple ongoing declarations over time, forming a declaration history. This supports scenarios such as:

- Donor provides ongoing declaration in 2020
- Donor withdraws declaration in 2022
- Donor provides new ongoing declaration in 2024

Each ongoing declaration record contains:

| Field | Description |
|-------|-------------|
| Start date | Date from which the declaration is effective |
| Covers past donations | Whether the declaration applies to past donations |
| Covers past from date | If covering past donations, from when |
| Withdrawn date | Date the declaration was withdrawn (NULL if still active) |

**One-off declarations (stored on transaction):**

For donors who provide a declaration for a single donation only, a flag is set on the specific transaction rather than creating a declaration record. This avoids complex linking between declarations and transactions.

**Current declaration status** (derived from history):

| Derived Status | Meaning |
|----------------|---------|
| None | No ongoing declarations on file |
| Active | Has an ongoing declaration with no withdrawal date |
| Withdrawn | Most recent ongoing declaration has been withdrawn |

**Determining eligibility for a specific donation:**

A donation is covered by a declaration if:

- A declaration exists where start_date ≤ donation_date
- AND (withdrawn_date is NULL OR withdrawn_date ≥ donation_date)
- AND (declaration is ongoing, OR declaration is one-off linked to this specific donation)

This allows accurate eligibility checking even with gaps in coverage.

### 6.3 Determining Eligibility

A donation is eligible for Gift Aid if:

- It is linked to a donor AND the donor has an ongoing declaration covering the donation date, OR
- The transaction has the one-off declaration flag set

**Ongoing declaration coverage:**

A donation date is covered by an ongoing declaration if:

- declaration start_date ≤ donation_date
- AND (withdrawn_date is NULL OR withdrawn_date ≥ donation_date)

This allows accurate eligibility checking even with gaps in coverage.

The system automatically determines eligibility based on these rules.

### 6.4 GASDS (Gift Aid Small Donations Scheme)

For small cash or contactless donations (up to £30) collected without individual donor details, charities can claim under GASDS.

- No donor link required
- User marks transaction as "GASDS" when entering
- System tracks cumulative GASDS claims against the annual cap (currently £8,000 claimable donations, yielding £2,000 Gift Aid)
- GASDS cap is per financial year

### 6.5 Gift Aid Claims

**Generating a claim:**

1. Admin selects a date range
2. System shows all eligible unclaimed donations
3. Admin reviews and confirms
4. System generates claim report in HMRC-compatible format
5. Admin submits to HMRC externally (no direct API integration)
6. Admin records submission date and optionally HMRC reference
7. Transactions marked as "Claimed"

**Claim report includes:**

For standard Gift Aid:
- Donor name and address
- Donation date and amount
- Declaration details

For GASDS:
- Total amount of small donations
- Dates covered

**Un-submitting a claim:**

If a claim needs correction:

1. Admin un-submits the claim
2. Transactions revert to "Eligible"
3. Admin makes corrections
4. Admin re-submits

---

## 7. Reports

### 7.1 Receipts and Payments Report

The primary financial report, structured by the charity's reporting categories.

**Content:**

- Reporting period (financial year or custom date range)
- For each fund:
  - Opening balance
  - Receipts by reporting category (with totals)
  - Payments by reporting category (with totals)
  - Net movement
  - Closing balance
- Combined totals across all funds

**Format:**

- Screen view
- PDF export
- CSV export (for further manipulation)

### 7.2 Detailed Transaction Report

A listing of individual transactions for audit or detailed review.

**Filters:**
- Date range
- Fund
- Category
- Type (receipt/payment)

**Columns:**
- Date
- Description
- Fund
- Category
- Amount
- Reference
- Gift Aid status (receipts)

**Export:** CSV

### 7.3 Gift Aid Report

For preparing HMRC claims.

**Filters:**
- Date range
- Status: Eligible unclaimed / GASDS unclaimed / All claimed / All

**Content:**
- Donor name and address
- Donation date and amount
- Declaration reference
- Claim status

**For GASDS section:**
- Summary total of small donations
- Running total against annual cap

**Export:** CSV (compatible with HMRC Charities Online spreadsheet)

### 7.4 Fund Summary

Overview of all funds.

**Content:**
- Fund name and type
- Opening balance
- Total receipts (YTD)
- Total payments (YTD)
- Current balance

### 7.5 Category Analysis

Breakdown by operational category within a reporting category.

**Use case:** "Donations and legacies was £5,000 - how much was regular giving vs one-off?"

**Content:**
- Reporting category
- Operational categories within it
- Amount per operational category
- Percentage breakdown

---

## 8. Audit and Logging

### 8.1 Transaction Audit Trail

Every transaction records:

- Created by (user), created at (timestamp)
- Last modified by (user), last modified at (timestamp)
- Full history of changes (what changed, by whom, when)

### 8.2 User Activity Log

The system logs:

- Login events (user, OAuth provider, timestamp, success/failure)
- Charity access (user, charity, timestamp)
- Data modifications (user, action, entity, timestamp)

### 8.3 Super-admin Audit Log

Separately logs super-admin actions:

- Charity created/modified
- Admin user assigned
- OAuth provider configuration changed
- CLI tool usage

### 8.4 Audit Log Retention

- Logs retained for 7 years (configurable)
- Logs are append-only (cannot be modified or deleted via the application)

### 8.5 Super-admin Access to Logs

Super-admin can view:

- Aggregate statistics (transaction counts, user counts)
- Login events (who, when - not what they accessed)
- System errors

Super-admin **cannot** view:

- Transaction content
- Donor details
- Report contents

---

## 9. Log Viewing Interfaces

### 9.1 Charity Admin: Audit Log Viewer

Charity Admins can view audit logs for their charity through a dedicated interface.

**Available views:**

| Log Type | Content Shown |
|----------|---------------|
| Transaction log | Created, modified, deleted transactions (who, when, what changed) |
| User activity | Logins to this charity, session invalidations, role changes |
| Donor access | Who viewed or edited donor records (PII access tracking) |
| Gift Aid activity | Claims created, submitted, modified |
| Configuration changes | Category, fund, and settings changes |

**Filters:**

- Date range
- User
- Action type (create, update, delete, view)
- Entity type (transaction, donor, claim, etc.)

**Display:**

Each log entry shows:
- Timestamp
- User who performed the action
- Action description
- Entity affected (with link if still exists)
- For updates: summary of what changed (old value → new value)

**Export:**

- CSV export for compliance or investigation purposes
- Filtered by current view settings

**Retention:**

- Logs visible in UI for 2 years
- Older logs retained in database per retention policy but not shown in UI
- Full export available on request

### 9.2 Super-admin: Service Log Viewer

Super-admins can view service-level logs through a separate interface.

**Available views:**

| Log Type | Content Shown |
|----------|---------------|
| Login history | All login attempts (success/failure), OAuth provider used, IP address |
| Failed logins | Filtered view of failed attempts for security monitoring |
| Charity activity | Charity created, suspended, admin assigned (metadata only) |
| OAuth provider changes | Provider configuration modifications |
| System errors | Application errors and exceptions |
| Session statistics | Active session counts, unusual patterns |

**What super-admin can see:**

- User email addresses and login timestamps
- Which charity a user logged into (not what they did inside)
- Error messages and stack traces
- IP addresses and user agents

**What super-admin cannot see:**

- Transaction descriptions or amounts
- Donor names or addresses
- Report contents
- Any charity workspace data

**Filters:**

- Date range
- User email
- Charity (by name/ID, for login counts only)
- Success/failure status
- OAuth provider

**Alerts:**

Super-admin dashboard highlights:

- Multiple failed login attempts from same IP
- Unusual login patterns (e.g., same user from multiple countries)
- System errors in the last 24 hours
- Charities with no activity in 90+ days

---

## 10. Service Administration

### 10.1 Super-admin Dashboard

Displays:

- System health status
- Number of charities (active/inactive)
- Total users across all charities
- Recent errors (without charity data)
- Storage usage

### 10.2 Charity Management

Super-admin can:

- Create a new charity
- View list of charities (name, registration number, admin email, created date)
- Suspend a charity (prevents all access)
- Reactivate a suspended charity
- Delete a charity (requires confirmation, soft-delete with data retention)

Super-admin **cannot**:

- View anything inside a charity's workspace
- Modify a charity's configuration (beyond suspension)
- Access on behalf of a charity

### 10.3 OAuth Provider Management

Super-admin configures:

- Google OAuth (client ID, client secret)
- Microsoft OAuth (client ID, client secret, tenant ID)
- Generic OIDC (discovery URL, client ID, client secret)

These credentials are stored encrypted in the database or in environment configuration.

### 10.4 System Configuration

Super-admin configures:

- Service name (appears on OAuth consent screens)
- Support email address
- Default session timeout (charity can override)
- Email settings (SMTP or local mail)

---

## 11. User Journeys

### 11.1 New Charity Setup

1. Super-admin creates charity with name and first admin email
2. First admin receives email invitation
3. First admin logs in via OAuth
4. First admin configures:
   - Financial year end
   - Authentication settings
   - Funds (at least one)
   - Reporting categories
   - Operational categories
5. First admin invites other users
6. Charity is ready for use

### 11.2 Daily Transaction Entry

1. User logs in
2. User selects charity (if multiple)
3. User navigates to "Enter transactions"
4. User enters receipt or payment details
5. System saves and shows confirmation
6. User continues or logs out

### 11.3 Month-End Review

1. Admin logs in
2. Admin runs transaction report for the month
3. Admin reviews for errors or omissions
4. Admin makes corrections if needed
5. Admin runs receipts and payments report
6. Admin compares bank balance to system balance

### 11.4 Gift Aid Claim

1. Admin logs in
2. Admin navigates to Gift Aid section
3. Admin selects date range for claim
4. System shows eligible donations
5. Admin reviews and confirms
6. System generates claim report
7. Admin downloads and submits to HMRC
8. Admin records submission in system
9. Transactions marked as claimed

### 11.5 Year-End Reporting

1. Admin runs receipts and payments report for the financial year
2. Admin reviews totals by category and fund
3. Admin exports report for independent examination
4. Admin prepares trustee annual report (outside this system)
5. Admin submits to regulator

### 11.6 Account Linking

1. User logs in with new OAuth account
2. User indicates they have an existing account
3. User authenticates with existing OAuth account
4. User receives confirmation email at existing email address
5. User clicks confirmation link
6. Accounts are linked
7. User can now use either OAuth account to log in

---

## 12. Non-Functional Requirements

### 12.1 Performance

- Page load time under 2 seconds for typical operations
- Report generation under 10 seconds for charities with up to 10,000 transactions
- Support concurrent users (at least 10 per charity)

### 12.2 Availability

- Target 99% uptime
- Planned maintenance windows communicated in advance
- Graceful degradation (read-only mode if database issues)

### 12.3 Data Backup

- Database backed up daily
- Backups retained for 30 days
- Backup restoration tested quarterly

### 12.4 Security

- All connections via HTTPS
- OAuth tokens stored securely
- Session tokens rotated on re-authentication
- Password hashing not applicable (no passwords stored)
- SQL injection prevention via parameterised queries
- XSS prevention via output encoding
- CSRF protection on all forms

### 12.5 Data Protection

- Personal data (donor details) access logged
- Data export available on request
- Data deletion available on request (subject to legal retention requirements)
- Privacy policy published

### 12.6 Browser Support

- Chrome (last 2 versions)
- Firefox (last 2 versions)
- Safari (last 2 versions)
- Edge (last 2 versions)
- Mobile browsers (responsive design)

---

## 13. Future Considerations

The following are explicitly out of scope for version 1 but may be considered later:

- Bank statement import (CSV/OFX)
- Direct HMRC API integration for Gift Aid
- Multi-currency support
- Budget tracking and variance reporting
- Document attachment (receipts, invoices)
- API for third-party integration
- Mobile app

---

## Appendix A: Glossary

| Term | Definition |
|------|------------|
| OSCR | Office of the Scottish Charity Regulator |
| R&P | Receipts and Payments (simplified accounting basis) |
| GASDS | Gift Aid Small Donations Scheme |
| HMRC | Her Majesty's Revenue and Customs |
| OAuth | Open Authorization protocol for authentication |
| OIDC | OpenID Connect (authentication layer on OAuth 2.0) |
| CSRF | Cross-Site Request Forgery |
| XSS | Cross-Site Scripting |

---

## Appendix B: Document History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 Draft | Jan 2026 | - | Initial draft for review |
