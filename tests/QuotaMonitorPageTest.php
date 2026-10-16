<?php
use PHPUnit\Framework\TestCase;

class QuotaMonitorPageTest extends TestCase {
    private function render(int $limit,int $count,string $label='age: 18–29'): string {
        $monitor=['name'=>'Example','completed'=>1,'groups'=>['age'=>['adult'=>[
            'labels'=>[$label],'limit'=>$limit,'count'=>$count]]]];
        ob_start();
        try { require __DIR__.'/../templates/run/quotas.php'; return ob_get_contents(); }
        finally { ob_end_clean(); }
    }

    public function testOverfillIsVisibleAndHasNoEditingControls(): void {
        $html=$this->render(10,11);
        self::assertStringContainsString('class="success">110 %',$html);
        self::assertStringContainsString('Fulfillment',$html);
        self::assertDoesNotMatchRegularExpression('/<(?:input|form|button|script)\b/i',$html);
        self::assertStringNotContainsString('Test participants',$html);
    }

    public function testZeroLimitAndUnfilledGroupAreNotGreen(): void {
        self::assertStringContainsString('<td>—</td>',$this->render(0,2));
        self::assertStringNotContainsString('class="success"',$this->render(0,2));
        self::assertStringNotContainsString('class="success"',$this->render(10,9));
        self::assertStringContainsString('class="success">100 %',$this->render(10,10));
    }

    public function testStudyAuthoredLabelsCannotInjectMarkup(): void {
        $html=$this->render(10,1,'<script>alert("x")</script>');
        self::assertStringContainsString('&lt;script&gt;',$html);
        self::assertStringNotContainsString('<script>',$html);
    }
}
