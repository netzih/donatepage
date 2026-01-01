<?php
/**
 * Campaign Functions
 * Handles campaign CRUD operations and stats
 */

require_once __DIR__ . '/db.php';

/**
 * Get campaign by slug with stats and matchers
 */
function getCampaignBySlug($slug) {
    if (empty($slug)) {
        return null;
    }
    
    try {
        $campaign = db()->fetch(
            "SELECT * FROM campaigns WHERE slug = ?",
            [$slug]
        );
        
        if (!$campaign) {
            return null;
        }
        
        return enrichCampaign($campaign);
    } catch (Exception $e) {
        // Table may not exist yet - return null gracefully
        error_log("Campaign lookup error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get campaign by ID with stats and matchers
 */
function getCampaignById($id) {
    if (empty($id)) {
        return null;
    }
    
    try {
        $campaign = db()->fetch(
            "SELECT * FROM campaigns WHERE id = ?",
            [(int)$id]
        );
        
        if (!$campaign) {
            return null;
        }
        
        return enrichCampaign($campaign);
    } catch (Exception $e) {
        error_log("Campaign lookup error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get all campaigns with stats
 */
function getAllCampaigns($includeInactive = false) {
    try {
        $sql = "SELECT * FROM campaigns";
        $params = [];
        
        if (!$includeInactive) {
            $sql .= " WHERE is_active = 1";
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $campaigns = db()->fetchAll($sql, $params);
        
        return array_map('enrichCampaign', $campaigns);
    } catch (Exception $e) {
        error_log("Campaigns list error: " . $e->getMessage());
        return [];
    }
}

/**
 * Enrich campaign with stats and matchers
 */
function enrichCampaign($campaign) {
    $id = $campaign['id'];
    
    // Convert boolean and numeric fields FIRST
    $campaign['matching_enabled'] = (bool)$campaign['matching_enabled'];
    $campaign['is_active'] = (bool)$campaign['is_active'];
    $campaign['matching_multiplier'] = (int)($campaign['matching_multiplier'] ?? 2);
    $campaign['goal_amount'] = (float)($campaign['goal_amount'] ?? 0);
    
    // Get donation stats (may fail if campaign_id column doesn't exist)
    try {
        // Calculate raised amount based on is_matched status
        // If is_matched = 1, amount is multiplied by campaign multiplier
        $stats = db()->fetch(
            "SELECT 
                SUM(CASE 
                    WHEN is_matched = 1 THEN amount * ? 
                    ELSE amount 
                END) as matched_raised,
                SUM(amount) as base_raised,
                COUNT(DISTINCT donor_id) as unique_donors,
                COUNT(DISTINCT CASE WHEN donor_id IS NULL THEN donor_email END) as email_only_donors
            FROM donations 
            WHERE campaign_id = ? AND status = 'completed'",
            [$campaign['matching_multiplier'], $id]
        );
        $campaign['raised_amount'] = (float)($stats['matched_raised'] ?? 0);
        $campaign['base_amount'] = (float)($stats['base_raised'] ?? 0);
        $campaign['donor_count'] = (int)($stats['unique_donors'] ?? 0) + (int)($stats['email_only_donors'] ?? 0);
    } catch (Exception $e) {
        $campaign['raised_amount'] = 0;
        $campaign['base_amount'] = 0;
        $campaign['donor_count'] = 0;
    }
    
    // Matched total is already calculated in SQL as matched_raised
    $campaign['matched_total'] = $campaign['raised_amount'];
    
    // Get matchers
    try {
        $campaign['matchers'] = db()->fetchAll(
            "SELECT id, name, image, color, amount_pledged, display_order 
            FROM campaign_matchers 
            WHERE campaign_id = ? 
            ORDER BY display_order ASC, id ASC",
            [$id]
        );
    } catch (Exception $e) {
        $campaign['matchers'] = [];
    }
    
    return $campaign;
}

/**
 * Create a new campaign
 */
function createCampaign($data) {
    $slug = createSlug($data['title']);
    
    // Ensure unique slug
    $existingSlug = db()->fetch("SELECT id FROM campaigns WHERE slug = ?", [$slug]);
    if ($existingSlug) {
        $slug = $slug . '-' . time();
    }
    
    return db()->insert('campaigns', [
        'slug' => $slug,
        'title' => $data['title'],
        'description' => $data['description'] ?? '',
        'header_image' => $data['header_image'] ?? '',
        'logo_image' => $data['logo_image'] ?? '',
        'goal_amount' => (float)($data['goal_amount'] ?? 0),
        'matching_enabled' => isset($data['matching_enabled']) ? 1 : 0,
        'matching_multiplier' => (int)($data['matching_multiplier'] ?? 2),
        'start_date' => $data['start_date'],
        'end_date' => $data['end_date'],
        'is_active' => isset($data['is_active']) ? 1 : 0,
        'matchers_section_title' => $data['matchers_section_title'] ?? 'OUR GENEROUS MATCHERS',
        'matchers_label_singular' => $data['matchers_label_singular'] ?? 'MATCHER',
        'preset_amounts' => !empty($data['preset_amounts']) ? $data['preset_amounts'] : null,
        'default_amount' => !empty($data['default_amount']) ? (float)$data['default_amount'] : null
    ]);
}

/**
 * Update a campaign
 */
function updateCampaign($id, $data) {
    $updateData = [];
    
    if (isset($data['title'])) {
        $updateData['title'] = $data['title'];
    }
    if (isset($data['description'])) {
        $updateData['description'] = $data['description'];
    }
    if (isset($data['header_image'])) {
        $updateData['header_image'] = $data['header_image'];
    }
    if (isset($data['logo_image'])) {
        $updateData['logo_image'] = $data['logo_image'];
    }
    if (isset($data['goal_amount'])) {
        $updateData['goal_amount'] = (float)$data['goal_amount'];
    }
    if (array_key_exists('matching_enabled', $data)) {
        $updateData['matching_enabled'] = $data['matching_enabled'] ? 1 : 0;
    }
    if (isset($data['matching_multiplier'])) {
        $updateData['matching_multiplier'] = (int)$data['matching_multiplier'];
    }
    if (isset($data['start_date'])) {
        $updateData['start_date'] = $data['start_date'];
    }
    if (isset($data['end_date'])) {
        $updateData['end_date'] = $data['end_date'];
    }
    if (array_key_exists('is_active', $data)) {
        $updateData['is_active'] = $data['is_active'] ? 1 : 0;
    }
    if (isset($data['matchers_section_title'])) {
        $updateData['matchers_section_title'] = $data['matchers_section_title'];
    }
    if (isset($data['matchers_label_singular'])) {
        $updateData['matchers_label_singular'] = $data['matchers_label_singular'];
    }
    if (array_key_exists('preset_amounts', $data)) {
        $updateData['preset_amounts'] = !empty($data['preset_amounts']) ? $data['preset_amounts'] : null;
    }
    if (array_key_exists('default_amount', $data)) {
        $updateData['default_amount'] = !empty($data['default_amount']) ? (float)$data['default_amount'] : null;
    }
    
    if (!empty($updateData)) {
        db()->update('campaigns', $updateData, 'id = ?', [(int)$id]);
    }
}

/**
 * Delete a campaign
 */
function deleteCampaign($id) {
    // Matchers will be deleted automatically due to ON DELETE CASCADE
    db()->execute("DELETE FROM campaigns WHERE id = ?", [(int)$id]);
}

/**
 * Add a matcher to a campaign
 */
function addMatcher($campaignId, $data) {
    $maxOrder = db()->fetch(
        "SELECT MAX(display_order) as max_order FROM campaign_matchers WHERE campaign_id = ?",
        [$campaignId]
    );
    $order = ($maxOrder['max_order'] ?? 0) + 1;
    
    return db()->insert('campaign_matchers', [
        'campaign_id' => (int)$campaignId,
        'name' => $data['name'],
        'image' => $data['image'] ?? null,
        'color' => $data['color'] ?? null,
        'amount_pledged' => (float)($data['amount_pledged'] ?? 0),
        'display_order' => $order
    ]);
}

/**
 * Update a matcher
 */
function updateMatcher($matcherId, $data) {
    $updateData = [];
    
    if (isset($data['name'])) {
        $updateData['name'] = $data['name'];
    }
    if (array_key_exists('image', $data)) {
        $updateData['image'] = $data['image'];
    }
    if (isset($data['amount_pledged'])) {
        $updateData['amount_pledged'] = (float)$data['amount_pledged'];
    }
    if (isset($data['display_order'])) {
        $updateData['display_order'] = (int)$data['display_order'];
    }
    if (array_key_exists('color', $data)) {
        $updateData['color'] = $data['color'];
    }
    
    if (!empty($updateData)) {
        db()->update('campaign_matchers', $updateData, 'id = ?', [(int)$matcherId]);
    }
}

/**
 * Reorder a matcher
 * $direction: 'up' | 'down'
 */
function reorderMatcher($matcherId, $direction) {
    $matcher = db()->fetch("SELECT id, campaign_id, display_order FROM campaign_matchers WHERE id = ?", [(int)$matcherId]);
    if (!$matcher) return false;

    $currentOrder = $matcher['display_order'];
    $campaignId = $matcher['campaign_id'];

    if ($direction === 'up') {
        $other = db()->fetch(
            "SELECT id, display_order FROM campaign_matchers 
            WHERE campaign_id = ? AND display_order < ? 
            ORDER BY display_order DESC LIMIT 1",
            [$campaignId, $currentOrder]
        );
    } else {
        $other = db()->fetch(
            "SELECT id, display_order FROM campaign_matchers 
            WHERE campaign_id = ? AND display_order > ? 
            ORDER BY display_order ASC LIMIT 1",
            [$campaignId, $currentOrder]
        );
    }

    if ($other) {
        $otherOrder = $other['display_order'];
        db()->update('campaign_matchers', ['display_order' => $otherOrder], 'id = ?', [$matcher['id']]);
        db()->update('campaign_matchers', ['display_order' => $currentOrder], 'id = ?', [$other['id']]);
        return true;
    }

    return false;
}

/**
 * Remove a matcher
 */
function removeMatcher($matcherId) {
    db()->execute("DELETE FROM campaign_matchers WHERE id = ?", [(int)$matcherId]);
}

/**
 * Get campaign stats
 */
function getCampaignStats($campaignId) {
    $campaign = getCampaignById($campaignId);
    
    if (!$campaign) {
        return null;
    }
    
    return [
        'raised_amount' => $campaign['raised_amount'],
        'donor_count' => $campaign['donor_count'],
        'matched_total' => $campaign['matched_total'],
        'goal_amount' => $campaign['goal_amount'],
        'progress_percent' => $campaign['goal_amount'] > 0 
            ? min(100, round(($campaign['raised_amount'] / $campaign['goal_amount']) * 100))
            : 0
    ];
}

/**
 * Create URL-friendly slug from title
 */
function createSlug($title) {
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    return $slug;
}
