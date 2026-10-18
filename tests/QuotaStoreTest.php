<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../application/Services/QuotaRules.php';
require_once __DIR__ . '/../application/Services/QuotaStore.php';
require_once __DIR__ . '/../application/Services/QuotaAnswerLoader.php';
require_once __DIR__ . '/../application/Services/QuotaEditor.php';
require_once __DIR__ . '/../application/Services/QuotaProviderConfig.php';
require_once __DIR__ . '/../application/Services/QuotaProviderStore.php';
require_once __DIR__ . '/../application/Services/QuotaRuntime.php';
require_once __DIR__ . '/../application/Services/QuotaCompletionPage.php';
require_once __DIR__ . '/../application/Services/QuotaOverview.php';
require_once __DIR__ . '/../application/Services/QuotaMonitor.php';
require_once __DIR__ . '/../application/Services/QuotaExport.php';
require_once __DIR__ . '/../application/Services/QuotaStructure.php';

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
        self::$db->exec('CREATE TABLE survey_runs (id INT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL, name VARCHAR(45) UNIQUE) ENGINE=InnoDB;
            CREATE TABLE survey_run_sessions (id INT UNSIGNED PRIMARY KEY, run_id INT UNSIGNED NOT NULL, testing TINYINT DEFAULT 0,
                current_unit_session_id INT UNSIGNED NULL, position INT NULL, ended DATETIME NULL, session VARCHAR(64)) ENGINE=InnoDB;
            CREATE TABLE survey_units (id INT UNSIGNED PRIMARY KEY, type VARCHAR(32) NOT NULL) ENGINE=InnoDB;
            CREATE TABLE survey_unit_sessions (id INT UNSIGNED PRIMARY KEY, run_session_id INT UNSIGNED, unit_id INT UNSIGNED,
                run_unit_id INT UNSIGNED NULL, ended DATETIME NULL, state VARCHAR(32), queued INT DEFAULT 0,
                result VARCHAR(40), result_log TEXT, state_log TEXT) ENGINE=InnoDB;
            CREATE TABLE survey_pages (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
            CREATE TABLE survey_run_units (id INT UNSIGNED, run_id INT UNSIGNED, unit_id INT UNSIGNED, position INT, description VARCHAR(500), PRIMARY KEY (id, run_id)) ENGINE=InnoDB;
            CREATE TABLE survey_studies (id INT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED, results_table VARCHAR(64), valid INT, unlinked INT) ENGINE=InnoDB;
            CREATE TABLE survey_items (study_id INT UNSIGNED, name VARCHAR(64), type VARCHAR(100), choice_list VARCHAR(255), label TEXT,
                UNIQUE KEY study_item (study_id, name)) ENGINE=InnoDB;
            CREATE TABLE survey_item_choices (study_id INT UNSIGNED, list_name VARCHAR(255), name VARCHAR(255), label TEXT) ENGINE=InnoDB;
            CREATE TABLE quota_demo_results (session_id INT UNSIGNED PRIMARY KEY, study_id INT UNSIGNED, ended DATETIME,
                age INT, sex VARCHAR(12), education VARCHAR(12)) ENGINE=InnoDB;');
        self::$db->exec(file_get_contents(__DIR__ . '/../sql/patches/060_quota_core.sql'));
        self::$db->exec(file_get_contents(__DIR__ . '/../sql/patches/061_quota_drafts.sql'));
        self::$db->exec(file_get_contents(__DIR__ . '/../sql/patches/062_quota_lifecycle.sql'));
        self::$db->exec(file_get_contents(__DIR__ . '/../sql/patches/063_quota_overview_edits.sql'));
        self::$db->exec(file_get_contents(__DIR__ . '/../sql/patches/064_quota_shares.sql'));
        self::$db->exec(file_get_contents(__DIR__ . '/../sql/patches/065_quota_reopening.sql'));
        self::$db->exec(file_get_contents(__DIR__ . '/../sql/patches/066_quota_completion_page.sql'));
        self::$db->exec(file_get_contents(__DIR__ . '/../sql/patches/067_quota_completion_link_text.sql'));
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
        foreach (['survey_quota_configuration_log','survey_quota_shares','survey_quota_edits','survey_quota_provider_ids','survey_quota_lifecycle','survey_quota_exit_drafts','survey_quota_drafts', 'survey_quota_limit_changes', 'survey_quota_adjustments', 'survey_quota_completions',
            'survey_quota_assignments', 'survey_quota_decisions', 'survey_quota_groups', 'survey_quota_tables',
            'survey_quotas', 'quota_demo_results', 'survey_item_choices', 'survey_items', 'survey_studies',
            'survey_run_units', 'survey_unit_sessions', 'survey_pages', 'survey_quota_completion_pages', 'survey_units', 'survey_run_sessions', 'survey_runs'] as $table) {
            self::$db->exec('DELETE FROM ' . $table);
        }
        self::$db->exec('INSERT INTO survey_runs VALUES (1, 7,"study-one"), (2, 8,"study-two");
            INSERT INTO survey_run_sessions (id,run_id,testing) VALUES (1,1,0), (2,1,0), (3,1,0), (4,1,1), (5,1,1), (6,2,0);
            INSERT INTO survey_units VALUES (10,"Complete"), (11,"Survey");
            INSERT INTO survey_unit_sessions (id,run_session_id,unit_id) VALUES (101,1,10), (102,2,10), (103,3,10), (104,4,10), (105,5,10), (106,6,10), (111,1,11);');
        $this->store = new QuotaStore(self::$db);
        $this->quota = $this->store->create(1, 7, self::tables());
    }

    public static function tables(): array {
        return ['age_sex' => ['dimensions' => [
            ['source' => 'demo.age', 'run_unit_id' => 201, 'item_name' => 'age', 'type' => 'integer', 'categories' => ['adult' => ['min' => 18, 'max' => 29]]],
            ['source' => 'demo.sex', 'run_unit_id' => 201, 'item_name' => 'sex', 'type' => 'category', 'categories' => ['female' => '2']],
        ], 'limits' => ['adult|female' => 1]],
            'education' => ['dimensions' => [['source' => 'demo.education', 'run_unit_id' => 201, 'item_name' => 'education', 'type' => 'category',
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

    private function seedSources(): void {
        self::$db->exec('INSERT INTO survey_units VALUES (20,"Survey"),(30,"Quota");
            INSERT INTO survey_run_units VALUES (201,1,20,1,"Demography"),(202,1,30,2,"Quota");
            INSERT INTO survey_studies VALUES (20,7,"quota_demo_results",1,0);
            INSERT INTO survey_items VALUES (20,"age","number",NULL,"Age in years"),
                (20,"sex","mc","sex_choices","Different visible label"),
                (20,"education","select_one","education_choices","Education");
            INSERT INTO survey_item_choices VALUES (20,"sex_choices","1","Male"), (20,"sex_choices","2","Female"),
                (20,"education_choices","uni","University");
            INSERT INTO survey_unit_sessions (id,run_session_id,unit_id,run_unit_id,ended,state) VALUES (120,1,20,201,NOW(),"ENDED"), (121,2,20,201,NOW(),"ENDED");
            INSERT INTO quota_demo_results VALUES (120,20,NOW(),29,"2","uni"), (121,20,NOW(),90,"1","uni");');
    }

    public function testSavedDemographicsAdmitOnlyTheMatchingParticipationAndReplayDoesNotReload(): void {
        $this->seedSources();
        $admission = $this->store->admitFromSurvey(1, $this->quota, 1, 202);
        self::assertSame('admitted', $admission['status']);
        self::assertSame(['age_sex' => 'adult|female', 'education' => 'uni'], $admission['assignments']);
        $other = $this->store->admitFromSurvey(1, $this->quota, 2, 202);
        self::assertSame('unmatched_answer', $other['reason']);
        self::$db->exec('UPDATE quota_demo_results SET age = NULL, sex = NULL WHERE session_id = 120');
        self::assertSame($admission, $this->store->admitFromSurvey(1, $this->quota, 1, 202));
        $this->store->complete(1, $this->quota, 1, 101);
        $this->assertCounts(1);
    }

    public function testUnansweredSavedItemIsRejectionRatherThanStorageFailure(): void {
        $this->seedSources();
        self::$db->exec('UPDATE quota_demo_results SET age = NULL WHERE session_id = 120');
        $result = $this->store->admitFromSurvey(1, $this->quota, 1, 202);
        self::assertSame('missing_answer', $result['reason']);
        self::assertSame('demo.age', $result['source']);
        self::assertSame([], $result['assignments']);
    }

    #[DataProvider('invalidSourceCases')]
    public function testInvalidSavedSourceNeverPersistsAnEligibilityDecision(string $mutation): void {
        $this->seedSources();
        self::$db->exec($mutation);
        try {
            $this->store->admitFromSurvey(1, $this->quota, 1, 202);
            self::fail('Expected technical source validation failure.');
        } catch (DomainException | InvalidArgumentException | UnexpectedValueException | PDOException $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_decisions')->fetchColumn());
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_assignments')->fetchColumn());
        self::assertFalse(self::$db->inTransaction());
    }

    public static function invalidSourceCases(): array {
        return [
            'missing result' => ['DELETE FROM quota_demo_results WHERE session_id=120'],
            'wrong result study' => ['UPDATE quota_demo_results SET study_id=21 WHERE session_id=120'],
            'unfinished result' => ['UPDATE quota_demo_results SET ended=NULL WHERE session_id=120'],
            'wrong participation' => ['UPDATE survey_unit_sessions SET run_session_id=2 WHERE id=120'],
            'wrong occurrence' => ['UPDATE survey_unit_sessions SET run_unit_id=999 WHERE id=120'],
            'unbackfilled occurrence' => ['UPDATE survey_unit_sessions SET run_unit_id=NULL WHERE id=120'],
            'duplicate visit' => ['INSERT INTO survey_unit_sessions (id,run_session_id,unit_id,run_unit_id,ended,state) VALUES (122,1,20,201,NOW(),"ENDED")'],
            'unfinished visit' => ['UPDATE survey_unit_sessions SET ended=NULL WHERE id=120'],
            'expired visit' => ['UPDATE survey_unit_sessions SET state="EXPIRED" WHERE id=120'],
            'later source' => ['UPDATE survey_run_units SET position=3 WHERE id=201'],
            'source in another run' => ['UPDATE survey_run_units SET run_id=2 WHERE id=201'],
            'quota in another run' => ['UPDATE survey_run_units SET run_id=2 WHERE id=202'],
            'wrong quota type' => ['UPDATE survey_units SET type="Page" WHERE id=30'],
            'another owner' => ['UPDATE survey_studies SET user_id=8'],
            'unlinked survey' => ['UPDATE survey_studies SET unlinked=1'],
            'invalid survey' => ['UPDATE survey_studies SET valid=0'],
            'missing metadata' => ['DELETE FROM survey_items WHERE name="age"'],
            'label is not a variable name' => ['UPDATE survey_items SET name="Age in years" WHERE name="age"'],
            'multiple choice' => ['UPDATE survey_items SET type="mc_multiple" WHERE name="sex"'],
            'free text as integer' => ['UPDATE survey_items SET type="text" WHERE name="age"'],
            'missing category' => ['DELETE FROM survey_item_choices WHERE name="2"'],
            'duplicate category' => ['INSERT INTO survey_item_choices VALUES (20,"sex_choices","2","Duplicate")'],
            'missing table' => ['UPDATE survey_studies SET results_table="absent_results"'],
            'ambiguous table ownership' => ['INSERT INTO survey_studies VALUES (21,7,"quota_demo_results",1,0)'],
            'table injection' => ['UPDATE survey_studies SET results_table="quota_demo_results` WHERE 1=1 --"'],
        ];
    }

    public function testFractionalStorageIsNotRoundedOrPermanentlyRejected(): void {
        $this->seedSources();
        self::$db->exec('ALTER TABLE quota_demo_results MODIFY age DECIMAL(5,2)');
        try {
            self::$db->exec('UPDATE quota_demo_results SET age=28.50 WHERE session_id=120');
            try {
                $this->store->admitFromSurvey(1, $this->quota, 1, 202);
                self::fail('Expected unsupported numeric storage error.');
            } catch (UnexpectedValueException $error) {
                self::assertSame('28.50', self::$db->query('SELECT age FROM quota_demo_results WHERE session_id=120')->fetchColumn());
                self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_decisions')->fetchColumn());
            }
        } finally {
            self::$db->exec('DELETE FROM quota_demo_results');
            self::$db->exec('ALTER TABLE quota_demo_results MODIFY age INT');
        }
    }

    public function testTechnicalSourceFailureCanBeRetriedAfterRepair(): void {
        $this->seedSources();
        self::$db->exec('UPDATE quota_demo_results SET ended=NULL WHERE session_id=120');
        try {
            $this->store->admitFromSurvey(1, $this->quota, 1, 202);
            self::fail('Expected incomplete source error.');
        } catch (UnexpectedValueException $error) {
            self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_decisions')->fetchColumn());
        }
        self::$db->exec('UPDATE quota_demo_results SET ended=NOW() WHERE session_id=120');
        self::assertSame('admitted', $this->store->admitFromSurvey(1, $this->quota, 1, 202)['status']);
    }

    public function testLeadingZeroCategoryIsPreservedInTextStorageAndRejectedForIntegerStorage(): void {
        $this->seedSources();
        $dimensions = self::tables()['age_sex']['dimensions'];
        $dimensions[1]['categories']['female'] = '02';
        $statement = self::$db->prepare('UPDATE survey_quota_tables SET dimensions_json=? WHERE quota_id=? AND code="age_sex"');
        $statement->execute([json_encode($dimensions), $this->quota]);
        self::$db->exec('UPDATE survey_item_choices SET name="02" WHERE name="2";
            UPDATE quota_demo_results SET sex="02" WHERE session_id=120');
        self::assertSame('admitted', $this->store->admitFromSurvey(1, $this->quota, 1, 202)['status']);
        self::$db->exec('ALTER TABLE quota_demo_results MODIFY sex INT');
        try {
            $this->store->admitFromSurvey(1, $this->quota, 2, 202);
            self::fail('Expected lossy choice storage error.');
        } catch (UnexpectedValueException $error) {
            self::assertSame(1, (int) self::$db->query('SELECT COUNT(*) FROM survey_quota_decisions')->fetchColumn());
        } finally {
            self::$db->exec('ALTER TABLE quota_demo_results MODIFY sex VARCHAR(12)');
        }
    }

    public function testConflictingSourceBindingsAndMissingResultColumnsAreTechnicalFailures(): void {
        $this->seedSources();
        $tables = self::tables();
        $tables['education']['dimensions'][0]['source'] = 'demo.sex';
        self::$db->beginTransaction();
        try {
            (new QuotaAnswerLoader(self::$db))->load(1, 1, 202, $tables);
            self::fail('Expected conflicting source error.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('conflicting', $error->getMessage());
        } finally {
            self::$db->rollBack();
        }
        $tables = self::tables();
        $tables['age_sex']['dimensions'][0]['item_name'] = 'removed_column';
        self::$db->exec('UPDATE survey_items SET name="removed_column" WHERE name="age"');
        self::$db->beginTransaction();
        try {
            (new QuotaAnswerLoader(self::$db))->load(1, 1, 202, $tables);
            self::fail('Expected missing result column error.');
        } catch (UnexpectedValueException $error) {
            self::assertStringContainsString('column', $error->getMessage());
        } finally {
            self::$db->rollBack();
        }
    }

    public function testLegacyNullValidityFlagStillAllowsAResolvedSurveySource(): void {
        $this->seedSources();
        self::$db->exec('UPDATE survey_studies SET valid=NULL');
        $catalogue = (new QuotaEditor(self::$db))->sources(1,202,7);
        self::assertTrue($catalogue['survey_201.age']['supported']);
        self::assertSame('admitted',$this->store->admitFromSurvey(1,$this->quota,1,202)['status']);
    }

    public function testTableCaseIsResolvedAccordingToTheDatabaseSetting(): void {
        $this->seedSources();
        self::$db->exec('UPDATE survey_studies SET results_table=UPPER(results_table)');
        $folded = (int) self::$db->query('SELECT @@lower_case_table_names')->fetchColumn() !== 0;
        $catalogue = (new QuotaEditor(self::$db))->sources(1,202,7);
        self::assertSame($folded,$catalogue['survey_201.age']['supported']);
        if ($folded) {
            self::assertSame('admitted',$this->store->admitFromSurvey(1,$this->quota,1,202)['status']);
        } else {
            $this->expectException(UnexpectedValueException::class);
            $this->store->admitFromSurvey(1,$this->quota,1,202);
        }
    }

    public function testCaseVariantsCannotHideSharedPhysicalTableOwnership(): void {
        $this->seedSources();
        self::$db->exec('INSERT INTO survey_studies (id,user_id,results_table,valid,unlinked)
            SELECT 99,user_id,UPPER(results_table),valid,unlinked FROM survey_studies LIMIT 1');
        $folded = (int) self::$db->query('SELECT @@lower_case_table_names')->fetchColumn() !== 0;
        $catalogue = (new QuotaEditor(self::$db))->sources(1,202,7);
        self::assertSame(!$folded,$catalogue['survey_201.age']['supported']);
        if ($folded) {
            $this->expectException(UnexpectedValueException::class);
            $this->store->admitFromSurvey(1,$this->quota,1,202);
        } else {
            self::assertSame('admitted',$this->store->admitFromSurvey(1,$this->quota,1,202)['status']);
        }
    }

    public function testSavedAnswerLoaderRefusesToRunOutsideTransaction(): void {
        $this->expectException(LogicException::class);
        (new QuotaAnswerLoader(self::$db))->load(1, 1, 202, self::tables());
    }

    private function overviewRows(string $cohort='field'): array {
        $snapshot=$this->store->overview(1,$this->quota,$cohort,7);
        $rows=[];
        foreach ((new QuotaRules($snapshot['tables']))->getGroups() as $table=>$groups) {
            foreach ($groups as $group=>$definition) {
                $count=$snapshot['counts'][$table][$group];
                $rows[$table][$group]=['limit'=>$definition['limit'],'base_limit'=>$definition['limit'],
                    'count'=>$count,'base_count'=>$count];
            }
        }
        return $rows;
    }

    private function saveOverview(array $rows,string $key='overview-1',string $cohort='field',int $actor=7): void {
        $this->store->saveOverview(1,$this->quota,$cohort,$rows,$actor,$key,QuotaStore::definitionHash(self::tables()));
    }

    public function testOverviewCountCorrectionPreservesNewCompletionsAndRetries(): void {
        $this->admit(1); $this->admit(2);
        $this->store->complete(1,$this->quota,1,101);
        $rows=$this->overviewRows();
        $rows['age_sex']['adult|female']['count']=0;
        $rows['education']['uni']['count']=0;
        $this->store->complete(1,$this->quota,2,102);
        $this->saveOverview($rows);
        $this->assertCounts(1);
        $this->saveOverview($rows);
        $this->assertCounts(1);
        self::assertSame(2,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_completions')->fetchColumn());
        self::assertSame(2,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_adjustments')->fetchColumn());
        self::assertSame(1,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_edits')->fetchColumn());
    }

    public function testOverviewRetryRejectsChangedPayload(): void {
        $rows=$this->overviewRows(); $this->saveOverview($rows);
        $rows['education']['uni']['limit']=5;
        $this->expectException(DomainException::class);
        $this->saveOverview($rows);
    }

    public function testOverviewChangesOnlySelectedCohortAndAuditsLimitAndCount(): void {
        $rows=$this->overviewRows('test');
        $rows['education']['uni']['count']=2;
        $rows['education']['uni']['limit']=4;
        $this->saveOverview($rows,'test-save','test');
        self::assertSame(0,$this->store->getCounts(1,$this->quota,'field')['education']['uni']);
        self::assertSame(2,$this->store->getCounts(1,$this->quota,'test')['education']['uni']);
        $snapshot=$this->store->overview(1,$this->quota,'test',7);
        self::assertSame(0,$snapshot['completed']);
        self::assertSame(4,$snapshot['tables']['education']['limits']['uni']);
        $audit=self::$db->query('SELECT actor_id,delta,reason,created_at FROM survey_quota_adjustments')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(7,(int)$audit['actor_id']);
        self::assertSame(2,(int)$audit['delta']);
        self::assertSame('Quota overview',$audit['reason']);
        self::assertNotEmpty($audit['created_at']);
    }

    public function testOverviewBatchRollsBackAllTablesOnNegativeEffectiveCount(): void {
        $rows=$this->overviewRows();
        $rows['age_sex']['adult|female']['limit']=5;
        $rows['education']['uni']['count']=1;
        $rows['education']['uni']['base_count']=2;
        try { $this->saveOverview($rows); self::fail('Negative effective count accepted.'); }
        catch (DomainException $error) { self::assertStringContainsString('negative',$error->getMessage()); }
        self::assertSame(1,$this->store->overview(1,$this->quota,'field',7)['tables']['age_sex']['limits']['adult|female']);
        self::assertSame(0,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_limit_changes')->fetchColumn());
        self::assertSame(0,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_edits')->fetchColumn());
    }

    public function testOverviewUnchangedLimitDoesNotOverwriteConcurrentLimitEdit(): void {
        $rows=$this->overviewRows();
        $this->store->setLimit(1,$this->quota,'education','uni',8,7,'Other edit','other');
        $rows['age_sex']['adult|female']['limit']=3;
        $this->saveOverview($rows);
        self::assertSame(8,$this->store->overview(1,$this->quota,'field',7)['tables']['education']['limits']['uni']);
    }

    public function testOverviewRejectsConcurrentEditOfChangedLimit(): void {
        $rows=$this->overviewRows(); $rows['education']['uni']['limit']=4;
        $this->store->setLimit(1,$this->quota,'education','uni',8,7,'Other edit','other');
        $this->expectException(DomainException::class);
        $this->saveOverview($rows);
    }

    public function testOverviewRequiresOwner(): void {
        $this->expectException(DomainException::class);
        $this->saveOverview($this->overviewRows(),'not-owner','field',8);
    }

    public function testOverviewNegativeResultCannotWriteCorrection(): void {
        $this->admit(); $this->store->complete(1,$this->quota,1,101);
        $rows=$this->overviewRows(); $rows['education']['uni']['count']=0;
        $this->store->adjust(1,$this->quota,'education','uni','field',-1,7,'Other correction','other');
        try { $this->saveOverview($rows); self::fail('Negative count accepted.'); }
        catch (DomainException $error) { self::assertStringContainsString('negative',$error->getMessage()); }
        self::assertSame(0,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_edits')->fetchColumn());
    }

    public function testOverviewReceiptFailureRollsBackAllEdits(): void {
        $rows=$this->overviewRows();
        $rows['age_sex']['adult|female']['limit']=5;
        $rows['education']['uni']['count']=1;
        self::$db->exec("CREATE TRIGGER fail_overview BEFORE INSERT ON survey_quota_edits FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected receipt failure'");
        try { $this->saveOverview($rows); self::fail('Receipt failure expected.'); }
        catch (PDOException $error) { self::assertStringContainsString('injected receipt failure',$error->getMessage()); }
        finally { self::$db->exec('DROP TRIGGER fail_overview'); }
        $this->assertCounts(0);
        self::assertSame(1,$this->store->overview(1,$this->quota,'field',7)['tables']['age_sex']['limits']['adult|female']);
        self::assertSame(0,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_limit_changes')->fetchColumn());
        self::assertSame(0,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_adjustments')->fetchColumn());
    }

    public function testOverviewIsAccessibleWithoutQuotaUnit(): void {
        $snapshot=(new QuotaOverview(self::$db))->read(2,8,'field');
        self::assertSame([],$snapshot['groups']);
        self::assertSame(0,$snapshot['completed']);
    }

    public function testDraftOverviewSavesLimitsWithoutActivation(): void {
        $tables=$this->seedEditor();
        (new QuotaEditor(self::$db))->save(1,202,7,$tables);
        $overview=new QuotaOverview(self::$db);
        $snapshot=$overview->read(1,7,'field');
        $rows=$snapshot['groups'];
        foreach ($rows as &$groups) {
            foreach ($groups as &$row) { $row['base_limit']=$row['limit']; $row['base_count']=0; }
            unset($row);
        }
        unset($groups);
        $rows['education']['uni']['limit']='9';
        $overview->save(1,7,'field',0,$snapshot['definition_hash'],$rows,'draft-save');
        $overview->save(1,7,'field',0,$snapshot['definition_hash'],$rows,'draft-save');
        self::assertSame(9,$overview->read(1,7,'field')['groups']['education']['uni']['limit']);
        self::assertSame(0,(int)self::$db->query('SELECT COUNT(*) FROM survey_quotas')->fetchColumn());
        self::assertSame(9,(new QuotaEditor(self::$db))->load(1,202,7)['education']['limits']['uni']);
    }

    public function testDraftSaveDoesNotApplyAfterActivation(): void {
        $this->seedLifecycle(false,false);
        $overview=new QuotaOverview(self::$db);
        $snapshot=$overview->read(1,7,'field');
        $rows=$snapshot['groups'];
        foreach ($rows as &$groups) {
            foreach ($groups as &$row) { $row['base_limit']=$row['limit']; $row['base_count']=0; }
            unset($row);
        }
        unset($groups);
        $this->store->activate(1,7);
        $this->expectException(DomainException::class);
        $overview->save(1,7,'field',0,$snapshot['definition_hash'],$rows,'old-draft');
    }

    public function testDraftSaveRejectsChangedCategoryMeaningWithSameGroupCode(): void {
        $tables=$this->seedEditor();
        $editor=new QuotaEditor(self::$db); $editor->save(1,202,7,$tables);
        $overview=new QuotaOverview(self::$db); $snapshot=$overview->read(1,7,'field');
        $rows=$snapshot['groups'];
        foreach ($rows as &$groups) {
            foreach ($groups as &$row) { $row['base_limit']=$row['limit']; $row['base_count']=0; }
            unset($row);
        }
        unset($groups);
        $tables['age_sex']['dimensions'][0]['categories']['adult']['max']=28;
        $editor->save(1,202,7,$tables);
        $this->expectException(DomainException::class);
        $overview->save(1,7,'field',0,$snapshot['definition_hash'],$rows,'old-meaning');
    }

    public function testCompact500GroupSaveAndIncompleteRetryPreserveLedgerIntegrity(): void {
        $categories=[];
        for ($i=1;$i<=500;$i++) { $categories['g'.$i]=(string)$i; }
        $tables=['generic'=>['dimensions'=>[['source'=>'generic.value','item_name'=>'value',
            'type'=>'category','categories'=>$categories]],'limits'=>[]]];
        $quota=$this->store->create(2,8,$tables);
        $snapshot=$this->store->overview(2,$quota,'field',8);
        $rows=[];
        foreach ($categories as $group=>$value) {
            $rows['generic'][$group]=['limit'=>'0','base_limit'=>'0','count'=>'0','base_count'=>'0'];
        }
        $rows['generic']['g1']['count']='2';
        $rows['generic']['g500']['limit']='11';
        parse_str(http_build_query(['rows_json'=>json_encode($rows,JSON_THROW_ON_ERROR)]),$posted);
        $decoded=QuotaOverview::decodeRows($posted['rows_json']);
        $overview=new QuotaOverview(self::$db);
        $hash=QuotaStore::definitionHash($snapshot['tables']);
        $overview->save(2,8,'field',$quota,$hash,$decoded,'compact-500');
        $overview->save(2,8,'field',$quota,$hash,$decoded,'compact-500');
        $saved=$this->store->overview(2,$quota,'field',8);
        self::assertCount(500,$saved['counts']['generic']);
        self::assertSame(2,$saved['counts']['generic']['g1']);
        self::assertSame(11,$saved['tables']['generic']['limits']['g500']);
        unset($decoded['generic']['g500']);
        $decoded['generic']['g1']['count']='9';
        try {
            $overview->save(2,8,'field',$quota,QuotaStore::definitionHash($saved['tables']),$decoded,'incomplete-500');
            self::fail('An incomplete group set must not be saved.');
        } catch (InvalidArgumentException $expected) {
            self::assertStringContainsString('groups',$expected->getMessage());
        }
        self::assertSame($saved,$this->store->overview(2,$quota,'field',8));
    }

    public function testOverviewRejectsIncompleteGroupSubmission(): void {
        $rows=$this->overviewRows(); unset($rows['education']);
        $this->expectException(InvalidArgumentException::class);
        $this->saveOverview($rows);
    }

    public function testActiveEditorShowsUpdatedOverviewLimits(): void {
        $this->quota=$this->seedLifecycle();
        $snapshot=$this->store->overview(1,$this->quota,'field',7);
        $rows=$this->overviewRows(); $rows['education']['uni']['limit']=9;
        $this->store->saveOverview(1,$this->quota,'field',$rows,7,'active-save',QuotaStore::definitionHash($snapshot['tables']));
        self::assertSame(9,(new QuotaEditor(self::$db))->load(1,202,7)['education']['limits']['uni']);
    }

    public function testConcurrentOverviewResubmissionsCorrectOnlyOnce(): void {
        $rows=$this->overviewRows(); $rows['education']['uni']['count']=2;
        $payload=['operation'=>'overview','quota'=>$this->quota,'rows'=>$rows,'key'=>'same-save',
            'definition_hash'=>QuotaStore::definitionHash(self::tables())];
        $results=$this->concurrently([$payload,$payload]);
        foreach ($results as $result) { self::assertArrayNotHasKey('error',$result); }
        self::assertSame(2,$this->store->getCounts(1,$this->quota,'field')['education']['uni']);
        self::assertSame(1,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_adjustments')->fetchColumn());
    }

    public function testConcurrentCompletionAndOverviewCorrectionPreserveBothWrites(): void {
        $this->admit(1); $this->admit(2);
        $this->store->complete(1,$this->quota,1,101);
        $rows=$this->overviewRows();
        $rows['age_sex']['adult|female']['count']=0;
        $rows['education']['uni']['count']=0;
        $results=$this->concurrently([
            ['operation'=>'overview','quota'=>$this->quota,'rows'=>$rows,'key'=>'race-save',
                'definition_hash'=>QuotaStore::definitionHash(self::tables())],
            ['operation'=>'complete','quota'=>$this->quota,'session'=>2,'unit_session'=>102],
        ]);
        foreach ($results as $result) { self::assertArrayNotHasKey('error',$result); }
        $this->assertCounts(1);
        self::assertSame(2,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_completions')->fetchColumn());
    }

    private function seedEditor(): array {
        $this->seedSources();
        self::$db->exec('DELETE FROM survey_quota_groups; DELETE FROM survey_quota_tables; DELETE FROM survey_quotas');
        $tables = self::tables();
        foreach ($tables as &$table) {
            foreach ($table['dimensions'] as &$dim) { $dim['source'] = 'survey_201.' . $dim['item_name']; }
            unset($dim);
        }
        unset($table);
        return $tables;
    }

    public function testDraftRoundTripUsesExactSourcesAndCreatesNoCountingDefinition(): void {
        $tables = $this->seedEditor();
        $editor = new QuotaEditor(self::$db);
        $sources = $editor->sources(1,202,7);
        self::assertSame('age', $sources['survey_201.age']['item_name']);
        self::assertSame('Age in years', $sources['survey_201.age']['label']);
        self::assertSame('Demography', $sources['survey_201.age']['survey_label']);
        self::assertTrue($sources['survey_201.sex']['supported']);
        self::assertSame([], $editor->load(1,202,7));
        self::assertSame([], $editor->save(1,202,7,$tables));
        self::assertSame($tables, $editor->load(1,202,7));
        $tables['education']['limits']['uni'] = 12;
        $editor->save(1,202,7,$tables);
        self::assertSame($tables, $editor->load(1,202,7));
        self::assertSame(0,(int) self::$db->query('SELECT COUNT(*) FROM survey_quotas')->fetchColumn());
        self::assertSame(0,(int) self::$db->query('SELECT COUNT(*) FROM survey_quota_decisions')->fetchColumn());
    }

    #[DataProvider('invalidEditorDefinitions')]
    public function testInvalidDraftPreservesPreviousSavedVersion(string $case): void {
        $tables = $this->seedEditor();
        $editor = new QuotaEditor(self::$db);
        $editor->save(1,202,7,$tables);
        $changed = $tables;
        switch ($case) {
            case 'source': $changed['age_sex']['dimensions'][0]['source']='another.age'; break;
            case 'run_unit': $changed['age_sex']['dimensions'][0]['run_unit_id']=999; break;
            case 'variable': $changed['age_sex']['dimensions'][0]['item_name']='secret'; break;
            case 'code': $changed['age_sex']['dimensions'][1]['categories']['female']='Female'; break;
            case 'negative': $changed['education']['limits']['uni']=-1; break;
            case 'overlap': $changed['age_sex']['dimensions'][0]['categories']['overlap']=['min'=>25,'max'=>35]; break;
            case 'decimal': $changed['age_sex']['dimensions'][0]['categories']['adult']['min']=18.5; break;
            case 'unsupported': self::$db->exec('UPDATE survey_items SET type="mc_multiple" WHERE name="sex"'); break;
        }
        try {
            $editor->save(1,202,7,$changed);
            self::fail('Invalid draft must not replace saved configuration.');
        } catch (InvalidArgumentException $error) { self::assertNotSame('', $error->getMessage()); }
        self::assertSame($tables,$editor->load(1,202,7));
        self::assertFalse(self::$db->inTransaction());
    }

    public static function invalidEditorDefinitions(): array {
        return array_map(fn($case) => [$case], ['source','run_unit','variable','code','negative','overlap','decimal','unsupported']);
    }

    public function testDraftRejectsAnotherOwnerAndAnotherQuotaOccurrence(): void {
        $tables = $this->seedEditor();
        $editor = new QuotaEditor(self::$db);
        try { $editor->save(1,202,8,$tables); self::fail('Wrong owner.'); }
        catch (DomainException $error) { self::assertSame([], $editor->load(1,202,7)); }
        $editor->save(1,202,7,$tables);
        self::$db->exec('INSERT INTO survey_run_units VALUES (203,1,30,3,"Another quota")');
        $this->expectException(DomainException::class);
        $editor->save(1,203,7,$tables);
    }

    public function testExistingCountingDefinitionCannotBeChangedByDraftSave(): void {
        $this->seedSources();
        $editor = new QuotaEditor(self::$db);
        $before = $this->store->overview(1,$this->quota,'field',7);
        $changed = self::tables();
        $changed['age_sex']['dimensions'][0]['categories']['adult']['min'] = 19;
        $changed['education']['limits']['uni'] = 999;
        try {
            $editor->save(1,202,7,$changed);
            self::fail('Activated definitions must reject draft edits.');
        } catch (DomainException $error) {
            self::assertStringContainsString('Quotas are active.', $error->getMessage());
            self::assertStringContainsString('limits and counts', $error->getMessage());
        }
        self::assertSame($before, $this->store->overview(1,$this->quota,'field',7));
        self::assertFalse(self::$db->inTransaction());
    }

    public function testUnsupportedVariableRemainsVisibleWithWarning(): void {
        $this->seedSources();
        self::$db->exec('UPDATE survey_items SET type="text" WHERE name="age"');
        $source = (new QuotaEditor(self::$db))->sources(1,202,7)['survey_201.age'];
        self::assertFalse($source['supported']);
        self::assertNotSame('', $source['warning']);
    }

    private function seedLifecycle(bool $provider = false, bool $activate = true): int {
        $tables = $this->seedEditor();
        self::$db->exec('INSERT INTO survey_units VALUES (40,"Complete"),(50,"Page");
            INSERT INTO survey_run_units VALUES (203,1,40,4,"Complete"),(204,1,50,3,"Screen out");
            INSERT INTO survey_pages VALUES (50,"screen_out");
            INSERT INTO survey_unit_sessions (id,run_session_id,unit_id,run_unit_id,state) VALUES (130,1,30,202,"RUNNING"),(140,1,50,204,"RUNNING");
            UPDATE survey_unit_sessions SET unit_id=40,run_unit_id=203,state="RUNNING" WHERE id=101;
            UPDATE survey_run_sessions SET current_unit_session_id=130,position=2 WHERE id=1');
        (new QuotaEditor(self::$db))->save(1,202,7,$tables);
        $config = $provider ? ['mode'=>'provider','entry_parameter'=>'panel','placeholder'=>'%PANEL%',
            'complete'=>'https://provider.example/complete?p=123&m=%PANEL%',
            'quota_full'=>'https://provider.example/quotasfull?p=456&m=%PANEL%',
            'screen_out'=>'https://provider.example/screenout?p=789&m=%PANEL%'] : ['mode'=>'none'];
        (new QuotaProviderStore(self::$db))->save(1,203,7,$config);
        $this->quota = $activate ? $this->store->activate(1,7) : 0;
        return $this->quota;
    }

    private function reachComplete(): void {
        self::$db->exec('UPDATE survey_unit_sessions SET ended=NOW(),state="ENDED" WHERE id=130;
            UPDATE survey_run_sessions SET current_unit_session_id=101,position=4 WHERE id=1');
    }

    public function testActivationPinsBothDraftsAndRetriesWithoutDuplicatingDefinitions(): void {
        $quota = $this->seedLifecycle();
        self::assertSame($quota,$this->store->activate(1,7));
        self::assertSame(1,(int) self::$db->query('SELECT COUNT(*) FROM survey_quotas')->fetchColumn());
        self::assertSame(202,(int) self::$db->query('SELECT quota_run_unit_id FROM survey_quota_lifecycle')->fetchColumn());
        $this->expectException(DomainException::class);
        (new QuotaProviderStore(self::$db))->save(1,203,7,['mode'=>'none']);
    }

    public function testActivationNeedsCompleteSettingsAndRollsBackAllWritesOnFailure(): void {
        $this->seedLifecycle(false,false);
        self::$db->exec('CREATE TRIGGER fail_lifecycle BEFORE INSERT ON survey_quota_lifecycle
            FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT="Injected activation failure"');
        try {
            try { $this->store->activate(1,7); self::fail('Expected failure.'); }
            catch (PDOException $error) { self::assertSame(0,(int) self::$db->query('SELECT COUNT(*) FROM survey_quotas')->fetchColumn()); }
            self::assertSame(0,(int) self::$db->query('SELECT COUNT(*) FROM survey_quota_groups')->fetchColumn());
            self::assertSame(1,(int) self::$db->query('SELECT COUNT(*) FROM survey_quota_drafts')->fetchColumn());
        } finally { self::$db->exec('DROP TRIGGER fail_lifecycle'); }
        self::$db->exec('DELETE FROM survey_quota_exit_drafts');
        $this->expectException(DomainException::class);
        $this->store->activate(1,7);
    }

    public static function invalidActivationCases(): array {
        return array_map(fn($case) => [$case], ['owner','order','duplicate_position','second_quota','second_complete',
            'ambiguous_results','result_view']);
    }

    #[DataProvider('invalidActivationCases')]
    public function testActivationRejectsInvalidRunOrSourceWithoutCreatingALedger(string $case): void {
        $this->seedLifecycle(false,false);
        $actor = 7;
        switch ($case) {
            case 'owner': $actor=8; break;
            case 'order': self::$db->exec('UPDATE survey_run_units SET position=0 WHERE id=203'); break;
            case 'duplicate_position': self::$db->exec('UPDATE survey_run_units SET position=2 WHERE id=204'); break;
            case 'second_quota': self::$db->exec('INSERT INTO survey_run_units VALUES (205,1,30,5,"Extra quota")'); break;
            case 'second_complete': self::$db->exec('INSERT INTO survey_run_units VALUES (205,1,40,5,"Extra complete")'); break;
            case 'ambiguous_results': self::$db->exec('INSERT INTO survey_studies SELECT 99,user_id,results_table,valid,unlinked FROM survey_studies LIMIT 1'); break;
            case 'result_view':
                self::$db->exec('CREATE VIEW quota_demo_view AS SELECT * FROM quota_demo_results');
                self::$db->exec('UPDATE survey_studies SET results_table="quota_demo_view"');
                break;
        }
        try {
            try { $this->store->activate(1,$actor); self::fail('Invalid activation must fail.'); }
            catch (DomainException|InvalidArgumentException $error) { self::assertNotSame('', $error->getMessage()); }
            self::assertSame(0,(int) self::$db->query('SELECT COUNT(*) FROM survey_quotas')->fetchColumn());
            self::assertSame(0,(int) self::$db->query('SELECT COUNT(*) FROM survey_quota_lifecycle')->fetchColumn());
            self::assertFalse(self::$db->inTransaction());
        } finally {
            if ($case === 'result_view') { self::$db->exec('DROP VIEW quota_demo_view'); }
        }
    }

    public static function invalidCompletionCases(): array {
        return array_map(fn($case) => [$case], ['not_admitted','wrong_current','expired','ended_without_completion']);
    }

    #[DataProvider('invalidCompletionCases')]
    public function testInvalidCompletionCannotCreateACount(string $case): void {
        $this->seedLifecycle();
        $runtime = new QuotaRuntime(self::$db);
        if ($case !== 'not_admitted') { $runtime->execute('Quota',1,1,202,130); }
        $this->reachComplete();
        switch ($case) {
            case 'wrong_current': self::$db->exec('UPDATE survey_run_sessions SET current_unit_session_id=140 WHERE id=1'); break;
            case 'expired': self::$db->exec('UPDATE survey_unit_sessions SET state="EXPIRED" WHERE id=101'); break;
            case 'ended_without_completion': self::$db->exec('UPDATE survey_run_sessions SET ended=NOW() WHERE id=1'); break;
        }
        try { $runtime->execute('Complete',1,1,203,101); self::fail('Invalid completion must fail.'); }
        catch (DomainException $error) { self::assertNotSame('', $error->getMessage()); }
        $this->assertCounts(0);
        self::assertSame(0,(int) self::$db->query('SELECT COUNT(*) FROM survey_quota_completions')->fetchColumn());
    }

    public function testAnOrdinaryPageCannotSendAScreenOutCallback(): void {
        $this->seedLifecycle(true);
        (new QuotaProviderStore(self::$db))->capture(1,1,['panel'=>'person']);
        self::$db->exec('UPDATE survey_pages SET provider_exit="" WHERE id=50;
            UPDATE survey_run_sessions SET current_unit_session_id=140,position=3 WHERE id=1');
        try { (new QuotaRuntime(self::$db))->execute('screen_out',1,1,204,140); self::fail('Only explicitly configured Pages can screen out.'); }
        catch (DomainException $error) { self::assertStringContainsString('configured Page',$error->getMessage()); }
        self::assertNull(self::$db->query('SELECT ended FROM survey_run_sessions WHERE id=1')->fetchColumn());
        $this->assertCounts(0);
    }

    public function testRuntimeAdmitsThenCompletesWithoutProviderAndKeepsRetryPosition(): void {
        $this->seedLifecycle();
        $runtime = new QuotaRuntime(self::$db);
        self::assertTrue($runtime->execute('Quota',1,1,202,130)['move_on']);
        $this->assertCounts(0);
        $this->reachComplete();
        $result = $runtime->execute('Complete',1,1,203,101);
        self::assertTrue($result['quota_terminal']);
        self::assertArrayNotHasKey('redirect',$result);
        self::assertStringContainsString('complete',$result['content']);
        $this->assertCounts(1);
        self::assertSame($result,$runtime->execute('Complete',1,1,203,101));
        self::assertSame(101,(int) self::$db->query('SELECT current_unit_session_id FROM survey_run_sessions WHERE id=1')->fetchColumn());
        self::assertSame(4,(int) self::$db->query('SELECT position FROM survey_run_sessions WHERE id=1')->fetchColumn());
    }

    public function testProviderIdCapturedAtEntryReturnsOnCompleteWithoutDoubleCounting(): void {
        $this->seedLifecycle(true);
        $providers = new QuotaProviderStore(self::$db);
        $providers->capture(1,1,['panel'=>'ä/a&b=1+%2F']);
        $providers->capture(1,1,['panel'=>'ä/a&b=1+%2F']);
        $runtime = new QuotaRuntime(self::$db);
        self::assertTrue($runtime->execute('Quota',1,1,202,130)['move_on']);
        $this->reachComplete();
        $result = $runtime->execute('Complete',1,1,203,101);
        self::assertArrayNotHasKey('redirect',$result);
        self::assertStringContainsString('href="https://provider.example/complete?p=123&amp;m=' . rawurlencode('ä/a&b=1+%2F').'"',$result['content']);
        self::assertStringContainsString('data-completion-delay="10"',$result['content']);
        self::assertSame($result,$runtime->execute('Complete',1,1,203,101));
        $this->assertCounts(1);
        self::assertSame(1,(int) self::$db->query('SELECT COUNT(*) FROM survey_quota_provider_ids')->fetchColumn());
    }

    public function testCompletionPageCanChangeAfterActivationWithoutChangingCountsOrProvider(): void {
        $this->seedLifecycle(true);
        $pages=new QuotaCompletionPage(self::$db);
        $settings=['body'=>'A long **debriefing**.','redirect_message'=>'Return in {seconds}. {link}','redirect_delay'=>'120'];
        $pages->save(1,203,7,$settings);
        self::assertSame(120,$pages->load(40)['redirect_delay']);
        self::assertSame($settings['body'],$pages->load(40)['body']);
        $this->assertCounts(0);
        self::assertFalse((bool)(new QuotaProviderStore(self::$db))->active(1)['editing']);
        (new QuotaProviderStore(self::$db))->capture(1,1,['panel'=>'long-debrief']);
        $runtime=new QuotaRuntime(self::$db);
        $runtime->execute('Quota',1,1,202,130); $this->reachComplete();
        $out=$runtime->execute('Complete',1,1,203,101,false,'<p>A long <strong>debriefing</strong>.</p>');
        self::assertArrayNotHasKey('redirect',$out);
        self::assertStringContainsString('data-completion-delay="120"',$out['content']);
        self::assertStringContainsString('<strong>debriefing</strong>',$out['content']);
        $this->assertCounts(1);
        $runtime->execute('Complete',1,1,203,101); $this->assertCounts(1);
        $pages->save(1,203,7,$settings); // An unchanged save is valid, too.
    }

    public function testCompletionPageCannotBeWrittenByAnotherOwnerOrToAnotherUnitType(): void {
        $this->seedLifecycle();
        $pages=new QuotaCompletionPage(self::$db);
        foreach ([[1,203,8],[1,202,7],[2,203,7]] as [$run,$unit,$actor]) {
            try { $pages->save($run,$unit,$actor,['body'=>'Unauthorized']); self::fail('Foreign completion page was saved.'); }
            catch (DomainException $expected) { self::assertSame(QuotaCompletionPage::defaults(),$pages->load(40)); }
        }
    }

    public function testMissingProviderIdentityAllowsAdmissionButHoldsCompletionForRetry(): void {
        $this->seedLifecycle(true);
        $providers = new QuotaProviderStore(self::$db);
        $providers->capture(1,1,[]);
        $runtime = new QuotaRuntime(self::$db);
        self::assertTrue($runtime->execute('Quota',1,1,202,130)['move_on']);
        $this->reachComplete();
        try { $runtime->execute('Complete',1,1,203,101); self::fail('Missing identity must not produce a callback.'); }
        catch (DomainException $error) { self::assertStringContainsString('identity',$error->getMessage()); }
        $this->assertCounts(1);
        self::assertNull(self::$db->query('SELECT ended FROM survey_run_sessions WHERE id=1')->fetchColumn());
        self::assertNull(self::$db->query('SELECT ended FROM survey_unit_sessions WHERE id=101')->fetchColumn());
        $providers->capture(1,1,['panel'=>'supplied_later']);
        self::assertStringContainsString('m=supplied_later',$runtime->execute('Complete',1,1,203,101)['content']);
        $this->assertCounts(1);
    }

    public function testQuotaFullUsesItsOwnExitAndNeverCountsAnAdmission(): void {
        $this->seedLifecycle(true);
        (new QuotaProviderStore(self::$db))->capture(1,1,['panel'=>'one']);
        $this->store->setLimit(1,$this->quota,'education','uni',0,7,'Close group','close');
        $runtime = new QuotaRuntime(self::$db);
        $result = $runtime->execute('Quota',1,1,202,130);
        self::assertSame('https://provider.example/quotasfull?p=456&m=one',$result['redirect']);
        self::assertSame($result,$runtime->execute('Quota',1,1,202,130));
        $this->assertCounts(0);
        self::assertSame('rejected',self::$db->query('SELECT outcome FROM survey_quota_decisions')->fetchColumn());
        self::assertSame(130,(int) self::$db->query('SELECT current_unit_session_id FROM survey_run_sessions WHERE id=1')->fetchColumn());
    }

    public function testScreenOutPageReturnsIdentityWithoutCreatingACompletion(): void {
        $this->seedLifecycle(true);
        (new QuotaProviderStore(self::$db))->capture(1,1,['panel'=>'two']);
        self::$db->exec('UPDATE survey_run_sessions SET current_unit_session_id=140,position=3 WHERE id=1');
        $result = (new QuotaRuntime(self::$db))->execute('screen_out',1,1,204,140,false,'<p>Screened out.</p>');
        self::assertSame('https://provider.example/screenout?p=789&m=two',$result['redirect']);
        self::assertSame(0,(int) self::$db->query('SELECT COUNT(*) FROM survey_quota_completions')->fetchColumn());
        self::assertSame(0,(int) self::$db->query('SELECT COUNT(*) FROM survey_quota_decisions')->fetchColumn());
    }

    public function testTestCompletionOnlyShowsPlainTextPreviewAndNeverRedirects(): void {
        $this->seedLifecycle(true);
        self::$db->exec('UPDATE survey_run_sessions SET testing=1 WHERE id=1');
        (new QuotaProviderStore(self::$db))->capture(1,1,['panel'=>'test-user']);
        $runtime = new QuotaRuntime(self::$db);
        self::assertTrue($runtime->execute('Quota',1,1,202,130)['move_on']);
        $this->reachComplete();
        $result = $runtime->execute('Complete',1,1,203,101);
        self::assertArrayNotHasKey('redirect',$result);
        self::assertStringContainsString('<pre>https://provider.example/complete?',$result['content']);
        self::assertStringNotContainsString('<a',$result['content']);
        self::assertStringNotContainsString('data-completion-delay',$result['content']);
        $this->assertCounts(0,'field');
        $this->assertCounts(1,'test');
    }

    public function testTerminalWriteFailureLeavesRetryableStateAndOneSavedCompletion(): void {
        $this->seedLifecycle();
        $runtime = new QuotaRuntime(self::$db);
        $runtime->execute('Quota',1,1,202,130);
        $this->reachComplete();
        self::$db->exec('CREATE TRIGGER fail_terminal BEFORE UPDATE ON survey_run_sessions
            FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT="Injected terminal failure"');
        try {
            try { $runtime->execute('Complete',1,1,203,101); self::fail('Expected terminal failure.'); }
            catch (PDOException $error) { $this->assertCounts(1); }
            self::assertNull(self::$db->query('SELECT ended FROM survey_unit_sessions WHERE id=101')->fetchColumn());
            self::assertNull(self::$db->query('SELECT ended FROM survey_run_sessions WHERE id=1')->fetchColumn());
        } finally { self::$db->exec('DROP TRIGGER fail_terminal'); }
        self::assertTrue($runtime->execute('Complete',1,1,203,101)['quota_terminal']);
        $this->assertCounts(1);
    }

    public function testProviderIdentityCannotBeReplacedAndCronCannotBook(): void {
        $this->seedLifecycle(true);
        $providers = new QuotaProviderStore(self::$db);
        $providers->capture(1,1,['panel'=>'first']);
        try { $providers->capture(1,1,['panel'=>'second']); self::fail('Identity must stay fixed.'); }
        catch (DomainException $error) { self::assertSame('first',$providers->identity($this->quota,1)); }
        self::assertSame(['wait_user'=>true],(new QuotaRuntime(self::$db))->execute('Complete',1,1,203,101,true));
        $this->assertCounts(0);
    }

    private function exportedRows(): array {
        $columns=(new QuotaExport(self::$db))->columns(20,7,'quota_demo_results',
            ['session_id','study_id','ended','age','sex','education']);
        return self::$db->query('SELECT '.implode(',',$columns).' FROM quota_demo_results
            LEFT JOIN survey_unit_sessions ON survey_unit_sessions.id=quota_demo_results.session_id
            LEFT JOIN survey_run_sessions ON survey_run_sessions.id=survey_unit_sessions.run_session_id
            ORDER BY quota_demo_results.session_id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testExportAddsNamedQuotaColumnsBeforeItemsWithoutChangingAnswers(): void {
        $this->seedSources(); $this->admit(); $this->store->complete(1,$this->quota,1,101);
        $this->store->admit(1,$this->quota,2,fn()=>['demo.age'=>90,'demo.sex'=>'1','demo.education'=>'uni']);
        self::$db->exec("UPDATE survey_run_sessions SET session='same-panel-code' WHERE id IN (1,2)");
        $rows=$this->exportedRows();
        self::assertCount(2,$rows);
        self::assertSame(['session','session_id','study_id','ended','quota_status','quota_counted',
            'quota_age_sex_group','quota_education_group','age','sex','education'],array_keys($rows[0]));
        self::assertSame('admitted',$rows[0]['quota_status']);
        self::assertSame(1,(int)$rows[0]['quota_counted']);
        self::assertSame('adult|female',$rows[0]['quota_age_sex_group']);
        self::assertSame('rejected',$rows[1]['quota_status']);
        self::assertSame(0,(int)$rows[1]['quota_counted']);
        self::assertNull($rows[1]['quota_age_sex_group']);
        self::assertSame([29,90],array_map('intval',array_column($rows,'age')));
        self::assertSame(['2','1'],array_column($rows,'sex'));
        self::assertSame(['uni','uni'],array_column($rows,'education'));
    }

    public function testExportRepeatsSavedRunAssignmentForRepeatedSurveyVisits(): void {
        $this->seedSources(); $this->admit();
        self::$db->exec('INSERT INTO survey_unit_sessions (id,run_session_id,unit_id,run_unit_id) VALUES (122,1,20,201);
            INSERT INTO quota_demo_results VALUES (122,20,NULL,50,"1","uni")');
        $rows=$this->exportedRows();
        self::assertCount(3,$rows);
        self::assertSame('adult|female',$rows[0]['quota_age_sex_group']);
        self::assertSame('adult|female',$rows[2]['quota_age_sex_group']);
        self::assertSame(50,(int)$rows[2]['age']);
        self::assertNull($rows[1]['quota_status']);
        self::assertSame(0,(int)$rows[1]['quota_counted']);
    }

    public function testExportNamesComeFromTablesAndResolveHyphenAndAnswerCollisions(): void {
        $this->seedSources(); $this->admit();
        self::$db->exec("UPDATE survey_quota_tables SET code='income-party' WHERE code='age_sex';
            UPDATE survey_quota_tables SET code='income_party' WHERE code='education'");
        $export=new QuotaExport(self::$db);
        $book=$export->codebook(20,7,['session_id','quota_status','quota_income_party_group']);
        $names=array_unique(array_column($book,'column'));
        self::assertContains('quota_status_2',$names);
        self::assertContains('quota_income_party_group_2',$names);
        self::assertContains('quota_income_party_group_3',$names);
        self::assertNotContains('quota_age_sex_group',$names);
        $row=array_values(array_filter($book,static fn($r)=>$r['value']==='adult|female'))[0];
        self::assertSame('income-party',$row['quota_table']);
        self::assertStringContainsString('age: 18–29',$row['meaning']);
        self::assertStringContainsString('Female (code 2)',$row['meaning']);
        self::assertSame($book,$export->codebook(20,7,['session_id','quota_status','quota_income_party_group']));
    }

    public function testSharedSurveyExportKeepsRunsSeparateEvenWithIdenticalTableCodes(): void {
        $this->seedSources(); $this->admit();
        self::$db->exec('UPDATE survey_runs SET user_id=7 WHERE id=2;
            INSERT INTO survey_run_units VALUES (301,2,20,1,"Shared demography");
            INSERT INTO survey_unit_sessions (id,run_session_id,unit_id,run_unit_id) VALUES (123,6,20,301);
            INSERT INTO quota_demo_results VALUES (123,20,NULL,25,"2","uni")');
        $other=$this->store->create(2,7,self::tables());
        $this->store->admit(2,$other,6,fn()=>self::answers());
        $this->store->complete(2,$other,6,106);
        $rows=$this->exportedRows();
        self::assertCount(3,$rows);
        self::assertSame('adult|female',$rows[0]['quota_age_sex_group']);
        self::assertNull($rows[0]['quota_age_sex_group_2']);
        self::assertNull($rows[2]['quota_age_sex_group']);
        self::assertSame('adult|female',$rows[2]['quota_age_sex_group_2']);
        self::assertSame(0,(int)$rows[0]['quota_counted']);
        self::assertSame(1,(int)$rows[2]['quota_counted']);
        $book=(new QuotaExport(self::$db))->codebook(20,7,['session_id','study_id','ended','age','sex','education']);
        self::assertSame(['study-one','study-two'],array_values(array_unique(array_filter(array_column($book,'run')))));
        $scoped=(new QuotaExport(self::$db))->codebookForRun(20,7,['session_id','study_id','ended','age','sex','education'],2);
        self::assertSame(['study-two'],array_values(array_unique(array_column($scoped,'run'))));
        self::assertContains('quota_age_sex_group_2',array_column($scoped,'column'));
        self::assertNotContains('quota_age_sex_group',array_column($scoped,'column'));
        self::assertContains('quota_status',array_column($scoped,'column'));
    }

    public function testRunCodebookCannotReadAnotherOwnersRun(): void {
        $this->seedSources();
        $this->expectException(DomainException::class);
        (new QuotaExport(self::$db))->codebookForRun(20,7,['age'],2);
    }

    public function testExportDoesNotExposeAnotherRunOwnersQuotaMetadata(): void {
        $this->seedSources();
        self::$db->exec('INSERT INTO survey_run_units VALUES (301,2,20,1,"Foreign run");
            INSERT INTO survey_unit_sessions (id,run_session_id,unit_id,run_unit_id) VALUES (123,6,20,301);
            INSERT INTO quota_demo_results VALUES (123,20,NULL,25,"2","uni")');
        $other=$this->store->create(2,8,self::tables());
        $this->store->admit(2,$other,6,fn()=>self::answers());
        $rows=$this->exportedRows();
        self::assertCount(3,$rows);
        self::assertNull($rows[2]['quota_status']);
        self::assertNull($rows[2]['quota_counted']);
        self::assertArrayNotHasKey('quota_age_sex_group_2',$rows[2]);
        $book=(new QuotaExport(self::$db))->codebook(20,7,['age']);
        self::assertNotContains('study-two',array_column($book,'run'));
    }

    public function testExportWithoutActiveQuotaIsUnchanged(): void {
        $this->seedEditor();
        $export=new QuotaExport(self::$db);
        self::assertFalse($export->available(20,7));
        self::assertSame(['survey_run_sessions.session','`quota_demo_results`.*'],$export->columns(20,7,'quota_demo_results',['age']));
        self::assertSame([],$export->codebook(20,7,['age']));
    }

    public function testExportPreservesUnlinkedSurveyBoundary(): void {
        $this->seedSources(); self::$db->exec('UPDATE survey_studies SET unlinked=1');
        $export=new QuotaExport(self::$db);
        self::assertFalse($export->available(20,7));
        self::assertSame([],$export->codebook(20,7,['age']));
        self::assertSame(['survey_run_sessions.session','`quota_demo_results`.*'],$export->columns(20,7,'quota_demo_results',['age']));
    }

    public function testExportRequiresSurveyOwner(): void {
        $this->seedSources(); $this->expectException(DomainException::class);
        (new QuotaExport(self::$db))->codebook(20,8,['age']);
    }

    public function testExportRetainsHistoricRunMappingAfterSurveyUnitRemoval(): void {
        $this->seedSources(); $this->admit();
        self::$db->exec('DELETE FROM survey_run_units WHERE id=201');
        self::assertSame('adult|female',$this->exportedRows()[0]['quota_age_sex_group']);
    }

    public function testExportCountedIsCompletionEvidenceRatherThanCorrectedGroupTotal(): void {
        $this->seedSources(); $this->admit(); $this->store->complete(1,$this->quota,1,101);
        $this->store->adjust(1,$this->quota,'education','uni','field',-1,7,'Exclude from total','exclude');
        self::assertSame(1,(int)$this->exportedRows()[0]['quota_counted']);
        self::assertSame(0,$this->store->getCounts(1,$this->quota,'field')['education']['uni']);
    }

    public function testMonitorLinkIsOptInStableAndRevocable(): void {
        $monitor=new QuotaMonitor(self::$db);
        self::assertNull($monitor->key(1,7));
        $key=$monitor->enable(1,7);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/',$key);
        self::assertSame($key,$monitor->enable(1,7));
        self::assertSame($key,$monitor->key(1,7));
        self::assertNotNull($monitor->read('study-one',$key));
        $monitor->revoke(1,7);
        self::assertNull($monitor->key(1,7));
        self::assertNull($monitor->read('study-one',$key));
        $newKey=$monitor->enable(1,7);
        self::assertNotSame($key,$newKey);
        self::assertNull($monitor->read('study-one',$key));
        self::assertNotNull($monitor->read('study-one',$newKey));
    }

    #[DataProvider('monitorOwnerOperations')]
    public function testMonitorManagementRequiresOwner(string $operation): void {
        $this->expectException(DomainException::class);
        (new QuotaMonitor(self::$db))->{$operation}(1,8);
    }
    public static function monitorOwnerOperations(): array { return [['key'],['enable'],['revoke']]; }

    public function testMonitorKeyCannotAccessAnotherRunOrMissingRun(): void {
        $monitor=new QuotaMonitor(self::$db);
        $key=$monitor->enable(1,7);
        $other=$monitor->enable(2,8);
        self::assertNull($monitor->read('study-two',$key));
        self::assertNull($monitor->read('study-one',$other));
        self::assertNull($monitor->read('missing',$key));
        foreach (['',str_repeat('x',64),str_repeat('0',64),$key.'a'] as $invalid) {
            self::assertNull($monitor->read('study-one',$invalid));
        }
    }

    public function testMonitorReturnsCurrentFieldAggregatesOnly(): void {
        $monitor=new QuotaMonitor(self::$db);
        $key=$monitor->enable(1,7);
        $this->admit(4); $this->store->complete(1,$this->quota,4,104);
        $this->store->adjust(1,$this->quota,'education','uni','test',4,7,'Test only','test-only');
        self::assertSame(0,$monitor->read('study-one',$key)['completed']);
        $this->admit(1); $this->store->complete(1,$this->quota,1,101);
        $this->store->adjust(1,$this->quota,'education','uni','field',1,7,'Synthetic','field-only');
        $view=$monitor->read('study-one',$key);
        self::assertSame(['name','completed','groups'],array_keys($view));
        self::assertSame(1,$view['completed']);
        self::assertSame(1,$view['groups']['age_sex']['adult|female']['count']);
        self::assertSame(2,$view['groups']['education']['uni']['count']);
        self::assertSame(['labels','limit','count'],array_keys($view['groups']['education']['uni']));
        $this->store->setLimit(1,$this->quota,'education','uni',0,7,'Close','close');
        self::assertSame(0,$monitor->read('study-one',$key)['groups']['education']['uni']['limit']);
        self::assertStringNotContainsString($key,json_encode($view,JSON_THROW_ON_ERROR));
    }

    public function testMonitorSupportsDraftWithoutActivationAndDoesNotCreateParticipation(): void {
        $tables=$this->seedEditor();
        (new QuotaEditor(self::$db))->save(1,202,7,$tables);
        $monitor=new QuotaMonitor(self::$db); $key=$monitor->enable(1,7);
        $before=(int)self::$db->query('SELECT COUNT(*) FROM survey_run_sessions')->fetchColumn();
        $view=$monitor->read('study-one',$key);
        self::assertSame(0,$view['completed']);
        self::assertNotEmpty($view['groups']);
        self::assertSame(0,(int)self::$db->query('SELECT COUNT(*) FROM survey_quotas')->fetchColumn());
        self::assertSame($before,(int)self::$db->query('SELECT COUNT(*) FROM survey_run_sessions')->fetchColumn());
        self::assertSame(0,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_decisions')->fetchColumn());
    }

    public function testMonitorReadHasNoQuotaWrites(): void {
        $monitor=new QuotaMonitor(self::$db); $key=$monitor->enable(1,7);
        for ($i=0;$i<2;$i++) { self::assertNotNull($monitor->read('study-one',$key)); }
        foreach (['survey_quota_decisions','survey_quota_completions','survey_quota_adjustments','survey_quota_limit_changes','survey_quota_edits'] as $table) {
            self::assertSame(0,(int)self::$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
        }
    }

    private function seedStructureTarget(): void {
        self::$db->exec('INSERT INTO survey_runs VALUES (3,7,"imported-study");
            INSERT INTO survey_run_units VALUES (301,3,20,10,"Imported demography"),
                (302,3,30,20,"Imported quota"),(303,3,40,40,"Imported complete")');
    }

    public function testPortableDraftImportsAsInactiveAndActivationStartsWithZeroCounts(): void {
        $this->seedLifecycle(false,false);
        $this->seedStructureTarget();
        $structure = new QuotaStructure(self::$db);
        $portable = $structure->exportQuota(1,202,7);
        self::assertTrue($structure->importQuota(3,302,7,$portable,[1=>301]));
        $structure->importComplete(3,303,7,'none');
        self::assertNull((new QuotaProviderStore(self::$db))->active(3));
        $local = (new QuotaEditor(self::$db))->load(3,302,7);
        self::assertSame(301,$local['age_sex']['dimensions'][0]['run_unit_id']);
        self::assertSame('survey_301.age',$local['age_sex']['dimensions'][0]['source']);
        $quota = $this->store->activate(3,7);
        self::assertSame(['age_sex'=>['adult|female'=>0],'education'=>['uni'=>0]],$this->store->getCounts(3,$quota,'field'));
        self::assertSame(0,(int) self::$db->query('SELECT COUNT(*) FROM survey_quota_decisions')->fetchColumn());
    }

    public function testActiveDefinitionExportsCurrentLimitsWithoutHistoryOrProviderProfile(): void {
        $this->seedLifecycle(true);
        $this->store->setLimit(1,$this->quota,'education','uni',12,7,'Increase limit','export-limit');
        $this->store->adjust(1,$this->quota,'education','uni','field',5,7,'Synthetic correction','export-correction');
        $portable = (new QuotaStructure(self::$db))->exportQuota(1,202,7);
        self::assertSame(12,$portable['tables']['education']['limits']['uni']);
        self::assertSame(['dimensions','limits'],array_keys($portable['tables']['education']));
        self::assertSame(['source','survey_position','item_name','type','categories'],
            array_keys($portable['tables']['education']['dimensions'][0]));
        self::assertStringNotContainsString('provider',json_encode($portable));
        self::assertStringNotContainsString('run_unit_id',json_encode($portable));
    }

    public function testPartialImportPreservesEditableDefinitionButCannotActivateOrReuseExistingSource(): void {
        $this->seedLifecycle(false,false);
        $this->seedStructureTarget();
        $structure = new QuotaStructure(self::$db);
        self::assertFalse($structure->importQuota(3,302,7,$structure->exportQuota(1,202,7),[]));
        $structure->importComplete(3,303,7,'none');
        $local = (new QuotaEditor(self::$db))->load(3,302,7);
        self::assertSame(0,$local['education']['dimensions'][0]['run_unit_id']);
        self::assertSame(['uni'=>1],$local['education']['limits']);
        try { $this->store->activate(3,7); self::fail('Missing source was activated.'); }
        catch (InvalidArgumentException $expected) { self::assertNull((new QuotaProviderStore(self::$db))->active(3)); }
    }

    public function testProviderModeDoesNotImportAnyExitProfileAndPreventsActivationUntilConfigured(): void {
        $this->seedLifecycle(true,false);
        $this->seedStructureTarget();
        $structure = new QuotaStructure(self::$db);
        $structure->importQuota(3,302,7,$structure->exportQuota(1,202,7),[1=>301]);
        $structure->importComplete(3,303,7,''); // The portable export strips the provider profile.
        self::assertSame(['mode'=>'provider'],(new QuotaProviderStore(self::$db))->settings(3,303,7)['provider']);
        try { $this->store->activate(3,7); self::fail('Missing provider settings were activated.'); }
        catch (InvalidArgumentException $expected) { self::assertNull((new QuotaProviderStore(self::$db))->active(3)); }
    }

    public function testStructureImportCannotWriteForAnotherOwnerOrCommitAnOuterTransaction(): void {
        $this->seedLifecycle(false,false);
        $this->seedStructureTarget();
        $structure = new QuotaStructure(self::$db);
        $portable = $structure->exportQuota(1,202,7);
        try { $structure->importQuota(3,302,8,$portable,[1=>301]); self::fail('Foreign owner imported quota.'); }
        catch (DomainException $expected) { self::assertSame([], (new QuotaEditor(self::$db))->load(3,302,7)); }
        self::$db->beginTransaction();
        try {
            $structure->importQuota(3,302,7,$portable,[1=>301]);
            $structure->importComplete(3,303,7,'none');
            self::assertTrue(self::$db->inTransaction());
        } finally { self::$db->rollBack(); }
        self::assertSame([], (new QuotaEditor(self::$db))->load(3,302,7));
        self::assertSame(['mode'=>'none'],(new QuotaProviderStore(self::$db))->settings(3,303,7)['provider']);
        self::assertSame(0,(int) self::$db->query('SELECT COUNT(*) FROM survey_quota_exit_drafts WHERE run_id=3')->fetchColumn());
    }

    public function testStructurePreflightRejectsForeignOwnerAndAnAlreadyConfiguredRun(): void {
        $this->seedLifecycle();
        $structure = new QuotaStructure(self::$db);
        $units = [(object)['type'=>'Quota']];
        foreach ([[1,8],[1,7]] as [$run,$actor]) {
            try { $structure->assertImportTarget($run,$actor,$units); self::fail('Invalid target accepted.'); }
            catch (DomainException $expected) { self::assertNotSame('',$expected->getMessage()); }
        }
        self::assertNotNull((new QuotaProviderStore(self::$db))->active(1));
    }

    public function testStructureAppendPreflightCannotResolveAnOverlappingExistingSurvey(): void {
        self::$db->exec('INSERT INTO survey_runs VALUES (3,7,"append-target");
            INSERT INTO survey_run_units VALUES (301,3,11,10,"Existing survey")');
        $structure = new QuotaStructure(self::$db);
        $units = [(object)['type'=>'Survey','position'=>-10],(object)['type'=>'Quota','position'=>20]];
        $this->expectException(InvalidArgumentException::class);
        $structure->assertImportTarget(3,7,$units,20);
    }

    public function testFailedRunCleanupPreservesAllResourcesAndAccountCascadesRefuseEarly(): void {
        $process=proc_open([PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir'),
            '-d','extension=mbstring','-d','extension=pdo_mysql',__DIR__.'/fixtures/quota/maintenance_regression.php'],
            [1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);
        $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$stdout.$stderr);
        $result=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
        self::assertSame([1,3,5],$result['preserved']);
        self::assertTrue($result['ordinary_deleted']);
        self::assertTrue($result['account_preserved']);
        self::assertTrue($result['outer_transaction_preserved']);
    }

    private function reopenAnswers(): array {
        return ['survey_201.age'=>'29','survey_201.sex'=>'2','survey_201.education'=>'uni'];
    }

    public function testReopeningKeepsLiveRulesAndDoesNotOverwriteAnEditedDraftOnRetry(): void {
        $this->seedLifecycle();
        $this->store->admit(1,$this->quota,1,fn()=>$this->reopenAnswers());
        $this->store->complete(1,$this->quota,1,101);
        self::$db->exec('UPDATE survey_quota_decisions SET expected_tables_json=NULL');
        $this->store->reopen(1,7);
        $editor=new QuotaEditor(self::$db);
        $draft=$editor->load(1,202,7);
        $draft['age_sex']['dimensions'][0]['categories']['adult']['min']=20;
        $editor->save(1,202,7,$draft);
        $this->store->reopen(1,7);
        self::assertSame($draft,$editor->load(1,202,7));
        self::assertSame(18,$this->store->overview(1,$this->quota,'field',7)['tables']['age_sex']['dimensions'][0]['categories']['adult']['min']);
        $this->assertCounts(1);
        self::assertFalse((new QuotaProviderStore(self::$db))->settings(1,203,7)['active']);
        self::assertSame(1,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_configuration_log')->fetchColumn());
        self::assertNotNull(self::$db->query('SELECT expected_tables_json FROM survey_quota_decisions')->fetchColumn());
    }

    public function testReactivationKeepsCountsCorrectionsAndLiveCompletionsAndStartsNewCodesAtZero(): void {
        $this->seedLifecycle();
        $quota=$this->quota;
        foreach ([1,2,4] as $session) { $this->store->admit(1,$quota,$session,fn()=>$this->reopenAnswers()); }
        $this->store->complete(1,$quota,1,101);
        $this->store->complete(1,$quota,4,104);
        $this->store->adjust(1,$quota,'age_sex','adult|female','field',2,7,'Correction','reopen-correction');
        $this->store->reopen(1,7);
        $editor=new QuotaEditor(self::$db); $draft=$editor->load(1,202,7);
        $draft['age_sex']['dimensions'][0]['categories']['adult']['max']=39;
        $draft['age_sex']['dimensions'][0]['categories']['older']=['min'=>40,'max'=>99];
        $draft['age_sex']['limits']['older|female']=5;
        $editor->save(1,202,7,$draft);
        $this->store->complete(1,$quota,2,102);
        self::assertSame($quota,$this->store->activate(1,7));
        self::assertSame($quota,$this->store->activate(1,7));
        $counts=$this->store->getCounts(1,$quota,'field');
        self::assertSame(4,$counts['age_sex']['adult|female']);
        self::assertSame(0,$counts['age_sex']['older|female']);
        self::assertSame(2,$counts['education']['uni']);
        self::assertSame(1,$this->store->getCounts(1,$quota,'test')['age_sex']['adult|female']);
        self::assertSame(3,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_completions')->fetchColumn());
        self::assertSame(2,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_configuration_log')->fetchColumn());
    }

    public function testReopeningRemovedGroupsKeepEarlierAdmissionsCompletionsAndExportLegends(): void {
        $this->seedLifecycle();
        $admitted=$this->store->admit(1,$this->quota,1,fn()=>$this->reopenAnswers());
        $rejected=$this->store->admit(1,$this->quota,2,fn()=>[]);
        $this->store->reopen(1,7);
        $editor=new QuotaEditor(self::$db); $draft=$editor->load(1,202,7);
        unset($draft['education']);
        $draft['age_sex']['dimensions'][0]['categories']=['older'=>['min'=>30,'max'=>99]];
        $draft['age_sex']['limits']=['older|female'=>10];
        $editor->save(1,202,7,$draft); $this->store->activate(1,7);
        $completed=$this->store->complete(1,$this->quota,1,101);
        self::assertSame($admitted['assignments'],$completed['assignments']);
        self::assertNotNull($completed['completed_at']);
        self::assertSame($rejected,$this->store->admit(1,$this->quota,2,fn()=>$this->reopenAnswers()));
        self::assertSame(['age_sex'=>['older|female'=>0]],$this->store->getCounts(1,$this->quota,'field'));
        $book=(new QuotaExport(self::$db))->codebook(20,7,['session_id','age']);
        $row=array_values(array_filter($book,fn($entry)=>$entry['quota_table']==='age_sex' && $entry['value']==='adult|female'));
        self::assertCount(1,$row);
        self::assertStringContainsString('Retired group:',$row[0]['meaning']);
        self::assertStringContainsString('18–29',$row[0]['meaning']);
        self::assertSame(2,(int)self::$db->query('SELECT COUNT(*) FROM survey_quota_assignments')->fetchColumn());
        // Previously used codes recover the same identities and their retained counts.
        $this->store->reopen(1,7);
        $restored=self::tables();
        foreach ($restored as &$table) {
            foreach ($table['dimensions'] as &$dimension) { $dimension['source']='survey_201.'.$dimension['item_name']; }
            unset($dimension);
        }
        unset($table);
        $editor->save(1,202,7,$restored); $this->store->activate(1,7);
        $this->assertCounts(1);
    }

    public function testReopeningNewTablesDoNotBlockPreviouslyAdmittedParticipants(): void {
        $this->seedLifecycle();
        $this->store->admit(1,$this->quota,1,fn()=>$this->reopenAnswers());
        $this->store->reopen(1,7);
        $editor=new QuotaEditor(self::$db); $draft=$editor->load(1,202,7);
        $draft['additional_education']=$draft['education'];
        foreach ($draft as &$table) { foreach ($table['limits'] as &$limit) { $limit=10; } unset($limit); }
        unset($table);
        $editor->save(1,202,7,$draft); $this->store->activate(1,7);
        self::assertCount(2,$this->store->complete(1,$this->quota,1,101)['assignments']);
        self::assertSame(0,$this->store->getCounts(1,$this->quota,'field')['additional_education']['uni']);
        self::assertCount(3,$this->store->admit(1,$this->quota,2,fn()=>$this->reopenAnswers())['assignments']);
        $this->store->complete(1,$this->quota,2,102);
        self::assertSame(1,$this->store->getCounts(1,$this->quota,'field')['additional_education']['uni']);
        self::assertSame(2,$this->store->getCounts(1,$this->quota,'field')['education']['uni']);
    }

    public function testReopeningProviderDraftDoesNotChangeLiveReturnLinksUntilReactivation(): void {
        $this->seedLifecycle(true);
        $providers=new QuotaProviderStore(self::$db);
        $providers->capture(1,1,['panel'=>'provided-test-ID']);
        $before=$providers->active(1)['provider']->toArray();
        $this->store->reopen(1,7);
        $draft=$providers->settings(1,203,7)['provider'];
        $draft['complete']='https://provider.example/changed?p=777&m=%PANEL%';
        $providers->save(1,203,7,$draft);
        self::assertSame($before,$providers->active(1)['provider']->toArray());
        self::assertSame($draft,$providers->settings(1,203,7)['provider']);
        $this->store->activate(1,7);
        self::assertSame($draft,$providers->active(1)['provider']->toArray());
        self::assertSame('provided-test-ID',$providers->identity($this->quota,1));
    }

    public function testReopeningRequiresTheOwnerAndFailedReactivationPreservesLiveRules(): void {
        $this->seedLifecycle();
        try { $this->store->reopen(1,8); self::fail('Wrong owner.'); } catch (DomainException $error) {}
        self::assertSame(0,(int)self::$db->query('SELECT editing FROM survey_quota_lifecycle')->fetchColumn());
        $this->store->reopen(1,7);
        $before=$this->store->overview(1,$this->quota,'field',7);
        self::$db->exec('CREATE TRIGGER refuse_reactivation BEFORE UPDATE ON survey_quota_groups
            FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT="Injected reactivation failure"');
        try { $this->store->activate(1,7); self::fail('Expected write refusal.'); }
        catch (PDOException $error) { self::assertStringContainsString('Injected reactivation failure',$error->getMessage()); }
        finally { self::$db->exec('DROP TRIGGER refuse_reactivation'); }
        self::assertSame($before,$this->store->overview(1,$this->quota,'field',7));
        self::assertSame(1,(int)self::$db->query('SELECT editing FROM survey_quota_lifecycle')->fetchColumn());
        self::assertFalse(self::$db->inTransaction());
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
