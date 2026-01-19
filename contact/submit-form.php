<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// PHPMailer includes
require __DIR__ . '/phpmailer/Exception.php';
require __DIR__ . '/phpmailer/PHPMailer.php';
require __DIR__ . '/phpmailer/SMTP.php';

// Load config
$config = require '/home2/lexrj81/private/mail-config.php';

$SMTP_HOST = $config['smtp_host'];
$SMTP_PORT = (int)$config['smtp_port'];
$SMTP_USER = $config['smtp_user'];
$SMTP_PASS = $config['smtp_pass'];



// ---- reCAPTCHA secret ----
$recaptcha_secret = '6LeqwU4sAAAAAOVITp9XZl5nVR5pOI4kaXJyn_fh';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(403);
    exit("Invalid request method");
}

// ---- Verify reCAPTCHA ----
$recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
if ($recaptcha_response === '') {
    header("Location: /contact/contact.html?error=captcha");
    exit;
}

$recaptcha_url = 'https://www.google.com/recaptcha/api/siteverify';
$recaptcha_data = [
    'secret'   => $recaptcha_secret,
    'response' => $recaptcha_response,
];

$recaptcha_context = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($recaptcha_data),
        'timeout' => 8,
    ]
]);

$recaptcha_verify = @file_get_contents($recaptcha_url, false, $recaptcha_context);
if ($recaptcha_verify === false) {
    header("Location: /contact/contact.html?error=captcha");
    exit;
}

$recaptcha_result = json_decode($recaptcha_verify);
$success = is_object($recaptcha_result) && !empty($recaptcha_result->success);
$score   = isset($recaptcha_result->score) ? (float)$recaptcha_result->score : 0.0;

if (!$success || $score < 0.5) {
    header("Location: /contact/contact.html?error=captcha");
    exit;
}

// ---- Sanitize inputs ----
$name         = strip_tags(trim($_POST["name"] ?? ''));
$email        = trim($_POST["email"] ?? '');
$phone        = strip_tags(trim($_POST["phone"] ?? ''));
$company      = strip_tags(trim($_POST["company"] ?? ''));
$employees    = strip_tags(trim($_POST["employees"] ?? ''));
$service_type = strip_tags(trim($_POST["service_type"] ?? ''));
$has_provider = strip_tags(trim($_POST["has_provider"] ?? ''));
$message      = strip_tags(trim($_POST["message"] ?? ''));

if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: /contact/contact.html?error=validation");
    exit;
}

// Interests (supports interests[] or single)
$interests_raw = $_POST["interests"] ?? null;
if (is_array($interests_raw)) {
    $interests = implode(", ", array_map(fn($v) => strip_tags(trim((string)$v)), $interests_raw));
} elseif (is_string($interests_raw) && trim($interests_raw) !== '') {
    $interests = strip_tags(trim($interests_raw));
} else {
    $interests = "None selected";
}

// ---- Build email ----
$to      = "help@simple.tech";
$subject = "New Contact Form: $service_type - $company";

$body  = "=== CONTACT FORM SUBMISSION ===\n\n";
$body .= "Name: $name\n";
$body .= "Email: $email\n";
$body .= "Phone: $phone\n";
$body .= "Company: $company\n";
$body .= "Employees: $employees\n\n";
$body .= "Service Type: $service_type\n";
$body .= "Has IT Provider: $has_provider\n";
$body .= "Areas of Interest: $interests\n\n";
$body .= "Message:\n$message\n\n";
$body .= "---\n";
$body .= "reCAPTCHA Score: $score\n";
$body .= "Submitted: " . date('Y-m-d H:i:s') . "\n";

try {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $SMTP_HOST;           // smtp.office365.com
    $mail->SMTPAuth   = true;
    $mail->Username   = $SMTP_USER;           // mailbox UPN
    $mail->Password   = $SMTP_PASS;           // mailbox password
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $SMTP_PORT;           // 587

    // From MUST match the authenticated mailbox for best deliverability
    $mail->setFrom($SMTP_USER, 'Simple.Tech Contact Form');
    $mail->addAddress($to);
    $mail->addReplyTo($email, $name);

    $mail->Subject = $subject;
    $mail->Body    = $body;

    $mail->send();
    header("Location: /contact/thank-you.html");
    exit;

} catch (Exception $e) {
    // If you want debug info, uncomment:
    // error_log("Mailer Error: " . $mail->ErrorInfo);
    header("Location: /contact/contact.html?error=email");
    exit;
}
