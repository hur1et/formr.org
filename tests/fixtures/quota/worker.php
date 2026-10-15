<?php

// Independent process for real MariaDB lock contention tests, never an application endpoint.
require_once __DIR__ . '/../../../application/Services/QuotaRules.php';
require_once __DIR__ . '/../../../application/Services/QuotaStore.php';

if (getenv('QUOTA_TEST_ALLOW_CREATE_DATABASE') !== '1'
    || !preg_match('/;dbname=formr_quota_test_[0-9a-f]{16}\z/', getenv('QUOTA_TEST_WORKER_DSN') ?: '')) {
    fwrite(STDERR, "Disposable quota test database is required.\n");
    exit(1);
}
$pdo = new PDO(getenv('QUOTA_TEST_WORKER_DSN'), getenv('QUOTA_TEST_USER') ?: 'root',
    getenv('QUOTA_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
echo 'READY:' . $pdo->query('SELECT CONNECTION_ID()')->fetchColumn() . "\n";
flush();
$payload = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$store = new QuotaStore($pdo);
try {
    switch ($payload['operation']) {
        case 'admit':
            $result = $store->admit(1, $payload['quota'], $payload['session'], fn() => $payload['answers']);
            break;
        case 'complete':
            $result = $store->complete(1, $payload['quota'], $payload['session'], $payload['unit_session']);
            break;
        case 'adjust':
            $result = $store->adjust(1, $payload['quota'], 'education', 'uni', 'field',
                $payload['delta'], 7, 'Concurrent correction', $payload['key']);
            break;
        case 'overview':
            $store->saveOverview(1,$payload['quota'],'field',$payload['rows'],7,$payload['key'],$payload['definition_hash']);
            $result=true;
            break;
        default:
            throw new InvalidArgumentException('Unknown worker operation.');
    }
    echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['error' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
