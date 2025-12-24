# Donation Platform

A full-featured PHP donation platform with Stripe and PayPal integration, admin backend, and customizable email templates.

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Composer
- SSL certificate (required for payment processing)

## Installation

1. **Upload files** to your web server

2. **Install dependencies**:
   ```bash
   composer install
   ```

3. **Create database** and import the schema:
   ```bash
   mysql -u username -p database_name < database/schema.sql
   ```

4. **Configure the application**:
   Edit `includes/config.php` with your database credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'your_database');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   define('APP_URL', 'https://yourdomain.com');
   ```

5. **Set file permissions**:
   ```bash
   chmod 755 assets/uploads
   ```

6. **Access admin panel**:
   Go to `https://yourdomain.com/admin/login.php`
   - Default username: `admin`
   - Default password: `admin123`
   - **⚠️ Change this immediately!**

## Admin Configuration

1. **Settings**: Configure organization name, tagline, logo, and background image
2. **Payment Gateways**: Add your Stripe and PayPal API keys
3. **Email Templates**: Set up SMTP and customize donor receipt emails

## Stripe Webhook (Optional but Recommended)

For reliable payment tracking, set up a Stripe webhook:

1. Go to [Stripe Dashboard > Webhooks](https://dashboard.stripe.com/webhooks)
2. Add endpoint: `https://yourdomain.com/api/webhook.php`
3. Select events: `checkout.session.completed`, `invoice.payment_succeeded`
4. Copy the signing secret and add it to your settings

## Image Recommendations

| Image | Size | Format |
|-------|------|--------|
| Logo | 200x60 px | PNG (transparent) |
| Background | 1920x1080 px | JPEG (< 500KB) |

## File Structure

```
├── admin/              # Admin backend
├── api/                # Payment processing endpoints
├── assets/             # CSS, JS, uploads
├── database/           # SQL schema
├── includes/           # Core PHP files
├── index.php           # Public donation page
├── success.php         # Thank you page
└── composer.json       # Dependencies
```

## Security Notes

- Always use HTTPS
- Change the default admin password
- Keep API keys secure
- Set proper file permissions
