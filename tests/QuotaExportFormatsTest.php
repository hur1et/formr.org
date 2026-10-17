<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuotaExportFormatsTest extends TestCase {
    #[DataProvider('formats')]
    public function testExistingDownloadWritersKeepHeadersAndAnswerValues(string $format): void {
        $path=tempnam(sys_get_temp_dir(),'quota_format_');
        $command=[PHP_BINARY,'-n','-d','extension_dir='.ini_get('extension_dir')];
        $extensionRoot=rtrim(ini_get('extension_dir'),'/\\').DIRECTORY_SEPARATOR;
        foreach (['pdo','mbstring','pdo_sqlite','zip','gd'] as $extension) {
            if (is_file($extensionRoot.$extension.'.so') || is_file($extensionRoot.'php_'.$extension.'.dll')) {
                array_push($command,'-d','extension='.$extension);
            }
        }
        array_push($command,__DIR__.'/fixtures/quota/export_format.php',$format,$path);
        try {
            $process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            self::assertIsResource($process);
            $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0,proc_close($process),$stdout.$stderr);
            self::assertGreaterThan(0,filesize($path),$stdout.$stderr);
            $headers=['session','session_id','study_id','iteration','created','modified','ended','expired',
                'quota_status','quota_counted','quota_income_party_group','income','party_preference','note'];
            if ($format==='json') {
                $rows=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
                self::assertSame($headers,array_keys($rows[0]));
                $values=array_map('array_values',$rows);
            } else {
                $reader=\PhpOffice\PhpSpreadsheet\IOFactory::createReader(in_array($format,['csv','csv_german','tsv'],true)
                    ? 'Csv' : ($format==='xls'?'Xls':'Xlsx'));
                if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv) {
                    $reader->setDelimiter(['csv'=>',','csv_german'=>';','tsv'=>"\t"][$format]);
                    if ($format==='tsv') { $reader->setEnclosure(''); }
                }
                $sheet=$reader->load($path);
                $rows=$sheet->getActiveSheet()->toArray(null,false,false,false);
                self::assertSame($headers,array_shift($rows)); $values=$rows;
                $sheet->disconnectWorksheets();
            }
            self::assertCount(2,$values);
            self::assertSame(['P001','101','20','1','2026-10-16 10:00:00','','2026-10-16 10:05:00','',
                'admitted','1','high|b','2500','B','A, B – quoted'],array_map(static fn($v)=>(string)$v,$values[0]));
            self::assertSame(['P002','102','20','2','2026-10-16 10:01:00','','','','','0','','1500','A','001'],
                array_map(static fn($v)=>(string)$v,$values[1]));
        } finally { if (is_file($path)) { unlink($path); } }
    }
    public static function formats(): array { return [['csv'],['csv_german'],['tsv'],['xls'],['xlsx'],['json']]; }
}
