<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../application/Model/Model.php';
require_once __DIR__ . '/../application/Model/RunUnit/RunUnit.php';
require_once __DIR__ . '/../application/Model/RunUnit/Quota.php';
require_once __DIR__ . '/../application/Model/RunUnit/Complete.php';
require_once __DIR__ . '/../application/Model/UnitSession.php';
require_once __DIR__ . '/../application/Model/RunSession.php';
require_once __DIR__ . '/../application/Model/Run.php';
require_once __DIR__ . '/../application/Services/QuotaRuntime.php';

class QuotaDraftUnitTest extends TestCase {
    public static function unitTypes(): array { return [['Quota'], ['Complete']]; }

    #[DataProvider('unitTypes')]
    public function testDraftOutputHoldsTheActualRunSessionWithoutAdvancingOrBooking(string $type): void {
        $runtime = $this->createMock(QuotaRuntime::class);
        $runtime->method('execute')->willReturn(['content'=>'<p>This study is temporarily unavailable. Please try again later.</p>']);
        $unit = $type === 'Quota' ? new class($runtime) extends Quota {
            public function __construct(private QuotaRuntime $engine) {}
            protected function quotaRuntime(): QuotaRuntime { return $this->engine; }
        } : new class($runtime) extends Complete {
            public function __construct(private QuotaRuntime $engine) {}
            protected function quotaRuntime(): QuotaRuntime { return $this->engine; }
        };
        $unit->run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
        $unit->run->id = 1;
        $session = new class extends UnitSession {
            public function __construct() {}
            protected function isExpired() { return false; }
        };
        $session->runUnit = $unit;
        $runSession = new class extends RunSession {
            public int $moves = 0;
            public function __construct() {}
            public function moveOn($starting = false, $execute = true) { $this->moves++; return []; }
            public function isCron() { return false; }
            public function debug($message = '', $only = false) {}
            public function runDraft(UnitSession $session) {
                $session->runSession = $this;
                $this->currentUnitSession = $session;
                return $this->executeUnitSession();
            }
        };
        $session->runSession = $runSession;
        self::assertFalse($session->isExecutedByCron());
        $direct = $unit->getUnitSessionOutput($session);
        self::assertArrayNotHasKey('log',$direct,json_encode($direct));
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

    public static function terminalOutputs(): array {
        return [[['quota_terminal'=>true,'ended_at'=>'2026-10-15 12:00:00','redirect'=>'https://provider.example/complete?m=fake']],
            [['quota_terminal'=>true,'ended_at'=>'2026-10-15 12:00:00','content'=>'Local completion page']]];
    }

    #[DataProvider('terminalOutputs')]
    public function testCommittedQuotaExitNeverMovesPastItsRetryPosition(array $output): void {
        $session = new class($output) extends UnitSession {
            public function __construct(private array $output) {}
            public function execute() { return $this->output; }
        };
        $run = new class extends RunSession {
            public int $moves = 0;
            public function __construct() {}
            public function moveOn($starting = false,$execute = true) { $this->moves++; return []; }
            public function debug($message = '',$only = false) {}
            public function end() { throw new LogicException('Terminal state was already committed.'); }
            public function runTerminal(UnitSession $session) { $this->currentUnitSession=$session; return $this->executeUnitSession(); }
        };
        $result = $run->runTerminal($session);
        self::assertSame(isset($output['redirect']) ? ['redirect'=>$output['redirect']] : ['body'=>$output['content']],$result);
        self::assertSame(0,$run->moves);
        self::assertSame($output['ended_at'],$run->ended);
        self::assertSame($result,$run->runTerminal($session));
    }
}
