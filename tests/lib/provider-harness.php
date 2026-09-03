<?php
/**
 * Fixture-backed fake provider transport for Stripe and PayPal tests.
 *
 * Every expected request is matched in declaration order. Unknown or
 * out-of-order requests throw from pre_http_request before WordPress can open
 * a socket. Captures intentionally retain URL, method, and body only.
 */

class FcProviderHarness
{
    /** @var string */
    private $fixtureRoot;

    /** @var array<int,array{method:string,url:string,fixture:string}> */
    private $expectations = [];

    /** @var array<int,array{method:string,url:string,body:mixed,raw_body:mixed}> */
    private $requests = [];

    public function __construct($fixtureRoot = null)
    {
        $root = $fixtureRoot !== null
            ? (string) $fixtureRoot
            : dirname(__DIR__) . '/fixtures/providers';
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException(
                'Provider fixture directory does not exist: ' . $root
            );
        }

        $this->fixtureRoot = $resolved;
    }

    public function expect($method, $url, $fixture)
    {
        $method = strtoupper(trim((string) $method));
        $url = trim((string) $url);
        $fixture = ltrim((string) $fixture, '/');
        if ($method === '' || $url === '' || $fixture === '') {
            throw new InvalidArgumentException(
                'Provider expectations require method, URL, and fixture.'
            );
        }
        if (strpos($fixture, '..') !== false) {
            throw new InvalidArgumentException('Provider fixture traversal is forbidden.');
        }

        $this->expectations[] = [
            'method'  => $method,
            'url'     => $url,
            'fixture' => $fixture,
        ];

        return $this;
    }

    public function install()
    {
        FcTest::useProviderHttpTransport([$this, 'intercept']);

        return $this;
    }

    public function uninstall()
    {
        FcTest::clearProviderHttpTransport();
    }

    public function intercept(array $args, $url)
    {
        $method = isset($args['method'])
            ? strtoupper((string) $args['method'])
            : 'GET';
        $rawBody = isset($args['body']) ? $args['body'] : null;
        $this->requests[] = [
            'method'   => $method,
            'url'      => (string) $url,
            'body'     => $this->normalizeBody($rawBody),
            'raw_body' => $rawBody,
        ];

        $expected = array_shift($this->expectations);
        if (
            !is_array($expected)
            || $expected['method'] !== $method
            || $expected['url'] !== (string) $url
        ) {
            $wanted = is_array($expected)
                ? $expected['method'] . ' ' . $expected['url']
                : '[no request expected]';
            throw new RuntimeException(
                'Provider transport rejected unmatched request: '
                . $method . ' ' . (string) $url . '; expected ' . $wanted
            );
        }

        return $this->fixtureResponse($expected['fixture']);
    }

    /**
     * @return array<int,array{method:string,url:string,body:mixed,raw_body:mixed}>
     */
    public function requests()
    {
        return $this->requests;
    }

    public function assertComplete()
    {
        if ($this->expectations) {
            $remaining = array_map(function ($expectation) {
                return $expectation['method'] . ' ' . $expectation['url'];
            }, $this->expectations);
            throw new RuntimeException(
                'Provider transport did not receive expected requests: '
                . implode(', ', $remaining)
            );
        }

        return true;
    }

    private function normalizeBody($body)
    {
        if (is_array($body) || $body === null) {
            return $body;
        }
        if (!is_string($body) || $body === '') {
            return $body;
        }

        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        $parsed = [];
        parse_str($body, $parsed);
        if ($parsed) {
            return $parsed;
        }

        return $body;
    }

    private function fixtureResponse($fixture)
    {
        $path = realpath($this->fixtureRoot . '/' . $fixture);
        if (
            $path === false
            || strpos($path, $this->fixtureRoot . DIRECTORY_SEPARATOR) !== 0
            || !is_file($path)
        ) {
            throw new RuntimeException(
                'Provider response fixture is missing or outside the fixture root: '
                . $fixture
            );
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (
            !is_array($decoded)
            || !isset($decoded['status'])
            || !array_key_exists('body', $decoded)
        ) {
            throw new RuntimeException('Provider response fixture is malformed: ' . $fixture);
        }

        $status = (int) $decoded['status'];
        $body = is_string($decoded['body'])
            ? $decoded['body']
            : wp_json_encode($decoded['body']);

        return [
            'response' => [
                'code'    => $status,
                'message' => isset($decoded['message'])
                    ? (string) $decoded['message']
                    : get_status_header_desc($status),
            ],
            'body'     => $body,
            'headers'  => isset($decoded['headers']) && is_array($decoded['headers'])
                ? $decoded['headers']
                : ['content-type' => 'application/json'],
            'cookies'  => [],
            'filename' => null,
        ];
    }
}
