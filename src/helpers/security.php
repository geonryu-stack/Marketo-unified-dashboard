<?php
// src/helpers/security.php — SQL 읽기전용 강제 + PII 마스킹
declare(strict_types=1);

function assert_readonly(string $sql): void
{
    $normalized = strtoupper(ltrim($sql));
    if (!str_starts_with($normalized, 'SELECT') && !str_starts_with($normalized, 'WITH')) {
        throw new RuntimeException('CONSTRAINT-01: 사내 DB는 SELECT 쿼리만 허용됩니다.');
    }
}

// PII 마스킹: 사내 DB 표본을 화면에 노출할 때 평문 이메일을 차단.
function mask_email_pii(string $email): string
{
    $email = trim($email);
    if ($email === '') return '***';

    $at = strrpos($email, '@');
    if ($at === false || $at === 0 || $at === strlen($email) - 1) {
        return '***';
    }

    $local  = substr($email, 0, $at);
    $domain = substr($email, $at + 1);

    $dot = strrpos($domain, '.');
    if ($dot === false || $dot === 0 || $dot === strlen($domain) - 1) {
        return '***';
    }

    $domain_main = substr($domain, 0, $dot);
    $tld         = substr($domain, $dot + 1);

    $local_prefix  = mb_substr($local, 0, 2, 'UTF-8');
    $domain_prefix = mb_substr($domain_main, 0, 2, 'UTF-8');

    return $local_prefix . '***@' . $domain_prefix . '***.' . $tld;
}
