<?php
/**
 * A display name cannot add recipients to a FluentCart email.
 *
 * wp_mail() splits a string To value, and the Cc, Bcc and Reply-To headers, on
 * every comma and ignores quotes. Mailer::to() and the Reply-To setting joined the
 * name into "Name <email>" as typed, so a name such as "x,attacker@example.invalid,y"
 * would add a recipient. No caller passes a customer name to Mailer::to() today;
 * this keeps the first one that does from opening the hole.
 */

use FluentCart\App\Services\Email\Mailer;

/**
 * Split a value the way core wp_mail() does (explode(',', ...)) and return every
 * fragment that holds a valid address.
 */
$recipientsAsCoreSeesThem = function ($value) {
    $addresses = [];
    foreach (explode(',', (string) $value) as $fragment) {
        if (preg_match('/(.*)<(.+)>/', $fragment, $matches)) {
            $fragment = $matches[2];
        }
        $fragment = trim($fragment);
        if (is_email($fragment)) {
            $addresses[] = $fragment;
        }
    }
    return $addresses;
};

// The harness fails any case that reaches wp_mail(), so read what send() would pass.
$field = function (Mailer $mailer, $name) {
    $property = new ReflectionProperty(Mailer::class, $name);
    $property->setAccessible(true);
    return $property->getValue($mailer);
};

return [
    [
        'id'            => 'mailer-display-name-to',
        'name'          => 'A To name with commas and an address does not add a recipient',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($recipientsAsCoreSeesThem, $field) {
            $mailer = Mailer::make()->to('victim@example.invalid', 'x,attacker@example.invalid,y Lead');

            FcTest::assertSame(
                ['victim@example.invalid'],
                $recipientsAsCoreSeesThem($field($mailer, 'to')),
                'the named address is the only recipient'
            );
        },
    ],
    [
        'id'            => 'mailer-display-name-plain',
        'name'          => 'A plain To name is kept and a name of separators only is dropped',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($field) {
            $plain = Mailer::make()->to('jane@example.invalid', 'Jane Doe');
            $separators = Mailer::make()->to('jane@example.invalid', ' , <> " ');

            FcTest::assertSame('Jane Doe <jane@example.invalid>', $field($plain, 'to'), 'a plain name is kept');
            FcTest::assertSame('jane@example.invalid', $field($separators, 'to'), 'a separators-only name falls back to the bare address');
        },
    ],
    [
        'id'            => 'mailer-display-name-reply-to',
        'name'          => 'A Reply-To name with commas and an address does not add a Reply-To address',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () use ($recipientsAsCoreSeesThem, $field) {
            $mailer = Mailer::make()->setDefaults([
                'reply_to_email' => 'support@example.invalid',
                'reply_to_name'  => 'Support, attacker@example.invalid, Team',
            ]);

            FcTest::assertSame(
                ['support@example.invalid'],
                $recipientsAsCoreSeesThem($field($mailer, 'replyTo')),
                'the configured address is the only Reply-To'
            );
        },
    ],
];
