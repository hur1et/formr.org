<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuotaPublicationTest extends TestCase {
    private PDO $pdo;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE survey_runs (id INTEGER PRIMARY KEY, public INTEGER);
            CREATE TABLE survey_units (id INTEGER PRIMARY KEY, type TEXT);
            CREATE TABLE survey_run_units (id INTEGER PRIMARY KEY,run_id INTEGER,unit_id INTEGER,position INTEGER);
            CREATE TABLE survey_quota_lifecycle (run_id INTEGER,quota_run_unit_id INTEGER,complete_run_unit_id INTEGER,provider_json TEXT,editing INTEGER DEFAULT 0);
            INSERT INTO survey_runs VALUES (1,0);
            INSERT INTO survey_units VALUES (10,"Survey"),(20,"Quota"),(30,"Complete");
            INSERT INTO survey_run_units VALUES (100,1,10,10)');
    }

    private function modules(): void {
        $this->pdo->exec('INSERT INTO survey_run_units VALUES (200,1,20,20),(300,1,30,40)');
    }

    private function activate(array $config = ['mode'=>'none']): void {
        $query = $this->pdo->prepare('INSERT INTO survey_quota_lifecycle (run_id,quota_run_unit_id,complete_run_unit_id,provider_json) VALUES (1,200,300,?)');
        $query->execute([json_encode($config, JSON_THROW_ON_ERROR)]);
    }

    private function studyRun(): Run {
        return new class($this->pdo) extends Run {
            public function __construct(PDO $pdo) {
                $this->id = 1;
                $this->expiresOn = '2099-01-01';
                $this->db = new class($pdo) {
                    public function __construct(private PDO $connection) {}
                    public function pdo() { return $this->connection; }
                    public function update($table, $data, $where) {
                        $query = $this->connection->prepare('UPDATE survey_runs SET public=? WHERE id=?');
                        return $query->execute([$data['public'],$where['id']]);
                    }
                };
            }
            public function hasPrivacy() { return true; }
        };
    }

    public function testRunsWithoutQuotaKeepTheirExistingVisibilityChoices(): void {
        foreach ([1,2,3,0] as $visibility) {
            self::assertTrue($this->studyRun()->togglePublic($visibility));
            self::assertSame($visibility,(int)$this->pdo->query('SELECT public FROM survey_runs')->fetchColumn());
        }
    }

    public static function incompleteCases(): array {
        return array_map(fn($case)=>[$case], ['missing_complete','missing_activation','wrong_order',
            'different_complete','duplicate_quota','invalid_provider','duplicate_position','editing']);
    }

    #[DataProvider('incompleteCases')]
    public function testIncompleteQuotasCannotChangeAnyParticipantVisibility(string $case): void {
        $this->modules();
        $this->activate();
        switch ($case) {
            case 'missing_complete': $this->pdo->exec('DELETE FROM survey_run_units WHERE id=300'); break;
            case 'missing_activation': $this->pdo->exec('DELETE FROM survey_quota_lifecycle'); break;
            case 'wrong_order': $this->pdo->exec('UPDATE survey_run_units SET position=15 WHERE id=300'); break;
            case 'different_complete': $this->pdo->exec('UPDATE survey_quota_lifecycle SET complete_run_unit_id=999'); break;
            case 'duplicate_quota': $this->pdo->exec('INSERT INTO survey_run_units VALUES (201,1,20,25)'); break;
            case 'invalid_provider': $this->pdo->exec('UPDATE survey_quota_lifecycle SET provider_json="{}"'); break;
            case 'duplicate_position': $this->pdo->exec('UPDATE survey_run_units SET position=20 WHERE id=100'); break;
            case 'editing': $this->pdo->exec('UPDATE survey_quota_lifecycle SET editing=1'); break;
        }
        foreach ([1,2,3] as $visibility) {
            self::assertFalse($this->studyRun()->togglePublic($visibility));
            self::assertSame(0,(int)$this->pdo->query('SELECT public FROM survey_runs')->fetchColumn());
        }
        self::assertTrue($this->studyRun()->togglePublic(0));
    }

    public function testSelfRecruitmentNeedsNoProviderToPublish(): void {
        $this->modules(); $this->activate();
        self::assertTrue($this->studyRun()->togglePublic(2));
        self::assertSame(2,(int)$this->pdo->query('SELECT public FROM survey_runs')->fetchColumn());
    }

    public function testValidProviderAlsoAllowsPublication(): void {
        $this->modules();
        $this->activate(['mode'=>'provider','entry_parameter'=>'panel_id','placeholder'=>'%ID%',
            'complete'=>'https://provider.example/complete?id=%ID%',
            'quota_full'=>'https://provider.example/full?id=%ID%',
            'screen_out'=>'https://provider.example/out?id=%ID%']);
        self::assertTrue($this->studyRun()->togglePublic(2));
    }
}
