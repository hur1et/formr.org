<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RunSessionResumeTest extends TestCase {
    #[DataProvider('unitTypes')]
    public function testCurrentUnitSessionReloadsItsTrustedIdentity(string $type): void {
        $original = DB::getInstance();
        $instance = new ReflectionProperty(DB::class, 'instance');
        $db = (new ReflectionClass(DB::class))->newInstanceWithoutConstructor();
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        (new ReflectionProperty(DB::class, 'PDO'))->setValue($db, $pdo);
        $pdo->exec('CREATE TABLE survey_units (id INTEGER PRIMARY KEY,type TEXT,created TEXT,modified TEXT);
            CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY,run_id INTEGER,unit_id INTEGER,
                position INTEGER,description TEXT);
            CREATE TABLE survey_unit_sessions (id INTEGER PRIMARY KEY,unit_id INTEGER,run_unit_id INTEGER,
                iteration INTEGER,run_session_id INTEGER,created TEXT,expires TEXT,queued INTEGER,
                result TEXT,result_log TEXT,ended TEXT,expired TEXT,state TEXT,state_log TEXT,idempotency_key TEXT);
            INSERT INTO survey_run_units VALUES (31,1,10,20,"Resume fixture");
            INSERT INTO survey_unit_sessions (id,unit_id,run_unit_id,iteration,run_session_id,queued,state)
                VALUES (101,10,31,1,7,0,"PENDING"), (102,10,31,2,7,-9,"SUPERSEDED");');
        $pdo->prepare('INSERT INTO survey_units (id,type) VALUES (10,?)')->execute([$type]);
        $instance->setValue(null, $db);
        try {
            $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
            $run->id = 1;
            $session = (new ReflectionClass(RunSession::class))->newInstanceWithoutConstructor();
            $session->boot();
            (new ReflectionProperty(RunSession::class, 'run'))->setValue($session, $run);
            $session->id = 7;
            $session->position = 20;

            $current = $session->getCurrentUnitSession();
            self::assertInstanceOf(UnitSession::class, $current);
            self::assertTrue($current->valid);
            self::assertSame(101, (int)$current->id);
            self::assertSame(31, (int)$current->run_unit_id);
            self::assertSame(7, (int)$current->run_session_id);
            self::assertSame(10, (int)$current->unit_id);
            self::assertSame('PENDING', $current->state);
            self::assertSame($type, $current->runUnit->type);
        } finally {
            $instance->setValue(null, $original);
        }
    }

    public static function unitTypes(): array {
        return [['Quota'], ['Complete']];
    }
}
