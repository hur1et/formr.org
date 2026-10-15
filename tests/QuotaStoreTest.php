<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../application/Services/QuotaRules.php';
require_once __DIR__ . '/../application/Services/QuotaStore.php';

/** Creates and drops only a randomly named disposable database, never the configured app DB. */
#[Group('integration')]
class QuotaStoreTest extends TestCase {
    private static ?PDO $admin = null;
    private static ?PDO $db = null;
    private static string $database = '';
    private QuotaStore $store;
    private int $quota;

    public static function setUpBeforeClass(): void {
        if (getenv('QUOTA_TEST_ALLOW_CREATE_DATABASE') !== '1' || !getenv('QUOTA_TEST_DSN')) {
            self::markTestSkipped('Explicit disposable MariaDB test connection and create-database opt-in required.');
        }
        self::$admin = new PDO(getenv('QUOTA_TEST_DSN'), getenv('QUOTA_TEST_USER') ?: 'root',
            getenv('QUOTA_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$database = 'formr_quota_test_' . bin2hex(random_bytes(8));
        self::$admin->exec('CREATE DATABASE `' . self::$database . '`');
        $dsn = preg_replace('/;?dbname=[^;]*/i', '', getenv('QUOTA_TEST_DSN')) . ';dbname=' . self::$database;
        putenv('QUOTA_TEST_WORKER_DSN=' . $dsn);
        self::$db = new PDO($dsn, getenv('QUOTA_TEST_USER') ?: 'root', getenv('QUOTA_TEST_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        self::$db->exec('CREATE TABLE survey_runs (id INT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL) ENGINE=InnoDB;
            CREATE TABLE survey_run_sessions (id INT UNSIGNED PRIMARY KEY, run_id INT UNSIGNED NOT NULL, testing TINYINT DEFAULT 0) ENGINE=InnoDB;
            CREATE TABLE survey_units (id INT UNSIGNED PRIMARY KEY, type VARCHAR(32) NOT NULL) ENGINE=InnoDB;
            CREATE TABLE survey_unit_sessions (id INT UNSIGNED PRIMARY KEY, run_session_id INT UNSIGNED, unit_id INT UNSIGNED) ENGINE=InnoDB;');
        self::$db->exec(file_get_contents(__DIR__ . '/../sql/patches/060_quota_core.sql'));
    }

    public static function tearDownAfterClass(): void {
        self::$db = null;
        if (self::$admin && preg_match('/\Aformr_quota_test_[0-9a-f]{16}\z/', self::$database)) {
            self::$admin->exec('DROP DATABASE `' . self::$database . '`');
        }
        self::$admin = null;
        putenv('QUOTA_TEST_WORKER_DSN');
    }

    protected function setUp(): void {
        foreach (['survey_quota_limit_changes', 'survey_quota_adjustments', 'survey_quota_completions',
            'survey_quota_assignments', 'survey_quota_decisions', 'survey_quota_groups', 'survey_quota_tables',
            'survey_quotas', 'survey_unit_sessions', 'survey_units', 'survey_run_sessions', 'survey_runs'] as $table) {
            self::$db->exec('DELETE FROM ' . $table);
        }
        self::$db->exec('INSERT INTO survey_runs VALUES (1, 7), (2, 8);
            INSERT INTO survey_run_sessions VALUES (1,1,0), (2,1,0), (3,1,0), (4,1,1), (5,1,1), (6,2,0);
            INSERT INTO survey_units VALUES (10,"Complete"), (11,"Survey");
            INSERT INTO survey_unit_sessions VALUES (101,1,10), (102,2,10), (103,3,10), (104,4,10), (105,5,10), (106,6,10), (111,1,11);');
        $this->store = new QuotaStore(self::$db);
        $this->quota = $this->store->create(1, 7, self::tables());
    }

    public static function tables(): array {
        return ['age_sex' => ['dimensions' => [
            ['source' => 'demo.age', 'type' => 'integer', 'categories' => ['adult' => ['min' => 18, 'max' => 29]]],
            ['source' => 'demo.sex', 'type' => 'category', 'categories' => ['female' => '2']],
        ], 'limits' => ['adult|female' => 1]],
            'education' => ['dimensions' => [['source' => 'demo.education', 'type' => 'category',
                'categories' => ['uni' => 'uni']]], 'limits' => ['uni' => 1]]];
    }

    public static function answers(): array {
        return ['demo.age' => '29', 'demo.sex' => '2', 'demo.education' => 'uni'];
    }

    private function admit(int $session = 1): array {
        return $this->store->admit(1, $this->quota, $session, fn() => self::answers());
    }

    private function assertCounts(int $count, string $cohort = 'field'): void {
        self::assertSame(['age_sex' => ['adult|female' => $count], 'education' => ['uni' => $count]],
            $this->store->getCounts(1, $this->quota, $cohort));
    }

    public function testAdmissionThenDuplicateCompletionCountsExactlyOnceInAllGroups(): void {
        $first = $this->admit();
        self::assertSame('admitted', $first['status']);
        $this->assertCounts(0);
        $repeated = $this->store->admit(1, $this->quota, 1, function () { throw new RuntimeException('Must not reload'); });
        self::assertSame($first, $repeated);
        $completed = $this->store->complete(1, $this->quota, 1, 101);
        self::assertNotNull($completed['completed_at']);
        self::assertSame($completed, $this->store->complete(1, $this->quota, 1, 101));
        $this->assertCounts(1);
        self::assertSame(1, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_completions')->fetchColumn());
    }

    public function testFieldAndTestAdmissionsCompletionsAndCorrectionsStaySeparate(): void {
        $this->admit(1);
        $this->store->complete(1, $this->quota, 1, 101);
        $this->assertCounts(1, 'field');
        $this->assertCounts(0, 'test');
        self::assertSame('rejected', $this->admit(2)['status']);
        self::assertSame('admitted', $this->admit(4)['status']);
        $this->store->complete(1, $this->quota, 4, 104);
        $this->store->complete(1, $this->quota, 4, 104);
        $this->assertCounts(1, 'field');
        $this->assertCounts(1, 'test');
        self::assertSame('rejected', $this->admit(5)['status']);
        $this->store->adjust(1, $this->quota, 'education', 'uni', 'test', -1, 7, 'Test correction', 'test-minus');
        self::assertSame(0, $this->store->getCounts(1, $this->quota, 'test')['education']['uni']);
        $this->assertCounts(1, 'field');
    }

    public function testRejectedDecisionSurvivesLimitIncreaseAndChangedAnswers(): void {
        $first = $this->store->admit(1, $this->quota, 1, fn() => []);
        self::assertSame('missing_answer', $first['reason']);
        $this->store->setLimit(1, $this->quota, 'education', 'uni', 5, 7, 'Raise target', 'raise');
        self::assertSame($first, $this->admit(1));
        $this->assertCounts(0);
        $this->expectException(DomainException::class);
        $this->store->complete(1, $this->quota, 1, 101);
    }

    public function testFullRejectionIsNotReopened(): void {
        $this->store->setLimit(1, $this->quota, 'education', 'uni', 0, 7, 'Close', 'close');
        $first = $this->admit();
        self::assertSame('group_full', $first['reason']);
        self::assertSame([], $first['assignments']);
        $this->store->setLimit(1, $this->quota, 'education', 'uni', 9, 7, 'Reopen', 'open');
        self::assertSame($first, $this->admit());
    }

    public function testTechnicalFailureDoesNotPersistARejection(): void {
        try {
            $this->store->admit(1, $this->quota, 1, function () { throw new RuntimeException('Answer read failed'); });
            self::fail('Expected answer loader failure.');
        } catch (RuntimeException $error) {
            self::assertSame('Answer read failed', $error->getMessage());
        }
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_decisions')->fetchColumn());
        self::assertSame('admitted', $this->admit()['status']);
    }

    public function testErrorOnSecondAssignmentRollsBackDecisionAndFirstAssignment(): void {
        self::$db->exec("CREATE TRIGGER fail_assignment BEFORE INSERT ON survey_quota_assignments FOR EACH ROW
            BEGIN IF EXISTS (SELECT 1 FROM survey_quota_tables WHERE id = NEW.table_id AND code = 'education')
            THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected assignment failure'; END IF; END");
        try {
            $this->admit();
            self::fail('Expected injected assignment failure.');
        } catch (PDOException $error) {
            self::assertStringContainsString('injected assignment failure', $error->getMessage());
        } finally {
            self::$db->exec('DROP TRIGGER fail_assignment');
        }
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_decisions')->fetchColumn());
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_assignments')->fetchColumn());
        self::assertSame('admitted', $this->admit()['status']);
    }

    public function testCompletionFailureRollsBackAndCanBeRetried(): void {
        $this->admit();
        self::$db->exec("CREATE TRIGGER fail_completion AFTER INSERT ON survey_quota_completions FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected completion failure'");
        try {
            $this->store->complete(1, $this->quota, 1, 101);
            self::fail('Expected injected completion failure.');
        } catch (PDOException $error) {
            self::assertStringContainsString('injected completion failure', $error->getMessage());
        } finally {
            self::$db->exec('DROP TRIGGER fail_completion');
        }
        $this->assertCounts(0);
        $this->store->complete(1, $this->quota, 1, 101);
        $this->assertCounts(1);
    }

    public function testCorrectionsAreAuditedIdempotentAndCannotBecomeNegative(): void {
        $this->admit();
        $this->store->complete(1, $this->quota, 1, 101);
        $id = $this->store->adjust(1, $this->quota, 'education', 'uni', 'field', -1, 7, 'Invalid response', 'correct-1');
        self::assertSame($id, $this->store->adjust(1, $this->quota, 'education', 'uni', 'field', -1, 7, 'Invalid response', 'correct-1'));
        self::assertSame(0, $this->store->getCounts(1, $this->quota, 'field')['education']['uni']);
        self::assertSame(1, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_completions')->fetchColumn());
        self::assertSame(1, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_adjustments')->fetchColumn());
        $this->expectException(DomainException::class);
        $this->store->adjust(1, $this->quota, 'education', 'uni', 'field', -1, 7, 'Too many', 'correct-2');
    }

    public function testCorrectionRetryKeyCannotChangePayload(): void {
        $this->store->adjust(1, $this->quota, 'education', 'uni', 'field', 1, 7, 'Correction', 'same-key');
        $this->expectException(DomainException::class);
        $this->store->adjust(1, $this->quota, 'education', 'uni', 'test', 1, 7, 'Correction', 'same-key');
    }

    public function testLimitRetryCannotUndoANewerLimitChange(): void {
        $id = $this->store->setLimit(1, $this->quota, 'education', 'uni', 5, 7, 'Raise', 'change-1');
        $this->store->setLimit(1, $this->quota, 'education', 'uni', 0, 7, 'Close', 'change-2');
        self::assertSame($id, $this->store->setLimit(1, $this->quota, 'education', 'uni', 5, 7, 'Raise', 'change-1'));
        self::assertSame('rejected', $this->admit()['status']);
        self::assertSame(2, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_limit_changes')->fetchColumn());
    }

    public function testLimitAuditFailureRollsBackTheLimitChange(): void {
        self::$db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON survey_quota_limit_changes FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected audit failure'");
        try {
            $this->store->setLimit(1, $this->quota, 'education', 'uni', 0, 7, 'Close', 'close');
            self::fail('Expected audit failure.');
        } catch (PDOException $error) {
            self::assertStringContainsString('injected audit failure', $error->getMessage());
        } finally {
            self::$db->exec('DROP TRIGGER fail_audit');
        }
        self::assertSame('admitted', $this->admit()['status']);
    }

    public function testCallerTransactionIsNeitherCommittedNorRolledBack(): void {
        self::$db->beginTransaction();
        self::$db->exec('UPDATE survey_runs SET user_id = 9 WHERE id = 2');
        try {
            $this->admit();
            self::fail('Expected transaction ownership failure.');
        } catch (LogicException $error) {
            self::assertTrue(self::$db->inTransaction());
            self::assertSame(9, (int) self::$db->query('SELECT user_id FROM survey_runs WHERE id = 2')->fetchColumn());
        } finally {
            self::$db->rollBack();
        }
        self::assertSame(8, (int) self::$db->query('SELECT user_id FROM survey_runs WHERE id = 2')->fetchColumn());
    }

    public function testMissingAssignmentCannotComplete(): void {
        $this->admit();
        self::$db->exec('DELETE FROM survey_quota_assignments ORDER BY table_id LIMIT 1');
        $this->expectException(UnexpectedValueException::class);
        $this->store->complete(1, $this->quota, 1, 101);
    }

    public function testMissingGroupIsNotRecreatedAsClosedOrEmpty(): void {
        self::$db->exec('DELETE FROM survey_quota_groups ORDER BY id LIMIT 1');
        $this->expectException(UnexpectedValueException::class);
        $this->admit();
    }

    public function testChangedTestFlagDoesNotMoveStoredRecordsBetweenCohorts(): void {
        $this->admit();
        self::$db->exec('UPDATE survey_run_sessions SET testing = 1 WHERE id = 1');
        try {
            $this->store->complete(1, $this->quota, 1, 101);
            self::fail('Expected cohort mismatch.');
        } catch (DomainException $error) {
            self::assertStringContainsString('test status changed', $error->getMessage());
        }
        $this->assertCounts(0, 'field');
        $this->assertCounts(0, 'test');
        self::assertSame('field', self::$db->query('SELECT cohort FROM survey_quota_decisions')->fetchColumn());
    }

    public function testWrongRunParticipantOwnerAndCompletionAreRejected(): void {
        $operations = [
            fn() => $this->store->admit(2, $this->quota, 6, fn() => self::answers()),
            fn() => $this->store->admit(1, $this->quota, 6, fn() => self::answers()),
            fn() => $this->store->setLimit(1, $this->quota, 'education', 'uni', 2, 8, 'Other owner', 'bad-owner'),
            fn() => $this->store->complete(1, $this->quota, 1, 101),
        ];
        foreach ($operations as $operation) {
            try { $operation(); self::fail('Expected domain error.'); }
            catch (DomainException $error) { self::assertNotEmpty($error->getMessage()); }
        }
        $this->admit();
        foreach ([106, 111] as $unitSession) {
            try { $this->store->complete(1, $this->quota, 1, $unitSession); self::fail('Expected invalid Complete.'); }
            catch (DomainException $error) { self::assertStringContainsString('Complete unit', $error->getMessage()); }
        }
        $this->assertCounts(0);
    }

    public function testDatabaseUniqueConstraintsProtectAgainstDuplicateRawWrites(): void {
        $this->admit();
        $this->store->complete(1, $this->quota, 1, 101);
        $this->expectException(PDOException::class);
        self::$db->exec('INSERT INTO survey_quota_completions SELECT * FROM survey_quota_completions');
    }

    /** Hold the quota lock until both independent connections are confirmed waiting on it. */
    private function concurrently(array $payloads): array {
        $workers = [];
        self::$db->beginTransaction();
        $lock = self::$db->prepare('SELECT id FROM survey_quotas WHERE id = ? FOR UPDATE');
        $lock->execute([$this->quota]);
        try {
            foreach ($payloads as $payload) {
                $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'),
                    '-d', 'extension=pdo_mysql', __DIR__ . '/fixtures/quota/worker.php'];
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes, null, null, ['bypass_shell' => true]);
                if (!is_resource($process)) { throw new RuntimeException('Could not start quota worker.'); }
                stream_set_timeout($pipes[1], 10);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
                $ready = trim((string) fgets($pipes[1]));
                self::assertMatchesRegularExpression('/\AREADY:[0-9]+\z/', $ready);
                $workers[count($workers) - 1]['connection'] = (int) substr($ready, 6);
                fwrite($pipes[0], json_encode($payload + ['quota' => $this->quota], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
            }
            $connections = implode(',', array_column($workers, 'connection'));
            $deadline = microtime(true) + 5;
            do {
                // MariaDB can wait during the optimizer's unique-key lookup (Statistics)
                // before exposing LOCK WAIT in INNODB_TRX. Both requests must be executing
                // our locking read while the parent still owns the target row lock.
                $waiting = (int) self::$db->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST
                    WHERE ID IN ($connections) AND INFO LIKE 'SELECT id FROM survey_quotas%FOR UPDATE'")->fetchColumn();
                if ($waiting === count($workers)) { break; }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $diagnostic = $waiting === count($workers) ? '' : json_encode(self::$db->query(
                "SELECT ID, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID IN ($connections)")->fetchAll(PDO::FETCH_ASSOC));
            self::assertSame(count($workers), $waiting, 'Both workers must actually contend for the quota lock. ' . $diagnostic);
            self::$db->commit();
            $results = [];
            foreach ($workers as $worker) {
                $output = stream_get_contents($worker['pipes'][1]);
                $errors = stream_get_contents($worker['pipes'][2]);
                self::assertSame('', $errors);
                $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            if (self::$db->inTransaction()) { self::$db->rollBack(); }
            foreach ($workers as $worker) {
                foreach ($worker['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                $status = proc_get_status($worker['process']);
                if ($status['running']) { proc_terminate($worker['process']); }
                proc_close($worker['process']);
            }
        }
    }

    public function testConcurrentAdmissionsOfSameParticipationReuseOneDecision(): void {
        $payload = ['operation' => 'admit', 'session' => 1, 'answers' => self::answers()];
        $results = $this->concurrently([$payload, $payload]);
        self::assertSame('admitted', $results[0]['result']['status']);
        self::assertSame($results[0]['result'], $results[1]['result']);
        self::assertSame(1, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_decisions')->fetchColumn());
        self::assertSame(2, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_assignments')->fetchColumn());
        $this->assertCounts(0);
    }

    public function testConcurrentCompletionsCountOnce(): void {
        $this->admit();
        $payload = ['operation' => 'complete', 'session' => 1, 'unit_session' => 101];
        $results = $this->concurrently([$payload, $payload]);
        self::assertNotNull($results[0]['result']['completed_at']);
        self::assertSame($results[0]['result'], $results[1]['result']);
        $this->assertCounts(1);
    }

    public function testConcurrentCorrectionsCannotMakeCountNegative(): void {
        $this->admit();
        $this->store->complete(1, $this->quota, 1, 101);
        $results = $this->concurrently([
            ['operation' => 'adjust', 'delta' => -1, 'key' => 'minus-a'],
            ['operation' => 'adjust', 'delta' => -1, 'key' => 'minus-b'],
        ]);
        self::assertCount(1, array_column($results, 'result'));
        self::assertSame([DomainException::class], array_column($results, 'error'));
        self::assertSame(0, $this->store->getCounts(1, $this->quota, 'field')['education']['uni']);
    }

    public function testConcurrentCompletionAndCorrectionKeepBothEffects(): void {
        $this->admit(1);
        $this->admit(2);
        $this->store->complete(1, $this->quota, 1, 101);
        $results = $this->concurrently([
            ['operation' => 'complete', 'session' => 2, 'unit_session' => 102],
            ['operation' => 'adjust', 'delta' => -1, 'key' => 'minus'],
        ]);
        self::assertCount(2, array_column($results, 'result'));
        self::assertSame(['age_sex' => ['adult|female' => 2], 'education' => ['uni' => 1]],
            $this->store->getCounts(1, $this->quota, 'field'));
    }

    public function testSeveralCorrectionsDoNotMultiplyCompletionCounts(): void {
        $this->admit(1);
        $this->admit(2);
        $this->store->complete(1, $this->quota, 1, 101);
        $this->store->complete(1, $this->quota, 2, 102);
        $this->store->adjust(1, $this->quota, 'education', 'uni', 'field', -1, 7, 'Exclude', 'exclude');
        $this->store->adjust(1, $this->quota, 'education', 'uni', 'field', 1, 7, 'Reverse correction', 'reverse');
        $this->assertCounts(2);
    }

    public function testDatabaseRejectsDuplicateDecisionAndCrossQuotaAssignment(): void {
        $decision = $this->admit();
        try {
            $statement = self::$db->prepare('INSERT INTO survey_quota_decisions
                (quota_id, run_session_id, cohort, outcome) VALUES (?,1,"field","admitted")');
            $statement->execute([$this->quota]);
            self::fail('Expected unique participation constraint.');
        } catch (PDOException $error) {
            self::assertSame('23000', $error->getCode());
        }
        $otherQuota = $this->store->create(2, 8, self::tables());
        $other = self::$db->query('SELECT id, table_id FROM survey_quota_groups WHERE quota_id = ' . $otherQuota . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $statement = self::$db->prepare('INSERT INTO survey_quota_assignments (decision_id, quota_id, table_id, group_id) VALUES (?, ?, ?, ?)');
        $this->expectException(PDOException::class);
        $statement->execute([$decision['decision_id'], $this->quota, $other['table_id'], $other['id']]);
    }

    public function testExistingQuotaCannotBeReplacedByCreatingAnother(): void {
        try {
            $this->store->create(1, 7, self::tables());
            self::fail('Expected duplicate run quota error.');
        } catch (PDOException $error) {
            self::assertSame('23000', $error->getCode());
        }
        self::assertSame(1, (int) self::$db->query('SELECT COUNT(*) FROM survey_quotas')->fetchColumn());
        self::assertSame('admitted', $this->admit()['status']);
    }

    public function testConcurrentDuplicateCorrectionUsesOneAuditRecord(): void {
        $payload = ['operation' => 'adjust', 'delta' => 1, 'key' => 'one-correction'];
        $results = $this->concurrently([$payload, $payload]);
        self::assertSame($results[0]['result'], $results[1]['result']);
        self::assertSame(1, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_adjustments')->fetchColumn());
        self::assertSame(1, $this->store->getCounts(1, $this->quota, 'field')['education']['uni']);
    }

    public function testAdmittedParticipantsCanOverfillAfterLimitReduction(): void {
        $this->admit(1);
        $this->admit(2);
        $this->store->setLimit(1, $this->quota, 'education', 'uni', 0, 7, 'Close field group', 'limit-close');
        $this->store->complete(1, $this->quota, 1, 101);
        $this->store->complete(1, $this->quota, 2, 102);
        $this->assertCounts(2);
        self::assertSame('rejected', $this->admit(3)['status']);
    }
}
