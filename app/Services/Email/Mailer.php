<?php

namespace FluentCart\App\Services\Email;

use FluentCart\App\App;
use FluentCart\App\Services\Libs\Emogrifier\Emogrifier;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\Framework\Support\Arr;

class Mailer
{
    private string $subject = '';

    private string $body = '';

    private string $footer = '';

    private $to = '';

    private string $from = '';

    private array $cc = [];

    private array $bcc = [];

    private string $replyTo = '';

    private bool $isHtml = true;

    public function __construct($to = '', string $subject = '', string $body = '')
    {
        $this->to = $to;
        $this->subject = $subject;
        $this->body = $body;

        $this->setDefaults();
    }


    public function subject(string $subject): Mailer
    {
        $this->subject = $subject;
        return $this;
    }

    public function setDefaults($settings = null): Mailer
    {
        if (!$settings) {
            $settings = EmailNotifications::getSettings();
        }

        $sendFromName = Arr::get($settings, 'from_name', '');
        $sendFromEmail = Arr::get($settings, 'from_email', '');

        if ($sendFromName && $sendFromEmail) {
            $this->from = $sendFromName . ' <' . $sendFromEmail . '>';
        } else if ($sendFromEmail) {
            $this->from = $sendFromEmail;
        }

        $replyToEmail = Arr::get($settings, 'reply_to_email', '');
        $replyToName = Arr::get($settings, 'reply_to_name', '');

        if ($replyToEmail && $replyToName) {
            $this->replyTo = $replyToName . ' <' . $replyToEmail . '>';
        } else if ($replyToEmail) {
            $this->replyTo = $replyToEmail;
        }

        $this->footer = '';

        return $this;
    }

    public function setSubject($subject): Mailer
    {
        $this->subject = $subject;
        return $this;
    }

    public function body($body): Mailer
    {
        $this->body = $body;
        return $this;
    }

    public function to($email, $name = ''): Mailer
    {
        if ($name) {
            $this->to = $name . ' <' . $email . '>';
        } else {
            $this->to = $email;
        }
        return $this;
    }

    public function setIsHtml($isHtml): Mailer
    {
        $this->isHtml = $isHtml;
        return $this;
    }

    public function setFrom($from): Mailer
    {
        $this->from = $from;
        return $this;
    }

    public function addCC($cc): Mailer
    {
        $this->cc[] = $cc;
        return $this;
    }

    public function addBCC($bcc): Mailer
    {
        $this->bcc[] = $bcc;
        return $this;
    }

    public function setReplyTo($replyTo): Mailer
    {
        $this->replyTo = $replyTo;
        return $this;
    }

    public function send($cssInliner = false)
    {
        if (!$this->to && !$this->cc && !$this->bcc) {
            return false;
        }

        if ($cssInliner) {
            $this->body = (string)(new Emogrifier($this->body))->emogrify();
        }

        $headers = [];

        if ($this->isHtml) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }

        if ($this->from) {
            $headers[] = 'From: ' . $this->from;
        }

        if ($this->cc) {
            $headers[] = 'Cc: ' . implode(',', $this->cc);
        }

        if ($this->bcc) {
            $headers[] = 'Bcc: ' . implode(',', $this->bcc);
        }

        if ($this->replyTo) {
            $headers[] = 'Reply-To: ' . $this->replyTo;
        }

        return wp_mail($this->to, $this->subject, $this->body, $headers);
    }

    public function wrapWithFooter($content): string
    {
        if (!empty($this->footer)) {
            $content .= ShortcodeTemplateBuilder::make($this->footer, []);
        }

        if (!App::isProActive()) {
            $cartFooter = "<div style='background: #fff;padding: 32px; text-align: center; font-size: 16px; color: #2F3448;'>Powered by <a href='https://fluentcart.com' style='color: #017EF3; text-decoration: none;'>FluentCart</a></div>";
            $content .= $cartFooter;
        }
        return $content;

    }

    public static function make(string $to = '', string $subject = '', string $body = ''): Mailer
    {
        return new static($to, $subject, $body);
    }
}
