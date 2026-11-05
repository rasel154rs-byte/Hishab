<?php
require 'config.php';
require 'includes/auth.php';

$page_title = $t['accounts'];

$errors = [];
$success = false;
$edit_account = null;
$show_modal = false;

$esc = function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

// ==================================================
// 01. ACCOUNT ACTIVATE / DEACTIVATE
// ==================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch.';
    } else {
        $toggle_id = (int)($_POST['account_id'] ?? 0);
        if ($toggle_id > 0) {
            $stmt = $conn->prepare("UPDATE accounts SET is_active = 1 - is_active WHERE id = ?");
            $stmt->bind_param('i', $toggle_id);
            if ($stmt->execute()) {
                $stmt->close();
                header('Location: accounts.php?saved=1');
                exit;
            }
            $errors[] = $t['error'];
            $stmt->close();
        }
    }
}

// ==================================================
// 02. EDIT ACCOUNT DATA
// ==================================================
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM accounts WHERE id = ?");
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($edit_account) {
        $show_modal = true;
    }
}

// ==================================================
// 03. SAVE / UPDATE ACCOUNT
// ==================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Security token mismatch.';
    }

    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $name_bn = trim($_POST['name_bn'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $opening = trim($_POST['opening_balance'] ?? '0');

    if ($name === '') {
        $errors[] = $t['required'] . ' (' . $t['name'] . ')';
    }
    if ($opening === '' || !is_numeric($opening)) {
        $errors[] = $t['invalid_amount'];
    }

    if (empty($errors)) {
        $opening_val = round((float)$opening, 2);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE accounts SET name = ?, name_bn = ?, account_number = ?, opening_balance = ? WHERE id = ?");
            $stmt->bind_param('sssdi', $name, $name_bn, $account_number, $opening_val, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO accounts (name, name_bn, account_number, opening_balance) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('sssd', $name, $name_bn, $account_number, $opening_val);
        }

        if ($stmt->execute()) {
            $stmt->close();
            header('Location: accounts.php?saved=1');
            exit;
        } else {
            $errors[] = $t['error'];
            $stmt->close();
        }
    }

    if (!empty($errors)) {
        $edit_account = [
            'id' => $id, 'name' => $name, 'name_bn' => $name_bn,
            'account_number' => $account_number, 'opening_balance' => $opening
        ];
        $show_modal = true;
    }
}

// ==================================================
// 04. SUCCESS MESSAGE
// ==================================================
if (isset($_GET['saved'])) {
    $success = true;
}

// ==================================================
// 05. GET ALL ACCOUNTS & SUMMARY
// ==================================================
$accounts_res = $conn->query("SELECT * FROM accounts ORDER BY is_active DESC, id ASC");
$accounts = [];

$total_accounts = 0;
$active_accounts = 0;
$inactive_accounts = 0;
$total_balance = 0;

while ($row = $accounts_res->fetch_assoc()) {
    // Calculate Current Balance
    $stmt_bal = $conn->prepare("
        SELECT ? + 
        COALESCE((SELECT SUM(amount) FROM transactions WHERE type = 'income' AND account_id = ?), 0)
        - COALESCE((SELECT SUM(amount) FROM transactions WHERE type = 'expense' AND account_id = ?), 0)
        - COALESCE((SELECT SUM(amount) FROM transactions WHERE type = 'transfer' AND account_id = ?), 0)
        + COALESCE((SELECT SUM(amount) FROM transactions WHERE type = 'transfer' AND to_account_id = ?), 0)
        AS current_balance
    ");
    $stmt_bal->bind_param('diiii', $row['opening_balance'], $row['id'], $row['id'], $row['id'], $row['id']);
    $stmt_bal->execute();
    $bal_res = $stmt_bal->get_result()->fetch_assoc();
    $row['current_balance'] = (float)$bal_res['current_balance'];
    $stmt_bal->close();

    // Summary Calculations
    $total_accounts++;
    if ((int)$row['is_active'] === 1) {
        $active_accounts++;
        $total_balance += $row['current_balance'];
    } else {
        $inactive_accounts++;
    }
    $accounts[] = $row;
}

// ==================================================
// 06. LANGUAGE TEXT
// ==================================================
$is_bn = ($lang === 'bn');
$page_description = $is_bn ? 'আপনার সকল অ্যাকাউন্ট এখানে দেখুন ও পরিচালনা করুন' : 'View and manage all your financial accounts here';
$summary_total = $is_bn ? 'মোট অ্যাকাউন্ট' : 'Total Accounts';
$summary_active = $is_bn ? 'সক্রিয় অ্যাকাউন্ট' : 'Active Accounts';
$summary_inactive = $is_bn ? 'নিষ্ক্রিয় অ্যাকাউন্ট' : 'Inactive Accounts';
$summary_balance = $is_bn ? 'মোট বর্তমান ব্যালেন্স' : 'Total Current Balance';
$empty_title = $is_bn ? 'কোনো অ্যাকাউন্ট পাওয়া যায়নি' : 'No accounts found';
$empty_description = $is_bn ? 'আপনার প্রথম অ্যাকাউন্টটি তৈরি করুন' : 'Create your first account to get started';
$show_account_status = $is_bn ? 'সক্রিয়' : 'Active';
$show_inactive_status = $is_bn ? 'নিষ্ক্রিয়' : 'Inactive';

require 'includes/header.php';
?>

<div class="accounts-page">

    <!-- PAGE HEADER -->
    <div class="accounts-hero">
        <div class="accounts-hero-content">
            <div class="accounts-hero-indicator"></div>
            <div>
                <h1><?= $esc($page_title) ?></h1>
                <p><?= $esc($page_description) ?></p>
            </div>
        </div>
        <button type="button" class="btn btn-success accounts-add-btn" onclick="openAccountModal()">
            <span class="add-symbol">+</span> <?= $esc($t['new']) ?> <?= $esc($t['account']) ?>
        </button>
    </div>

    <!-- ALERT MESSAGES -->
    <?php if ($success): ?>
        <div class="alert alert-success accounts-alert"><?= $esc($t['saved']) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error accounts-alert">
            <ul><?php foreach ($errors as $e): ?><li><?= $esc($e) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <!-- SUMMARY CARDS -->
    <div class="accounts-summary">
        <div class="summary-card">
            <div class="summary-info">
                <span><?= $esc($summary_total) ?></span>
                <strong><?= $lang === 'bn' ? to_bn_number($total_accounts) : $total_accounts ?></strong>
            </div>
            <div class="summary-indicator summary-blue"></div>
        </div>
        <div class="summary-card">
            <div class="summary-info">
                <span><?= $esc($summary_active) ?></span>
                <strong><?= $lang === 'bn' ? to_bn_number($active_accounts) : $active_accounts ?></strong>
            </div>
            <div class="summary-indicator summary-green"></div>
        </div>
        <div class="summary-card">
            <div class="summary-info">
                <span><?= $esc($summary_inactive) ?></span>
                <strong><?= $lang === 'bn' ? to_bn_number($inactive_accounts) : $inactive_accounts ?></strong>
            </div>
            <div class="summary-indicator summary-red"></div>
        </div>
        <div class="summary-card summary-balance-card">
            <div class="summary-info">
                <span><?= $esc($summary_balance) ?></span>
                <strong><?= format_money($total_balance, $lang) ?></strong>
            </div>
            <div class="summary-indicator summary-purple"></div>
        </div>
    </div>

    <!-- ACCOUNT TABLE -->
    <div class="card accounts-table-card" style="padding: 16px;">
        <div class="accounts-table-heading">
            <div>
                <h2><?= $esc($t['accounts']) ?></h2>
                <p><?= $lang === 'bn' ? 'আপনার অ্যাকাউন্টের বিস্তারিত তথ্য' : 'Detailed information about your accounts' ?></p>
            </div>
            <span class="account-count">
                <?= $lang === 'bn' ? to_bn_number($total_accounts) : $total_accounts ?>
                <?= $lang === 'bn' ? 'টি অ্যাকাউন্ট' : 'Accounts' ?>
            </span>
        </div>

        <?php if (!empty($accounts)): ?>
            <div class="table-wrap accounts-table-wrap">
                <table class="account-table">
                    <colgroup>
                        <col style="width: 22%">
                        <col style="width: 18%">
                        <col style="width: 16%">
                        <col style="width: 16%">
                        <col style="width: 12%">
                        <col style="width: 16%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th><?= $esc($t['name']) ?></th>
                            <th><?= $esc($t['account_number']) ?></th>
                            <th><?= $esc($t['opening_balance']) ?></th>
                            <th><?= $esc($t['balance']) ?> (<?= $esc($t['current']) ?>)</th>
                            <th><?= $esc($t['status']) ?></th>
                            <th><?= $esc($t['actions']) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $a): ?>
                            <?php
                            $is_active = ((int)$a['is_active'] === 1);
                            $account_json = json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                            ?>
                            <tr class="<?= $is_active ? '' : 'account-inactive-row' ?>">
                                <td>
                                    <div class="account-name">
                                        <strong><?= $esc($a['name']) ?></strong>
                                        <?php if (!empty($a['name_bn'])): ?>
                                            <small><?= $esc($a['name_bn']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="account-number">
                                    <?php if (!empty($a['account_number'])): ?>
                                        <?= $esc($lang === 'bn' ? to_bn_number($a['account_number']) : $a['account_number']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="money-cell"><?= format_money((float)$a['opening_balance'], $lang) ?></td>
                                <td><strong class="current-balance"><?= format_money((float)$a['current_balance'], $lang) ?></strong></td>
                                <td>
                                    <?php if ($is_active): ?>
                                        <span class="status-badge status-active"><span class="status-dot"></span><?= $esc($show_account_status) ?></span>
                                    <?php else: ?>
                                        <span class="status-badge status-inactive"><span class="status-dot"></span><?= $esc($show_inactive_status) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="account-actions">
                                        <button type="button" class="account-action-btn edit-account-btn" data-edit-account data-account="<?= $esc($account_json) ?>">
                                            <?= $esc($t['edit']) ?>
                                        </button>
                                        <form method="post" action="accounts.php" class="toggle-account-form" onsubmit="event.preventDefault(); showPremiumConfirm(this.dataset.confirmMessage, () => this.submit());" data-confirm-message="<?= $esc($is_active ? $t['confirm_toggle_inactive'] : $t['confirm_toggle_active']) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $esc(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="account_id" value="<?= (int)$a['id'] ?>">
                                            <button type="submit" class="account-action-btn <?= $is_active ? 'deactivate-account-btn' : 'activate-account-btn' ?>">
                                                <?= $esc($is_active ? $t['inactive'] : $t['active']) ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="accounts-empty">
                <h3><?= $esc($empty_title) ?></h3>
                <p><?= $esc($empty_description) ?></p>
                <button type="button" class="btn btn-success" onclick="openAccountModal()">+ <?= $esc($t['new']) ?> <?= $esc($t['account']) ?></button>
            </div>
        <?php endif; ?>
    </div>

<!-- ACCOUNT MODAL -->
<div id="accountModal" class="modal-overlay accounts-modal" aria-hidden="true">
    <div class="modal-content accounts-modal-content" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        
        <!-- MODAL HEADER -->
        <div class="accounts-modal-header">
            <div>
                <h2 id="modalTitle"><?= $esc($t['new']) ?> <?= $esc($t['account']) ?></h2>
                <p><?= $lang === 'bn' ? 'অ্যাকাউন্টের তথ্য পূরণ করুন' : 'Enter your account information' ?></p>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeAccountModal()" aria-label="Close">✕</button>
        </div>

        <!-- MODAL FORM -->
        <form method="post" action="accounts.php" id="accountForm">
            <input type="hidden" name="csrf_token" value="<?= $esc(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="acc_id" value="0">

            <div class="form-group">
                <label for="acc_name"><?= $esc($t['name']) ?> (English) *</label>
                <input type="text" name="name" id="acc_name" required maxlength="100" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="acc_name_bn"><?= $esc($t['name_bn']) ?></label>
                <input type="text" name="name_bn" id="acc_name_bn" maxlength="100" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="acc_number"><?= $esc($t['account_number']) ?></label>
                <input type="text" name="account_number" id="acc_number" maxlength="100" placeholder="<?= $esc($t['account_number_hint'] ?? '') ?>" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="acc_opening"><?= $esc($t['opening_balance']) ?></label>
                <input type="number" step="0.01" name="opening_balance" id="acc_opening" value="0" required>
            </div>

            <div class="accounts-modal-actions">
                <button type="submit" class="btn btn-success"><?= $esc($t['save']) ?></button>
                <button type="button" class="btn accounts-cancel-btn" onclick="closeAccountModal()"><?= $esc($t['cancel']) ?></button>
            </div>
        </form>
    </div>
</div>

<script>
const accountLabels = <?= json_encode(['new' => $t['new'], 'edit' => $t['edit'], 'account' => $t['account']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function openAccountModal(data = null) {
    const modal = document.getElementById('accountModal');
    const title = document.getElementById('modalTitle');
    const form = document.getElementById('accountForm');
    
    if (data) {
        title.textContent = accountLabels.edit + ' ' + accountLabels.account;
        document.getElementById('acc_id').value = data.id;
        document.getElementById('acc_name').value = data.name || '';
        document.getElementById('acc_name_bn').value = data.name_bn || '';
        document.getElementById('acc_number').value = data.account_number || '';
        document.getElementById('acc_opening').value = data.opening_balance ?? 0;
    } else {
        form.reset();
        document.getElementById('acc_id').value = '0';
        title.textContent = accountLabels.new + ' ' + accountLabels.account;
    }
    
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    setTimeout(function () { document.getElementById('acc_name').focus(); }, 100);
}

function closeAccountModal() {
    const modal = document.getElementById('accountModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

document.querySelectorAll('[data-edit-account]').forEach(function(button) {
    button.addEventListener('click', function() {
        try {
            const account = JSON.parse(this.dataset.account);
            openAccountModal(account);
        } catch (error) {
            console.error('Unable to load account data:', error);
        }
    });
});

document.getElementById('accountModal').addEventListener('click', function(e) {
    if (e.target === this) closeAccountModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('accountModal').style.display === 'flex') {
        closeAccountModal();
    }
});

<?php if ($show_modal): ?>
document.addEventListener('DOMContentLoaded', function() {
    openAccountModal(<?= json_encode($edit_account, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>);
});
<?php endif; ?>
</script>

<?php require 'includes/footer.php'; ?>