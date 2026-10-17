<?php
use PHPUnit\Framework\TestCase;

class QuotaMaintenanceTest extends TestCase {
    public function testRefusedNativeReplacementIsAnApiConflictWithoutOldCountFallback(): void {
        $process=proc_open([PHP_BINARY,'-n',__DIR__.'/fixtures/quota/astra_api_refusal.php'],
            [1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);
        $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$stdout.$stderr);
        $result=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
        self::assertFalse($result['native_replace_result']);
        self::assertSame(409,$result['api_response']['code']);
        self::assertArrayNotHasKey('payload',$result['api_response']);
    }
}
