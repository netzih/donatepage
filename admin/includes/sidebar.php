<?php
/**
 * Admin Sidebar Component
 * Include this file in all admin pages to render the sidebar
 * 
 * Required before including:
 *   - $settings = getAllSettings();
 *   - $currentPage = 'dashboard' | 'donations' | 'campaigns' | 'settings' | 'payments' | 'emails' | 'civicrm'
 */

$orgName = $settings['org_name'] ?? 'Donation Platform';
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h2><?= h($orgName) ?></h2>
        <span>Admin Panel</span>
    </div>
    <nav class="sidebar-nav">
        <a href="<?= BASE_PATH ?>/admin"<?= ($currentPage ?? '') === 'dashboard' ? ' class="active"' : '' ?>>📊 Dashboard</a>
        <a href="<?= BASE_PATH ?>/admin/donations"<?= ($currentPage ?? '') === 'donations' ? ' class="active"' : '' ?>>💳 Donations</a>
        <a href="<?= BASE_PATH ?>/admin/subscriptions"<?= ($currentPage ?? '') === 'subscriptions' ? ' class="active"' : '' ?>>🔄 Subscriptions</a>
        <a href="<?= BASE_PATH ?>/admin/campaigns"<?= ($currentPage ?? '') === 'campaigns' ? ' class="active"' : '' ?>>📣 Campaigns</a>
        <a href="<?= BASE_PATH ?>/admin/settings"<?= ($currentPage ?? '') === 'settings' ? ' class="active"' : '' ?>>⚙️ Settings</a>
        <a href="<?= BASE_PATH ?>/admin/payments"<?= ($currentPage ?? '') === 'payments' ? ' class="active"' : '' ?>>💰 Payment Gateways</a>
        <a href="<?= BASE_PATH ?>/admin/emails"<?= ($currentPage ?? '') === 'emails' ? ' class="active"' : '' ?>>📧 Email Templates</a>
        <a href="<?= BASE_PATH ?>/admin/civicrm"<?= ($currentPage ?? '') === 'civicrm' ? ' class="active"' : '' ?>>🔗 CiviCRM</a>
        <hr>
        <a href="<?= BASE_PATH ?>/admin/logout">🚪 Logout</a>
    </nav>
</aside>
