<?php
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$base_dir = rtrim(str_replace('/public', '', dirname($script_name)), '/\\');
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$request_path = parse_url($request_uri, PHP_URL_PATH);
if ($base_dir !== '' && strpos($request_path, $base_dir) === 0) {
    $request_path = substr($request_path, strlen($base_dir));
}
$request_path = trim($request_path, '/');

if ($request_path !== '') {
    // 1. varliklar/([^/]+)/deleted
    if (preg_match('#^(varliklar|assets)/([^/]+)/deleted/?$#i', $request_path, $matches)) {
        $_GET['route'] = 'varliklar';
        $_GET['view'] = $matches[2];
        $_GET['view_deleted'] = 1;
        require __DIR__ . '/dashboard.php';
        exit;
    }
    // 2. varliklar/deleted (fallback for assets)
    if (preg_match('#^(varliklar|assets)/deleted/?$#i', $request_path)) {
        $_GET['route'] = 'varliklar';
        $_GET['view'] = 'assets';
        $_GET['view_deleted'] = 1;
        require __DIR__ . '/dashboard.php';
        exit;
    }
    // 3. kullanici-listele/deleted
    if (preg_match('#^(kullanici-listele|user-list)/deleted/?$#i', $request_path)) {
        $_GET['route'] = 'kullanici_listele';
        $_GET['view_deleted'] = 1;
        require __DIR__ . '/dashboard.php';
        exit;
    }
    // 4. download_agent / download_agent_linux fallback
    if (preg_match('#download_agent_linux\.php$#i', $request_path)) {
        require __DIR__ . '/ajax/download_agent_linux.php';
        exit;
    }
    if (preg_match('#download_agent\.php$#i', $request_path)) {
        require __DIR__ . '/ajax/download_agent.php';
        exit;
    }
}

if (file_exists(__DIR__ . '/../app/config/installed.lock')) {
    header("Location: " . $base_dir . "/giris");
    exit;
} else {
    header("Location: " . $base_dir . "/install/");
    exit;
}
