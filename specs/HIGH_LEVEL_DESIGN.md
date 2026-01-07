# Charity Cashbook - High-Level Design

**Version:** 1.0 Draft  
**Date:** January 2026  
**Status:** For Review

---

## 1. Overview

### 1.1 Purpose

This document describes the technical architecture and design of the Charity Cashbook application. It is intended for developers and technical reviewers.

### 1.2 Related Documents

- Functional Specification v1.0

### 1.3 Technology Stack

| Layer | Technology |
|-------|------------|
| Language | PHP 8.2+ |
| Framework | Slim 4 (lightweight) or vanilla PHP with custom routing |
| Database | MariaDB 10.6+ |
| Templating | Twig |
| CSS | Tailwind CSS (compiled) or simple custom CSS |
| JavaScript | Vanilla JS (minimal, progressive enhancement) |
| Authentication | OAuth 2.0 via league/oauth2-client |
| Email | PHP mail() function |
| Server | Apache or Nginx with PHP-FPM |

### 1.4 Design Principles

- **Simplicity:** Minimal dependencies, easy to deploy on standard PHP hosting
- **Security:** Defence in depth, principle of least privilege
- **Separation:** Service administration strictly separated from charity data
- **Auditability:** All changes logged with full trail
- **Maintainability:** Clear structure, documented code

---

## 2. Architecture Overview

### 2.1 Deployment Model

```
┌─────────────────────────────────────────────────────────────┐
│                        Web Server                           │
│                    (Apache/Nginx + PHP)                     │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────────┐  ┌─────────────────┐                  │
│  │  Public Routes  │  │  CLI Tools      │                  │
│  │  /login         │  │  admin.php      │                  │
│  │  /oauth/...     │  │  backup.php     │                  │
│  │  /app/...       │  │                 │                  │
│  └────────┬────────┘  └────────┬────────┘                  │
│           │                    │                            │
│           ▼                    ▼                            │
│  ┌─────────────────────────────────────────────────────┐   │
│  │              Application Layer                       │   │
│  │  ┌───────────┐ ┌───────────┐ ┌───────────┐         │   │
│  │  │   Auth    │ │  Service  │ │  Charity  │         │   │
│  │  │  Module   │ │   Admin   │ │ Workspace │         │   │
│  │  └───────────┘ └───────────┘ └───────────┘         │   │
│  └─────────────────────────────────────────────────────┘   │
│                          │                                  │
│                          ▼                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │              Data Access Layer                       │   │
│  │         (Repositories / Query Builders)              │   │
│  └─────────────────────────────────────────────────────┘   │
│                          │                                  │
└──────────────────────────┼──────────────────────────────────┘
                           │
                           ▼
                    ┌─────────────┐
                    │  MariaDB    │
                    │  Database   │
                    └─────────────┘
```

### 2.2 Module Structure

```
charity-cashbook/
├── public/                     # Web root
│   ├── index.php              # Front controller
│   ├── assets/                # CSS, JS, images
│   └── .htaccess              # URL rewriting
├── src/
│   ├── Auth/                  # Authentication module
│   │   ├── OAuthProvider.php
│   │   ├── SessionManager.php
│   │   ├── IdentityService.php
│   │   └── AccountLinkingService.php
│   ├── ServiceAdmin/          # Super-admin functionality
│   │   ├── CharityManager.php
│   │   ├── ProviderConfig.php
│   │   └── DiagnosticsService.php
│   ├── Charity/               # Per-charity functionality
│   │   ├── ConfigService.php
│   │   ├── UserManager.php
│   │   ├── FundService.php
│   │   ├── CategoryService.php
│   │   ├── TransactionService.php
│   │   ├── DonorService.php
│   │   ├── GiftAidService.php
│   │   └── ReportService.php
│   ├── Core/                  # Shared infrastructure
│   │   ├── Database.php
│   │   ├── Router.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   ├── Middleware/
│   │   └── Exceptions/
│   └── Views/                 # Twig templates
│       ├── layouts/
│       ├── auth/
│       ├── service-admin/
│       └── charity/
├── cli/                       # Command-line tools
│   ├── admin.php
│   └── backup.php
├── config/
│   ├── config.php             # Main configuration
│   ├── config.example.php     # Example configuration
│   └── routes.php             # Route definitions
├── migrations/                # Database migrations
├── tests/                     # Test suite
├── vendor/                    # Composer dependencies
├── docs/                      # Documentation
├── composer.json
└── README.md
```

---

## 3. Database Design

### 3.1 Entity Relationship Overview

```
┌──────────────────┐
│  oauth_providers │
└────────┬─────────┘
         │
         │ configured at service level
         ▼
┌──────────────────┐         ┌──────────────────┐
│     charities    │◄────────│  charity_users   │
└────────┬─────────┘         └────────┬─────────┘
         │                            │
         │                            │
         │    ┌───────────────────────┤
         │    │                       │
         ▼    ▼                       ▼
┌──────────────────┐         ┌──────────────────┐
│      users       │◄────────│  user_identities │
└──────────────────┘         └──────────────────┘
         
         
┌──────────────────┐
│    charities     │
└────────┬─────────┘
         │
         │ contains
         ▼
┌──────────────────┐    ┌──────────────────┐    ┌──────────────────┐
│      funds       │    │reporting_categories│   │operational_categories│
└──────────────────┘    └────────┬─────────┘    └────────┬─────────┘
                                 │                       │
                                 │ parent of             │
                                 └───────────────────────┘


┌──────────────────┐    ┌──────────────────┐
│   transactions   │───▶│     donors       │
└────────┬─────────┘    └────────┬─────────┘
         │                       │
         │                       │
         ▼                       ▼
┌──────────────────┐    ┌──────────────────┐
│transaction_audit │    │  declarations    │
└──────────────────┘    └──────────────────┘


┌──────────────────┐    ┌──────────────────┐
│  giftaid_claims  │───▶│giftaid_claim_items│
└──────────────────┘    └──────────────────┘
```

### 3.2 Table Definitions

#### 3.2.1 Service-Level Tables

```sql
-- OAuth provider configuration (service-level)
CREATE TABLE oauth_providers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_type ENUM('google', 'microsoft', 'oidc') NOT NULL,
    name VARCHAR(100) NOT NULL,
    client_id VARCHAR(255) NOT NULL,
    client_secret_encrypted TEXT NOT NULL,
    -- For generic OIDC
    discovery_url VARCHAR(500) NULL,
    tenant_id VARCHAR(100) NULL,  -- For Microsoft
    is_enabled BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Internal user identities
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(200) NOT NULL,
    primary_email VARCHAR(255) NOT NULL,
    is_super_admin BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_primary_email (primary_email)
);

-- OAuth identities linked to users
CREATE TABLE user_identities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    oauth_provider_id INT UNSIGNED NOT NULL,
    oauth_subject VARCHAR(255) NOT NULL,  -- Provider's unique ID for this user
    oauth_email VARCHAR(255) NOT NULL,
    is_verified BOOLEAN DEFAULT FALSE,
    verified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (oauth_provider_id) REFERENCES oauth_providers(id),
    UNIQUE KEY uk_provider_subject (oauth_provider_id, oauth_subject),
    KEY idx_oauth_email (oauth_email)
);

-- Pending identity links awaiting confirmation
CREATE TABLE pending_identity_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    existing_user_id INT UNSIGNED NOT NULL,
    new_oauth_provider_id INT UNSIGNED NOT NULL,
    new_oauth_subject VARCHAR(255) NOT NULL,
    new_oauth_email VARCHAR(255) NOT NULL,
    confirmation_token VARCHAR(100) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (existing_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (new_oauth_provider_id) REFERENCES oauth_providers(id),
    UNIQUE KEY uk_token (confirmation_token)
);

-- Charities (tenants)
CREATE TABLE charities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    registration_number VARCHAR(50) NULL,  -- e.g., SC012345
    year_end_day TINYINT UNSIGNED NOT NULL DEFAULT 31,
    year_end_month TINYINT UNSIGNED NOT NULL DEFAULT 3,  -- March
    session_timeout_hours INT UNSIGNED DEFAULT 24,
    allow_account_linking BOOLEAN DEFAULT TRUE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Which OAuth providers a charity allows
CREATE TABLE charity_oauth_providers (
    charity_id INT UNSIGNED NOT NULL,
    oauth_provider_id INT UNSIGNED NOT NULL,
    
    PRIMARY KEY (charity_id, oauth_provider_id),
    FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
    FOREIGN KEY (oauth_provider_id) REFERENCES oauth_providers(id)
);

-- Allowed email domains/addresses per charity
CREATE TABLE charity_allowed_emails (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    charity_id INT UNSIGNED NOT NULL,
    email_pattern VARCHAR(255) NOT NULL,  -- Either domain (@example.org) or full email
    pattern_type ENUM('domain', 'email') NOT NULL,
    
    FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
    KEY idx_charity (charity_id)
);

-- User membership in charities
CREATE TABLE charity_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    charity_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('admin', 'standard', 'readonly') NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_authenticated_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_charity_user (charity_id, user_id)
);
```

#### 3.2.2 Charity Data Tables

```sql
-- Funds within a charity
CREATE TABLE funds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    charity_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    fund_type ENUM('unrestricted', 'restricted') NOT NULL DEFAULT 'unrestricted',
    opening_balance DECIMAL(15,2) DEFAULT 0.00,
    opening_balance_date DATE NULL,
    display_order INT UNSIGNED DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
    KEY idx_charity (charity_id)
);

-- Reporting categories (appear in published accounts)
CREATE TABLE reporting_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    charity_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    category_type ENUM('receipt', 'payment') NOT NULL,
    display_order INT UNSIGNED DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
    KEY idx_charity_type (charity_id, category_type)
);

-- Operational categories (day-to-day use, map to reporting categories)
CREATE TABLE operational_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    charity_id INT UNSIGNED NOT NULL,
    reporting_category_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    giftaid_eligible_default BOOLEAN DEFAULT FALSE,
    display_order INT UNSIGNED DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
    FOREIGN KEY (reporting_category_id) REFERENCES reporting_categories(id),
    KEY idx_charity (charity_id),
    KEY idx_reporting_category (reporting_category_id)
);

-- Donors
CREATE TABLE donors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    charity_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    address_line1 VARCHAR(200) NULL,
    address_line2 VARCHAR(200) NULL,
    address_city VARCHAR(100) NULL,
    address_postcode VARCHAR(20) NULL,
    email VARCHAR(255) NULL,
    notes TEXT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    updated_by_user_id INT UNSIGNED NULL,
    
    FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_charity (charity_id),
    KEY idx_name (charity_id, name)
);

-- Gift Aid declarations (ongoing declarations only)
CREATE TABLE declarations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    donor_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    covers_past_donations BOOLEAN DEFAULT FALSE,
    covers_past_from_date DATE NULL,
    withdrawn_date DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    
    FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_donor (donor_id)
);

-- Transactions (receipts and payments)
CREATE TABLE transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    charity_id INT UNSIGNED NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_type ENUM('receipt', 'payment') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    description VARCHAR(500) NOT NULL,
    reference VARCHAR(100) NULL,
    fund_id INT UNSIGNED NOT NULL,
    operational_category_id INT UNSIGNED NOT NULL,
    donor_id INT UNSIGNED NULL,  -- Only for receipts
    has_oneoff_declaration BOOLEAN DEFAULT FALSE,  -- One-off Gift Aid declaration for this specific donation
    giftaid_status ENUM('not_applicable', 'not_eligible', 'eligible', 'gasds', 'claimed') 
        DEFAULT 'not_applicable',
    giftaid_claim_id INT UNSIGNED NULL,
    is_deleted BOOLEAN DEFAULT FALSE,
    deleted_at TIMESTAMP NULL,
    deleted_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NOT NULL,
    updated_by_user_id INT UNSIGNED NULL,
    
    FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
    FOREIGN KEY (fund_id) REFERENCES funds(id),
    FOREIGN KEY (operational_category_id) REFERENCES operational_categories(id),
    FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    KEY idx_charity_date (charity_id, transaction_date),
    KEY idx_charity_type (charity_id, transaction_type),
    KEY idx_fund (fund_id),
    KEY idx_category (operational_category_id),
    KEY idx_donor (donor_id),
    KEY idx_giftaid (charity_id, giftaid_status)
);

-- Transaction audit trail
CREATE TABLE transaction_audit (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    action ENUM('created', 'updated', 'deleted', 'restored') NOT NULL,
    changed_fields JSON NULL,  -- For updates: {"field": {"old": x, "new": y}}
    user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    KEY idx_transaction (transaction_id)
);

-- Gift Aid claims
CREATE TABLE giftaid_claims (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    charity_id INT UNSIGNED NOT NULL,
    claim_period_start DATE NOT NULL,
    claim_period_end DATE NOT NULL,
    total_donations DECIMAL(15,2) NOT NULL,
    total_giftaid DECIMAL(15,2) NOT NULL,
    gasds_donations DECIMAL(15,2) DEFAULT 0.00,
    gasds_giftaid DECIMAL(15,2) DEFAULT 0.00,
    status ENUM('draft', 'submitted', 'cancelled') NOT NULL DEFAULT 'draft',
    submitted_at TIMESTAMP NULL,
    hmrc_reference VARCHAR(100) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NOT NULL,
    submitted_by_user_id INT UNSIGNED NULL,
    
    FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    KEY idx_charity (charity_id),
    KEY idx_status (charity_id, status)
);

-- Link table for transactions in a claim
CREATE TABLE giftaid_claim_items (
    claim_id INT UNSIGNED NOT NULL,
    transaction_id INT UNSIGNED NOT NULL,
    
    PRIMARY KEY (claim_id, transaction_id),
    FOREIGN KEY (claim_id) REFERENCES giftaid_claims(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id)
);
```

#### 3.2.3 Audit and Logging Tables

```sql
-- General audit log
CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    charity_id INT UNSIGNED NULL,  -- NULL for service-level actions
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id INT UNSIGNED NULL,
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    KEY idx_charity (charity_id),
    KEY idx_user (user_id),
    KEY idx_created (created_at),
    KEY idx_action (action)
);

-- Login history
CREATE TABLE login_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,  -- NULL if login failed for unknown user
    oauth_provider_id INT UNSIGNED NULL,
    oauth_email VARCHAR(255) NOT NULL,
    success BOOLEAN NOT NULL,
    failure_reason VARCHAR(200) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    KEY idx_user (user_id),
    KEY idx_email (oauth_email),
    KEY idx_created (created_at)
);

-- Donor access log (PII access tracking)
CREATE TABLE donor_access_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    charity_id INT UNSIGNED NOT NULL,
    donor_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    access_type ENUM('view', 'view_address', 'edit', 'export') NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (charity_id) REFERENCES charities(id) ON DELETE CASCADE,
    FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    KEY idx_charity (charity_id),
    KEY idx_donor (donor_id),
    KEY idx_created (created_at)
);
```

### 3.3 Indexing Strategy

Key indexes for performance:

- `transactions(charity_id, transaction_date)` - date-range queries
- `transactions(charity_id, giftaid_status)` - Gift Aid claim generation
- `donors(charity_id, name)` - donor lookup
- `audit_log(created_at)` - log retention cleanup
- `login_history(created_at)` - log retention cleanup

### 3.4 Data Isolation

Charity data isolation is enforced at the application layer:

1. Every query for charity data includes `WHERE charity_id = ?`
2. The current charity ID is stored in the session
3. Middleware validates charity access before any controller action
4. No cross-charity queries are possible through the application

---

## 4. Authentication Flow

### 4.1 OAuth Login Sequence

```
User                    App                     OAuth Provider
  │                      │                           │
  │  Click "Login with   │                           │
  │  Google"             │                           │
  │─────────────────────>│                           │
  │                      │                           │
  │                      │  Redirect to provider     │
  │                      │  with client_id, scope,   │
  │                      │  redirect_uri, state      │
  │<─────────────────────│                           │
  │                      │                           │
  │  Redirect to provider│                           │
  │──────────────────────────────────────────────────>
  │                      │                           │
  │                      │        Login & Consent    │
  │<──────────────────────────────────────────────────
  │                      │                           │
  │  Redirect back with  │                           │
  │  authorization code  │                           │
  │─────────────────────>│                           │
  │                      │                           │
  │                      │  Exchange code for token  │
  │                      │─────────────────────────> │
  │                      │                           │
  │                      │  Access token + ID token  │
  │                      │<───────────────────────── │
  │                      │                           │
  │                      │  Validate token, extract  │
  │                      │  email + subject          │
  │                      │                           │
  │                      │  Look up user_identities  │
  │                      │                           │
  │  Session created,    │                           │
  │  redirect to app     │                           │
  │<─────────────────────│                           │
```

### 4.2 Identity Resolution

On successful OAuth callback:

```php
function resolveIdentity($provider_id, $oauth_subject, $oauth_email) {
    // 1. Look for existing identity
    $identity = findIdentity($provider_id, $oauth_subject);
    
    if ($identity) {
        // Known identity - return associated user
        return getUser($identity->user_id);
    }
    
    // 2. Unknown identity - check if email is authorised for any charity
    $authorisedCharities = findCharitiesForEmail($oauth_email);
    
    if (empty($authorisedCharities)) {
        throw new UnauthorisedException("Email not authorised");
    }
    
    // 3. Check if any of those charities allow linking
    $linkingAllowed = anyCharityAllowsLinking($authorisedCharities);
    
    // 4. Return state for UI to handle
    return [
        'status' => 'new_identity',
        'email' => $oauth_email,
        'charities' => $authorisedCharities,
        'can_link' => $linkingAllowed
    ];
}
```

### 4.3 Session Management

```php
class Session {
    // Session data structure
    [
        'user_id' => int,
        'user_display_name' => string,
        'is_super_admin' => bool,
        'current_charity_id' => int|null,
        'current_charity_role' => string|null,
        'charity_sessions' => [
            charity_id => [
                'role' => string,
                'authenticated_at' => timestamp,
                'authenticated_via_identity_id' => int,  // Which OAuth identity was used
                'timeout_hours' => int
            ]
        ]
    ]
}

function checkCharitySession($charity_id) {
    $session = getCharitySession($charity_id);
    
    if (!$session) {
        return false;
    }
    
    $expiresAt = $session['authenticated_at'] + ($session['timeout_hours'] * 3600);
    
    if (time() > $expiresAt) {
        // Session expired - need re-authentication
        return false;
    }
    
    return true;
}
```

### 4.4 Re-authentication and Charity Access Validation

**Critical security principle:** Account linking provides identity unification, not authorisation bypass.

When a user re-authenticates for a specific charity, the OAuth identity used must itself be valid for that charity:

```php
function handleCharityReauthentication($charity_id, $oauth_provider_id, $oauth_email) {
    // 1. Verify the OAuth identity belongs to this user
    $identity = findIdentityForUser($this->currentUserId, $oauth_provider_id, $oauth_email);
    if (!$identity) {
        throw new AuthException("OAuth identity not linked to your account");
    }
    
    // 2. Verify this specific OAuth identity is authorised for this charity
    $charity = getCharity($charity_id);
    if (!$charity->isEmailAuthorised($oauth_email)) {
        throw new AuthException(
            "You need to authenticate with an account authorised for this charity"
        );
    }
    
    // 3. Verify user still has a role in this charity
    $membership = getCharityMembership($charity_id, $this->currentUserId);
    if (!$membership || !$membership->is_active) {
        throw new AuthException("You no longer have access to this charity");
    }
    
    // 4. Refresh the charity session
    refreshCharitySession($charity_id, $identity->id);
}
```

**What this means in practice:**

| Scenario | Outcome |
|----------|---------|
| User has `chris@charitya.org` and linked `chris@charityb.net` | Can log into service with either |
| User accesses Charity A, session expires | Must re-authenticate with `chris@charitya.org` (or another identity Charity A accepts) |
| User is removed from `charitya.org` Google Workspace | Cannot re-authenticate for Charity A, even with valid `charityb.net` login |
| User accesses Charity B with same service session | Must re-authenticate with identity valid for Charity B |

**Account linking benefits:**
- Single internal user across charities (unified audit trail)
- Can access service with any linked identity
- Profile and preferences shared

**Account linking does NOT provide:**
- Access to charities you're not authorised for
- Bypass of per-charity authentication requirements
- Persistence after removal from a charity's allowed domains

### 4.4 Account Linking Flow

```
User (logged in)        App                     Email
      │                  │                        │
      │  "Add login      │                        │
      │   method"        │                        │
      │─────────────────>│                        │
      │                  │                        │
      │  OAuth flow      │                        │
      │<────────────────>│                        │
      │                  │                        │
      │                  │  Create pending link   │
      │                  │  with token            │
      │                  │                        │
      │                  │  Send confirmation     │
      │                  │  email                 │
      │                  │───────────────────────>│
      │                  │                        │
      │  "Check your     │                        │
      │   email"         │                        │
      │<─────────────────│                        │
      │                  │                        │
      │                  │                        │
User clicks link in email│                        │
      │─────────────────────────────────────────>│
      │                  │                        │
      │                  │  Validate token        │
      │                  │  Activate link         │
      │                  │                        │
      │  "Account        │                        │
      │   linked"        │                        │
      │<─────────────────│                        │
```

---

## 5. Security Design

### 5.1 Authentication Security

| Measure | Implementation |
|---------|---------------|
| OAuth state parameter | Random token, verified on callback |
| OAuth nonce (OIDC) | Included in ID token, verified |
| Token storage | Access tokens not stored; ID token claims extracted |
| Session tokens | Cryptographically random, regenerated on auth |
| Session fixation | Session ID regenerated after login |

### 5.2 Authorisation Enforcement

```php
// Middleware stack for charity routes
$charityRoutes->add(new AuthenticationMiddleware())      // Must be logged in
              ->add(new CharityAccessMiddleware())       // Must have access to charity
              ->add(new CharitySessionMiddleware())      // Session not expired
              ->add(new RoleMiddleware(['admin', 'standard']));  // Required role
```

### 5.3 Data Access Controls

```php
// Repository pattern enforces charity isolation
class TransactionRepository {
    public function find($id, $charityId) {
        return $this->db->query(
            "SELECT * FROM transactions 
             WHERE id = ? AND charity_id = ? AND is_deleted = FALSE",
            [$id, $charityId]
        );
    }
    
    // No method exists to query across charities
}
```

### 5.4 Super-admin Isolation

Super-admin functionality is in a completely separate module with its own routes:

```php
// Service admin routes - no access to charity data tables
/service-admin/charities          -> list charities (metadata only)
/service-admin/charities/create   -> create charity
/service-admin/diagnostics        -> system health

// These routes use ServiceAdminMiddleware which:
// 1. Verifies user is super-admin
// 2. Does NOT set any charity context
// 3. Only allows access to service-level tables
```

### 5.5 SQL Injection Prevention

All queries use prepared statements:

```php
// NEVER concatenate user input
$db->query(
    "SELECT * FROM transactions WHERE charity_id = ? AND description LIKE ?",
    [$charityId, '%' . $searchTerm . '%']
);
```

### 5.6 XSS Prevention

Twig auto-escapes by default:

```twig
{# Automatically escaped #}
{{ transaction.description }}

{# Raw output only when explicitly needed and safe #}
{{ trusted_html|raw }}
```

### 5.7 CSRF Protection

All state-changing forms include CSRF token:

```php
// Generate
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Verify
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    throw new CsrfException();
}
```

### 5.8 Sensitive Data Handling

```php
// OAuth client secrets encrypted at rest
$encryptedSecret = encrypt($clientSecret, $encryptionKey);

// Donor addresses only returned when explicitly requested
class DonorService {
    public function getForLinking($id, $charityId) {
        // Returns name only
        return $this->repo->findNameOnly($id, $charityId);
    }
    
    public function getFullDetails($id, $charityId, $userId) {
        // Log PII access
        $this->auditLog->logDonorAccess($id, $userId, 'view_address');
        return $this->repo->findWithAddress($id, $charityId);
    }
}
```

---

## 6. Component Design

### 6.1 Core Components

#### Router

Lightweight routing based on URL patterns:

```php
$router->get('/charity/{id}/transactions', [TransactionController::class, 'index']);
$router->post('/charity/{id}/transactions', [TransactionController::class, 'store']);
```

#### Database

PDO wrapper with connection management:

```php
class Database {
    public function query(string $sql, array $params = []): array;
    public function execute(string $sql, array $params = []): int;
    public function lastInsertId(): int;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
}
```

#### Request/Response

Simple value objects:

```php
class Request {
    public function getParam(string $name): ?string;
    public function getBody(): array;
    public function getFile(string $name): ?UploadedFile;
}

class Response {
    public static function html(string $content, int $status = 200): Response;
    public static function json(array $data, int $status = 200): Response;
    public static function redirect(string $url): Response;
}
```

### 6.2 Service Layer

Each domain area has a service class:

```php
class TransactionService {
    public function __construct(
        private TransactionRepository $repo,
        private AuditService $audit,
        private CategoryService $categories
    ) {}
    
    public function create(int $charityId, int $userId, array $data): Transaction {
        // Validate
        $this->validate($data);
        
        // Determine type from category
        $category = $this->categories->get($data['category_id'], $charityId);
        $data['transaction_type'] = $category->getReportingCategory()->type;
        
        // Create
        $transaction = $this->repo->create($charityId, $data);
        
        // Audit
        $this->audit->log($charityId, $userId, 'transaction.created', 
            'transaction', $transaction->id);
        
        return $transaction;
    }
}
```

### 6.3 Repository Layer

Data access encapsulation:

```php
class TransactionRepository {
    public function find(int $id, int $charityId): ?Transaction;
    public function findByDateRange(int $charityId, Date $from, Date $to): array;
    public function findEligibleForGiftAid(int $charityId): array;
    public function create(int $charityId, array $data): Transaction;
    public function update(int $id, int $charityId, array $data): Transaction;
    public function softDelete(int $id, int $charityId, int $userId): void;
}
```

---

## 7. API Design (Internal)

The application uses a traditional server-rendered approach with forms, not a REST API. However, some AJAX endpoints exist for enhanced UX:

### 7.1 AJAX Endpoints

```
POST /charity/{id}/api/donors/search
    Body: { "query": "smith" }
    Response: { "donors": [{ "id": 1, "name": "John Smith" }] }

POST /charity/{id}/api/transactions/validate
    Body: { transaction data }
    Response: { "valid": true } or { "valid": false, "errors": [...] }

GET /charity/{id}/api/categories/{type}
    Response: { "categories": [...] }
```

### 7.2 Response Format

```json
{
    "success": true,
    "data": { ... }
}

{
    "success": false,
    "error": "Validation failed",
    "details": {
        "amount": "Must be a positive number"
    }
}
```

---

## 8. CLI Tools

### 8.1 Admin Recovery Tool

```bash
# Add OAuth identity to existing user
php cli/admin.php add-oauth \
    --user=admin@example.org \
    --provider=google \
    --oauth-email=admin@gmail.com

# Promote user to super-admin
php cli/admin.php promote-superadmin \
    --user=admin@example.org

# Demote super-admin
php cli/admin.php demote-superadmin \
    --user=admin@example.org

# List super-admins
php cli/admin.php list-superadmins

# Verify identity link (bypass email confirmation in emergency)
php cli/admin.php verify-link \
    --user=admin@example.org \
    --oauth-email=admin@gmail.com
```

### 8.2 Backup Tool

```bash
# Full database backup
php cli/backup.php --output=/backups/charity-cashbook-$(date +%Y%m%d).sql

# Backup specific charity (for data export requests)
php cli/backup.php --charity=5 --output=/exports/charity-5-export.sql
```

### 8.3 Maintenance Tasks

```bash
# Clean up expired tokens
php cli/maintenance.php clean-tokens

# Clean up old audit logs (respects retention policy)
php cli/maintenance.php clean-logs --older-than=7years

# Recalculate Gift Aid eligibility
php cli/maintenance.php recalc-giftaid --charity=5
```

---

## 9. Deployment

### 9.1 Requirements

- PHP 8.2+
- MariaDB 10.6+
- Apache with mod_rewrite or Nginx
- HTTPS certificate (Let's Encrypt recommended)
- Mail server or configured mail() function

### 9.2 Directory Permissions

```
/var/www/charity-cashbook/
├── public/           # Web-accessible, read-only
├── src/              # Not web-accessible
├── config/           # Not web-accessible, read-only
├── logs/             # Not web-accessible, writable
├── cache/            # Not web-accessible, writable
└── vendor/           # Not web-accessible, read-only
```

### 9.3 Configuration

```php
// config/config.php
return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'database' => getenv('DB_NAME') ?: 'charity_cashbook',
        'username' => getenv('DB_USER'),
        'password' => getenv('DB_PASS'),
    ],
    'app' => [
        'name' => 'Charity Cashbook',
        'url' => getenv('APP_URL'),
        'debug' => getenv('APP_DEBUG') === 'true',
    ],
    'session' => [
        'name' => 'charity_cashbook_session',
        'lifetime' => 86400,  // 24 hours max
    ],
    'encryption' => [
        'key' => getenv('ENCRYPTION_KEY'),  // For OAuth secrets
    ],
];
```

### 9.4 Apache Configuration

```apache
<VirtualHost *:443>
    ServerName cashbook.example.org
    DocumentRoot /var/www/charity-cashbook/public
    
    <Directory /var/www/charity-cashbook/public>
        AllowOverride All
        Require all granted
    </Directory>
    
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/cashbook.example.org/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/cashbook.example.org/privkey.pem
</VirtualHost>
```

### 9.5 Database Migrations

```bash
# Run migrations
php cli/migrate.php up

# Rollback last migration
php cli/migrate.php down

# Check status
php cli/migrate.php status
```

---

## 10. Testing Strategy

### 10.1 Test Types

| Type | Coverage | Tools |
|------|----------|-------|
| Unit tests | Services, repositories | PHPUnit |
| Integration tests | Database operations | PHPUnit + test database |
| Feature tests | Full request/response | PHPUnit + test client |
| Security tests | Auth, authorisation | Manual + automated |

### 10.2 Test Database

Tests use a separate database, reset before each test run:

```php
class TestCase {
    protected function setUp(): void {
        $this->db = new Database($testConfig);
        $this->db->execute(file_get_contents('migrations/schema.sql'));
        $this->db->execute(file_get_contents('tests/fixtures/base.sql'));
    }
}
```

### 10.3 Key Test Scenarios

**Authentication:**
- OAuth login success/failure
- Session expiry and re-authentication
- Account linking flow
- Cross-charity session isolation

**Authorisation:**
- Role-based access (admin vs standard vs readonly)
- Charity isolation (user A cannot see charity B data)
- Super-admin cannot access charity data

**Data Integrity:**
- Transaction audit trail completeness
- Soft delete behavior
- Gift Aid claim prevents transaction modification

---

## 11. Monitoring and Logging

### 11.1 Application Logs

```
/var/log/charity-cashbook/
├── app.log           # General application log
├── error.log         # Errors and exceptions
├── auth.log          # Authentication events
└── audit.log         # Audit trail (also in database)
```

### 11.2 Log Format

```
[2026-01-03 14:22:15] INFO: User login successful {"user_id":42,"provider":"google"} 
[2026-01-03 14:22:18] INFO: Transaction created {"charity_id":5,"transaction_id":1234,"user_id":42}
[2026-01-03 14:23:01] ERROR: Database connection failed {"exception":"PDOException","message":"..."}
```

### 11.3 Monitoring Endpoints

```
GET /health
    Response: { "status": "ok", "database": "ok", "version": "1.0.0" }

GET /service-admin/diagnostics  (super-admin only)
    Response: System statistics, no charity data
```

---

## 12. Future Considerations

### 12.1 Potential Enhancements

- Bank statement import (CSV parsing)
- Direct HMRC Gift Aid API integration
- Budget tracking module
- Document attachment storage
- Mobile-optimised views

### 12.2 Scalability Path

Current design is suitable for:
- Dozens of charities
- Thousands of transactions per charity per year
- Single server deployment

If scaling beyond this:
- Read replicas for reporting queries
- Redis for session storage
- Queue system for report generation

---

## Appendix A: Dependencies

```json
{
    "require": {
        "php": ">=8.2",
        "slim/slim": "^4.0",
        "slim/psr7": "^1.0",
        "twig/twig": "^3.0",
        "league/oauth2-client": "^2.0",
        "league/oauth2-google": "^4.0",
        "thenetworg/oauth2-azure": "^2.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0"
    }
}
```

---

## Appendix B: Document History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 Draft | Jan 2026 | - | Initial draft for review |
