<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../application/Services/QuotaOverview.php';

class QuotaOverviewInputTest extends TestCase {
    public function testPostedIntegersArePreservedWithoutRounding(): void {
        $rows=QuotaOverview::parseRows(['a'=>['g'=>['limit'=>'12','base_limit'=>'10','count'=>'7','base_count'=>'9','reason'=>'  Exclude two  ']]]);
        self::assertSame(['limit'=>12,'base_limit'=>10,'count'=>7,'base_count'=>9,'reason'=>'Exclude two'],$rows['a']['g']);
    }

    #[DataProvider('invalidNumbers')]
    public function testInvalidCountsCannotBeRoundedOrCoerced($value): void {
        $this->expectException(InvalidArgumentException::class);
        QuotaOverview::parseRows(['a'=>['g'=>['limit'=>'1','base_limit'=>'1','count'=>$value,'base_count'=>'0']]]);
    }
    public static function invalidNumbers(): array {
        return [[''],['1.5'],[1.5],['-1'],['1e2'],[' 1'],['01'],[null],[true],[[]],['999999999999999999999999']];
    }

    public function testLimitCannotOverflowStorage(): void {
        $this->expectException(InvalidArgumentException::class);
        QuotaOverview::parseRows(['a'=>['g'=>['limit'=>'4294967296','base_limit'=>'1','count'=>'0','base_count'=>'0']]]);
    }
}
