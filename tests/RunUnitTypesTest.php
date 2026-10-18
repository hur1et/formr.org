<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RunUnitTypesTest extends TestCase {
    private function fixture(bool $legacyTypeColumn): array {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $legacy = $legacyTypeColumn ? ",type TEXT DEFAULT 'legacy_adapter'" : '';
        $pdo->exec('CREATE TABLE survey_units (id INTEGER PRIMARY KEY,type TEXT);
            CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY,run_id INTEGER,unit_id INTEGER,
                position INTEGER,description TEXT' . $legacy . ');
            INSERT INTO survey_units VALUES (1,"Survey"),(2,"Complete"),(3,"Privacy");
            INSERT INTO survey_run_units (id,run_id,unit_id,position,description) VALUES
                (71,7,2,20,"Finish"),(72,7,1,10,"Questions"),(73,8,3,30,"Other run");');
        $db = (new ReflectionClass(DB::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(DB::class, 'PDO'))->setValue($db, $pdo);
        $run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Model::class, 'db'))->setValue($run, $db);
        $run->id = 7;
        return [$run, $pdo];
    }

    #[DataProvider('schemas')]
    public function testUnitTypesUseUnitDefinitionAndKeepRunOrder(bool $legacyTypeColumn): void {
        [$run] = $this->fixture($legacyTypeColumn);
        $rows = $run->getAllUnitTypes();
        self::assertSame(['Survey','Complete'], array_column($rows, 'type'));
        self::assertSame([72,71], array_map('intval', array_column($rows, 'run_unit_id')));
        self::assertSame([1,2], array_map('intval', array_column($rows, 'unit_id')));
        self::assertSame([10,20], array_map('intval', array_column($rows, 'position')));
        self::assertSame(['Questions','Finish'], array_column($rows, 'description'));
    }

    #[DataProvider('schemas')]
    public function testPrivacyDetectionUsesUnitTypeWithinThisRun(bool $legacyTypeColumn): void {
        [$run, $pdo] = $this->fixture($legacyTypeColumn);
        self::assertFalse($run->hasPrivacyUnit());
        $pdo->exec('INSERT INTO survey_run_units (id,run_id,unit_id,position,description)
            VALUES (74,7,3,30,"This run privacy")');
        self::assertTrue($run->hasPrivacyUnit());
    }

    public static function schemas(): array {
        return ['standard schema'=>[false], 'legacy adapter schema'=>[true]];
    }
}
