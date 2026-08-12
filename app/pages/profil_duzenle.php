<?php
// app/pages/profil_duzenle.php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$current_user_id = $_SESSION['user_id'] ?? 1;
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_GET['id'] = $current_user_id;
}

include __DIR__ . '/kullanici_duzenle.php';
