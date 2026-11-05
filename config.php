<?php
// টেস্টের সময় error দেখার জন্য — অনলাইনে দেওয়ার আগে নিচের দুটো লাইন কমেন্ট করবেন
error_reporting(E_ALL);
ini_set('display_errors', '1');

// সেশন শুরু (পরে theme ও lang মনে রাখতে কাজে লাগবে)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';           // XAMPP-এ ডিফল্ট পাসওয়ার্ড খালি
$DB_NAME = 'hishab_db';

// কানেকশন তৈরি
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

// কানেকশন চেক
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

// বাংলা লেখার জন্য MUST
$conn->set_charset('utf8mb4');

// টাইমজোন বাংলাদেশ
date_default_timezone_set('Asia/Dhaka');

// ===== ভাষা ও থিম সেটিং লোড =====
$settings = $conn->query("SELECT theme, lang FROM settings WHERE id=1")->fetch_assoc();
if (!$settings) {
    // সেফটি: সেটিংস না থাকলে ডিফল্ট
    $conn->query("INSERT INTO settings (id, theme, lang) VALUES (1, 'light', 'bn')");
    $settings = ['theme' => 'light', 'lang' => 'bn'];
}

// AJAX থেকে ভাষা/থিম বদলালে সেশন থেকে অগ্রাধিকার
$lang   = $_SESSION['lang']   ?? $settings['lang'];
$theme  = $_SESSION['theme']  ?? $settings['theme'];

// অনুমোদিত মানের বাইরে কিছু হলে ডিফল্টে ফেরা (নিরাপত্তা)
if (!in_array($lang, ['en', 'bn'], true))  $lang = 'bn';
if (!in_array($theme, ['light', 'dark'], true)) $theme = 'light';

$t = require __DIR__ . "/lang/{$lang}.php";
require_once __DIR__ . '/includes/functions.php';
