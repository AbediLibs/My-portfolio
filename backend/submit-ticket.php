<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    respond(503, ['ok' => false, 'error' => 'Support service is not configured']);
}
$config = require $configPath;

/* Restrict browser requests to the configured website origin. */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin = (string)($config['allowed_origin'] ?? '');
if ($origin !== '' && $allowedOrigin !== '' && !hash_equals($allowedOrigin, $origin)) {
    respond(403, ['ok' => false, 'error' => 'Origin not allowed']);
}
if ($origin !== '' && $allowedOrigin !== '' && hash_equals($allowedOrigin, $origin)) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
}

if (!empty($_POST['website'])) {
    respond(200, ['ok' => true, 'message' => 'Request received']);
}

/* Lightweight session cooldown. Add host-level rate limiting / CAPTCHA before public launch. */
$now = time();
$lastRequest = (int)($_SESSION['last_support_request'] ?? 0);
if ($lastRequest > 0 && ($now - $lastRequest) < 20) {
    respond(429, ['ok' => false, 'error' => 'Please wait before sending another request']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = str_contains($contentType, 'application/json')
    ? json_decode((string)file_get_contents('php://input'), true)
    : $_POST;
if (!is_array($input)) {
    respond(400, ['ok' => false, 'error' => 'Invalid request body']);
}

$name = trim((string)($input['name'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$subject = trim((string)($input['subject'] ?? ''));
$message = trim((string)($input['message'] ?? ''));

if (
    $name === '' || mb_strlen($name) > 80 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120 ||
    $subject === '' || mb_strlen($subject) > 120 ||
    $message === '' || mb_strlen($message) > 4000
) {
    respond(422, ['ok' => false, 'error' => 'Please check the submitted fields']);
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_port'],
        $config['db_name']
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

    $statement = $pdo->prepare(
        'INSERT INTO support_requests (name, email, subject, message) VALUES (:name, :email, :subject, :message)'
    );
    $statement->execute([
        ':name' => $name,
        ':email' => $email,
        ':subject' => $subject,
        ':message' => $message
    ]);
    $ticketId = (int)$pdo->lastInsertId();
    $_SESSION['last_support_request'] = $now;

    /* mail() depends on the PHP host's mail configuration. Database storage is primary. */
    $recipient = (string)($config['support_email'] ?? '');
    $fromEmail = (string)($config['mail_from_email'] ?? '');
    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $mailSubject = 'New support request #' . $ticketId . ': ' . $subject;
        $mailBody = "New support request\n\nTicket: #{$ticketId}\nName: {$name}\nEmail: {$email}\nSubject: {$subject}\n\nMessage:\n{$message}\n";
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . (string)($config['mail_from_name'] ?? 'Portfolio Support') . ' <' . $fromEmail . '>',
            'Reply-To: ' . $email
        ];
        @mail($recipient, mb_encode_mimeheader($mailSubject, 'UTF-8'), $mailBody, implode("\r\n", $headers));
    }

    respond(201, ['ok' => true, 'ticket_id' => $ticketId, 'message' => 'Your request was saved']);
} catch (Throwable $error) {
    error_log('Support request failed: ' . $error->getMessage());
    respond(500, ['ok' => false, 'error' => 'Could not save the request']);
}
