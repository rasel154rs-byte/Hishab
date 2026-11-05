<?php
require 'config.php';
require 'includes/auth.php';

$page_title = $t['categories'];
$errors = [];
$success = isset($_GET['saved']);
$edit_cat = null;

$h = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$is_bn = isset($lang) && $lang === 'bn';

/*
 * Load edit data.
 */
if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];

    $stmt = $conn->prepare("SELECT id, name, name_bn, type FROM categories WHERE id = ?");
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_cat = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$edit_cat) {
        header('Location: categories.php');
        exit;
    }
}

/*
 * Save new category or update existing category.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch. Please refresh and try again.';
    }

    $id      = (int)($_POST['id'] ?? 0);
    $name    = trim($_POST['name'] ?? '');
    $name_bn = trim($_POST['name_bn'] ?? '');
    $type    = $_POST['type'] ?? '';

    if ($name === '') {
        $errors[] = $t['required'] . ' (' . $t['name'] . ')';
    }

    if (!in_array($type, ['income', 'expense'], true)) {
        $errors[] = $t['select_type'] ?? 'Please select a valid type.';
    }

    if (mb_strlen($name) > 100 || mb_strlen($name_bn) > 100) {
        $errors[] = 'Category names must be 100 characters or fewer.';
    }

    if (empty($errors)) {
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE categories SET name = ?, name_bn = ?, type = ? WHERE id = ?");
            $stmt->bind_param('sssi', $name, $name_bn, $type, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO categories (name, name_bn, type) VALUES (?, ?, ?)");
            $stmt->bind_param('sss', $name, $name_bn, $type);
        }

        if ($stmt->execute()) {
            $stmt->close();
            header('Location: categories.php?saved=1');
            exit;
        }

        $stmt->close();
        $errors[] = $t['error'];
    }

    // Keep submitted values visible if validation fails.
    $edit_cat = [
        'id' => $id,
        'name' => $name,
        'name_bn' => $name_bn,
        'type' => $type
    ];
}

/*
 * Load categories.
 */
$cats_res = $conn->query("SELECT id, name, name_bn, type FROM categories ORDER BY type ASC, name ASC");
$categories = [];

while ($row = $cats_res->fetch_assoc()) {
    $categories[] = $row;
}

require 'includes/header.php';
?>

<main class="category-page">

    <!-- গ্লোবাল প্রিমিয়াম হেডিং -->
    <div class="premium-page-header">
        <div class="premium-header-content">
            <div class="premium-header-icon icon-primary">
                <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <rect x="3" y="5" width="18" height="14" rx="2" />
                    <path d="M7 9h10M7 13h5M7 16h3" />
                </svg>
            </div>
            <div class="premium-header-text">
                <h1><?= $h($page_title) ?></h1>
                <p><?= $h($t['financial_analytics'] ?? 'আর্থিক বিশ্লেষণ') ?></p>
            </div>
        </div>

        <!-- নতুন ক্যাটাগরি বাটন -->
        <button type="button" class="btn btn-success" onclick="openCategoryModal()">
            + <?= $h($t['new'] ?? 'নতুন') ?> <?= $h($t['category']) ?>
        </button>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <span>✓</span>
            <?= $h($t['saved']) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul style="margin:0; padding-left:20px;">
                <?php foreach ($errors as $error): ?>
                    <li><?= $h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="category-panel">
        <div class="category-panel-heading" style="justify-content: space-between;">
            <div class="d-flex align-center gap-2">
                <h2 style="margin:0;"><?= $h($t['categories']) ?></h2>
                <span class="category-count"><?= $is_bn ? to_bn_number(count($categories)) : count($categories) ?></span>
            </div>
            <div class="category-toolbar" style="margin: 0; width: auto;">
                <div class="category-filters">
                    <button type="button" class="category-filter active" data-filter="all"><?= $h($t['all'] ?? 'সব') ?></button>
                    <button type="button" class="category-filter" data-filter="income"><?= $h($t['income']) ?></button>
                    <button type="button" class="category-filter" data-filter="expense"><?= $h($t['expense']) ?></button>
                </div>
                <input type="search" id="category-search" class="category-search" placeholder="<?= $h($t['search'] ?? 'সার্চ') ?>..." style="width: 200px;">
            </div>
        </div>

        <div class="category-list" id="category-list">
            <?php foreach ($categories as $category): ?>
                <?php
                $category_type = $category['type'] === 'income' ? 'income' : 'expense';
                $display_name = label_bilingual($category['name'], $category['name_bn'], $lang);
                ?>
                <article class="category-row" data-type="<?= $category_type ?>"
                    data-search="<?= $h(mb_strtolower($category['name'] . ' ' . ($category['name_bn'] ?? ''))) ?>">
                    <div class="category-row-info">
                        <strong><?= $h($category['name']) ?></strong>
                        <?php if (!empty($category['name_bn'])): ?>
                            <small><?= $h($category['name_bn']) ?></small>
                        <?php endif; ?>
                    </div>

                    <span class="category-type-badge <?= $category_type ?>">
                        <?= $h($t[$category_type]) ?>
                    </span>

                    <div class="category-row-actions">
                        <a href="javascript:void(0);" onclick="openCategoryModal(<?= htmlspecialchars(json_encode($category)) ?>)" class="category-icon-btn edit" title="<?= $h($t['edit']) ?>">
                            <?= $h($t['edit']) ?>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>

            <div class="category-empty" id="category-empty" <?= count($categories) ? 'hidden' : '' ?>>
                <?= $h($t['no_data'] ?? 'No data found') ?>
            </div>
        </div>
    </div>
</main>

<!-- Popup (Modal) ফর্ম -->
<div id="categoryModal" class="modal-overlay">
    <div class="modal-content">
        <div class="d-flex justify-between align-center" style="margin-bottom: 16px;">
            <h2 id="modalTitle" style="margin: 0;"><?= $h($t['new'] ?? 'নতুন') ?> <?= $h($t['category']) ?></h2>
            <button type="button" class="icon-btn" onclick="closeCategoryModal()" style="min-height: auto; font-size: 1.2rem;">✕</button>
        </div>

        <form method="post" action="categories.php" id="categoryForm">
            <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
            <input type="hidden" name="id" id="cat_id" value="0">

            <div class="form-group">
                <label><?= $h($t['type']) ?> *</label>
                <div class="category-type-switch">
                    <label class="type-option income-option">
                        <input type="radio" name="type" value="income" id="cat_type_income" required>
                        <span><b>↗</b> <?= $h($t['income']) ?></span>
                    </label>
                    <label class="type-option expense-option">
                        <input type="radio" name="type" value="expense" id="cat_type_expense" required>
                        <span><b>↘</b> <?= $h($t['expense']) ?></span>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label><?= $h($t['name']) ?> (English) *</label>
                <input type="text" name="name" id="cat_name" maxlength="100" placeholder="e.g. Salary" required>
            </div>

            <div class="form-group">
                <label><?= $h($t['name_bn']) ?></label>
                <input type="text" name="name_bn" id="cat_name_bn" maxlength="100" placeholder="<?= $is_bn ? 'যেমন: বেতন' : 'e.g. Salary' ?>">
            </div>

            <div class="d-flex gap-2" style="margin-top: 20px;">
                <button type="submit" class="btn btn-success" style="flex: 1;"><?= $h($t['save']) ?></button>
                <button type="button" class="btn" style="background:var(--muted); flex: 1;" onclick="closeCategoryModal()"><?= $h($t['cancel']) ?></button>
            </div>
        </form>
    </div>
</div>

<script>
    // ফিল্টার ও সার্চ লজিক
    (function() {
        const filterButtons = document.querySelectorAll('.category-filter');
        const searchInput = document.getElementById('category-search');
        const rows = Array.from(document.querySelectorAll('.category-row'));
        const emptyState = document.getElementById('category-empty');
        let currentFilter = 'all';

        function updateCategoryList() {
            const query = (searchInput.value || '').trim().toLocaleLowerCase();
            let visibleCount = 0;

            rows.forEach(function(row) {
                const typeMatches = currentFilter === 'all' || row.dataset.type === currentFilter;
                const searchMatches = (row.dataset.search || '').toLocaleLowerCase().includes(query);
                const visible = typeMatches && searchMatches;

                row.hidden = !visible;
                if (visible) visibleCount++;
            });

            emptyState.hidden = visibleCount !== 0;
        }

        filterButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                currentFilter = button.dataset.filter;
                filterButtons.forEach(function(item) {
                    item.classList.toggle('active', item === button);
                });
                updateCategoryList();
            });
        });

        searchInput.addEventListener('input', updateCategoryList);
    })();

    // মডাল ওপেন/ক্লোজ লজিক
    function openCategoryModal(data = null) {
        const modal = document.getElementById('categoryModal');
        const title = document.getElementById('modalTitle');
        const form = document.getElementById('categoryForm');

        if (data) {
            title.innerText = '<?= $h($t['edit']) ?> <?= $h($t['category']) ?>';
            document.getElementById('cat_id').value = data.id;
            document.getElementById('cat_name').value = data.name;
            document.getElementById('cat_name_bn').value = data.name_bn || '';

            if (data.type === 'income') {
                document.getElementById('cat_type_income').checked = true;
            } else {
                document.getElementById('cat_type_expense').checked = true;
            }
        } else {
            title.innerText = '<?= $h($t['new'] ?? 'নতুন') ?> <?= $h($t['category']) ?>';
            form.reset();
            document.getElementById('cat_id').value = '0';
        }

        modal.style.display = 'flex';
    }

    function closeCategoryModal() {
        document.getElementById('categoryModal').style.display = 'none';
    }

    document.getElementById('categoryModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeCategoryModal();
        }
    });
</script>

<?php require 'includes/footer.php'; ?>