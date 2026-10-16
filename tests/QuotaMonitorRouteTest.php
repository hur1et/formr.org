<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuotaMonitorRouteTest extends TestCase {
    #[DataProvider('modes')]
    public function testMonitorUsesExistingStudyRoute(string $mode): void {
        $process=proc_open([PHP_BINARY,'-n',__DIR__.'/fixtures/quota/monitor_route.php',$mode],
            [1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);
        $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$error.$output);
        self::assertSame('Quota route OK',trim($output));
    }
    public static function modes(): array { return [['folder'],['subdomain'],['admin']]; }
}
