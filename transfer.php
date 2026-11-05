<?php
require 'config.php';
require 'includes/auth.php';

$page_title = $t['transfer'] ?? 'Fund Transfer';
$errors = [];
$success = false;
$accounts = get_account_balances($conn);
$account_map = [];
foreach ($accounts as $a) $account_map[(int)$a['id']] = $a;

$h = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$is_bn = isset($lang) && $lang === 'bn';
$tr = static fn($key, $fallback = ''): string => (string)($t[$key] ?? $fallback);

$from_id = (int)($_POST['account_id'] ?? 0);
$to_id = (int)($_POST['to_account_id'] ?? 0);
$amount = trim((string)($_POST['amount'] ?? ''));
$fee = trim((string)($_POST['transfer_fee'] ?? '0'));
$date = trim((string)($_POST['trans_date'] ?? date('Y-m-d')));
$note = trim((string)($_POST['note'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) $errors[] = 'Security token mismatch. Please refresh and try again.';
    if ($amount === '' || !is_numeric($amount) || (float)$amount <= 0) $errors[] = $tr('invalid_amount', 'Please enter a valid amount.');
    if ($fee === '' || !is_numeric($fee) || (float)$fee < 0) $errors[] = $tr('invalid_fee', 'Please enter a valid transfer fee.');
    if ($from_id === $to_id) $errors[] = $tr('cannot_transfer_same', 'Cannot transfer to the same account.');

    $from_ok = isset($account_map[$from_id]);
    $to_ok = isset($account_map[$to_id]);
    $from_balance = $from_ok ? (float)$account_map[$from_id]['balance'] : 0;
    if (!$from_ok || !$to_ok) $errors[] = $tr('invalid_account', 'Please select valid accounts.');

    $amount_num = is_numeric($amount) ? round((float)$amount, 2) : 0;
    $fee_num = is_numeric($fee) ? round((float)$fee, 2) : 0;
    if ($from_ok && $to_ok && !$errors && ($amount_num + $fee_num) > $from_balance) {
        $errors[] = $tr('insufficient_balance', 'Insufficient balance! Current balance is:') . ' ' . format_money($from_balance, $lang);
    }

    $date_check = DateTime::createFromFormat('Y-m-d', $date);
    if (!$date_check || $date_check->format('Y-m-d') !== $date) $errors[] = $tr('required', 'Required') . ' (' . $tr('date', 'Date') . ')';
    if (mb_strlen($note) > 255) $errors[] = 'Note is too long (max 255 characters).';

    if (!$errors) {
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("INSERT INTO transactions (type, amount, account_id, to_account_id, note, trans_date) VALUES ('transfer', ?, ?, ?, ?, ?)");
            if (!$stmt) throw new RuntimeException('Could not prepare transfer query.');
            $stmt->bind_param('diiss', $amount_num, $from_id, $to_id, $note, $date);
            if (!$stmt->execute()) throw new RuntimeException('Could not save transfer.');
            $stmt->close();

            if ($fee_num > 0) {
                $fee_note = mb_substr($tr('transfer_fee', 'Transfer Fee') . ($note !== '' ? ' - ' . $note : ''), 0, 255);
                $fee_stmt = $conn->prepare("INSERT INTO transactions (type, amount, account_id, note, trans_date) VALUES ('expense', ?, ?, ?, ?)");
                if (!$fee_stmt) throw new RuntimeException('Could not prepare fee query.');
                $fee_stmt->bind_param('diss', $fee_num, $from_id, $fee_note, $date);
                if (!$fee_stmt->execute()) throw new RuntimeException('Could not save transfer fee.');
                $fee_stmt->close();
            }

            $conn->commit();
            $success = true;
            unset($_SESSION['csrf_token']);
            $from_id = $to_id = 0;
            $amount = '';
            $fee = '0';
            $date = date('Y-m-d');
            $note = '';
            $accounts = get_account_balances($conn);
            $account_map = [];
            foreach ($accounts as $a) $account_map[(int)$a['id']] = $a;
        } catch (Throwable $e) {
            $conn->rollback();
            $errors[] = $tr('error', 'Something went wrong') . ' ' . $e->getMessage();
        }
    }
}

$from_label = $is_bn ? 'যে অ্যাকাউন্ট থেকে' : 'From Account';
$to_label = $is_bn ? 'যে অ্যাকাউন্টে' : 'To Account';
$balance_label = $is_bn ? 'বর্তমান ব্যালেন্স' : 'Current Balance';
$number_label = $tr('account_number', $is_bn ? 'অ্যাকাউন্ট নম্বর' : 'Account Number');

require 'includes/header.php';
?>

<style>
    /* ===== ট্রান্সফার পেজের ডিজাইন (কম্প্যাক্ট) ===== */
    .transfer-page {
        max-width: 1120px;
        margin: 10px auto 30px;
        padding: 0 16px;
        color: var(--text)
    }

    /* হেডিং ছোট করা হলো */
    .tf-heading {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0 0 16px
    }

    .tf-heading-icon {
        width: 46px;
        height: 46px;
        display: grid;
        place-items: center;
        border-radius: 14px;
        background: linear-gradient(135deg, #2563eb, #06b6d4);
        color: #fff;
        font-size: 22px;
        box-shadow: 0 6px 15px rgba(37, 99, 235, .2)
    }

    .tf-heading h1 {
        margin: 0;
        font-size: 1.45rem;
        font-weight: 800
    }

    .tf-heading p {
        margin: 2px 0 0;
        color: var(--muted);
        font-size: .85rem
    }

    /* মেইন কার্ড */
    .tf-card {
        padding: 20px;
        border: 1px solid var(--border);
        border-radius: 16px;
        background: var(--card);
        box-shadow: var(--shadow)
    }

    [data-theme="dark"] .tf-card {
        background: linear-gradient(145deg, #101e39, #0b162d);
        border-color: #203657
    }

    /* অ্যাকাউন্ট সিলেকশন বক্স (কম্প্যাক্ট) */
    .tf-accounts {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 40px minmax(0, 1fr);
        gap: 12px;
        align-items: center;
        margin-bottom: 18px
    }

    .tf-account {
        padding: 12px 14px;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--bg);
        min-width: 0
    }

    [data-theme="dark"] .tf-account {
        background: #0f172a;
        border-color: #334155
    }

    .tf-account.from {
        border-color: rgba(59, 130, 246, .4)
    }

    .tf-account.to {
        border-color: rgba(16, 185, 129, .4)
    }

    .tf-account-title {
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--text)
    }

    .tf-icon {
        width: 28px;
        height: 28px;
        border-radius: 8px;
        display: grid;
        place-items: center;
        background: rgba(37, 99, 235, .15);
        color: #3b82f6;
        font-size: 16px
    }

    .tf-account.to .tf-icon {
        background: rgba(16, 185, 129, .15);
        color: #10b981
    }

    /* ইনপুট ফিল্ড */
    .tf-select,
    .tf-input {
        width: 100%;
        height: 42px;
        padding: 0 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--card);
        color: var(--text);
        font: inherit;
        font-size: 14px;
        box-sizing: border-box;
        outline: none;
        transition: .2s
    }

    [data-theme="dark"] .tf-select,
    [data-theme="dark"] .tf-input {
        background: #101e35;
        border-color: #203657
    }

    .tf-select:focus,
    .tf-input:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, .15)
    }

    /* মেটা ডাটা (নম্বর ও ব্যালেন্স) */
    .tf-meta {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 8px;
        margin-top: 8px
    }

    .tf-meta-label {
        font-size: 10px;
        color: var(--muted);
        margin-bottom: 2px
    }

    .tf-number {
        font-size: 11px;
        color: var(--muted);
        overflow-wrap: anywhere
    }

    .tf-balance {
        font-size: 13px;
        font-weight: 800;
        color: #1685ff;
        white-space: nowrap
    }

    .tf-account.to .tf-balance {
        color: #10b981
    }

    /* সোয়াপ আইকন */
    .tf-swap {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        color: #fff;
        background: linear-gradient(135deg, #2563eb, #0ea5e9);
        font-size: 18px;
        box-shadow: 0 4px 10px rgba(37, 99, 235, .2)
    }

    /* নিচের ফর্ম গ্রিড (২ কলাম) */
    .tf-fields {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px
    }

    .tf-field {
        min-width: 0
    }

    .tf-field.full {
        grid-column: 1/-1
    }

    .tf-field label {
        display: block;
        font-size: 13px;
        font-weight: 700;
        margin: 0 0 6px;
        color: var(--text)
    }

    .tf-hint {
        display: block;
        margin-top: 4px;
        color: var(--muted);
        font-size: 11px;
        line-height: 1.4
    }

    /* সারাংশ (Summary) বক্স */
    .tf-summary {
        margin-top: 16px;
        padding: 14px 16px;
        border: 1px solid rgba(59, 130, 246, .2);
        border-radius: 12px;
        background: rgba(37, 99, 235, .03)
    }

    [data-theme="dark"] .tf-summary {
        background: rgba(37, 99, 235, .08)
    }

    .tf-summary-title {
        font-size: 13px;
        font-weight: 800;
        margin-bottom: 8px;
        color: var(--text)
    }

    .tf-row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        padding: 4px 0;
        font-size: 13px;
        color: var(--muted)
    }

    .tf-row strong {
        color: var(--text)
    }

    .tf-row.total {
        border-top: 1px solid var(--border);
        margin-top: 4px;
        padding-top: 8px;
        color: var(--text);
        font-weight: 800
    }

    .tf-row.total strong {
        font-size: 15px;
        color: var(--transfer)
    }

    .tf-remaining {
        font-size: 12px;
        color: var(--muted);
        margin-top: 6px
    }

    /* অ্যাকশন বাটন */
    .tf-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 18px
    }

    .tf-btn {
        min-height: 42px;
        padding: 0 18px;
        border: 0;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        font: inherit;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: .2s
    }

    .tf-primary {
        color: white;
        background: linear-gradient(135deg, #2563eb, #0ea5e9);
        box-shadow: 0 4px 12px rgba(37, 99, 235, .2)
    }

    .tf-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 15px rgba(37, 99, 235, .3)
    }

    .tf-secondary {
        color: var(--text);
        background: var(--bg);
        border: 1px solid var(--border)
    }

    [data-theme="dark"] .tf-secondary {
        background: rgba(255, 255, 255, .05)
    }

    /* অ্যালার্ট */
    .tf-alert {
        padding: 12px 16px;
        border-radius: 10px;
        margin-bottom: 16px;
        font-size: 14px
    }

    .tf-success {
        background: rgba(16, 185, 129, .1);
        border: 1px solid rgba(16, 185, 129, .2);
        color: var(--income)
    }

    .tf-error {
        background: rgba(239, 68, 68, .1);
        border: 1px solid rgba(239, 68, 68, .2);
        color: var(--expense)
    }

    /* মোবাইল রেসপন্সিভ */
    @media(max-width:680px) {
        .transfer-page {
            padding: 0 12px;
            margin: 10px auto 20px
        }

        .tf-accounts {
            grid-template-columns: 1fr;
            gap: 10px
        }

        .tf-swap {
            margin: auto;
            transform: rotate(90deg);
            width: 32px;
            height: 32px
        }

        .tf-fields {
            grid-template-columns: 1fr
        }

        .tf-field.full {
            grid-column: auto
        }

        .tf-actions {
            display: grid;
            grid-template-columns: 1fr 1fr
        }
    }

    @media(max-width:380px) {
        .tf-meta {
            align-items: flex-start;
            flex-direction: column
        }

        .tf-actions {
            grid-template-columns: 1fr
        }
    }
</style>

<main class="transfer-page">
    <div class="tf-heading">
        <div class="tf-heading-icon">⇄</div>
        <div>
            <h1><?= $h($page_title) ?></h1>
            <p><?= $is_bn ? 'এক অ্যাকাউন্ট থেকে অন্য অ্যাকাউন্টে টাকা পাঠান' : 'Transfer money between your accounts' ?></p>
        </div>
    </div>

    <?php if ($success): ?><div class="tf-alert tf-success"><?= $h($tr('saved', 'Transfer saved successfully.')) ?></div><?php endif; ?>
    <?php if ($errors): ?>
        <div class="tf-alert tf-error">
            <ul style="margin:0;padding-left:20px"><?php foreach ($errors as $e): ?><li><?= $h($e) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <section class="tf-card">
        <form method="post" action="" novalidate id="transferForm">
            <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">

            <div class="tf-accounts">
                <div class="tf-account from">
                    <div class="tf-account-title"><span class="tf-icon">↗</span><?= $h($from_label) ?> *</div>
                    <select class="tf-select" id="account_id" name="account_id" required>
                        <option value=""><?= $h($tr('select', '— Select —')) ?></option>
                        <?php foreach ($accounts as $a): $id = (int)$a['id']; ?>
                            <option value="<?= $id ?>" data-balance="<?= $h($a['balance'] ?? 0) ?>" data-number="<?= $h($is_bn ? to_bn_number($a['account_number'] ?? '') : ($a['account_number'] ?? '')) ?>" <?= $from_id === $id ? 'selected' : '' ?>>
                                <?= $h(label_bilingual($a['name'] ?? '', $a['name_bn'] ?? '', $lang)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="tf-meta">
                        <div>
                            <div class="tf-meta-label"><?= $h($number_label) ?></div>
                            <div class="tf-number" id="fromNumber">—</div>
                        </div>
                        <div>
                            <div class="tf-meta-label"><?= $h($balance_label) ?></div>
                            <div class="tf-balance" id="fromBalance">—</div>
                        </div>
                    </div>
                </div>

                <div class="tf-swap" aria-hidden="true">⇄</div>

                <div class="tf-account to">
                    <div class="tf-account-title"><span class="tf-icon">↙</span><?= $h($to_label) ?> *</div>
                    <select class="tf-select" id="to_account_id" name="to_account_id" required>
                        <option value=""><?= $h($tr('select', '— Select —')) ?></option>
                        <?php foreach ($accounts as $a): $id = (int)$a['id']; ?>
                            <option value="<?= $id ?>" data-balance="<?= $h($a['balance'] ?? 0) ?>" data-number="<?= $h($is_bn ? to_bn_number($a['account_number'] ?? '') : ($a['account_number'] ?? '')) ?>" <?= $to_id === $id ? 'selected' : '' ?>>
                                <?= $h(label_bilingual($a['name'] ?? '', $a['name_bn'] ?? '', $lang)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="tf-meta">
                        <div>
                            <div class="tf-meta-label"><?= $h($number_label) ?></div>
                            <div class="tf-number" id="toNumber">—</div>
                        </div>
                        <div>
                            <div class="tf-meta-label"><?= $h($balance_label) ?></div>
                            <div class="tf-balance" id="toBalance">—</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tf-fields">
                <div class="tf-field">
                    <label for="amount"><?= $h($tr('amount', 'Amount')) ?> *</label>
                    <input class="tf-input" type="number" step="0.01" min="0.01" inputmode="decimal" id="amount" name="amount" value="<?= $h($amount) ?>" placeholder="<?= $is_bn ? 'টাকার পরিমাণ লিখুন' : 'Enter amount' ?>" required>
                </div>
                <div class="tf-field">
                    <label for="transfer_fee"><?= $h($tr('transfer_fee_optional', 'Transfer Fee (Optional)')) ?></label>
                    <input class="tf-input" type="number" step="0.01" min="0" inputmode="decimal" id="transfer_fee" name="transfer_fee" value="<?= $h($fee) ?>" placeholder="0.00">
                    <small class="tf-hint"><?= $h($tr('transfer_fee_hint', 'This fee will be deducted from your account as an expense.')) ?></small>
                </div>
                <div class="tf-field">
                    <label for="trans_date"><?= $h($tr('date', 'Date')) ?> *</label>
                    <input class="tf-input" type="date" id="trans_date" name="trans_date" value="<?= $h($date) ?>" required>
                </div>
                <div class="tf-field">
                    <label for="note"><?= $h($tr('note', 'Note')) ?></label>
                    <input class="tf-input" type="text" id="note" name="note" maxlength="255" value="<?= $h($note) ?>" placeholder="<?= $is_bn ? 'ট্রান্সফারের কারণ বা বিবরণ' : 'Optional transfer note' ?>">
                </div>
            </div>

            <div class="tf-summary">
                <div class="tf-summary-title"><?= $h($is_bn ? 'ট্রান্সফার সারাংশ' : 'Transfer Summary') ?></div>
                <div class="tf-row"><span><?= $h($tr('amount', 'Amount')) ?></span><strong id="sumAmount">৳ 0.00</strong></div>
                <div class="tf-row"><span><?= $h($tr('transfer_fee', 'Transfer Fee')) ?></span><strong id="sumFee">৳ 0.00</strong></div>
                <div class="tf-row total"><span><?= $h($is_bn ? 'মোট কাটা হবে' : 'Total Deduction') ?></span><strong id="sumTotal">৳ 0.00</strong></div>
                <div class="tf-remaining" id="remaining"><?= $h($is_bn ? 'ট্রান্সফারের পর অবশিষ্ট' : 'Remaining after transfer') ?>: —</div>
            </div>

            <div class="tf-actions">
                <a href="index.php" class="tf-btn tf-secondary"><?= $h($tr('cancel', 'Cancel')) ?></a>
                <button type="submit" class="tf-btn tf-primary">⇄ <?= $h($is_bn ? 'টাকা ট্রান্সফার করুন' : 'Transfer Money') ?></button>
            </div>
        </form>
    </section>
</main>

<script>
    (() => {
        const $ = id => document.getElementById(id);
        const from = $('account_id'),
            to = $('to_account_id');
        const amount = $('amount'),
            fee = $('transfer_fee');
        const bn = <?= $is_bn ? 'true' : 'false' ?>;

        // বাংলা সংখ্যা কনভার্ট ফাংশন
        const toBnNumber = (num) => {
            if (!bn) return num.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            const en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
            const b = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
            let str = num.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            return str.replace(/[0-9]/g, w => b[en.indexOf(w)]);
        };

        const money = n => '৳ ' + toBnNumber(Number(n) || 0);

        const data = select => {
            const o = select.options[select.selectedIndex];
            return o && o.value ? {
                balance: Number(o.dataset.balance || 0),
                number: o.dataset.number || '—'
            } : null;
        };

        function refresh() {
            const f = data(from),
                t = data(to),
                a = Math.max(0, Number(amount.value) || 0),
                feeVal = Math.max(0, Number(fee.value) || 0),
                total = a + feeVal;
            $('fromBalance').textContent = f ? money(f.balance) : '—';
            $('toBalance').textContent = t ? money(t.balance) : '—';
            $('fromNumber').textContent = f ? f.number : '—';
            $('toNumber').textContent = t ? t.number : '—';
            $('sumAmount').textContent = money(a);
            $('sumFee').textContent = money(feeVal);
            $('sumTotal').textContent = money(total);
            $('remaining').textContent = <?= json_encode($is_bn ? 'ট্রান্সফারের পর অবশিষ্ট' : 'Remaining after transfer', JSON_UNESCAPED_UNICODE) ?> + ': ' + (f ? money(f.balance - total) : '—');
            $('remaining').style.color = f && total > f.balance ? 'var(--expense)' : '';
        }

        [from, to].forEach(el => el.addEventListener('change', refresh));
        [amount, fee].forEach(el => el.addEventListener('input', refresh));
        refresh();
    })();
</script>

<?php require 'includes/footer.php'; ?>