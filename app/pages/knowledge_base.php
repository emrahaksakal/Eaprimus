<?php
require_once __DIR__ . '/../includes/session.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$isTr = ($_SESSION['lang'] ?? 'tr') === 'tr';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kb_action'])) {
    if ($_POST['kb_action'] === 'save') {
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $category_id = intval($_POST['category_id']);
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE knowledge_base SET title = ?, content = ?, category_id = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$title, $content, $category_id, $id, CURRENT_TENANT_ID]);
            $msg = $isTr ? 'Makale güncellendi.' : 'Article updated.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO knowledge_base (tenant_id, category_id, title, content, author_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([CURRENT_TENANT_ID, $category_id, $title, $content, $_SESSION['user_id']]);
            $msg = $isTr ? 'Makale eklendi.' : 'Article added.';
        }
        $action = 'list';
    } elseif ($_POST['kb_action'] === 'delete') {
        $id = intval($_POST['id']);
        $stmt = $pdo->prepare("DELETE FROM knowledge_base WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, CURRENT_TENANT_ID]);
        $msg = $isTr ? 'Makale silindi.' : 'Article deleted.';
        $action = 'list';
    } elseif ($_POST['kb_action'] === 'add_cat') {
        $name = trim($_POST['cat_name']);
        if($name) {
            $stmt = $pdo->prepare("INSERT INTO kb_categories (tenant_id, name) VALUES (?, ?)");
            $stmt->execute([CURRENT_TENANT_ID, $name]);
        }
    }
}

if ($action === 'list') {
    $stmt = $pdo->prepare("SELECT kb.*, kbc.name as category_name, u.ad_soyad as author_name FROM knowledge_base kb LEFT JOIN kb_categories kbc ON kb.category_id = kbc.id LEFT JOIN users u ON kb.author_id = u.id WHERE kb.tenant_id = ? ORDER BY kb.created_at DESC");
    $stmt->execute([CURRENT_TENANT_ID]);
    $articles = $stmt->fetchAll();
    
    $stmtC = $pdo->prepare("SELECT * FROM kb_categories WHERE tenant_id = ?");
    $stmtC->execute([CURRENT_TENANT_ID]);
    $categories = $stmtC->fetchAll();
} elseif ($action === 'edit' || $action === 'add') {
    $article = ['id'=>0, 'title'=>'', 'content'=>'', 'category_id'=>0];
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM knowledge_base WHERE id = ? AND tenant_id = ?");
        $stmt->execute([intval($_GET['id']), CURRENT_TENANT_ID]);
        $article = $stmt->fetch() ?: $article;
    }
    $stmtC = $pdo->prepare("SELECT * FROM kb_categories WHERE tenant_id = ?");
    $stmtC->execute([CURRENT_TENANT_ID]);
    $categories = $stmtC->fetchAll();
} elseif ($action === 'view' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("UPDATE knowledge_base SET views = views + 1 WHERE id = ? AND tenant_id = ?");
    $stmt->execute([intval($_GET['id']), CURRENT_TENANT_ID]);
    
    $stmt = $pdo->prepare("SELECT kb.*, kbc.name as category_name, u.ad_soyad as author_name FROM knowledge_base kb LEFT JOIN kb_categories kbc ON kb.category_id = kbc.id LEFT JOIN users u ON kb.author_id = u.id WHERE kb.id = ? AND kb.tenant_id = ?");
    $stmt->execute([intval($_GET['id']), CURRENT_TENANT_ID]);
    $article = $stmt->fetch();
}
?>
<div class="container-fluid pt-4 px-4">
    <?php if($msg): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= htmlspecialchars($msg) ?>
        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    </div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0 font-weight-bold text-dark"><i class="fas fa-book-open mr-2 text-primary"></i> <?= $isTr ? 'Bilgi Bankası' : 'Knowledge Base' ?></h3>
            <p class="text-muted mb-0"><?= $isTr ? 'Çözüm makaleleri ve self-servis dökümanlar' : 'Solution articles and self-service documents' ?></p>
        </div>
        <div>
            <button class="btn btn-outline-secondary font-weight-bold shadow-sm mr-2" data-toggle="modal" data-target="#catModal"><i class="fas fa-tags mr-1"></i><?= $isTr ? 'Kategoriler' : 'Categories' ?></button>
            <a href="?route=knowledge_base&action=add" class="btn btn-primary font-weight-bold shadow-sm"><i class="fas fa-plus-circle mr-2"></i><?= $isTr ? 'Yeni Makale' : 'New Article' ?></a>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius:15px;">
        <div class="card-body p-4">
            <?php if(empty($articles)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-book text-muted mb-3" style="font-size: 48px; opacity:0.5;"></i>
                    <h5 class="text-muted"><?= $isTr ? 'Henüz hiç makale eklenmemiş.' : 'No articles added yet.' ?></h5>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach($articles as $a): ?>
                        <div class="col-md-4 mb-4">
                            <div class="card h-100 border shadow-sm" style="border-radius:12px; transition: transform 0.2s;">
                                <div class="card-body d-flex flex-column">
                                    <span class="badge badge-info mb-2 align-self-start"><?= htmlspecialchars($a['category_name']??'Genel') ?></span>
                                    <h5 class="font-weight-bold"><a href="?route=knowledge_base&action=view&id=<?= $a['id'] ?>" class="text-dark"><?= htmlspecialchars($a['title']) ?></a></h5>
                                    <p class="text-muted small mb-3"><?= mb_substr(strip_tags($a['content']), 0, 100) ?>...</p>
                                    <div class="d-flex justify-content-between align-items-center mt-auto">
                                        <small class="text-muted"><i class="fas fa-user mr-1"></i> <?= htmlspecialchars($a['author_name']) ?></small>
                                        <small class="text-muted"><i class="fas fa-eye mr-1"></i> <?= $a['views'] ?> views</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Category Modal -->
    <div class="modal fade" id="catModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="kb_action" value="add_cat">
                    <div class="modal-header">
                        <h5 class="modal-title"><?= $isTr ? 'Kategori Ekle' : 'Add Category' ?></h5>
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                    </div>
                    <div class="modal-body">
                        <input type="text" name="cat_name" class="form-control" required placeholder="<?= $isTr ? 'Kategori Adı' : 'Category Name' ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary"><?= $isTr ? 'Ekle' : 'Add' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php elseif ($action === 'view' && !empty($article)): ?>
    <div class="mb-4">
        <a href="?route=knowledge_base" class="btn btn-light shadow-sm"><i class="fas fa-arrow-left mr-2"></i><?= $isTr ? 'Geri Dön' : 'Go Back' ?></a>
        <a href="?route=knowledge_base&action=edit&id=<?= $article['id'] ?>" class="btn btn-warning shadow-sm ml-2"><i class="fas fa-edit mr-2"></i><?= $isTr ? 'Düzenle' : 'Edit' ?></a>
        <form method="POST" class="d-inline" onsubmit="return confirm('Silmek istediğinize emin misiniz?');">
            <input type="hidden" name="kb_action" value="delete">
            <input type="hidden" name="id" value="<?= $article['id'] ?>">
            <button type="submit" class="btn btn-danger shadow-sm ml-2"><i class="fas fa-trash mr-2"></i><?= $isTr ? 'Sil' : 'Delete' ?></button>
        </form>
    </div>
    <div class="card border-0 shadow-sm" style="border-radius:15px;">
        <div class="card-body p-5">
            <h2 class="font-weight-bold mb-3"><?= htmlspecialchars($article['title']) ?></h2>
            <div class="text-muted small mb-4 pb-3 border-bottom">
                <span class="mr-3"><i class="fas fa-folder mr-1"></i> <?= htmlspecialchars($article['category_name']) ?></span>
                <span class="mr-3"><i class="fas fa-user mr-1"></i> <?= htmlspecialchars($article['author_name']) ?></span>
                <span class="mr-3"><i class="fas fa-clock mr-1"></i> <?= $article['created_at'] ?></span>
                <span><i class="fas fa-eye mr-1"></i> <?= $article['views'] ?> views</span>
            </div>
            <div class="article-content" style="font-size: 15px; line-height: 1.8;">
                <?= $article['content'] ?>
            </div>
        </div>
    </div>

    <?php elseif ($action === 'edit' || $action === 'add'): ?>
    <div class="mb-4">
        <h3 class="mb-0 font-weight-bold text-dark"><?= $isTr ? ($action==='edit'?'Makale Düzenle':'Yeni Makale Ekle') : ($action==='edit'?'Edit Article':'Add New Article') ?></h3>
    </div>
    <div class="card border-0 shadow-sm" style="border-radius:15px;">
        <div class="card-body p-4">
            <form method="POST">
                <input type="hidden" name="kb_action" value="save">
                <?php if($action==='edit'): ?><input type="hidden" name="id" value="<?= $article['id'] ?>"><?php endif; ?>
                
                <div class="row">
                    <div class="col-md-8 form-group">
                        <label class="font-weight-bold">Başlık / Title</label>
                        <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($article['title']) ?>" required>
                    </div>
                    <div class="col-md-4 form-group">
                        <label class="font-weight-bold">Kategori / Category</label>
                        <select name="category_id" class="form-control" required>
                            <option value="">Seçiniz / Select</option>
                            <?php foreach($categories as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $article['category_id']==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="font-weight-bold">İçerik / Content (HTML Destekli)</label>
                    <textarea name="content" class="form-control summernote" rows="15"><?= htmlspecialchars($article['content']) ?></textarea>
                </div>
                <button type="submit" class="btn btn-success btn-lg px-5 font-weight-bold"><i class="fas fa-save mr-2"></i><?= $isTr ? 'Kaydet' : 'Save' ?></button>
                <a href="?route=knowledge_base" class="btn btn-light btn-lg ml-2"><?= $isTr ? 'İptal' : 'Cancel' ?></a>
            </form>
        </div>
    </div>
    <script>
    $(document).ready(function() {
        if($.fn.summernote) {
            $('.summernote').summernote({height: 400});
        }
    });
    </script>
    <?php endif; ?>
</div>
<style>
.card.h-100:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }
</style>
