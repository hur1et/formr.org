<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../application/Model/Model.php';
require_once __DIR__ . '/../application/Model/RunUnit/RunUnit.php';
require_once __DIR__ . '/../application/Model/RunUnit/Quota.php';
require_once __DIR__ . '/../application/Model/RunUnit/Complete.php';
require_once __DIR__ . '/../application/Model/UnitSession.php';
require_once __DIR__ . '/../application/Model/RunSession.php';

class QuotaDraftUnitTest extends TestCase {
    public static function unitTypes(): array { return [['Quota'], ['Complete']]; }

    #[DataProvider('unitTypes')]
    public function testDraftOutputHoldsTheActualRunSessionWithoutAdvancingOrBooking(string $type): void {
        $unit = (new ReflectionClass($type))->newInstanceWithoutConstructor();
        $session = new class extends UnitSession {
            public function __construct() {}
            protected function isExpired() { return false; }
        };
        $session->runUnit = $unit;
        $runSession = new class extends RunSession {
            public int $moves = 0;
            public function __construct() {}
            public function moveOn($starting = false, $execute = true) { $this->moves++; return []; }
            public function debug($message = '', $only = false) {}
            public function runDraft(UnitSession $session) {
                $this->currentUnitSession = $session;
                return $this->executeUnitSession();
            }
        };
        self::assertContains($type, RunUnitFactory::getSupportedUnits());
        $result = $runSession->runDraft($session);
        self::assertStringContainsString('temporarily unavailable', $result['body']);
        self::assertSame(0, $runSession->moves);
        self::assertSame($result, $runSession->runDraft($session));
    }

    #[DataProvider('unitTypes')]
    public function testDraftCannotBeSilentlyExportedWithoutItsConfiguration(string $type): void {
        $unit = (new ReflectionClass($type))->newInstanceWithoutConstructor();
        $this->expectException(DomainException::class);
        $unit->getExportUnit();
    }
}
