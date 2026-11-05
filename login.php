<?php
require 'config.php';

$errors = [];
$success = false;

// ১. প্রথমেই লগআউট চেক করতে হবে (লগইন চেকের আগে)
if (isset($_GET['logout'])) {
    $_SESSION = array();
    
    // ব্রাউজারের কুকি মুছে ফেলা
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
    session_start(); // মেসেজ দেখানোর জন্য নতুন সেশন
    $success = true;
}

// ২. লগআউট হয়ে গেলে এখন যদি সেশনে ইউজার থাকে (অর্থাৎ লগইন করা থাকে), তবেই ড্যাশবোর্ডে যাবে
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ৩. ফর্ম সাবমিট হলে
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $errors[] = $t['login_security_error'];
    }

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
    $errors[] = $t['login_required'];
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id, password, name FROM users WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'] ?: $username;
            header('Location: index.php');
            exit;
        } else {
    $errors[] = $t['login_error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bn" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>লগইন — হিসাব</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container" style="max-width: 400px; margin-top: 10vh;">
  <div class="card">
    <h2 style="text-align: center;">💰 <?= htmlspecialchars($t['app_name']) ?></h2>
    
    <!-- লগআউট মেসেজ -->
    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($t['logout_success']) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-error"><?= htmlspecialchars($errors[0]) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      
           <div class="form-group">
        <label><?= htmlspecialchars($t['username']) ?></label>
        <input type="text" name="username" required autofocus>
      </div>
      
      <div class="form-group">
        <label><?= htmlspecialchars($t['password']) ?></label>
        <input type="password" name="password" required>
      </div>
      
      <button type="submit" class="btn btn-success btn-block" style="margin-top: 10px;"><?= htmlspecialchars($t['login_btn']) ?></button>
    </form>
  </div>
</div>

<?php if ($success): ?>
<script>
  // 5 সেকেন্ড (5000 মিলিসেকেন্ড) পর URL থেকে ?logout=1 মুছে ফেলবে
  setTimeout(function() {
    window.history.replaceState({}, document.title, window.location.pathname);
  }, 5000); 
</script>
<?php endif; ?>

</body>
</html>