<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\Email\EmailNotifications;
use FluentCart\App\Services\Email\Mailer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\EmailNotificationConfigFactory;

class MailerTest extends WPTestCase
{
    private EmailNotificationConfigFactory $configFactory;
    private array $captured = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->configFactory = new EmailNotificationConfigFactory();
        $this->configFactory->reset();
        $this->captured = [];

        // Capture wp_mail args without sending
        add_filter('pre_wp_mail', [$this, 'capturePreWpMail'], 10, 2);
    }

    public function tearDown(): void
    {
        remove_filter('pre_wp_mail', [$this, 'capturePreWpMail'], 10);
        $this->configFactory->reset();
        parent::tearDown();
    }

    public function capturePreWpMail($null, array $atts)
    {
        $this->captured[] = $atts;
        return true; // short-circuit so wp_mail returns true without dispatch
    }

    private function readPrivate(Mailer $mailer, string $name)
    {
        $ref = new \ReflectionClass($mailer);
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($mailer);
    }

    // =========================================================================
    // Spec 46: Mailer::make returns instance with provided fields
    // =========================================================================

    public function test_make_returns_instance_with_fields(): void
    {
        $mailer = Mailer::make('to@x.test', 'Subj', '<p>body</p>');
        $this->assertInstanceOf(Mailer::class, $mailer);
        $this->assertSame('to@x.test', $this->readPrivate($mailer, 'to'));
        $this->assertSame('Subj', $this->readPrivate($mailer, 'subject'));
        $this->assertSame('<p>body</p>', $this->readPrivate($mailer, 'body'));
    }

    // =========================================================================
    // Spec 47: Constructor calls setDefaults populating from
    // =========================================================================

    public function test_constructor_populates_from_from_settings(): void
    {
        $this->configFactory->create([
            'from_name'  => 'Sender',
            'from_email' => 'sender@x.test',
        ]);
        $mailer = Mailer::make('to@x.test', 'S', 'B');
        $this->assertSame('Sender <sender@x.test>', $this->readPrivate($mailer, 'from'));
    }

    // =========================================================================
    // Spec 48: from formatted "Name <email>" when both present
    // =========================================================================

    public function test_from_formatted_name_email_when_both_present(): void
    {
        $this->configFactory->create([
            'from_name'  => 'Foo Bar',
            'from_email' => 'foo@bar.test',
        ]);
        $mailer = Mailer::make();
        $this->assertSame('Foo Bar <foo@bar.test>', $this->readPrivate($mailer, 'from'));
    }

    // =========================================================================
    // Spec 49: from = email alone when no name
    // =========================================================================

    public function test_from_email_alone_when_no_name(): void
    {
        $this->configFactory->create([
            'from_name'  => '',
            'from_email' => 'noname@x.test',
        ]);
        $mailer = Mailer::make();
        $this->assertSame('noname@x.test', $this->readPrivate($mailer, 'from'));
    }

    // =========================================================================
    // Spec 50: replyTo formatted Name <email> when both present
    // =========================================================================

    public function test_reply_to_formatted_with_name(): void
    {
        $this->configFactory->create([
            'reply_to_name'  => 'Reply Name',
            'reply_to_email' => 'reply@x.test',
        ]);
        $mailer = Mailer::make();
        $this->assertSame('Reply Name <reply@x.test>', $this->readPrivate($mailer, 'replyTo'));
    }

    // =========================================================================
    // Spec 51: replyTo = email alone when no name
    // =========================================================================

    public function test_reply_to_email_alone(): void
    {
        $this->configFactory->create([
            'reply_to_name'  => '',
            'reply_to_email' => 'reply2@x.test',
        ]);
        $mailer = Mailer::make();
        $this->assertSame('reply2@x.test', $this->readPrivate($mailer, 'replyTo'));
    }

    // =========================================================================
    // Spec 52: setters chainable
    // =========================================================================

    public function test_setters_chainable(): void
    {
        $mailer = Mailer::make();
        $this->assertSame($mailer, $mailer->subject('A'));
        $this->assertSame($mailer, $mailer->setSubject('B'));
        $this->assertSame($mailer, $mailer->body('C'));
        $this->assertSame($mailer, $mailer->setIsHtml(false));
        $this->assertSame($mailer, $mailer->setFrom('x@y'));
        $this->assertSame($mailer, $mailer->setReplyTo('r@y'));
    }

    // =========================================================================
    // Spec 53: to($email,$name) format
    // =========================================================================

    public function test_to_with_name_formats_pair(): void
    {
        $mailer = Mailer::make()->to('a@b.test', 'Alice');
        $this->assertSame('Alice <a@b.test>', $this->readPrivate($mailer, 'to'));
    }

    public function test_to_email_only_stores_raw(): void
    {
        $mailer = Mailer::make()->to('only@b.test');
        $this->assertSame('only@b.test', $this->readPrivate($mailer, 'to'));
    }

    // =========================================================================
    // Spec 54: addCC / addBCC append
    // =========================================================================

    public function test_add_cc_and_bcc_append(): void
    {
        $mailer = Mailer::make()->addCC('cc1@x.test')->addCC('cc2@x.test')->addBCC('bcc1@x.test');
        $this->assertSame(['cc1@x.test', 'cc2@x.test'], $this->readPrivate($mailer, 'cc'));
        $this->assertSame(['bcc1@x.test'], $this->readPrivate($mailer, 'bcc'));
    }

    // =========================================================================
    // Spec 55: addAttachment appends; setAttachments replaces
    // =========================================================================

    public function test_attachments_append_and_replace(): void
    {
        $mailer = Mailer::make()->addAttachment('/tmp/a.pdf')->addAttachment('/tmp/b.pdf');
        $this->assertSame(['/tmp/a.pdf', '/tmp/b.pdf'], $this->readPrivate($mailer, 'attachments'));

        $mailer->setAttachments(['/tmp/c.pdf']);
        $this->assertSame(['/tmp/c.pdf'], $this->readPrivate($mailer, 'attachments'));
    }

    // =========================================================================
    // Spec 56: wrapWithFooter appends footer when set
    // =========================================================================

    public function test_wrap_with_footer_appends_footer_when_set(): void
    {
        $mailer = Mailer::make();
        $ref = new \ReflectionClass($mailer);
        $prop = $ref->getProperty('footer');
        $prop->setAccessible(true);
        $prop->setValue($mailer, '<div>Custom Footer</div>');

        $wrapped = $mailer->wrapWithFooter('<p>main</p>');
        $this->assertStringContainsString('<p>main</p>', $wrapped);
        $this->assertStringContainsString('Custom Footer', $wrapped);
    }

    // =========================================================================
    // Spec 57: wrapWithFooter appends FluentCart branding when not pro
    // =========================================================================

    public function test_wrap_with_footer_appends_fluentcart_branding_when_not_pro(): void
    {
        $wrapped = Mailer::make()->wrapWithFooter('<p>main</p>');
        $this->assertStringContainsString('Powered by', $wrapped);
        $this->assertStringContainsString('fluentcart.com', $wrapped);
    }

    // =========================================================================
    // Spec 58: send returns false when no recipient
    // =========================================================================

    public function test_send_returns_false_when_no_recipient(): void
    {
        $result = Mailer::make()->send();
        $this->assertFalse($result);
        $this->assertSame([], $this->captured); // wp_mail not invoked
    }

    // =========================================================================
    // Spec 59: send($cssInliner=true) runs body through Emogrifier
    // =========================================================================

    public function test_send_with_css_inliner_emogrifies_body(): void
    {
        $body = '<style>p { color: red; }</style><p>Hi</p>';
        $mailer = Mailer::make('to@x.test', 'S', $body);
        $mailer->send(true);

        $this->assertNotEmpty($this->captured);
        $sentBody = $this->captured[0]['message'];
        // Emogrifier inlines style as attribute; check that color:red moved into the <p> tag
        $this->assertStringContainsString('color: red', $sentBody);
        $this->assertStringContainsString('<p', $sentBody);
        $this->assertMatchesRegularExpression('/<p[^>]*style="[^"]*color:\s*red/i', $sentBody);
    }

    // =========================================================================
    // Spec 60: Content-Type html when isHtml=true
    // =========================================================================

    public function test_html_content_type_header(): void
    {
        Mailer::make('to@x.test', 'S', 'B')->setIsHtml(true)->send();
        $headers = $this->captured[0]['headers'];
        $this->assertContains('Content-Type: text/html; charset=UTF-8', $headers);
    }

    // =========================================================================
    // Spec 61: Content-Type plain when isHtml=false
    // =========================================================================

    public function test_plain_content_type_header(): void
    {
        Mailer::make('to@x.test', 'S', 'B')->setIsHtml(false)->send();
        $headers = $this->captured[0]['headers'];
        $this->assertContains('Content-Type: text/plain; charset=UTF-8', $headers);
    }

    // =========================================================================
    // Spec 62: From/Cc/Bcc/Reply-To headers only when set
    // =========================================================================

    public function test_optional_headers_only_when_set(): void
    {
        $this->configFactory->create([
            'from_name'      => '',
            'from_email'     => '',
            'reply_to_name'  => '',
            'reply_to_email' => '',
        ]);
        // Bare mailer: no from/replyTo/cc/bcc
        Mailer::make('to@x.test', 'S', 'B')->send();
        $headers = $this->captured[0]['headers'];
        foreach ($headers as $h) {
            $this->assertStringStartsNotWith('From:', $h);
            $this->assertStringStartsNotWith('Cc:', $h);
            $this->assertStringStartsNotWith('Bcc:', $h);
            $this->assertStringStartsNotWith('Reply-To:', $h);
        }

        // With all set
        $this->captured = [];
        Mailer::make('to@x.test', 'S', 'B')
            ->setFrom('From <from@x.test>')
            ->addCC('cc@x.test')
            ->addBCC('bcc@x.test')
            ->setReplyTo('reply@x.test')
            ->send();
        $headers = $this->captured[0]['headers'];
        $found = ['From' => false, 'Cc' => false, 'Bcc' => false, 'Reply-To' => false];
        foreach ($headers as $h) {
            foreach ($found as $name => $_) {
                if (strpos($h, $name . ':') === 0) {
                    $found[$name] = true;
                }
            }
        }
        $this->assertSame(['From' => true, 'Cc' => true, 'Bcc' => true, 'Reply-To' => true], $found);
    }

    // =========================================================================
    // Spec 63: send forwards to wp_mail with all args
    // =========================================================================

    public function test_send_forwards_to_wp_mail(): void
    {
        Mailer::make('to@x.test', 'Subject Line', '<p>Body</p>')
            ->setAttachments(['/tmp/x.pdf'])
            ->send();
        $args = $this->captured[0];
        $this->assertSame('to@x.test', $args['to']);
        $this->assertSame('Subject Line', $args['subject']);
        $this->assertSame('<p>Body</p>', $args['message']);
        $this->assertSame(['/tmp/x.pdf'], $args['attachments']);
    }
}
