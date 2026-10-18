<?php

/** Presentation only: saving text or waiting for a redirect never books a completion. */
class QuotaCompletionPage {
    public function __construct(private PDO $pdo) {}

    public static function defaults(): array {
        return ['body'=>'Thank you for participating. Your participation is complete.',
            'redirect_message'=>'You will be redirected in {seconds} seconds. If this does not work, {link}.',
            'redirect_delay'=>10,'redirect_link_text'=>'click here'];
    }

    public static function validate(array $input): array {
        $settings = $input + self::defaults();
        if (!is_string($settings['body']) || !is_string($settings['redirect_message'])) {
            throw new InvalidArgumentException('Enter text for the completion page and return message.');
        }
        if (!is_string($settings['redirect_link_text']) || trim($settings['redirect_link_text'])==='' || strlen($settings['redirect_link_text'])>255) {
            throw new InvalidArgumentException('Enter return link text (up to 255 bytes).');
        }
        $delay = $settings['redirect_delay'];
        // Milliseconds must fit a browser's signed 32-bit timer.
        if ((!is_int($delay) && (!is_string($delay) || !preg_match('/\A[0-9]+\z/',$delay)))
            || $delay < 0 || $delay > 2147483) {
            throw new InvalidArgumentException('Enter a whole number of seconds from 0 to 2147483.');
        }
        return ['body'=>$settings['body'],'redirect_message'=>$settings['redirect_message'],'redirect_delay'=>(int)$delay,
            'redirect_link_text'=>$settings['redirect_link_text']];
    }

    public function load(int $unitId): array {
        $query=$this->pdo->prepare('SELECT body,redirect_message,redirect_delay,redirect_link_text FROM survey_quota_completion_pages WHERE unit_id=?');
        $query->execute([$unitId]);
        return self::validate($query->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    public function save(int $runId,int $runUnitId,int $actorId,array $input): void {
        $settings=self::validate($input);
        $query=$this->pdo->prepare('INSERT INTO survey_quota_completion_pages (unit_id,body,redirect_message,redirect_delay,redirect_link_text)
            SELECT u.id,?,?,?,? FROM survey_units u JOIN survey_run_units ru ON ru.unit_id=u.id
            JOIN survey_runs r ON r.id=ru.run_id WHERE r.id=? AND r.user_id=? AND ru.id=? AND u.type="Complete"
            ON DUPLICATE KEY UPDATE body=VALUES(body),redirect_message=VALUES(redirect_message),redirect_delay=VALUES(redirect_delay),redirect_link_text=VALUES(redirect_link_text)');
        $query->execute([$settings['body'],$settings['redirect_message'],$settings['redirect_delay'],$settings['redirect_link_text'],$runId,$actorId,$runUnitId]);
        // MySQL reports zero changed rows for an unchanged valid save; verify identity separately.
        if (!$query->rowCount()) {
            $check=$this->pdo->prepare('SELECT u.id FROM survey_units u JOIN survey_run_units ru ON ru.unit_id=u.id
                JOIN survey_runs r ON r.id=ru.run_id WHERE r.id=? AND r.user_id=? AND ru.id=? AND u.type="Complete"');
            $check->execute([$runId,$actorId,$runUnitId]);
            if (!$check->fetchColumn()) { throw new DomainException('Completion page requires this run owner and Complete unit.'); }
        }
    }

    public static function render(array $settings,string $body,?string $url,bool $test): string {
        if ($url===null || $test) { return $body; }
        $seconds='<span data-completion-seconds>'.$settings['redirect_delay'].'</span>';
        $link='<a data-completion-link href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8')
            .'" rel="noreferrer" referrerpolicy="no-referrer">'.htmlspecialchars($settings['redirect_link_text'],ENT_QUOTES,'UTF-8').'</a>';
        $notice=nl2br(strtr(htmlspecialchars($settings['redirect_message'],ENT_QUOTES,'UTF-8'),
            ['{seconds}'=>$seconds,'{link}'=>$link]));
        // Keep the individual return link available even when its placeholder was removed.
        if (!str_contains($settings['redirect_message'],'{link}')) { $notice.=' '.$link; }
        return $body.'<div class="completion-redirect" data-completion-delay="'.$settings['redirect_delay'].'"><p>'
            .$notice.'</p></div>';
    }
}
