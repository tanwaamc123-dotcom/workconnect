<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/database.php';

$pdo = db();
$rows = $pdo->query("SELECT id,id_card_number FROM users WHERE id_card_number<>'' AND id_card_number NOT LIKE 'enc:v1:%'")->fetchAll();
$stmt = $pdo->prepare('UPDATE users SET id_card_number=? WHERE id=? AND id_card_number=?');
$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        $stmt->execute([encrypt_sensitive((string) $row['id_card_number']), (int) $row['id'], (string) $row['id_card_number']]);
    }
    $pdo->commit();
    echo count($rows) . " identity records encrypted.\n";
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}
