<?php
/**
 * GiveWP Integration Helper Functions
 * 
 * Simple helper functions for the GiveWP webhook integration.
 */

require_once __DIR__ . '/functions.php';

/**
 * Check if GiveWP integration is enabled
 */
function givewp_is_enabled(): bool {
    return getSetting('givewp_enabled') === '1';
}

/**
 * Get the webhook secret for validation
 */
function givewp_get_webhook_secret(): string {
    return getSetting('givewp_webhook_secret') ?? '';
}
