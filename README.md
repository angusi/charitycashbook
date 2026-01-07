# Charity Cashbook

A web-based financial management application for small charities using receipts and payments accounting.

## Features

- **Transaction Recording**: Record income (receipts) and expenditure (payments)
- **Fund Accounting**: Support for general and restricted funds
- **Category Management**: Two-tier category system (reporting and operational)
- **Donor Management**: Track donors with Gift Aid declaration support
- **Gift Aid Tracking**: Manage Gift Aid claims including GASDS
- **Multi-Charity Support**: Single deployment supporting multiple charities
- **OAuth Authentication**: Support for Google, Microsoft, and generic OIDC providers
- **Role-Based Access**: Admin, Standard, and Read-only user roles
- **Comprehensive Audit Trail**: Full audit logging of all changes

## Requirements

- PHP 8.2+
- MariaDB 10.6+ or MySQL 8.0+
- Apache with mod_rewrite or Nginx
- Composer

## Installation

1. Clone the repository:
   ```bash
   git clone <repository-url> charity-cashbook
   cd charity-cashbook
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Configure the application:
   ```bash
   cp config/config.example.php config/config.php
   # Edit config/config.php with your settings
   ```

4. Generate an encryption key:
   ```bash
   php -r "echo 'ENCRYPTION_KEY=' . base64_encode(random_bytes(32)) . PHP_EOL;"
   ```

5. Create the database:
   ```sql
   CREATE DATABASE charity_cashbook CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

6. Run migrations:
   ```bash
   php cli/migrate.php up
   ```

7. Create the first super-admin:
   ```bash
   php cli/admin.php create-user --email=admin@example.org --name="Admin User" --super-admin
   ```

8. Configure your web server to point to the `public/` directory.

## Configuration

### Environment Variables

Set these in your environment or in `config/config.php`:

- `DB_HOST` - Database host (default: localhost)
- `DB_NAME` - Database name (default: charity_cashbook)
- `DB_USER` - Database username
- `DB_PASS` - Database password
- `APP_URL` - Base URL of the application
- `APP_DEBUG` - Enable debug mode (true/false)
- `ENCRYPTION_KEY` - Base64-encoded 32-byte encryption key

### OAuth Providers

OAuth providers must be configured before users can log in.

```bash
# List configured providers
php cli/setup-oauth.php list

# Add Google provider
php cli/setup-oauth.php add-google \
    --client-id=YOUR_CLIENT_ID \
    --client-secret=YOUR_CLIENT_SECRET

# Add Microsoft/Azure AD provider
php cli/setup-oauth.php add-microsoft \
    --client-id=YOUR_CLIENT_ID \
    --client-secret=YOUR_CLIENT_SECRET \
    --tenant-id=YOUR_TENANT_ID
```

**Google Setup:**
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a project and enable Google+ API
3. Go to APIs & Services > Credentials > Create OAuth 2.0 Client ID
4. Set authorized redirect URI to: `https://yourdomain.com/oauth/callback/PROVIDER_ID`
5. Copy Client ID and Client Secret

**Microsoft Setup:**
1. Go to [Azure Portal](https://portal.azure.com/)
2. Navigate to Azure Active Directory > App registrations
3. Create new registration
4. Add redirect URI: `https://yourdomain.com/oauth/callback/PROVIDER_ID`
5. Create a client secret under Certificates & secrets
6. Copy Application ID, Tenant ID, and secret value

Run `php cli/setup-oauth.php` for detailed instructions.

## Usage

### Super-Admin Tasks

Super-admins manage the service infrastructure:

- Create and configure charities
- Assign first admin users
- Configure OAuth providers
- Monitor system health

Super-admins cannot access charity financial data.

### Charity Admin Tasks

Charity admins have full control within their charity:

- Configure charity settings
- Manage users and roles
- Set up funds and categories
- Manage donors and Gift Aid
- Generate reports

### Standard User Tasks

Standard users can:

- Enter and edit transactions
- Link donations to donors
- View transactions and reports

### Read-Only User Tasks

Read-only users can:

- View transactions
- View reports

## CLI Tools

### Admin Tool

```bash
# List super-admins
php cli/admin.php list-superadmins

# Promote user to super-admin
php cli/admin.php promote-superadmin --user=admin@example.org

# Create user
php cli/admin.php create-user --email=user@example.org --name="User Name"
```

### Migration Tool

```bash
# Run migrations
php cli/migrate.php up

# Check status
php cli/migrate.php status

# Rollback
php cli/migrate.php down
```

### Maintenance Tool

```bash
# Clean expired sessions
php cli/maintenance.php clean-sessions

# Clean old logs
php cli/maintenance.php clean-logs --older-than=7years

# Show statistics
php cli/maintenance.php stats
```

## Directory Structure

```
charity-cashbook/
├── public/                 # Web root
│   ├── index.php          # Front controller
│   └── assets/            # CSS, JS, images
├── src/
│   ├── Auth/              # Authentication module
│   ├── Charity/           # Charity functionality
│   ├── Core/              # Shared infrastructure
│   ├── ServiceAdmin/      # Super-admin functionality
│   └── Views/             # Twig templates
├── cli/                   # Command-line tools
├── config/                # Configuration
├── migrations/            # Database migrations
├── tests/                 # Test suite
├── logs/                  # Application logs
└── cache/                 # Cache files
```

## Security

- All authentication via OAuth 2.0 (no passwords stored)
- Session tokens stored as hashes in database
- CSRF protection on all forms
- SQL injection prevention via prepared statements
- XSS prevention via Twig auto-escaping
- Complete audit trail of all changes
- PII access logging for donor data

## License

Proprietary - All rights reserved

## Support

Contact the system administrator for support.
