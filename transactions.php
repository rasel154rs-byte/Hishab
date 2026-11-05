<?php
require 'config.php';
require 'includes/auth.php';

$page_title = $t['transactions'];
$errors = [];
$success = false;
$deleted = false;

// HTML এস্কেপ করার সেফ ফাংশন
$h = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$is_bn = isset($lang) && $lang === 'bn';

// ===== ডিলিট করার লজিক =====
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    if (!isset($_GET['token']) || !csrf_verify($_GET['token'])) {
        $errors[] = 'Security token mismatch.';
    } else {
        $del_id = (int)$_GET['delete'];
        $stmt = $conn->prepare("DELETE FROM transactions WHERE id = ?");
        $stmt->bind_param('i', $del_id);
        if ($stmt->execute()) {
            header('Location: transactions.php?deleted=1');
            exit;
        }
        $stmt->close();
    }
}

if (isset($_GET['saved'])) {
    $success = true;
}
if (isset($_GET['deleted'])) {
    $deleted = true;
}

// ===== ফিল্টার ভ্যালু নেওয়া =====
$filter_type    = $_GET['type'] ?? '';
$filter_start   = $_GET['start_date'] ?? '';
$filter_end     = $_GET['end_date'] ?? '';
$filter_account = (int)($_GET['account_id'] ?? 0);
$filter_cat     = (int)($_GET['category_id'] ?? 0);
$filter_q       = trim($_GET['q'] ?? '');

// পেজিনেশন
$limit = 15;
$page  = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// ===== SQL কোয়েরি তৈরি (Prepared Statement) =====
$where = [];
$params = [];
$types = '';

if (in_array($filter_type, ['income', 'expense', 'transfer'], true)) {
    $where[] = "t.type = ?";
    $params[] = $filter_type;
    $types .= 's';
}

if ($filter_start !== '' && DateTime::createFromFormat('Y-m-d', $filter_start)) {
    $where[] = "t.trans_date >= ?";
    $params[] = $filter_start;
    $types .= 's';
}

if ($filter_end !== '' && DateTime::createFromFormat('Y-m-d', $filter_end)) {
    $where[] = "t.trans_date <= ?";
    $params[] = $filter_end;
    $types .= 's';
}

if ($filter_account > 0) {
    $where[] = "(t.account_id = ? OR t.to_account_id = ?)";
    $params[] = $filter_account;
    $params[] = $filter_account;
    $types .= 'ii';
}

if ($filter_cat > 0) {
    $where[] = "t.category_id = ?";
    $params[] = $filter_cat;
    $types .= 'i';
}

if ($filter_q !== '') {
    $where[] = "(t.note LIKE ? OR a.name LIKE ? OR a.name_bn LIKE ? OR c.name LIKE ? OR c.name_bn LIKE ? OR t.amount LIKE ?)";
    $search_term = "%{$filter_q}%";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $search_term;
        $types .= 's';
    }
}

$where_sql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

// মোট কতটি লেনদেন (পেজিনেশনের জন্য)
$count_sql = "SELECT COUNT(*) AS total FROM transactions t 
              JOIN accounts a ON a.id = t.account_id
              LEFT JOIN categories c ON c.id = t.category_id 
              $where_sql";
$stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_rows = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);
$stmt->close();

// ===== ফিল্টার অনুযায়ী মোট আয় ও ব্যয়ের হিসাব =====
$filtered_totals = ['income' => 0.0, 'expense' => 0.0];
$sum_sql = "SELECT 
                COALESCE(SUM(CASE WHEN t.type = 'income' THEN t.amount END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN t.type = 'expense' THEN t.amount END), 0) AS total_expense
            FROM transactions t 
            JOIN accounts a ON a.id = t.account_id 
            LEFT JOIN categories c ON c.id = t.category_id 
            $where_sql";
$stmt_sum = $conn->prepare($sum_sql);
if (!empty($params)) {
    $stmt_sum->bind_param($types, ...$params);
}
$stmt_sum->execute();
$sum_res = $stmt_sum->get_result()->fetch_assoc();
$filtered_totals['income'] = (float)$sum_res['total_income'];
$filtered_totals['expense'] = (float)$sum_res['total_expense'];
$stmt_sum->close();

// নিট ব্যালেন্স (আয় - ব্যয়)
$filtered_totals['balance'] = $filtered_totals['income'] - $filtered_totals['expense'];

// মূল ডাটা আনা
$sql = "
    SELECT t.id, t.type, t.amount, t.trans_date, t.note,
           a.name AS account_name, a.name_bn AS account_name_bn,
           c.name AS category_name, c.name_bn AS category_name_bn,
           ta.name AS to_account_name, ta.name_bn AS to_account_name_bn
    FROM transactions t
    JOIN accounts a   ON a.id = t.account_id
    LEFT JOIN categories c ON c.id = t.category_id
    LEFT JOIN accounts ta  ON ta.id = t.to_account_id
    $where_sql
    ORDER BY t.trans_date DESC, t.id DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$transactions = [];
while ($row = $result->fetch_assoc()) {
    $transactions[] = $row;
}
$stmt->close();

// ফিল্টার ড্রপডাউনের জন্য একাউন্ট ও ক্যাটাগরি লোড
$filter_accounts = $conn->query("SELECT id, name, name_bn FROM accounts ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$filter_cats = $conn->query("SELECT id, name, name_bn, type FROM categories ORDER BY type, name")->fetch_all(MYSQLI_ASSOC);

require 'includes/header.php';
?>

<div class="transaction-page">

    <?php if ($success): ?>
        <div class="alert alert-success"><?= $h($t['saved']) ?></div>
    <?php endif; ?>

    <?php if ($deleted): ?>
        <div class="alert alert-success"><?= $h($t['deleted'] ?? 'Deleted successfully') ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul style="margin:0; padding-left:20px;">
                <?php foreach ($errors as $e): ?>
                    <li><?= $h($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Page heading -->
    <div class="premium-page-header">
        <div class="premium-header-content">
            <div class="premium-header-icon icon-primary">
                <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 14l4-4 4 4 6-7" />
                </svg>
            </div>
            <div class="premium-header-text">
                <h1><?= $h($page_title) ?></h1>
                <p><?= $h($t['transactions_subtitle'] ?? '') ?></p>
            </div>
        </div>
    </div>

    <!-- Search and filters -->
    <div class="card transaction-filter-card">
        <form method="get" action="transactions.php" id="searchForm" class="transaction-filter">
            <input type="hidden" name="filter_open" value="1">

            <div class="filter-field search-field">
                <label for="transaction-search"><?= $h($t['search'] ?? 'Search') ?></label>
                <input id="transaction-search" type="text" name="q"
                    value="<?= $h($filter_q) ?>"
                    placeholder="<?= $is_bn ? 'নোট, একাউন্ট বা ক্যাটাগরি খুঁজুন...' : 'Search notes, accounts or categories...' ?>">
            </div>

            <div class="filter-field">
                <label for="filter-type"><?= $h($t['type'] ?? 'Type') ?></label>
                <select id="filter-type" name="type" class="filter-input">
                    <option value=""><?= $h($t['all'] ?? 'All') ?></option>
                    <option value="income" <?= $filter_type === 'income' ? 'selected' : '' ?>><?= $h($t['income'] ?? 'Income') ?></option>
                    <option value="expense" <?= $filter_type === 'expense' ? 'selected' : '' ?>><?= $h($t['expense'] ?? 'Expense') ?></option>
                    <option value="transfer" <?= $filter_type === 'transfer' ? 'selected' : '' ?>><?= $h($t['transfer'] ?? 'Transfer') ?></option>
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-account"><?= $h($t['account'] ?? 'Account') ?></label>
                <select id="filter-account" name="account_id" class="filter-input">
                    <option value=""><?= $h($t['all'] ?? 'All') ?></option>
                    <?php foreach ($filter_accounts as $fa): ?>
                        <option value="<?= (int)$fa['id'] ?>"
                            <?= $filter_account === (int)$fa['id'] ? 'selected' : '' ?>>
                            <?= $h(label_bilingual($fa['name'], $fa['name_bn'], $lang)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-category"><?= $h($t['category'] ?? 'Category') ?></label>
                <select id="filter-category" name="category_id" class="filter-input">
                    <option value=""><?= $h($t['all'] ?? 'All') ?></option>
                    <?php foreach ($filter_cats as $fc): ?>
                        <option value="<?= (int)$fc['id'] ?>"
                            <?= $filter_cat === (int)$fc['id'] ? 'selected' : '' ?>>
                            <?= $h(label_bilingual($fc['name'], $fc['name_bn'], $lang)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-start-date"><?= $h($t['start_date'] ?? 'Start Date') ?></label>
                <input id="filter-start-date" type="date" name="start_date"
                    class="filter-input" value="<?= $h($filter_start) ?>">
            </div>

            <div class="filter-field">
                <label for="filter-end-date"><?= $h($t['end_date'] ?? 'End Date') ?></label>
                <input id="filter-end-date" type="date" name="end_date"
                    class="filter-input" value="<?= $h($filter_end) ?>">
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn filter-search-btn">
                    <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="11" cy="11" r="7" />
                        <path stroke-linecap="round" d="m20 20-4-4" />
                    </svg>
                    <?= $h($t['search'] ?? 'Search') ?>
                </button>
                <a href="transactions.php" class="btn filter-reset-btn"><?= $h($t['reset'] ?? 'Reset') ?></a>
            </div>
        </form>
    </div>

    <!-- Summary cards -->
    <div class="transaction-summary">

        <div class="transaction-stat">
            <div class="stat-icon blue">
                <svg width="25" height="25" fill="none" stroke="currentColor" stroke-width="2"
                    viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
                </svg>
            </div>
            <div>
                <div class="stat-label"><?= $h($t['total_transactions'] ?? 'Total Transactions') ?></div>
                <div class="stat-value">
                    <?= $is_bn ? to_bn_number($total_rows) : $total_rows ?> 
                    <?= $is_bn ? 'টি' : '' ?>
                </div>
            </div>
        </div>

        <div class="transaction-stat">
            <div class="stat-icon green">
                <svg width="25" height="25" fill="none" stroke="currentColor" stroke-width="2"
                    viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <div>
                <div class="stat-label"><?= $h($t['total_income'] ?? 'Total Income') ?></div>
                <div class="stat-value income"><?= format_money($filtered_totals['income'], $lang) ?></div>
            </div>
        </div>

        <div class="transaction-stat">
            <div class="stat-icon red">
                <svg width="25" height="25" fill="none" stroke="currentColor" stroke-width="2"
                    viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
            </div>
            <div>
                <div class="stat-label"><?= $h($t['total_expense'] ?? 'Total Expense') ?></div>
                <div class="stat-value expense"><?= format_money($filtered_totals['expense'], $lang) ?></div>
            </div>
        </div>

        <div class="transaction-stat">
            <div class="stat-icon teal">
                <svg width="25" height="25" fill="none" stroke="currentColor" stroke-width="2"
                    viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M3 17l6-6 4 4 8-9M15 6h6v6" />
                </svg>
            </div>
            <div>
                <div class="stat-label"><?= $h($is_bn ? 'নিট ব্যালেন্স' : 'Net Balance') ?></div>
                <div class="stat-value"><?= format_money($filtered_totals['balance'], $lang) ?></div>
            </div>
        </div>

    </div>

    <!-- Transactions table -->
    <div class="card transaction-table-card">
        <div class="table-card-heading">
            <h2>
                <svg width="23" height="23" fill="none" stroke="var(--primary)"
                    stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M4 6h16M4 12h16M4 18h16M8 4v4m8-4v4m-8 2v4m8-4v4m-8 2v4m8-4v4" />
                </svg>
                <?= $h($t['total_transactions'] ?? 'Total Transactions') ?>
                <span class="transaction-count">
                    <?= $is_bn ? to_bn_number($total_rows) : $total_rows ?>
                </span>
            </h2>
        </div>

        <?php if (empty($transactions)): ?>
            <div class="transaction-empty">
                <?= $h($t['no_data'] ?? 'No data found') ?>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <colgroup>
                        <col style="width:13%">
                        <col style="width:12%">
                        <col style="width:15%">
                        <col style="width:15%">
                        <col style="width:23%">
                        <col style="width:12%">
                        <col style="width:10%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th><?= $h($t['date'] ?? 'Date') ?></th>
                            <th><?= $h($t['type'] ?? 'Type') ?></th>
                            <th><?= $h($t['account'] ?? 'Account') ?></th>
                            <th><?= $h($t['category'] ?? 'Category') ?></th>
                            <th><?= $h($t['note'] ?? 'Note') ?></th>
                            <th class="text-right"><?= $h($t['amount'] ?? 'Amount') ?></th>
                            <th><?= $h($t['actions'] ?? 'Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx):
                            $acc_name = label_bilingual($tx['account_name'], $tx['account_name_bn'], $lang);
                            if ($tx['type'] === 'transfer') {
                                $acc_name .= ' → ' . label_bilingual($tx['to_account_name'], $tx['to_account_name_bn'], $lang);
                            }

                            $cat_name = $tx['type'] === 'transfer'
                                ? '—'
                                : label_bilingual($tx['category_name'], $tx['category_name_bn'], $lang);

                            $sign = $tx['type'] === 'income' ? '+' : ($tx['type'] === 'expense' ? '−' : '⇄');
                            $amount_class = $tx['type'] === 'income'
                                ? 'amount-income'
                                : ($tx['type'] === 'expense' ? 'amount-expense' : 'amount-transfer');

                            $type_class = in_array($tx['type'], ['income', 'expense', 'transfer'], true)
                                ? $tx['type'] : 'transfer';
                        ?>
                            <tr>
                                <td><?= $h(format_date($tx['trans_date'], $lang)) ?></td>
                                <td>
                                    <span class="type-badge <?= $type_class ?>">
                                        <?php if ($tx['type'] === 'income'): ?>↑
                                        <?php elseif ($tx['type'] === 'expense'): ?>↓
                                        <?php else: ?>⇄<?php endif; ?>
                                        <?= $h($t[$tx['type']] ?? $tx['type']) ?>
                                    </span>
                                </td>
                                <td><?= $h($acc_name) ?></td>
                                <td><?= $h($cat_name) ?></td>
                                <td class="transaction-note"
                                    title="<?= $h($tx['note'] ?? '') ?>">
                                    <?= $h(($tx['note'] ?? '') !== '' ? $tx['note'] : '—') ?>
                                </td>
                                <td class="text-right <?= $amount_class ?>">
                                    <?= $sign ?> <?= format_money((float)$tx['amount'], $lang) ?>
                                </td>
                                <td>
                                    <div class="transaction-actions">
                                        <?php if ($tx['type'] !== 'transfer'): ?>
                                            <a href="<?= $tx['type'] === 'income' ? 'add-income.php' : 'add-expense.php' ?>?edit=<?= (int)$tx['id'] ?>"
                                                class="icon-action edit"
                                                title="<?= $h($t['edit'] ?? 'Edit') ?>"
                                                aria-label="<?= $h($t['edit'] ?? 'Edit') ?>">
                                                <svg fill="none" stroke="currentColor" stroke-width="2"
                                                    viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M12 20h9M16.5 3.5a2.12 2.12 0 013 3L8 18l-4 1 1-4Z" />
                                                </svg>
                                            </a>
                                        <?php endif; ?>

                                        <a href="transactions.php?delete=<?= (int)$tx['id'] ?>&token=<?= $h(csrf_token()) ?>"
                                            class="icon-action delete"
                                            title="<?= $h($t['delete'] ?? 'Delete') ?>"
                                            aria-label="<?= $h($t['delete'] ?? 'Delete') ?>"
                                            onclick="return confirm('<?= $h($t['confirm_delete'] ?? 'Are you sure?') ?>');">
                                            <svg fill="none" stroke="currentColor" stroke-width="2"
                                                viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                    d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14M10 10v6m4-6v6" />
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="transaction-pagination">
                    <div>
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&type=<?= urlencode($filter_type) ?>&start_date=<?= urlencode($filter_start) ?>&end_date=<?= urlencode($filter_end) ?>&account_id=<?= $filter_account ?>&category_id=<?= $filter_cat ?>&q=<?= urlencode($filter_q) ?>"
                                class="btn">« <?= $is_bn ? 'আগের' : 'Previous' ?></a>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?= $is_bn ? 'পেজ' : 'Page' ?> <?= $is_bn
                                ? to_bn_number($page) . ' / ' . to_bn_number($total_pages)
                                : $page . ' / ' . $total_pages ?>
                    </div>

                    <div>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= $page + 1 ?>&type=<?= urlencode($filter_type) ?>&start_date=<?= urlencode($filter_start) ?>&end_date=<?= urlencode($filter_end) ?>&account_id=<?= $filter_account ?>&category_id=<?= $filter_cat ?>&q=<?= urlencode($filter_q) ?>"
                                class="btn"><?= $is_bn ? 'পরের' : 'Next' ?> »</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<?php require 'includes/footer.php'; ?>