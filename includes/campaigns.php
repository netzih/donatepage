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
    
    // Get donation stats (may fail if campaign_id column doesn't exist)
    try {
        $stats = db()->fetch(
            "SELECT 
                COALESCE(SUM(amount), 0) as raised_amount,
                COUNT(*) as donor_count
            FROM donations 
            WHERE campaign_id = ? AND status = 'completed'",
            [$id]
        );
        $campaign['raised_amount'] = (float)($stats['raised_amount'] ?? 0);
        $campaign['donor_count'] = (int)($stats['donor_count'] ?? 0);
    } catch (Exception $e) {
        $campaign['raised_amount'] = 0;
        $campaign['donor_count'] = 0;
    }
    
    // Calculate matched total
    if ($campaign['matching_enabled']) {
        $campaign['matched_total'] = $campaign['raised_amount'] * $campaign['matching_multiplier'];
    } else {
        $campaign['matched_total'] = $campaign['raised_amount'];
    }
    
    // Get matchers
    try {
        $campaign['matchers'] = db()->fetchAll(
            "SELECT id, name, image, amount_pledged, display_order 
            FROM campaign_matchers 
            WHERE campaign_id = ? 
            ORDER BY display_order ASC, id ASC",
            [$id]
        );
    } catch (Exception $e) {
        $campaign['matchers'] = [];
    }
    
    // Convert boolean fields
    $campaign['matching_enabled'] = (bool)$campaign['matching_enabled'];
    $campaign['is_active'] = (bool)$campaign['is_active'];
    $campaign['matching_multiplier'] = (int)$campaign['matching_multiplier'];
    $campaign['goal_amount'] = (float)$campaign['goal_amount'];
    
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
        'goal_amount' => (float)($data['goal_amount'] ?? 0),
        'matching_enabled' => isset($data['matching_enabled']) ? 1 : 0,
        'matching_multiplier' => (int)($data['matching_multiplier'] ?? 2),
        'start_date' => $data['start_date'],
        'end_date' => $data['end_date'],
        'is_active' => isset($data['is_active']) ? 1 : 0
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
    
    if (!empty($updateData)) {
        db()->update('campaign_matchers', $updateData, 'id = ?', [(int)$matcherId]);
    }
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
