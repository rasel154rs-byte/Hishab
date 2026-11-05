<?php
// সব পেজে ব্যবহারযোগ্য হেল্পার ফাংশন

/**
 * একাউন্টের ব্যালেন্স হিসাব:
 * opening_balance + income - expense - transfer_out + transfer_in
 */
function get_account_balances(mysqli $conn): array
{
    $sql = "
        SELECT a.id, a.name, a.name_bn, a.account_number, a.opening_balance,
            a.opening_balance
            + COALESCE(SUM(CASE WHEN t.type='income'   AND t.account_id=a.id    THEN t.amount END),0)
            - COALESCE(SUM(CASE WHEN t.type='expense'  AND t.account_id=a.id    THEN t.amount END),0)
            - COALESCE(SUM(CASE WHEN t.type='transfer' AND t.account_id=a.id    THEN t.amount END),0)
            + COALESCE(SUM(CASE WHEN t.type='transfer' AND t.to_account_id=a.id THEN t.amount END),0)
            AS balance
        FROM accounts a
        LEFT JOIN transactions t
            ON a.id = t.account_id OR a.id = t.to_account_id
        WHERE a.is_active = 1
        GROUP BY a.id
        ORDER BY a.id ASC
    ";
    $result = $conn->query($sql);
    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    return $rows;
}

/**
 * মোট আয়, মোট ব্যয় ও মোট ব্যালেন্স
 * (সব সক্রিয় একাউন্টের opening_balance + income - expense; transfer ধরা হয় না কারণ একই পকেটে ঘোরে)
 */
function get_totals(mysqli $conn): array
{
    $totals = ['income' => 0.0, 'expense' => 0.0, 'balance' => 0.0];

    // আয় ও ব্যয়ের যোগফল
    $res = $conn->query("
        SELECT
          COALESCE(SUM(CASE WHEN type='income'  THEN amount END),0) AS total_income,
          COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) AS total_expense
        FROM transactions
    ");
    $row = $res->fetch_assoc();
    $totals['income']  = (float)$row['total_income'];
    $totals['expense'] = (float)$row['total_expense'];

    // সব সক্রিয় একাউন্টের ওপেনিং ব্যালেন্সের যোগফল
    $res2 = $conn->query("SELECT COALESCE(SUM(opening_balance),0) AS opening FROM accounts WHERE is_active=1");
    $opening = (float)$res2->fetch_assoc()['opening'];

    // মোট ব্যালেন্স = ওপেনিং + আয় - ব্যয় (transfer মোটে কোনো প্রভাব ফেলে না)
    $totals['balance'] = $opening + $totals['income'] - $totals['expense'];

    return $totals;
}

/**
 * সাম্প্রতিক লেনদেন (নতুন আগে)
 */
function get_recent_transactions(mysqli $conn, int $limit = 10): array
{
    $limit = max(1, min(50, $limit)); // সীমা নির্ধারণ, ইন্টিজার
    $sql = "
        SELECT t.id, t.type, t.amount, t.trans_date, t.note,
               a.name AS account_name, a.name_bn AS account_name_bn,
               c.name AS category_name, c.name_bn AS category_name_bn,
               ta.name AS to_account_name, ta.name_bn AS to_account_name_bn
        FROM transactions t
        JOIN accounts a   ON a.id = t.account_id
        LEFT JOIN categories c ON c.id = t.category_id
        LEFT JOIN accounts ta  ON ta.id = t.to_account_id
        ORDER BY t.trans_date DESC, t.id DESC
        LIMIT {$limit}
    ";
    $result = $conn->query($sql);
    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }
    return $rows;
}

/**
 * সংখ্যায় টাকা ফরম্যাট — বাংলা সংখ্যা বা ইংরেজি, অ্যাপের ভাষা অনুযায়ী
 */
function format_money(float $amount, string $lang = 'bn'): string
{
    $formatted = number_format($amount, 2, '.', ',');
    if ($lang === 'bn') {
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $bn = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
        $formatted = str_replace($en, $bn, $formatted);
    }
    return '৳ ' . $formatted;
}

/**
 * ইংরেজি সংখ্যা থেকে বাংলা সংখ্যায় রূপান্তর (যেমন: 12 থেকে ১২)
 */
function to_bn_number($number): string
{
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $bn = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
    return str_replace($en, $bn, (string)$number);
}

/**
 * তারিখ দেখানোর ফরম্যাট (Y-m-d ডাটাবেসে, পড়ার সময় সুন্দর ফরম্যাটে)
 */
function format_date(string $date, string $lang = 'bn'): string
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d) return $date;
    if ($lang === 'bn') {
        $months = [1 => 'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];
        $day = str_replace(range(0, 9), ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'], (string)$d->format('j'));
        return $day . ' ' . $months[(int)$d->format('n')] . ' ' . str_replace(range(0, 9), ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'], $d->format('Y'));
    }
    // ইংরেজির জন্যও পুরো মাসের নাম দিতে চাইলে
    //return $d->format('d F Y'); 
    return $d->format('d M Y');
}

/**
 * HTML-এ দেখানোর জন্য বাংলা নাম থাকলে সেটা, নাহলে ইংরেজি
 */
function label_bilingual(?string $name, ?string $name_bn, string $lang): string
{
    if ($lang === 'bn' && $name_bn !== null && $name_bn !== '') return $name_bn;
    return $name ?? '';
}


/**
 * CSRF টোকেন তৈরি ও যাচাই (ফর্ম সাবমিটের নিরাপত্তা)
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool
{
    return !empty($_SESSION['csrf_token']) && !empty($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}


/**
 * HTML এস্কেপ করার জন্য সেফ হেল্পার (null সেফ)
 * এটি null ভ্যালু পেলে এরর দেয় না, বরং খালি স্ট্রিং রিটার্ন করে।
 */
function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}