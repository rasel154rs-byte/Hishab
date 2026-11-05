<?php
require 'config.php';
require 'includes/auth.php';

// This file must be included by add-income.php or add-expense.php.
if (!isset($form_type) || !in_array($form_type, ['income', 'expense'], true)) {
  header('Location: index.php');
  exit;
}

$is_income  = ($form_type === 'income');
$page_title = $is_income ? $t['add_income'] : $t['add_expense'];
$errors  = [];
$success = false;

// Edit transaction
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_tx = null;

if ($edit_id > 0) {
  $stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ? AND type = ?");
  $stmt->bind_param('is', $edit_id, $form_type);
  $stmt->execute();
  $edit_tx = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($edit_tx) {
    $page_title = $t['edit'] . ' - ' . $page_title;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      $_POST['amount']      = $edit_tx['amount'];
      $_POST['account_id']  = $edit_tx['account_id'];
      $_POST['category_id'] = $edit_tx['category_id'];
      $_POST['trans_date']  = $edit_tx['trans_date'];
      $_POST['note']        = $edit_tx['note'];
    }
  } else {
    header('Location: transactions.php');
    exit;
  }
}

// Accounts with current balance
$accounts_balance_data = get_account_balances($conn);
$accounts = [];
foreach ($accounts_balance_data as $a) {
  $accounts[] = $a;
}

// Categories
$cat_stmt = $conn->prepare("SELECT id, name, name_bn FROM categories WHERE type=? ORDER BY id");
$cat_stmt->bind_param('s', $form_type);
$cat_stmt->execute();
$categories = $cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$cat_stmt->close();

// Save transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  }

  $amount      = trim($_POST['amount'] ?? '');
  $account_id  = (int)($_POST['account_id'] ?? 0);
  $category_id = (int)($_POST['category_id'] ?? 0);
  $trans_date  = trim($_POST['trans_date'] ?? '');
  $note        = trim($_POST['note'] ?? '');

  if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
    $errors[] = $t['invalid_amount'];
  }

  $account_ok = false;
  foreach ($accounts as $a) {
    if ((int)$a['id'] === $account_id) {
      $account_ok = true;
      break;
    }
  }
  if (!$account_ok) {
    $errors[] = $t['required'] . ' (' . $t['account'] . ')';
  }

  $cat_ok = false;
  foreach ($categories as $c) {
    if ((int)$c['id'] === $category_id) {
      $cat_ok = true;
      break;
    }
  }
  if (!$cat_ok) {
    $errors[] = $t['required'] . ' (' . $t['category'] . ')';
  }

  $d = DateTime::createFromFormat('Y-m-d', $trans_date);
  if (!$d || $d->format('Y-m-d') !== $trans_date) {
    $errors[] = $t['required'] . ' (' . $t['date'] . ')';
  }

  if (mb_strlen($note) > 255) {
    $errors[] = 'Note is too long (max 255 characters).';
  }

  // ===== ব্যালেন্স চেক (শুধুমাত্র Expense-এর জন্য) =====
  if ($form_type === 'expense' && $account_ok) {
    $current_balance = 0;
    foreach ($accounts as $a) {
      if ((int)$a['id'] === $account_id) {
        $current_balance = (float)$a['balance'];
        break;
      }
    }

    // এডিটের সময় পুরনো টাকাটুকু বাদ দিয়ে চেক করতে হবে
    if ($edit_id > 0 && $edit_tx && (int)$edit_tx['account_id'] === $account_id) {
      $current_balance += (float)$edit_tx['amount'];
    }

    if ((float)$amount > $current_balance) {
      $errors[] = $lang === 'bn'
        ? 'এই একাউন্টে পর্যাপ্ত ব্যালেন্স নেই! বর্তমান ব্যালেন্স: ' . format_money($current_balance, $lang)
        : 'Insufficient balance! Current balance is: ' . format_money($current_balance, $lang);
    }
  }

  if (empty($errors)) {
    $amount_val = round((float)$amount, 2);

    if ($edit_id > 0) {
      $stmt = $conn->prepare("
                UPDATE transactions
                SET amount=?, account_id=?, category_id=?, note=?, trans_date=?
                WHERE id=? AND type=?
            ");
      $stmt->bind_param('diissis', $amount_val, $account_id, $category_id, $note, $trans_date, $edit_id, $form_type);
    } else {
      $stmt = $conn->prepare("
                INSERT INTO transactions (type, amount, account_id, category_id, note, trans_date)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
      $stmt->bind_param('sdiiss', $form_type, $amount_val, $account_id, $category_id, $note, $trans_date);
    }

    if ($stmt->execute()) {
      $success = true;
      unset($_SESSION['csrf_token']);
      $_POST = [];
      $edit_id = 0;
    } else {
      $errors[] = $t['error'];
    }
    $stmt->close();
  }
}

require 'includes/header.php';

$amount_value = $_POST['amount'] ?? '';
$account_value = (int)($_POST['account_id'] ?? 0);
$category_value = (int)($_POST['category_id'] ?? 0);
$date_value = $_POST['trans_date'] ?? date('Y-m-d');
$note_value = $_POST['note'] ?? '';

$form_heading = $is_income ? $t['add_income'] : $t['add_expense'];
$form_hint = $is_income
  ? ($lang === 'bn' ? 'আপনার আয়ের তথ্য এখানে সংরক্ষণ করুন' : 'Record and organize your income')
  : ($lang === 'bn' ? 'আপনার ব্যয়ের তথ্য এখানে সংরক্ষণ করুন' : 'Record and organize your expenses');
?>

<style>
  /* Hisab transaction form — styles are scoped to .tf-page */
  .tf-page {
    --tf-accent: <?= $is_income ? '#10b981' : '#ef4444' ?>;
    --tf-accent-soft: <?= $is_income ? 'rgba(16,185,129,.15)' : 'rgba(239,68,68,.15)' ?>;
    --tf-border: var(--border);
    max-width: 900px;
    margin: 22px auto 36px;
    padding: 0 22px;
  }

  .tf-shell {
    overflow: hidden;
    border: 1px solid var(--tf-border);
    border-radius: 16px;
    background: var(--card);
    box-shadow: var(--shadow);
  }

  [data-theme="dark"] .tf-shell {
    background: linear-gradient(145deg, #101e39, #0b162d);
    border-color: #203657;
  }

  .tf-heading {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 22px 26px 18px;
    border-bottom: 1px solid var(--tf-border);
  }

  .tf-heading-icon {
    width: 54px;
    height: 54px;
    flex: 0 0 54px;
    display: grid;
    place-items: center;
    border-radius: 16px;
    color: var(--tf-accent);
    background: var(--tf-accent-soft);
    border: 1px solid color-mix(in srgb, var(--tf-accent) 35%, transparent);
    font-size: 27px;
  }

  .tf-heading h1 {
    margin: 0 0 4px;
    font-size: clamp(21px, 2.4vw, 28px);
    line-height: 1.35;
    font-weight: 750;
    color: var(--text);
  }

  .tf-heading p {
    margin: 0;
    color: var(--muted);
    font-size: 14px;
  }

  .tf-form {
    padding: 22px 26px 24px;
  }

  .tf-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px 22px;
  }

  .tf-field {
    min-width: 0;
  }

  .tf-label {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0 0 9px;
    color: var(--text);
    font-size: 14px;
    font-weight: 650;
  }

  .tf-label-icon {
    width: 25px;
    text-align: center;
    color: var(--tf-accent);
    font-size: 18px;
  }

  .tf-control-wrap {
    position: relative;
  }

  .tf-control {
    display: block;
    width: 100%;
    min-height: 48px;
    box-sizing: border-box;
    padding: 11px 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    outline: none;
    background: var(--bg);
    color: var(--text);
    font: inherit;
    font-size: 15px;
    transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
  }

  [data-theme="dark"] .tf-control {
    background: #0f172a;
    border-color: #334155;
  }

  .tf-control::placeholder {
    color: var(--muted);
    opacity: 1;
  }

  .tf-control:focus {
    border-color: var(--tf-accent);
    background: var(--card);
    box-shadow: 0 0 0 4px var(--tf-accent-soft);
  }

  [data-theme="dark"] .tf-control:focus {
    background: #0f172a;
  }

  .tf-control option {
    background: var(--card);
    color: var(--text);
  }

  [data-theme="dark"] .tf-control option {
    background: #101e35;
    color: #f1f6ff;
  }

  .tf-note {
    grid-column: 1 / -1;
  }

  .tf-note .tf-control {
    min-height: 88px;
    resize: vertical;
  }

  .tf-help {
    margin: 6px 0 0;
    color: var(--muted);
    font-size: 12px;
  }

  .tf-actions {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 22px;
  }

  .tf-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 46px;
    padding: 10px 22px;
    border: 1px solid transparent;
    border-radius: 10px;
    text-decoration: none;
    font: inherit;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: transform .16s ease, filter .16s ease, box-shadow .16s ease;
  }

  .tf-btn:hover {
    transform: translateY(-1px);
    filter: brightness(1.08);
  }

  .tf-btn-primary {
    min-width: 190px;
    color: #fff;
    background: var(--tf-accent);
    box-shadow: 0 7px 18px var(--tf-accent-soft);
  }

  .tf-btn-secondary {
    color: var(--text);
    background: var(--bg);
    border-color: var(--border);
  }

  [data-theme="dark"] .tf-btn-secondary {
    background: rgba(255, 255, 255, .035);
    border-color: rgba(160, 184, 216, .4);
  }

  .tf-alert {
    margin: 0 0 14px;
    padding: 12px 15px;
    border-radius: 10px;
    font-size: 14px;
  }

  .tf-alert-success {
    color: var(--income);
    background: rgba(16, 185, 129, .1);
    border: 1px solid rgba(16, 185, 129, .3);
  }

  .tf-alert-error {
    color: var(--expense);
    background: rgba(239, 68, 68, .1);
    border: 1px solid rgba(239, 68, 68, .3);
  }

  .tf-alert ul {
    margin: 0;
    padding-left: 20px;
  }

  /* Live Balance Error */
  #live-balance-error {
    padding: 8px 12px;
    background: rgba(239, 68, 68, 0.1);
    border-left: 3px solid var(--expense);
    border-radius: 4px;
    font-weight: 600;
    margin-top: 8px;
    display: none;
  }

  @media (max-width: 700px) {
    .tf-page {
      margin: 14px auto 24px;
      padding: 0 12px;
    }

    .tf-shell {
      border-radius: 14px;
    }

    .tf-heading {
      padding: 17px 16px 14px;
      gap: 12px;
    }

    .tf-heading-icon {
      width: 46px;
      height: 46px;
      flex-basis: 46px;
      font-size: 23px;
      border-radius: 13px;
    }

    .tf-form {
      padding: 17px 16px 19px;
    }

    .tf-grid {
      grid-template-columns: 1fr;
      gap: 15px;
    }

    .tf-note {
      grid-column: auto;
    }

    .tf-actions {
      justify-content: stretch;
    }

    .tf-btn {
      flex: 1 1 140px;
    }
  }
</style>

<div class="tf-page">
  <section class="tf-shell">
    <header class="tf-heading">
      <div class="tf-heading-icon" aria-hidden="true"><?= $is_income ? '↗' : '↘' ?></div>
      <div>
        <h1><?= htmlspecialchars($form_heading) ?></h1>
        <p><?= htmlspecialchars($form_hint) ?></p>
      </div>
    </header>

    <div class="tf-form">
      <?php if ($success): ?>
        <div class="tf-alert tf-alert-success" role="status"><?= htmlspecialchars($t['saved']) ?></div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div class="tf-alert tf-alert-error" role="alert">
          <ul>
            <?php foreach ($errors as $e): ?>
              <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" action="" novalidate>
        <input type="hidden" name="edit_id" value="<?= (int)$edit_id ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

        <div class="tf-grid">
          <div class="tf-field">
            <label class="tf-label" for="amount"><span class="tf-label-icon" aria-hidden="true">৳</span><?= htmlspecialchars($t['amount']) ?> *</label>
            <div class="tf-control-wrap">
              <input class="tf-control" type="number" step="0.01" min="0.01" inputmode="decimal"
                id="amount" name="amount" placeholder="<?= $lang === 'bn' ? 'উদাহরণ: ১০০০' : 'e.g. 1000' ?>"
                value="<?= htmlspecialchars((string)$amount_value) ?>" required>
            </div>
            <div id="live-balance-error"></div>
          </div>

          <div class="tf-field">
            <label class="tf-label" for="account_id"><span class="tf-label-icon" aria-hidden="true">▣</span><?= htmlspecialchars($t['account']) ?> *</label>
            <select class="tf-control" id="account_id" name="account_id" required>
              <option value=""><?= htmlspecialchars($t['select']) ?></option>
              <?php foreach ($accounts as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= $account_value === (int)$a['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars(label_bilingual($a['name'], $a['name_bn'], $lang)) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <!-- নতুন: বর্তমান ব্যালেন্স দেখানোর জায়গা -->
            <div id="account-balance-display" style="margin-top: 6px; font-size: 0.85rem; font-weight: 600; color: var(--primary); display: none;">
              <?= $lang === 'bn' ? 'বর্তমান ব্যালেন্স:' : 'Current balance:' ?> <span id="balance-value">৳ ০.০০</span>
            </div>
          </div>

          <div class="tf-field">
            <label class="tf-label" for="category_id"><span class="tf-label-icon" aria-hidden="true">▦</span><?= htmlspecialchars($t['category']) ?> *</label>
            <select class="tf-control" id="category_id" name="category_id" required>
              <option value=""><?= htmlspecialchars($t['select']) ?></option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $category_value === (int)$c['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars(label_bilingual($c['name'], $c['name_bn'], $lang)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="tf-field">
            <label class="tf-label" for="trans_date"><span class="tf-label-icon" aria-hidden="true">▣</span><?= htmlspecialchars($t['date']) ?> *</label>
            <input class="tf-control" type="date" id="trans_date" name="trans_date"
              value="<?= htmlspecialchars((string)$date_value) ?>" required>
          </div>

          <div class="tf-field tf-note">
            <label class="tf-label" for="note"><span class="tf-label-icon" aria-hidden="true">▤</span><?= htmlspecialchars($t['note']) ?></label>
            <textarea class="tf-control" id="note" name="note" maxlength="255"
              placeholder="<?= $lang === 'bn' ? 'প্রয়োজনীয় কোনো তথ্য লিখুন (ঐচ্ছিক)…' : 'Add a note (optional)…' ?>"><?= htmlspecialchars((string)$note_value) ?></textarea>
            <p class="tf-help"><?= $lang === 'bn' ? 'সর্বোচ্চ ২৫৫ অক্ষর' : 'Maximum 255 characters' ?></p>
          </div>
        </div>

        <div class="tf-actions">
          <button type="submit" class="tf-btn tf-btn-primary">
            <span aria-hidden="true">✓</span><?= htmlspecialchars($t['save']) ?>
          </button>
          <button type="reset" class="tf-btn tf-btn-secondary">
            <span aria-hidden="true">↺</span><?= $lang === 'bn' ? 'রিসেট' : 'Reset' ?>
          </button>
          <a href="transactions.php" class="tf-btn tf-btn-secondary"><?= htmlspecialchars($t['cancel']) ?></a>
        </div>
      </form>
    </div>
  </section>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    // PHP থেকে অ্যাকাউন্টের বর্তমান ব্যালেন্স JS-এ পাঠানো হলো
    const accountBalances = <?= json_encode(array_column($accounts, 'balance', 'id')) ?>;
    const isExpense = <?= $form_type === 'expense' ? 'true' : 'false' ?>;
    const editAmount = <?= ($edit_id > 0 && $edit_tx) ? (float)$edit_tx['amount'] : 0 ?>;
    const editAccount = <?= ($edit_id > 0 && $edit_tx) ? (int)$edit_tx['account_id'] : 0 ?>;

    const amountInput = document.getElementById('amount');
    const accountSelect = document.getElementById('account_id');
    const errorDiv = document.getElementById('live-balance-error');

    function checkBalance() {
      const accountId = accountSelect.value;
      const balanceDisplay = document.getElementById('account-balance-display');
      const balanceValueSpan = document.getElementById('balance-value');

      // ১. অ্যাকাউন্ট সিলেক্ট করলে নিচে ব্যালেন্স দেখানো
      if (accountId && accountBalances[accountId] !== undefined) {
        let currentBal = parseFloat(accountBalances[accountId]) || 0;
        // এডিটের সময় পুরনো টাকা যোগ করে দেখানো
        if (editAccount == accountId) {
          currentBal += editAmount;
        }
        balanceDisplay.style.display = 'block';

        // সংখ্যাটি বাংলায় রূপান্তর (সিম্পল রিপ্লেস)
        let formattedBal = currentBal.toFixed(2);
        if (<?= $lang === 'bn' ? 'true' : 'false' ?>) {
          const en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
          const bn = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
          formattedBal = formattedBal.replace(/[0-9]/g, function(w) {
            return bn[en.indexOf(w)];
          });
        }
        balanceValueSpan.textContent = '৳ ' + formattedBal;
      } else {
        balanceDisplay.style.display = 'none';
      }

      // ২. ব্যালেন্স চেক (শুধুমাত্র Expense-এর জন্য)
      if (!isExpense) return;

      const amount = parseFloat(amountInput.value) || 0;

      if (!accountId) {
        errorDiv.style.display = 'none';
        amountInput.style.borderColor = '';
        return;
      }

      let balance = parseFloat(accountBalances[accountId]) || 0;

      // এডিটের সময় পুরনো টাকাটা যোগ করে হিসাব
      if (editAccount == accountId) {
        balance += editAmount;
      }

      if (amount > balance) {
        const formattedBalance = balance.toFixed(2);
        errorDiv.textContent = `পর্যাপ্ত ব্যালেন্স নেই! বর্তমান ব্যালেন্স: ৳ ${formattedBalance}`;
        errorDiv.style.display = 'block';
        amountInput.style.borderColor = '#ef4444'; // লাল বর্ডার
      } else {
        errorDiv.style.display = 'none';
        amountInput.style.borderColor = ''; // ডিফল্ট বর্ডার ফিরে আসবে
      }
    }

    amountInput.addEventListener('input', checkBalance);
    accountSelect.addEventListener('change', checkBalance);
    checkBalance(); // পেজ লোড হলে একবার চেক করবে
  });
</script>

<?php require 'includes/footer.php'; ?>