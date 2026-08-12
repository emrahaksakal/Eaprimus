<?php
$is_cihaz_izle = (strpos($_SERVER['REQUEST_URI'], '/cihaz/izle/') !== false);
$lang_is_tr = (($_SESSION['lang'] ?? 'tr') === 'tr');
$app_version = defined('EAPRIMUS_VERSION') ? EAPRIMUS_VERSION : 'v1.0.0';
?>
<?php if (!$is_cihaz_izle): ?>
<footer class="main-footer py-3 border-top text-center" style="font-size: 13px;">
  <div class="container-fluid">
    <span class="text-muted">
      <?= $lang_is_tr ? 'Tüm Hakları Saklıdır' : 'All rights reserved' ?> &copy; <?= date('Y') ?>
      <a href="https://www.eaprimus.com" target="_blank" rel="noopener noreferrer" class="text-primary font-weight-bold mx-1" style="text-decoration: none;">Eaprimus</a>
      <span class="mx-2 text-muted">&bull;</span>
      <span class="text-muted font-weight-bold"><?= $lang_is_tr ? 'Sürüm' : 'Version' ?> <?= $app_version ?></span>
    </span>
  </div>
</footer>
<?php endif; ?>
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
<script>
// Global AJAX CSRF header for jQuery-based requests
(function(){
  try {
    var t = document.querySelector('meta[name="csrf-token"]');
    if (!t) return;
    var token = t.getAttribute('content');
    if (!token) return;
    if (window.jQuery) {
      $.ajaxSetup({
        headers: { 'X-CSRF-Token': token }
      });
    }
  } catch (e) {
    // fail silently
  }
})();
</script>