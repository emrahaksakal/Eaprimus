<?php
// manifest.php - Root & Public Web App Manifest Generator
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'name' => 'Eaprimus IT Asset & Ticket System',
    'short_name' => 'Eaprimus',
    'description' => 'Enterprise IT Asset Management & Live Support Ticket System',
    'start_url' => 'anasayfa',
    'display' => 'standalone',
    'background_color' => '#0f172a',
    'theme_color' => '#2563eb',
    'icons' => [
        [
            'src' => 'public/favicon.png',
            'sizes' => '192x192',
            'type' => 'image/png'
        ],
        [
            'src' => 'public/favicon.png',
            'sizes' => '512x512',
            'type' => 'image/png'
        ]
    ]
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;
