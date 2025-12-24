<?php
/**
 * Admin - Campaign Management (Placeholder)
 * Agent B will implement full functionality
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$settings = getAllSettings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaigns - Admin</title>
    <link rel="stylesheet" href="admin-style.css">
    <style>
        .coming-soon {
            text-align: center;
            padding: 60px 20px;
        }
        .coming-soon-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        .coming-soon h2 {
            font-size: 28px;
            margin-bottom: 12px;
            color: #333;
        }
        .coming-soon p {
            color: #666;
            max-width: 500px;
            margin: 0 auto 24px;
            line-height: 1.6;
        }
        .feature-list {
            display: flex;
            gap: 24px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 32px;
        }
        .feature-item {
            background: #f8f9fa;
            padding: 20px 24px;
            border-radius: 12px;
            text-align: left;
            max-width: 280px;
        }
        .feature-item h3 {
            font-size: 16px;
            margin: 0 0 8px;
            color: #20a39e;
        }
        .feature-item p {
            font-size: 13px;
            margin: 0;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php $currentPage = 'campaigns'; include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="content-header">
                <h1>Campaigns</h1>
                <p>Create and manage fundraising campaigns with matching</p>
            </header>
            
            <section class="card coming-soon">
                <div class="coming-soon-icon">🚧</div>
                <h2>Coming Soon</h2>
                <p>Campaign management is currently under development. This feature will allow you to create matching campaigns with dedicated landing pages.</p>
                
                <div class="feature-list">
                    <div class="feature-item">
                        <h3>📣 Create Campaigns</h3>
                        <p>Set up campaigns with goals, matching multipliers, and custom branding</p>
                    </div>
                    <div class="feature-item">
                        <h3>👥 Manage Matchers</h3>
                        <p>Add sponsors who match donations and display them on campaign pages</p>
                    </div>
                    <div class="feature-item">
                        <h3>📊 Track Progress</h3>
                        <p>Monitor campaign progress, donations, and matching in real-time</p>
                    </div>
                </div>
                
                <p style="margin-top: 32px;">
                    <strong>Preview:</strong> <a href="/campaign?slug=test" target="_blank" style="color: #20a39e;">View sample campaign page →</a>
                </p>
            </section>
        </main>
    </div>
</body>
</html>
