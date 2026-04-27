<?php
/**
 * Minimal SMTP mail helper.
 * Uses STARTTLS on port 587, suitable for Gmail app-password SMTP.
 */

function isMailConfigured() {
    $config = require __DIR__ . '/../config/config.php';
    $mail = $config['mail'] ?? [];

    return !empty($mail['host'])
        && !empty($mail['port'])
        && !empty($mail['username'])
        && !empty($mail['password'])
        && !empty($mail['from_email']);
}

function smtpRead($socket) {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function smtpCommand($socket, $command, $expectedCodes) {
    fwrite($socket, $command . "\r\n");
    $response = smtpRead($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new Exception(trim($response));
    }
    return $response;
}

function encodeHeader($value) {
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function formatAddress($email, $name = '') {
    $email = trim($email);
    $name = trim($name);
    if ($name === '') {
        return '<' . $email . '>';
    }
    return encodeHeader($name) . ' <' . $email . '>';
}

function dotStuff($message) {
    $message = str_replace(["\r\n", "\r"], "\n", $message);
    $lines = explode("\n", $message);
    foreach ($lines as &$line) {
        if (isset($line[0]) && $line[0] === '.') {
            $line = '.' . $line;
        }
    }
    return implode("\r\n", $lines);
}

function sendAppEmail($toEmail, $subject, $body, $replyToEmail = '', $replyToName = '') {
    $config = require __DIR__ . '/../config/config.php';
    $mail = $config['mail'] ?? [];

    if (!isMailConfigured()) {
        return [false, 'Mail is not configured.'];
    }

    $host = $mail['host'];
    $port = (int)$mail['port'];
    $fromEmail = $mail['from_email'];
    $fromName = $mail['from_name'] ?: $fromEmail;

    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT
    );

    if (!$socket) {
        return [false, "SMTP connection failed: {$errstr}"];
    }

    stream_set_timeout($socket, 15);

    try {
        $greeting = smtpRead($socket);
        if ((int)substr($greeting, 0, 3) !== 220) {
            throw new Exception(trim($greeting));
        }

        smtpCommand($socket, 'EHLO localhost', [250]);
        smtpCommand($socket, 'STARTTLS', [220]);

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception('Could not enable TLS.');
        }

        smtpCommand($socket, 'EHLO localhost', [250]);
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($mail['username']), [334]);
        smtpCommand($socket, base64_encode($mail['password']), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $headers = [
            'From: ' . formatAddress($fromEmail, $fromName),
            'To: ' . formatAddress($toEmail),
            'Subject: ' . encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        if ($replyToEmail !== '') {
            $headers[] = 'Reply-To: ' . formatAddress($replyToEmail, $replyToName);
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        fwrite($socket, dotStuff($message) . "\r\n.\r\n");
        $response = smtpRead($socket);
        if ((int)substr($response, 0, 3) !== 250) {
            throw new Exception(trim($response));
        }

        smtpCommand($socket, 'QUIT', [221]);
        fclose($socket);
        return [true, null];
    } catch (Throwable $e) {
        fclose($socket);
        return [false, $e->getMessage()];
    }
}
