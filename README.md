# Highnoon Product Documents Portal

A secure, enterprise-grade internal web application built for managing, searching, and viewing pharmaceutical product documentation (inserts, leaflets, and manuals). The portal integrates Microsoft Entra ID (Azure SSO) for corporate authentication, role-based access control (RBAC), zero-cache inline PDF rendering, automated QR code generation, and update audit logging.

---

## Table of Contents
- [Architecture & Tech Stack](#architecture--tech-stack)
- [Key Features](#key-features)
- [Database Schema](#database-schema)
- [Security & Audit Control](#security--audit-control)
- [Environment Configuration](#environment-configuration)
- [Local Development Setup](#local-development-setup)
- [Production Deployment (Hostinger)](#production-deployment-hostinger)
- [File Structure](#file-structure)

---

## Architecture & Tech Stack

- **Backend Language:** PHP 8.3 (Native / Standalone)
- **Database:** MySQL / MariaDB
- **Authentication:** Microsoft Entra ID (OAuth 2.0 / OpenID Connect via cURL)
- **Frontend:** Vanilla JS, CSS3, HTML5, jsPDF (for QR PDF exports)
- **Web Server:** Apache / Nginx (PHP-FPM)

---

## Key Features

### 1. Corporate Azure SSO Authentication
- Integrated with Microsoft Entra ID (Azure App Registration).
- Secure authorization code grant flow exchanging tokens to authorize corporate emails.
- Automatic session destruction and cookie clearance upon logout.

### 2. Role-Based Access Control (RBAC)
- **Standard Users:** Authorized to search, view PDF documents, inspect metadata, and download dynamic QR codes.
- **Superusers (Admins):** Access to the User Management dashboard (`add_user.php`) to add or edit user privileges, as well as inline document modification rights via modal interfaces on the main portal.

### 3. Zero-Cache Inline PDF Viewer
- Solves browser PDF caching issues by converting fetched PDF streams into Base64 Data URIs (`data:application/pdf;base64,...`) on the client side.
- Appends dynamic cache-busting timestamp queries (`?cache_bust=TIMESTAMP`) to ensure immediate rendering of newly updated documents without requiring manual browser cache clears.

### 4. Dynamic QR Code Generation & Multi-Format Export
- Automatically generates QR codes pointing to document destination links via vector/image APIs.
- Built-in canvas conversion allowing users to download QR codes in PNG, JPG, or PDF formats.

### 5. Audit Logging & Compliance
- Tracks metadata changes on every record modification inside the `product_docs` table:
  - `Updated_By`: Email/Username of the superuser who made the modification.
  - `Updated_On`: Server timestamp of the change (`Y-m-d H:i:s`).
  - `Last_Access_IP`: Client IP address captured during update (with proxy/Cloudflare header support).

---

## Database Schema

### Table: `users`
| Column | Type | Constraints | Description |
| :--- | :--- | :--- | :--- |
| `id` | INT | PRIMARY KEY, AUTO_INCREMENT | Unique user identifier |
| `username` | VARCHAR(100) | NOT NULL | User's full name |
| `useremail` | VARCHAR(150) | UNIQUE, NOT NULL | Azure SSO matching email |
| `superuser` | TINYINT(1) | DEFAULT 0 | `1` for Admin, `0` for Standard User |

### Table: `product_docs`
| Column | Type | Constraints | Description |
| :--- | :--- | :--- | :--- |
| `id` | INT | PRIMARY KEY, AUTO_INCREMENT | Unique document identifier |
| `Doc_Product` | VARCHAR(255) | NOT NULL | Product/Medicine name |
| `Doc_Type` | VARCHAR(100) | NULLABLE | Document type classification |
| `Doc_Description` | TEXT | NULLABLE | Product description |
| `Folder_Path` | VARCHAR(255) | NULLABLE | Storage folder path |
| `TextURL` | VARCHAR(255) | NULLABLE | Display link text |
| `Link` | TEXT | NOT NULL | PDF file location path or URL |
| `QR_Code` | TEXT | NULLABLE | Cached QR code API URL |
| `Updated_By` | VARCHAR(100) | NULLABLE | Email of the last updating user |
| `Updated_On` | DATETIME | NULLABLE | Timestamp of the last update |
| `Last_Access_IP` | VARCHAR(45) | NULLABLE | IPv4/IPv6 address of the updater |

---

## Security & Audit Control

- **SQL Injection Prevention:** All database operations utilize MySQLi prepared statements with parameter binding (`prepare()`, `bind_param()`).
- **XSS Protection:** Output sanitization using `htmlspecialchars()` across all user-rendered templates.
- **Sensitive File Locking:** Protection of internal environment configurations (`.env`), SQL backups, and dependency manifests via `.htaccess` access restrictions.

---

## Environment Configuration

Create a `.env` file in your root project directory with the following variables:

```ini
AZURE_TENANT_ID="your-azure-tenant-id"
AZURE_CLIENT_ID="your-azure-client-id"
AZURE_CLIENT_SECRET="your-azure-client-secret-value"
AZURE_REDIRECT_URI="https://your-domain.com/microsoft_sso/callback/"
```

---

## Local Development Setup

### Prerequisites

- PHP 8.3 or higher with `php-curl` and `php-mysqli` extensions enabled.
- MySQL Server (Native or Docker instance).

### Installation Steps

**1. Clone the repository:**
```bash
git clone https://github.com/your-username/highnoon-doc-portal.git
cd highnoon-doc-portal
```

**2. Install PHP cURL Extension (if missing):**
```bash
sudo apt update
sudo apt install php8.3-curl
```

**3. Import Database Schema:**

Import your database export file into MySQL:
```bash
mysql -u root -p pharmacy_db < live_pharmacy_db.sql
```

**4. Configure Database Connection:**

Update `db.php` with your database credentials:
```php
$host     = "localhost";
$username = "root";
$password = "your_password";
$dbname   = "pharmacy_db";
```

**5. Start PHP Development Server:**
```bash
php -S localhost:8000
```

**6. Access Application:**

Navigate to `http://localhost:8000/login.php` in your browser.

---

## Production Deployment (Hostinger)

1. Upload project files to the `public_html/` directory via FTP or Hostinger File Manager.
2. Ensure the `.htaccess` file is created in `public_html/` to block public access to `.env`:

```apache
<FilesMatch "^\.env|composer\.(json|lock)|.*\.sql">
    Order allow,deny
    Deny from all
</FilesMatch>
Options -Indexes
```

3. Update `db.php` with your live Hostinger MySQL credentials.
4. Add your production callback URL (`https://your-domain.com/microsoft_sso/callback/`) to the Redirect URIs list under your Microsoft Entra ID App Registration in Azure Portal.

---

## File Structure

```text
public_html/
├── .env                    # Azure credentials configuration (Protected)
├── .htaccess               # Web server security & access rules
├── db.php                  # Database connection initialization
├── index.php               # Main document viewer, search & superuser edit portal
├── login.php               # Login gateway & Azure OAuth trigger
├── logout.php              # Session destruction & cookie reset
├── add_user.php            # Superuser management panel (Add/Edit users)
└── microsoft_sso/
    └── callback/
        └── index.php       # OAuth callback receiver & token exchange handler
```