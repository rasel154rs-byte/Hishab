<?php
require 'config.php';
require 'includes/auth.php';

$page_title = $t['dashboard'];

$totals   = get_totals($conn);
$accounts = get_account_balances($conn);
$recent   = get_recent_transactions($conn, 10);

// ===== Analytics data: last 6 months (real database values) =====
$chart_months = [];
$month_cursor = new DateTimeImmutable('first day of this month');
for ($i = 5; $i >= 0; $i--) {
    $month_date = $month_cursor->modify('-' . $i . ' months');
    $chart_months[$month_date->format('Y-m')] = [
        'date' => $month_date,
        'income' => 0.0,
        'expense' => 0.0,
    ];
}

$chart_start = array_values($chart_months)[0]['date']->format('Y-m-01');
$chart_end = date('Y-m-d');
$chart_sql = "SELECT DATE_FORMAT(trans_date, '%Y-%m') AS month_key,
                     SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income_total,
                     SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense_total
              FROM transactions
              WHERE trans_date >= ? AND trans_date <= ?
                AND type IN ('income', 'expense')
              GROUP BY DATE_FORMAT(trans_date, '%Y-%m')";
$chart_stmt = $conn->prepare($chart_sql);
if ($chart_stmt) {
    $chart_stmt->bind_param('ss', $chart_start, $chart_end);
    if ($chart_stmt->execute()) {
        $chart_result = $chart_stmt->get_result();
        while ($chart_row = $chart_result->fetch_assoc()) {
            $key = $chart_row['month_key'];
            if (isset($chart_months[$key])) {
                $chart_months[$key]['income'] = (float)$chart_row['income_total'];
                $chart_months[$key]['expense'] = (float)$chart_row['expense_total'];
            }
        }
    }
    $chart_stmt->close();
}

$monthly_max = 0.0;
foreach ($chart_months as $month_data) {
    $monthly_max = max($monthly_max, $month_data['income'], $month_data['expense']);
}
$monthly_max = $monthly_max > 0 ? $monthly_max : 1;

$account_max = 0.0;
foreach ($accounts as $account_data) {
    $account_max = max($account_max, abs((float)$account_data['balance']));
}
$account_max = $account_max > 0 ? $account_max : 1;

require 'includes/header.php';

$account_count = count($accounts);
$is_bn = isset($lang) && $lang === 'bn';

// ড্যাশবোর্ডের সব লেখা lang ফাইল থেকে নেওয়া হচ্ছে, না থাকলে ডিফল্ট খালি স্ট্রিং থাকবে
$dashboard_subtitle  = $t['dashboard_subtitle'] ?? '';
$account_count_label = $t['total_accounts'] ?? $t['accounts'] ?? '';
$view_all_label      = $t['view_all_transactions'] ?? '';
$empty_accounts_label = $t['no_accounts_added'] ?? $t['no_data'] ?? '';
$empty_recent_label  = $t['no_transactions_yet'] ?? $t['no_data'] ?? '';
$monthly_title       = $t['monthly_income_vs_expense'] ?? '';
$account_chart_title = $t['balance_by_account'] ?? '';
$income_label        = $t['income'] ?? '';
$expense_label       = $t['expense'] ?? '';
$no_chart_data       = $t['no_data_available'] ?? $t['no_data'] ?? '';
$month_names         = $t['months_short'] ?? [];

if (!is_array($month_names) || count($month_names) < 12) {
    $month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
}
?>

<div class="dashboard-page">

    <!-- Dashboard heading -->
    <div class="dashboard-hero">
        <div class="dashboard-hero-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 3v18h18" />
                <path d="M8 15V9" />
                <path d="M13 15V5" />
                <path d="M18 15v-7" />
            </svg>
        </div>
        <div>
            <h1><?= h($t['dashboard'] ?? '') ?></h1>
            <p><?= h($dashboard_subtitle) ?></p>
        </div>
    </div>

    <!-- Summary cards -->
    <div class="dashboard-stats">

        <div class="dashboard-stat balance">
            <div class="dashboard-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="5" width="18" height="14" rx="2" />
                    <path d="M3 10h18" />
                    <path d="M7 15h3" />
                </svg>
            </div>
            <div class="dashboard-stat-copy">
                <div class="dashboard-stat-label"><?= h($t['total_balance'] ?? '') ?></div>
                <div class="dashboard-stat-value"><?= format_money($totals['balance'], $lang) ?></div>
            </div>
        </div>

        <div class="dashboard-stat income">
            <div class="dashboard-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 19V5" />
                    <path d="m5 12 7-7 7 7" />
                    <path d="M4 21h16" />
                </svg>
            </div>
            <div class="dashboard-stat-copy">
                <div class="dashboard-stat-label"><?= h($t['total_income'] ?? '') ?></div>
                <div class="dashboard-stat-value"><?= format_money($totals['income'], $lang) ?></div>
            </div>
        </div>

        <div class="dashboard-stat expense">
            <div class="dashboard-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14" />
                    <path d="m19 12-7 7-7-7" />
                    <path d="M4 3h16" />
                </svg>
            </div>
            <div class="dashboard-stat-copy">
                <div class="dashboard-stat-label"><?= h($t['total_expense'] ?? '') ?></div>
                <div class="dashboard-stat-value"><?= format_money($totals['expense'], $lang) ?></div>
            </div>
        </div>

        <div class="dashboard-stat accounts">
            <div class="dashboard-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="5" width="18" height="14" rx="2" />
                    <path d="M7 9h10M7 13h5M7 16h3" />
                </svg>
            </div>
            <div class="dashboard-stat-copy">
                <div class="dashboard-stat-label"><?= h($account_count_label) ?></div>
                <div class="dashboard-stat-value">
                    <?= $is_bn ? to_bn_number($account_count) : $account_count ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Financial analytics: monthly comparison + account balances -->
    <div class="dashboard-analytics">
        <section class="dashboard-panel dashboard-chart-panel">
            <div class="dashboard-panel-heading">
                <div class="dashboard-panel-title">
                    <span class="dashboard-panel-title-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 3v18h18" />
                            <path d="M8 15V9" />
                            <path d="M13 15V5" />
                            <path d="M18 15v-7" />
                        </svg>
                    </span>
                    <h2><?= h($monthly_title) ?></h2>
                </div>
                <div class="dashboard-chart-legend">
                    <span class="dashboard-legend-item"><i class="dashboard-legend-dot income"></i><?= h($income_label) ?></span>
                    <span class="dashboard-legend-item"><i class="dashboard-legend-dot expense"></i><?= h($expense_label) ?></span>
                </div>
            </div>

            <?php if ($monthly_max <= 1 && array_sum(array_column($chart_months, 'income')) == 0 && array_sum(array_column($chart_months, 'expense')) == 0): ?>
                <div class="dashboard-empty"><?= h($no_chart_data) ?></div>
            <?php else: ?>
                <div class="dashboard-month-chart" role="img" aria-label="<?= h($monthly_title) ?>">
                    <?php foreach ($chart_months as $month_data):
                        $income_height = ($month_data['income'] / $monthly_max) * 100;
                        $expense_height = ($month_data['expense'] / $monthly_max) * 100;
                        $month_number = (int)$month_data['date']->format('n') - 1;
                        $month_label = $is_bn ? $month_names[$month_number] : $month_data['date']->format('M');
                    ?>
                        <div class="dashboard-month-column">
                            <div class="dashboard-month-bars">
                                <span class="dashboard-month-bar income"
                                    style="height: <?= number_format($income_height, 2, '.', '') ?>%;"
                                    title="<?= h($income_label . ': ' . format_money($month_data['income'], $lang)) ?>"></span>
                                <span class="dashboard-month-bar expense"
                                    style="height: <?= number_format($expense_height, 2, '.', '') ?>%;"
                                    title="<?= h($expense_label . ': ' . format_money($month_data['expense'], $lang)) ?>"></span>
                            </div>
                            <span class="dashboard-month-label"><?= h($month_label) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel dashboard-chart-panel">
            <div class="dashboard-panel-heading">
                <div class="dashboard-panel-title">
                    <span class="dashboard-panel-title-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="5" width="18" height="14" rx="2" />
                            <path d="M7 9h10M7 13h5M7 16h3" />
                        </svg>
                    </span>
                    <h2><?= h($account_chart_title) ?></h2>
                </div>
            </div>

            <?php if (empty($accounts)): ?>
                <div class="dashboard-empty"><?= h($empty_accounts_label) ?></div>
            <?php else: ?>
                <div class="dashboard-account-chart-list">
                    <?php foreach ($accounts as $acc):
                        $account_value = (float)$acc['balance'];
                        $account_width = (abs($account_value) / $account_max) * 100;
                        $account_label = label_bilingual($acc['name'] ?? '', $acc['name_bn'] ?? '', $lang);
                    ?>
                        <div class="dashboard-account-chart-item">
                            <div class="dashboard-account-chart-top">
                                <span class="dashboard-account-chart-identity">
                                    <span class="dashboard-account-chart-name"><?= h($account_label) ?></span>
                                    <?php if (!empty($acc['account_number'])): ?>
                                        <small class="dashboard-account-number">
                                            <?= h($is_bn ? to_bn_number($acc['account_number']) : $acc['account_number']) ?>
                                        </small>
                                    <?php endif; ?>
                                </span>
                                <span class="dashboard-account-chart-value"><?= format_money($account_value, $lang) ?></span>
                            </div>
                            <div class="dashboard-account-track" role="img" aria-label="<?= h($account_label . ': ' . format_money($account_value, $lang)) ?>">
                                <span class="dashboard-account-fill" style="width: <?= number_format($account_width, 2, '.', '') ?>%;"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Recent transactions -->
    <section class="dashboard-panel">
        <div class="dashboard-panel-heading">
            <div class="dashboard-panel-title">
                <span class="dashboard-panel-title-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M8 6h13M8 12h13M8 18h13" />
                        <path d="M3 6h.01M3 12h.01M3 18h.01" />
                    </svg>
                </span>
                <h2><?= h($t['recent_trans'] ?? '') ?></h2>
                <span class="dashboard-count">
                    <?= $is_bn ? to_bn_number(count($recent)) : count($recent) ?>
                </span>
            </div>

            <a href="transactions.php" class="dashboard-view-all">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 12h14M13 6l6 6-6 6" />
                </svg>
                <?= h($view_all_label) ?>
            </a>
        </div>

        <?php if (empty($recent)): ?>
            <div class="dashboard-empty">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 6h13M8 12h13M8 18h13" />
                    <path d="M3 6h.01M3 12h.01M3 18h.01" />
                </svg>
                <span><?= h($empty_recent_label) ?></span>
            </div>
        <?php else: ?>
            <div class="dashboard-table-wrap">
                <table class="dashboard-table">
                    <colgroup>
                        <col style="width:14%"> <!-- তারিখ -->
                        <col style="width:12%"> <!-- ধরন -->
                        <col style="width:22%"> <!-- একাউন্ট -->
                        <col style="width:15%"> <!-- ক্যাটাগরি -->
                        <col style="width:20%"> <!-- নোট -->
                        <col style="width:17%"> <!-- পরিমাণ -->
                    </colgroup>
                    <thead>
                        <tr>
                            <th><?= h($t['date'] ?? '') ?></th>
                            <th><?= h($t['type'] ?? '') ?></th>
                            <th><?= h($t['account'] ?? '') ?></th>
                            <th><?= h($t['category'] ?? '') ?></th>
                            <th><?= h($t['note'] ?? '') ?></th> <!-- নতুন: নোট কলাম -->
                            <th class="text-right"><?= h($t['amount'] ?? '') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $tx):
                            $acc_name = label_bilingual($tx['account_name'] ?? '', $tx['account_name_bn'] ?? '', $lang);

                            if ($tx['type'] === 'transfer') {
                                $acc_name .= ' → ' . label_bilingual(
                                    ($tx['to_account_name'] ?? ''),
                                    ($tx['to_account_name_bn'] ?? ''),
                                    $lang
                                );
                            }

                            $cat_name = $tx['type'] === 'transfer'
                                ? '—'
                                : label_bilingual(
                                    ($tx['category_name'] ?? ''),
                                    ($tx['category_name_bn'] ?? ''),
                                    $lang
                                );

                            $sign = $tx['type'] === 'income'
                                ? '+'
                                : ($tx['type'] === 'expense' ? '−' : '⇄');

                            $type_class = in_array($tx['type'], ['income', 'expense', 'transfer'], true)
                                ? $tx['type']
                                : 'transfer';

                            // নোট খালি থাকলে ড্যাশ দেখাবে
                            $note_text = ($tx['note'] ?? '') !== '' ? $tx['note'] : '—';
                        ?>
                            <tr>
                                <td><?= h(format_date($tx['trans_date'] ?? '', $lang)) ?></td>
                                <td>
                                    <span class="dashboard-type <?= h($type_class) ?>">
                                        <?php if ($tx['type'] === 'income'): ?>↑
                                        <?php elseif ($tx['type'] === 'expense'): ?>↓
                                        <?php else: ?>⇄<?php endif; ?>
                                        <?= h($t[$tx['type']] ?? $tx['type'] ?? '') ?>
                                    </span>
                                </td>
                                <td><?= h($acc_name) ?></td>
                                <td><?= h($cat_name) ?></td>
                                <td class="transaction-note" title="<?= h($tx['note'] ?? '') ?>" style="font-size: 0.85rem; color: var(--muted);">
                                    <?= h($note_text) ?>
                                </td>
                                <td class="text-right">
                                    <span class="dashboard-amount <?= h($type_class) ?>">
                                        <?= $sign ?> <?= format_money((float)$tx['amount'], $lang) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</div>

<?php require 'includes/footer.php'; ?>