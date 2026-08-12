<?php
// public/manifest.php - Dynamic Web App Manifest Generator for Eaprimus PWA
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'id' => '/?pwa=1',
    'name' => 'Eaprimus IT Asset & Ticket System',
    'short_name' => 'Eaprimus',
    'description' => 'Kurumsal BT Varlık ve Canlı Destek Yönetim Sistemi',
    'start_url' => 'anasayfa',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#0f172a',
    'theme_color' => '#2563eb',
    'icons' => [
        [
            'src' => 'icon-192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ],
        [
            'src' => 'icon-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ]
    ]
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
exit;
