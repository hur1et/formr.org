<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../application/Services/QuotaProviderConfig.php';

class QuotaProviderConfigTest extends TestCase {
    public static function config(): array {
        return ['mode'=>'provider','entry_parameter'=>'panel','placeholder'=>'%PANEL%',
            'complete'=>'https://provider.example/complete?p=study_123&m=%PANEL%',
            'quota_full'=>'https://provider.example/quotasfull?p=study_456&m=%PANEL%',
            'screen_out'=>'https://provider.example/screenout?p=study_789&m=%PANEL%'];
    }

    #[DataProvider('identityCases')]
    public function testProviderIdentityIsEncodedExactlyOnce(string $identity): void {
        $config = new QuotaProviderConfig(self::config());
        foreach (['complete','quota_full','screen_out'] as $outcome) {
            $url = $config->resolve($outcome,$identity);
            parse_str(parse_url($url,PHP_URL_QUERY),$query);
            self::assertSame($identity,$query['m']);
            self::assertSame(2,count($query));
            self::assertSame($outcome === 'complete' ? 'study_123' : ($outcome === 'quota_full' ? 'study_456' : 'study_789'),$query['p']);
        }
    }

    public static function identityCases(): array {
        return [['random_123'],['ä/字 &x=1?+%'],['%2Falready-literal'],['0']];
    }

    #[DataProvider('badConfigurations')]
    public function testInvalidConfigurationCannotBeActivated(array $changes): void {
        $this->expectException(InvalidArgumentException::class);
        new QuotaProviderConfig(array_replace(self::config(),$changes));
    }

    public static function badConfigurations(): array {
        return [[['mode'=>'']], [['mode'=>'guess']], [['entry_parameter'=>'code']], [['entry_parameter'=>'m[]']],
            [['placeholder'=>'']], [['complete'=>'javascript:alert(1)']], [['quota_full'=>'https://provider.example/no-id']],
            [['complete'=>'https://%PANEL%.example/exit?a=1']], [['complete'=>'https://provider.example/%PANEL%?a=1']],
            [['complete'=>'https://provider.example/exit?a=1#%PANEL%']],
            [['complete'=>"https://provider.example/?m=%PANEL%\r\nX-Fake: yes"]]];
    }

    public function testMissingIdentityNeverCreatesAnEmptyCallback(): void {
        $this->expectException(DomainException::class);
        (new QuotaProviderConfig(self::config()))->resolve('complete',null);
    }

    public function testExplicitNoProviderNeedsNoIdentityOrUrls(): void {
        $config = new QuotaProviderConfig(['mode'=>'none']);
        self::assertNull($config->entryParameter());
        self::assertNull($config->resolve('complete',null));
    }
}
