<?php
declare(strict_types=1);

session_start();
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit('Admin is not configured.');
}
$config = require $configPath;
$passwordHash = (string)($config['admin_password_hash'] ?? '');
if ($passwordHash === '') {
    http_response_code(503);
    exit('Set ADMIN_PASSWORD_HASH before enabling the admin panel.');
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params(['httponly' => true, 'secure' => $secure, 'samesite' => 'Strict']);
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['admin_csrf'];
$error = '';

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $postedToken)) {
        http_response_code(403);
        exit('Invalid security token.');
    }
    if (isset($_POST['logout'])) {
        $_SESSION = [];
        session_destroy();
        header('Location: admin.php');
        exit;
    }
    if (!isset($_SESSION['admin_authenticated']) && isset($_POST['password'])) {
        if (password_verify((string)$_POST['password'], $passwordHash)) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
            header('Location: admin.php');
            exit;
        }
        $error = 'رمز عبور درست نیست.';
    }
}

$requests = [];
if (!empty($_SESSION['admin_authenticated'])) {
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_port'], $config['db_name']);
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ticket_id'], $_POST['status'])) {
            $allowed = ['new', 'in_progress', 'answered', 'closed'];
            $newStatus = (string)$_POST['status'];
            $ticketId = filter_var($_POST['ticket_id'], FILTER_VALIDATE_INT);
            if ($ticketId && in_array($newStatus, $allowed, true)) {
                $update = $pdo->prepare('UPDATE support_requests SET status = :status WHERE id = :id');
                $update->execute([':status' => $newStatus, ':id' => $ticketId]);
            }
        }
        $requests = $pdo->query('SELECT id, name, email, subject, message, status, created_at FROM support_requests ORDER BY created_at DESC LIMIT 200')->fetchAll();
    } catch (Throwable $exception) {
        error_log('Support admin error: ' . $exception->getMessage());
        $error = 'خطا در اتصال به پایگاه داده.';
    }
}
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>مدیریت درخواست‌های پشتیبانی</title>
<style>
body{font-family:tahoma,Arial,sans-serif;background:#081327;color:#f4f8ff;margin:0;padding:24px}
main{max-width:1000px;margin:auto}.panel,article{background:#10213c;border:1px solid #294765;border-radius:15px;padding:20px;margin:16px 0}
input,select,button{font:inherit;padding:10px;border-radius:8px;border:1px solid #46617e}
button{background:#00d9ff;color:#061326;font-weight:bold;cursor:pointer}
label{display:block;margin:12px 0 6px}.muted{color:#b5c8dc}.error{color:#ff9a9a}
.ticket-head{display:flex;justify-content:space-between;gap:15px;flex-wrap:wrap}
pre{white-space:pre-wrap;overflow-wrap:anywhere;font:inherit}
</style></head><body><main><h1>پنل مدیریت پشتیبانی</h1>
<?php if (empty($_SESSION['admin_authenticated'])): ?>
<div class="panel"><p class="muted">برای مشاهده درخواست‌ها وارد شوید.</p>
<?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
<label for="password">رمز عبور مدیر</label><input id="password" name="password" type="password" required autocomplete="current-password">
<p><button type="submit">ورود</button></p></form></div>
<?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button type="submit" name="logout" value="1">خروج</button></form>
<p class="muted">آخرین ۲۰۰ درخواست. اطلاعات کاربران را محرمانه نگه دارید.</p>
<?php foreach ($requests as $request): ?>
<article><div class="ticket-head"><strong>#<?= (int)$request['id'] ?> — <?= h($request['subject']) ?></strong><span><?= h($request['created_at']) ?></span></div>
<p><?= h($request['name']) ?> · <a href="mailto:<?= h($request['email']) ?>"><?= h($request['email']) ?></a></p>
<pre><?= h($request['message']) ?></pre>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="ticket_id" value="<?= (int)$request['id'] ?>">
<label for="status-<?= (int)$request['id'] ?>">وضعیت</label>
<select id="status-<?= (int)$request['id'] ?>" name="status">
<?php foreach (['new'=>'جدید','in_progress'=>'در حال بررسی','answered'=>'پاسخ داده‌شده','closed'=>'بسته‌شده'] as $value=>$label): ?>
<option value="<?= h($value) ?>" <?= $request['status']===$value?'selected':'' ?>><?= h($label) ?></option>
<?php endforeach; ?>
</select> <button type="submit">ذخیره وضعیت</button></form></article>
<?php endforeach; ?>
<?php endif; ?>
</main></body></html>
