<?php
// Config must be loaded before header.php
if (!isset($t)) {
    die('Config not loaded before header.');
}

$current = basename($_SERVER['PHP_SELF']);

$nav_items = [
    'index.php'        => 'dashboard',
    'add-income.php'   => 'add_income',
    'add-expense.php'  => 'add_expense',
    'transfer.php'     => 'transfer',
    'accounts.php'     => 'accounts',
    'categories.php'   => 'categories',
    'transactions.php' => 'transactions',
];

$current_page = basename($_SERVER['PHP_SELF'], '.php');
$page_css = "assets/css/{$current_page}.css";
?>
<!DOCTYPE html>
<html lang="<?= $lang === 'bn' ? 'bn' : 'en' ?>" data-theme="<?= htmlspecialchars($theme) ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= isset($page_title) ? htmlspecialchars($page_title) . ' — ' : '' ?>
        <?= htmlspecialchars($t['app_name']) ?>
    </title>

    <!-- মেইন গ্লোবাল সিএসএস -->
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- পেজের নির্দিষ্ট সিএসএস (যদি থাকে) -->
    <?php if (file_exists($page_css)): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($page_css) ?>">
    <?php endif; ?>
</head>

<body>

<header class="topbar premium-header">

    <div class="container premium-header-inner">

        <!-- BRAND -->
        <a href="index.php" class="premium-brand">
            <span class="premium-brand-name">
                <?= htmlspecialchars($t['app_name']) ?>
            </span>
        </a>

        <!-- MOBILE MENU BUTTON -->
        <button
            type="button"
            class="menu-btn premium-menu-btn"
            id="menuBtn"
            aria-label="Toggle navigation"
            aria-expanded="false"
            aria-controls="navLinks"
        >
            <span></span>
            <span></span>
            <span></span>
        </button>

        <!-- NAVIGATION -->
        <nav class="nav-links premium-nav" id="navLinks">

            <?php foreach ($nav_items as $file => $key): ?>

                <a
                    href="<?= htmlspecialchars($file) ?>"
                    class="nav-link premium-nav-link <?= $current === $file ? 'active' : '' ?>"
                    <?= $current === $file ? 'aria-current="page"' : '' ?>
                >
                    <?= htmlspecialchars($t[$key]) ?>
                </a>

            <?php endforeach; ?>

        </nav>

        <!-- HEADER TOOLS -->
        <div class="topbar-tools premium-tools">

            <!-- THEME -->
            <button
                type="button"
                class="icon-btn premium-theme-btn"
                id="themeBtn"
                title="<?= htmlspecialchars($t['theme']) ?>"
                aria-label="<?= htmlspecialchars($t['theme']) ?>"
            >
                <?= $theme === 'dark' ? '☀' : '◐' ?>
            </button>

            <!-- LANGUAGE -->
            <select
                id="langSelect"
                class="lang-select premium-lang-select"
                aria-label="<?= htmlspecialchars($t['language']) ?>"
            >
                <option value="bn" <?= $lang === 'bn' ? 'selected' : '' ?>>
                    বাংলা
                </option>

                <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>
                    English
                </option>
            </select>

            <!-- LOGOUT -->
            <a href="login.php?logout=1" class="premium-logout">
                <?= htmlspecialchars($t['logout_btn']) ?>
            </a>

        </div>

    </div>

</header>

<main class="container main-content">