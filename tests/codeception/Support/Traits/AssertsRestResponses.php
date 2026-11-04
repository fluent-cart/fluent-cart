<?php

namespace Tests\Support\Traits;

/**
 * Response-shape helpers for REST integration tests: normalising paginated
 * payloads and asserting FluentCart's validation / error envelopes.
 */
trait AssertsRestResponses
{
    /**
     * Extract items array from a paginated response which may be an object,
     * a LengthAwarePaginator, or a nested array with 'data' key.
     */
    protected function extractPaginatedItems($paginated): array
    {
        if (is_object($paginated) && method_exists($paginated, 'toArray')) {
            $arr = $paginated->toArray();
            return $arr['data'] ?? $arr;
        }
        if (is_array($paginated) && isset($paginated['data'])) {
            return is_array($paginated['data']) ? $paginated['data'] : $paginated['data']->toArray();
        }
        return is_array($paginated) ? $paginated : [];
    }

    protected function flattenMessages($value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            return trim(implode(' ', array_map([$this, 'flattenMessages'], $value)));
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return '';
    }

    protected function assertValidationErrorForField(
        \WP_REST_Response $response,
        string $field,
        string $expectedMessage
    ): array {
        $data = $response->get_data();
        if (!in_array($response->get_status(), [200, 422], true)) {
            $this->fail(
                'Validation failures should return either a 422 response or a 200 response body with validation errors. ' .
                'Status=' . $response->get_status() . ' Response=' . var_export($data, true)
            );
        }

        $this->assertIsArray($data);
        if (!array_key_exists($field, $data)) {
            $this->fail(
                'Expected validation error for field: ' . $field . '. Response=' . var_export($data, true)
            );
        }
        $this->assertStringContainsString($expectedMessage, $this->flattenMessages($data[$field]));

        return $data;
    }

    /**
     * Assert a request was rejected by FIELD VALIDATION with a client-error
     * status — not swallowed into a 200, not blocked by auth (401/403), and
     * not crashed into a 5xx. Stricter than assertValidationErrorForField,
     * which tolerates 200 envelopes; use this when the route contract is a
     * genuine 4xx on invalid input.
     */
    protected function assertRejectedByValidation(
        \WP_REST_Response $response,
        ?string $expectedField = null,
        string $context = ''
    ): void {
        $status = $response->get_status();
        $this->assertLessThan(500, $status, "Validation test must not receive server error; got {$status}. {$context}");
        $this->assertNotSame(401, $status, "Validation test must not be auth-rejected (401); got 401. {$context}");
        $this->assertNotSame(403, $status, "Validation test must not be auth-rejected (403); got 403. {$context}");
        $this->assertGreaterThanOrEqual(400, $status, "Expected client-error status (4xx), got {$status}. {$context}");

        $data = $response->get_data();
        $this->assertIsArray($data, "Error response must have array body. {$context}");
        $this->assertNotEmpty($data, "Error response body must not be empty. {$context}");

        if ($expectedField !== null) {
            // Word-bounded so 'type' is not satisfied by 'payment_type'.
            $haystack = strtolower(json_encode($data));
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote(strtolower($expectedField), '/') . '\b/',
                $haystack,
                "Error response must reference field '{$expectedField}' (rule key, error key, or message). Got: " . json_encode($data)
            );
        }
    }

    protected function assertErrorResponseMessage(
        \WP_REST_Response $response,
        array $expectedStatuses,
        string $expectedMessage,
        ?string $expectedCode = null
    ): array {
        $this->assertContains($response->get_status(), $expectedStatuses);

        $data = $response->get_data();
        $this->assertIsArray($data);

        if ($expectedCode !== null) {
            $this->assertArrayHasKey('code', $data);
            $this->assertSame($expectedCode, (string)$data['code']);
        }

        $message = $data['message'] ?? ($data['data']['message'] ?? '');
        $this->assertStringContainsString($expectedMessage, $this->flattenMessages($message ?: $data));

        return $data;
    }
}
