<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../application/Services/QuotaOverview.php';

class QuotaOverviewInputTest extends TestCase {
    public function testPostedIntegersArePreservedWithoutRounding(): void {
        $rows=QuotaOverview::parseRows(['a'=>['g'=>['limit'=>'12','base_limit'=>'10','count'=>'7','base_count'=>'9']]]);
        self::assertSame(['limit'=>12,'base_limit'=>10,'count'=>7,'base_count'=>9],$rows['a']['g']);
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

    public function testCompactFormPreserves500GroupsAtTheDefaultInputLimit(): void {
        $rows=[];
        for ($i=1;$i<=500;$i++) {
            $rows['generic']['g'.$i]=['limit'=>'12','base_limit'=>'10','count'=>'7','base_count'=>'9'];
        }
        parse_str(http_build_query(['cohort'=>'field','quota_id'=>'1','definition_hash'=>'hash',
            'operation_key'=>'key','rows_json'=>json_encode($rows,JSON_THROW_ON_ERROR)]),$posted);
        self::assertCount(5,$posted);
        $decoded=QuotaOverview::parseRows(QuotaOverview::decodeRows($posted['rows_json']));
        self::assertCount(500,$decoded['generic']);
        self::assertSame(['limit'=>12,'base_limit'=>10,'count'=>7,'base_count'=>9],$decoded['generic']['g500']);
    }

    #[DataProvider('invalidPayloads')]
    public function testBrokenCompactPayloadIsRefused($json): void {
        $this->expectException(InvalidArgumentException::class);
        QuotaOverview::decodeRows($json);
    }
    public static function invalidPayloads(): array {
        return [[null],[[]],[''],['{'],['null'],['false'],['123'],['"text"'],['[]'],['{}']];
    }

    public function testCompactValuesStillUseExactIntegerValidation(): void {
        $rows=['a'=>['g'=>['limit'=>'1','base_limit'=>'1','count'=>'9007199254740993','base_count'=>'0']]];
        $decoded=QuotaOverview::parseRows(QuotaOverview::decodeRows(json_encode($rows,JSON_THROW_ON_ERROR)));
        self::assertSame(9007199254740993,$decoded['a']['g']['count']);
        $rows['a']['g']['count']='1.5';
        $this->expectException(InvalidArgumentException::class);
        QuotaOverview::parseRows(QuotaOverview::decodeRows(json_encode($rows,JSON_THROW_ON_ERROR)));
    }
}
