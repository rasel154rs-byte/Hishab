<?php
require 'config.php';
header('Content-Type: application/json; charset=utf-8');

// শুধু POST গ্রহণ
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$key   = $_POST['key']   ?? '';
$value = $_POST['value'] ?? '';

$allowed = [
    'theme' => ['light', 'dark'],
    'lang'  => ['en', 'bn'],
];

if (!isset($allowed[$key]) || !in_array($value, $allowed[$key], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

// ডাটাবেসে সেভ
$stmt = $conn->prepare("UPDATE settings SET {$key} = ? WHERE id = 1");
// {$key} নিরাপদ কারণ $allowed চেক করা হয়েছে; value bound
$stmt->bind_param('s', $value);
$saved = $stmt->execute();
$stmt->close();

// সেশনেও রাখি
$_SESSION[$key] = $value;

echo json_encode(['ok' => (bool)$saved]);