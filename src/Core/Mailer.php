<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Отправка почты и SMS. Без настроенного SMTP письма сохраняются в storage/mails
 * (режим разработки), с SMTP — отправляются по протоколу SMTP (AUTH LOGIN, STARTTLS/SSL).
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $m = cfg('mail');
        if (empty($m['smtp_host'])) {
            return self::toFile($to, $subject, $body);
        }
        try {
            return self::smtp($m, $to, $subject, $body);
        } catch (\Throwable $e) {
            Log::file('SMTP error: ' . $e->getMessage());
            self::toFile($to, $subject, $body);
            return false;
        }
    }

    /** SMS: запись в storage/sms (заглушка шлюза) или отправка на шлюз, если задан sms.url. */
    public static function sms(string $phone, string $text): bool
    {
        $c = cfg('sms');
        if (!empty($c['url'])) {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'ignore_errors' => true,
                'header' => "Content-Type: application/json
Authorization: Bearer " . $c['token'],
                'content' => json_encode(['to' => $phone, 'text' => $text, 'sender' => $c['sender']], JSON_UNESCAPED_UNICODE)]]);
            $r = @file_get_contents($c['url'], false, $ctx);
            return $r !== false;
        }
        $name = date('Ymd_His') . '_' . substr(md5($phone . $text . microtime()), 0, 6) . '.txt';
        return (bool)@file_put_contents(STORAGE . '/sms/' . $name, "To: $phone
Date: " . date('r') . "

$text
");
    }

    private static function toFile(string $to, string $subject, string $body): bool
    {
        $name = date('Ymd_His') . '_' . substr(md5($to . $subject . microtime()), 0, 6) . '.txt';
        $txt = "To: $to\nSubject: $subject\nDate: " . date('r') . "\n\n$body\n";
        return (bool)@file_put_contents(STORAGE . '/mails/' . $name, $txt);
    }

    private static function smtp(array $m, string $to, string $subject, string $body): bool
    {
        $secure = $m['smtp_secure'];
        $host = ($secure === 'ssl' ? 'ssl://' : '') . $m['smtp_host'];
        $fp = @stream_socket_client($host . ':' . $m['smtp_port'], $en, $es, 10);
        if (!$fp) {
            throw new \RuntimeException("connect failed: $es");
        }
        $read = function () use ($fp) {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) {
                $out .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $out;
        };
        $cmd = function (string $c, string $expect) use ($fp, $read) {
            fwrite($fp, $c . "\r\n");
            $r = $read();
            if (strpos($r, $expect) !== 0) {
                throw new \RuntimeException("SMTP: '$c' -> $r");
            }
        };
        $read();
        $cmd('EHLO posaddom', '250');
        if ($secure === 'tls') {
            $cmd('STARTTLS', '220');
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO posaddom', '250');
        }
        if ($m['smtp_user'] !== '') {
            $cmd('AUTH LOGIN', '334');
            $cmd(base64_encode($m['smtp_user']), '334');
            $cmd(base64_encode($m['smtp_pass']), '235');
        }
        $cmd('MAIL FROM:<' . $m['from'] . '>', '250');
        $cmd('RCPT TO:<' . $to . '>', '250');
        $cmd('DATA', '354');
        $headers = 'From: =?UTF-8?B?' . base64_encode($m['from_name']) . '?= <' . $m['from'] . ">\r\n"
            . "To: <$to>\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        $msg = $headers . "\r\n" . chunk_split(base64_encode($body));
        $cmd($msg . "\r\n.", '250');
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    }
}
