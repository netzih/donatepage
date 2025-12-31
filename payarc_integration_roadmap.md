# PayArc Integration Roadmap

This document outlines the planned features and improvements for the PayArc integration, consolidating the payment UI, and enhancing digital wallet support.

## 1. Consolidated Payment UI
- **Unified Button**: Replace separate "Stripe", "PayPal", and "PayArc" buttons with a single "Continue to Payment" button.
- **Dynamic Gateway Loading**:
    - Prioritize gateways (e.g., PayArc > Stripe).
    - Load the appropriate payment form only after the user clicks "Continue".
- **Consistent Styling**: Ensure the primary button and back buttons have a premium, consistent design across both main and campaign pages.

## 2. PayArc Hosted Fields Integration
- **Securely Managed Fields**: Use PayArc's "Hosted Fields" to provide card inputs (Number, Expiry, CVV). This ensures sensitive data never touches your server, keeping you PCI compliant.
- **Tokenization**: The fields automatically securely tokenize the card info, providing a `PMT_ID` for the transaction.
- **Subscription Support**: Enable recurring monthly donations via PayArc (already partially implemented in `api/process-payarc.php`).

## 3. Digital Wallet Support (Apple Pay & Google Pay)
- **Capability Detection**: Automatically detect if the donor's browser/device supports Apple Pay or Google Pay.
- **Conditional Visibility**: Show wallet buttons only when available.
- **PayArc Integration**: Use PayArc's SDK to handle the wallet session and tokenization.
- **Domain Verification**: Remember to upload the domain association file (provided by PayArc) to `/.well-known/apple-developer-merchantid-domain-association`.

## 4. Technical Reliability & Security
- **Content Security Policy (CSP)**: Update headers to allow `js.payarc.com` and `api.payarc.com`.
- **Conditional Script Loading**: Only load the Stripe, PayPal, or PayArc SDKs if the gateway is enabled and configured in Admin.
- **Iframe Compatibility**: Maintain signed sessionless tokens for CSRF to ensure the form works when embedded as an iframe.
- **Cache Busting**: Append version or file timestamps to JS/CSS files to prevent stale content issues on live.

## 5. Admin Controls
- **Global Settings**: Enable/disable PayArc alongside Stripe and PayPal.
- **Campaign Overrides**: Allow specific campaigns to override global gateway availability.
- **Subscription Management**: Show PayArc subscriptions in the admin dashboard with cancellation support.
