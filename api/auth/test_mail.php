<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = 'smtp.hostinger.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'bugs@moajmalnk.in';
    $mail->Password = 'Codo@8848';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->setFrom('bugs@moajmalnk.in', 'Bug Ricer');
    $mail->addAddress('moajmalnk@gmail.com');
    $mail->Subject = 'Test OTP';
    $mail->Body = 'This is a test OTP email.';
    $mail->send();
    echo "Mail sent!";
} catch (Exception $e) {
    echo "Mail error: " . $mail->ErrorInfo;
}
