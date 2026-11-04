<?php

namespace Tests\Support\Traits;

use FluentCart\App\Modules\Turnstile\TurnstileValidator;

/**
 * Scripts Cloudflare's siteverify endpoint and records every request body, so a
 * test can assert what the validator sent as well as how it read the reply.
 */
trait MocksTurnstileSiteverify
{
    /** @var array<int, array> siteverify request bodies, in order */
    private array $turnstileSent = [];

    /** @var array<int, array|\WP_Error> scripted siteverify replies, consumed in order */
    private array $turnstileReplies = [];

    /** @var callable|null */
    private $turnstileHttp = null;

    protected function installTurnstileHttpMock(): void
    {
        $this->turnstileSent = [];
        $this->turnstileReplies = [];
        $this->turnstileHttp = function ($preempt, $args, $url) {
            if (strpos($url, 'challenges.cloudflare.com/turnstile/v0/siteverify') === false) {
                return $preempt;
            }
            $this->turnstileSent[] = (array) ($args['body'] ?? []);
            $reply = array_shift($this->turnstileReplies);
            return $reply === null ? new \WP_Error('http_request_failed', 'unscripted') : $reply;
        };
        add_filter('pre_http_request', $this->turnstileHttp, 10, 3);
    }

    protected function removeTurnstileHttpMock(): void
    {
        remove_filter('pre_http_request', $this->turnstileHttp, 10);
    }

    protected function turnstileReply(int $status, $body): array
    {
        return [
            'headers'  => [],
            'body'     => is_string($body) ? $body : wp_json_encode($body),
            'response' => ['code' => $status, 'message' => ''],
            'cookies'  => [],
        ];
    }

    protected function validateTurnstileCheckout(string $token = 'token-1')
    {
        return (new TurnstileValidator())->validateCheckout(true, ['cf_turnstile_token' => $token]);
    }
}
