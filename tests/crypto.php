<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/helpers.php';

$plain = '1234567890123';
$encrypted = encrypt_sensitive($plain);
if ($encrypted === $plain || !str_starts_with($encrypted, 'enc:v1:')) throw new RuntimeException('Encryption format failed.');
if (decrypt_sensitive($encrypted) !== $plain) throw new RuntimeException('Sensitive data round trip failed.');
if (mask_id_card_number($encrypted) !== '1-xxxxxxxx-0123') throw new RuntimeException('Encrypted ID masking failed.');
echo "[PASS] AES-256-GCM encryption and masked display work.\n";
