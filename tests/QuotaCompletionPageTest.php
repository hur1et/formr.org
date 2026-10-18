<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuotaCompletionPageTest extends TestCase {
    public function testDefaultWaitAndCustomTextHaveAnEscapedIndividualFallbackLink(): void {
        $settings=QuotaCompletionPage::validate([]);
        self::assertSame(10,$settings['redirect_delay']);
        $html=QuotaCompletionPage::render($settings,'<p>Debriefing</p>','https://panel.example/complete?id=a%26b&study=1',false);
        self::assertStringContainsString('<p>Debriefing</p>',$html);
        self::assertStringContainsString('data-completion-delay="10"',$html);
        self::assertStringContainsString('data-completion-seconds>10</span>',$html);
        self::assertStringContainsString('href="https://panel.example/complete?id=a%26b&amp;study=1"',$html);
    }

    public function testPlainReturnMessageIsEscapedAndCannotRemoveTheFallbackLink(): void {
        $settings=QuotaCompletionPage::validate(['redirect_message'=>'<script>evil()</script> {seconds}','redirect_delay'=>'120']);
        $html=QuotaCompletionPage::render($settings,'Text','https://panel.example/?id=abc',false);
        self::assertStringContainsString('&lt;script&gt;evil()&lt;/script&gt;',$html);
        self::assertStringNotContainsString('<script>',$html);
        self::assertStringContainsString('data-completion-delay="120"',$html);
        self::assertStringContainsString('data-completion-link',$html);
    }

    public function testNoProviderAndTestModeNeverRenderAnAutomaticOrClickableReturn(): void {
        $settings=QuotaCompletionPage::defaults();
        self::assertSame('<p>Thanks</p>',QuotaCompletionPage::render($settings,'<p>Thanks</p>',null,false));
        self::assertSame('<p>Thanks</p>',QuotaCompletionPage::render($settings,'<p>Thanks</p>','https://panel.example/?id=test',true));
    }

    #[DataProvider('invalidDelays')]
    public function testInvalidDelaysAreRejectedWithoutRounding(mixed $delay): void {
        $this->expectException(InvalidArgumentException::class);
        QuotaCompletionPage::validate(['redirect_delay'=>$delay]);
    }

    public static function invalidDelays(): array {
        return [[-1],['1.5'],[1.5],[''],[null],[[]],['2147484'],['99999999999999999999999']];
    }

    public function testExplicitZeroAndLongDebriefingArePreserved(): void {
        $body=str_repeat('Debriefing text. ',500);
        self::assertSame(['body'=>$body,'redirect_message'=>'Return {link}','redirect_delay'=>0,'redirect_link_text'=>'click here'],
            QuotaCompletionPage::validate(['body'=>$body,'redirect_message'=>'Return {link}','redirect_delay'=>'0']));
    }

    public function testLinkTextCanBeTranslatedAndIsEscaped(): void {
        $settings=QuotaCompletionPage::validate(['redirect_link_text'=>'hier <klicken>']);
        $html=QuotaCompletionPage::render($settings,'Danke.','https://panel.example/?id=test',false);
        self::assertStringContainsString('>hier &lt;klicken&gt;</a>',$html);
    }
}
