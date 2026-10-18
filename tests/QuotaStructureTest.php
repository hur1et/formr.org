<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../application/Services/QuotaRules.php';
require_once __DIR__.'/../application/Services/QuotaStructure.php';
require_once __DIR__.'/../application/Model/RunUnit/Page.php';

class QuotaStructureTest extends TestCase {
    private function definition(): array {
        $tables = ['income_party'=>['dimensions'=>[
            ['source'=>'survey_991.income','run_unit_id'=>991,'item_name'=>'income','type'=>'integer',
                'categories'=>['low'=>['min'=>0,'max'=>1999],'high'=>['min'=>2000,'max'=>9999]]],
            ['source'=>'survey_992.party','run_unit_id'=>992,'item_name'=>'party','type'=>'category',
                'categories'=>['a'=>'001','b'=>'B']],
        ],'limits'=>['low|a'=>12,'low|b'=>0,'high|a'=>20,'high|b'=>40],'count'=>999]];
        return ['version'=>1,'tables'=>QuotaStructure::portableTables($tables,[991=>10,992=>20])];
    }

    public function testCrossedGenericSourcesRemapToTheImportedOccurrencesAndPreserveLimits(): void {
        $portable = $this->definition();
        $json = json_encode($portable, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('991',$json);
        self::assertStringNotContainsString('992',$json);
        self::assertStringNotContainsString('"count"',$json);
        $local = QuotaStructure::localTables($portable,[10=>51,20=>52]);
        self::assertSame('survey_51.income',$local['income_party']['dimensions'][0]['source']);
        self::assertSame('survey_52.party',$local['income_party']['dimensions'][1]['source']);
        self::assertSame('001',$local['income_party']['dimensions'][1]['categories']['a']);
        self::assertSame($portable['tables']['income_party']['limits'],$local['income_party']['limits']);
        self::assertCount(4,(new QuotaRules($local))->getGroups()['income_party']);
    }

    public function testMissingSourceNeverFallsBackToAnOldDatabaseIdentity(): void {
        $portable = $this->definition();
        $portable['tables']['income_party']['dimensions'][0]['run_unit_id']=991;
        $local = QuotaStructure::localTables($portable,[20=>52]);
        self::assertSame(0,$local['income_party']['dimensions'][0]['run_unit_id']);
        self::assertSame('position_10.income',$local['income_party']['dimensions'][0]['source']);
        self::assertSame(52,$local['income_party']['dimensions'][1]['run_unit_id']);
    }

    public function testImportDropsExecutableAndProviderFieldsAndKeepsExplicitNoProvider(): void {
        $units = QuotaStructure::prepareImport([
            (object) ['type'=>'Quota','position'=>30,'quota_definition'=>$this->definition(),
                'id'=>55,'run_unit_id'=>99,'quota_activate'=>1,'quota_tables'=>'untrusted','user_id'=>999],
            (object) ['type'=>'Complete','position'=>40,'provider_mode'=>'provider',
                'provider_complete'=>'https://old-study.example/complete','provider_placeholder'=>'%ID%','id'=>88],
        ]);
        self::assertSame(['type','position','description','quota_definition'],array_keys((array) $units[0]));
        self::assertSame(['type'=>'Complete','position'=>40,'description'=>'','provider_mode'=>''],(array) $units[1]);
        $units[1]->provider_mode = 'none';
        self::assertSame('none',QuotaStructure::prepareImport($units)[1]->provider_mode);
    }

    public function testDuplicatePositionsAreRejectedBeforeAnyImportMutation(): void {
        $this->expectException(InvalidArgumentException::class);
        QuotaStructure::prepareImport([(object)['type'=>'Survey','position'=>10],
            (object)['type'=>'Complete','position'=>10]]);
    }

    public function testPortableCompletionTextAndTimingSurviveWithoutAProviderProfile(): void {
        $page=['body'=>'Study debriefing','redirect_message'=>'Wait {seconds}: {link}','redirect_delay'=>90,'redirect_link_text'=>'Return'];
        $unit=(object)['type'=>'Complete','position'=>40,'provider_mode'=>'provider','completion_page'=>$page,
            'provider_complete'=>'https://old-study.example/complete'];
        $imported=QuotaStructure::prepareImport([$unit])[0];
        self::assertSame($page,$imported->completion_page);
        self::assertSame('',$imported->provider_mode);
        self::assertFalse(isset($imported->provider_complete));
    }

    public function testUnsupportedVersionIsRejected(): void {
        $definition = $this->definition(); $definition['version'] = 2;
        $this->expectException(InvalidArgumentException::class);
        QuotaStructure::localTables($definition,[]);
    }

    public function testEmptyQuotaUnitIsPortableAndRemainsAnEmptyDraft(): void {
        self::assertSame([],QuotaStructure::localTables(['version'=>1,'tables'=>[]],[]));
    }

    public function testPageRoundTripNormalizesEmptyProviderExitAfterModelAssignment(): void {
        $db = $this->getMockBuilder(DB::class)->disableOriginalConstructor()->getMock();
        $db->method('insert')->willReturnOnConsecutiveCalls(10,20);
        $db->expects(self::once())->method('insert_update')->with('survey_pages',self::callback(
            static fn($row)=>$row['provider_exit'] === '' && $row['body'] === 'Normal stop page'));
        $page = new class($db) extends Page {
            public function __construct(DB $db) {
                $this->db = $db;
                $this->run = (new ReflectionClass(Run::class))->newInstanceWithoutConstructor();
                $this->run->id = 1;
                $this->provider_exit = null; // Empty exported strings are normalized here by Model.
            }
        };
        $page->create(['type'=>'Page','position'=>10,'provider_exit'=>'','body'=>'Normal stop page']);
        self::assertSame('',$page->provider_exit);
    }
}
