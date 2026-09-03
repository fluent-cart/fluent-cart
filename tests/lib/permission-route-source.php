<?php
/**
 * Static mutating-route discovery for the Phase 3 permission inventory.
 *
 * This file deliberately has no WordPress dependency. It tokenizes only the
 * configured route source files and never registers or invokes a route.
 */

class FcPermissionRouteSource
{
    /**
     * @param string              $pluginDir
     * @param array<string,mixed> $config
     * @return array{route_files:array<int,string>,declarations:array<string,array<string,mixed>>,stats:array<string,array<string,int>>,errors:array<int,array<string,string>>}
     */
    public static function discover($pluginDir, array $config)
    {
        $routeFiles = isset($config['routes_files']) && is_array($config['routes_files'])
            ? array_values($config['routes_files'])
            : [];
        $routeFileTypes = isset($config['route_file_types']) && is_array($config['route_file_types'])
            ? $config['route_file_types']
            : [];
        $errors = [];
        $declarations = [];
        $stats = [];
        $configured = [];

        if (!$routeFiles) {
            self::addError(
                $errors,
                'tests/suite.config.php',
                'routes_files must configure at least one route source file'
            );
        }

        foreach ($routeFiles as $index => $relativeFile) {
            if (!is_string($relativeFile) || $relativeFile === '') {
                self::addError(
                    $errors,
                    'tests/suite.config.php',
                    'routes_files[' . $index . '] must be a non-empty relative path'
                );
                continue;
            }
            if (isset($configured[$relativeFile])) {
                self::addError(
                    $errors,
                    'tests/suite.config.php',
                    'duplicate configured route file: ' . $relativeFile
                );
            }
            $configured[$relativeFile] = true;
            $stats[$relativeFile] = [
                'POST'   => 0,
                'PUT'    => 0,
                'PATCH'  => 0,
                'DELETE' => 0,
            ];

            if (!isset($routeFileTypes[$relativeFile])) {
                self::addError(
                    $errors,
                    'tests/suite.config.php',
                    'route_file_types is missing configured file: ' . $relativeFile
                );
                continue;
            }
            if (!in_array($routeFileTypes[$relativeFile], ['rest', 'web', 'faker'], true)) {
                self::addError(
                    $errors,
                    'tests/suite.config.php',
                    'unsupported route type for ' . $relativeFile . ': '
                        . var_export($routeFileTypes[$relativeFile], true)
                );
                continue;
            }

            $absoluteFile = rtrim($pluginDir, '/') . '/' . $relativeFile;
            if (!is_file($absoluteFile)) {
                self::addError($errors, $relativeFile, 'configured route source file is missing');
                continue;
            }

            $source = file_get_contents($absoluteFile);
            if ($source === false) {
                self::addError($errors, $relativeFile, 'configured route source file cannot be read');
                continue;
            }

            $fileDeclarations = self::extractRestDeclarations(
                $relativeFile,
                $source,
                $routeFileTypes[$relativeFile],
                $errors
            );
            foreach ($fileDeclarations as $declaration) {
                $id = $declaration['id'];
                if (isset($declarations[$id])) {
                    self::addError(
                        $errors,
                        $relativeFile . ':' . $declaration['source_line'],
                        'duplicate source declaration ID: ' . $id
                    );
                    continue;
                }

                $declarations[$id] = $declaration;
                $stats[$relativeFile][$declaration['verb']]++;
            }
        }

        foreach ($routeFileTypes as $relativeFile => $type) {
            if (!isset($configured[$relativeFile])) {
                self::addError(
                    $errors,
                    'tests/suite.config.php',
                    'route_file_types contains stale unconfigured file: ' . $relativeFile
                );
            }
        }

        return [
            'route_files'  => $routeFiles,
            'declarations' => $declarations,
            'stats'        => $stats,
            'errors'       => $errors,
        ];
    }

    /**
     * @param string                           $relativeFile
     * @param string                           $source
     * @param string                           $transport
     * @param array<int,array<string,string>>  $errors
     * @return array<int,array<string,mixed>>
     */
    private static function extractRestDeclarations(
        $relativeFile,
        $source,
        $transport,
        array &$errors
    ) {
        $tokens = self::tokenize($source);
        $pairs = self::matchingPairs($tokens);
        $prefixRanges = [];

        foreach ($tokens as $index => $token) {
            $methodIndex = self::routerMethodAt($tokens, $index, ['prefix']);
            if ($methodIndex === null) {
                continue;
            }

            $openParen = self::nextSignificant($tokens, $methodIndex);
            $literalIndex = $openParen === null
                ? null
                : self::nextSignificant($tokens, $openParen);
            $prefix = $literalIndex === null
                ? null
                : self::decodeLiteral($tokens[$literalIndex]['text']);
            if (
                $openParen === null
                || $tokens[$openParen]['text'] !== '('
                || $prefix === null
                || !isset($pairs[$openParen])
            ) {
                self::addError(
                    $errors,
                    $relativeFile . ':' . $token['line'],
                    'unsupported dynamic router prefix; permission inventory requires a literal prefix'
                );
                continue;
            }

            $groupOpen = null;
            $groupPolicy = null;
            $groupPolicyLine = null;
            $sawGroup = false;
            $sawFunction = false;
            $tokenCount = count($tokens);
            for ($cursor = $pairs[$openParen] + 1; $cursor < $tokenCount; $cursor++) {
                if ($tokens[$cursor]['text'] === ';') {
                    break;
                }
                if (
                    $tokens[$cursor]['id'] === T_STRING
                    && strtolower($tokens[$cursor]['text']) === 'withpolicy'
                ) {
                    $policyOpen = self::nextSignificant($tokens, $cursor);
                    $policyLiteral = $policyOpen === null
                        ? null
                        : self::nextSignificant($tokens, $policyOpen);
                    $decoded = $policyLiteral === null
                        ? null
                        : self::decodeLiteral($tokens[$policyLiteral]['text']);
                    if ($decoded !== null) {
                        $groupPolicy = $decoded;
                        $groupPolicyLine = $tokens[$cursor]['line'];
                    }
                } elseif (
                    $tokens[$cursor]['id'] === T_STRING
                    && strtolower($tokens[$cursor]['text']) === 'group'
                ) {
                    $sawGroup = true;
                } elseif ($tokens[$cursor]['id'] === T_FUNCTION) {
                    $sawFunction = true;
                } elseif (
                    $tokens[$cursor]['text'] === '{'
                    && $sawGroup
                    && $sawFunction
                ) {
                    $groupOpen = $cursor;
                    break;
                }
            }

            if ($groupOpen === null || !isset($pairs[$groupOpen])) {
                self::addError(
                    $errors,
                    $relativeFile . ':' . $token['line'],
                    'literal router prefix is not attached to a statically bounded group'
                );
                continue;
            }

            $prefixRanges[] = [
                'prefix'             => $prefix,
                'open'               => $groupOpen,
                'close'              => $pairs[$groupOpen],
                'policy'             => $groupPolicy,
                'policy_source_line' => $groupPolicyLine,
            ];
        }

        usort($prefixRanges, static function ($left, $right) {
            return $left['open'] <=> $right['open'];
        });

        $declarations = [];
        foreach ($tokens as $index => $token) {
            $methodIndex = self::routerMethodAt(
                $tokens,
                $index,
                ['post', 'put', 'patch', 'delete']
            );
            if ($methodIndex === null) {
                continue;
            }

            $verb = strtoupper($tokens[$methodIndex]['text']);
            $openParen = self::nextSignificant($tokens, $methodIndex);
            $literalIndex = $openParen === null
                ? null
                : self::nextSignificant($tokens, $openParen);
            $route = $literalIndex === null
                ? null
                : self::decodeLiteral($tokens[$literalIndex]['text']);
            if (
                $openParen === null
                || $tokens[$openParen]['text'] !== '('
                || $route === null
                || !isset($pairs[$openParen])
            ) {
                self::addError(
                    $errors,
                    $relativeFile . ':' . $token['line'],
                    'unsupported dynamic ' . $verb
                        . ' declaration; first argument must be a literal route'
                );
                continue;
            }

            $closeParen = $pairs[$openParen];
            $comma = self::nextSignificant($tokens, $literalIndex);
            if ($comma === null || $tokens[$comma]['text'] !== ',') {
                self::addError(
                    $errors,
                    $relativeFile . ':' . $token['line'],
                    $verb . ' declaration is missing a static handler argument'
                );
                continue;
            }
            $handler = self::compactTokens($tokens, $comma + 1, $closeParen - 1);
            if ($handler === '') {
                self::addError(
                    $errors,
                    $relativeFile . ':' . $token['line'],
                    $verb . ' declaration has an empty handler'
                );
                continue;
            }

            $prefixes = [];
            $policy = null;
            $policySourceLine = null;
            foreach ($prefixRanges as $range) {
                if ($index > $range['open'] && $index < $range['close']) {
                    $prefixes[] = $range['prefix'];
                    if ($range['policy'] !== null) {
                        $policy = $range['policy'];
                        $policySourceLine = $range['policy_source_line'];
                    }
                }
            }

            $chainEnd = self::findStatementEnd($tokens, $closeParen);
            $routePolicy = self::findChainedLiteral(
                $tokens,
                $closeParen + 1,
                $chainEnd,
                'withpolicy',
                $pairs
            );
            if ($routePolicy !== null) {
                $policy = $routePolicy['value'];
                $policySourceLine = $routePolicy['line'];
            }

            $meta = self::extractMeta(
                $tokens,
                $closeParen + 1,
                $chainEnd,
                $pairs,
                $relativeFile,
                $token['line'],
                $errors
            );
            $parts = $prefixes;
            $parts[] = $route;
            $fullRoute = self::joinRoute($parts);
            $id = $relativeFile . ':' . $token['line'] . ':' . $verb;

            $declarations[] = [
                'id'                 => $id,
                'source_file'        => $relativeFile,
                'source_line'        => $token['line'],
                'verb'               => $verb,
                'route'              => $fullRoute,
                'group_prefix'       => self::joinRoute($prefixes),
                'policy'             => $policy,
                'policy_source_line' => $policySourceLine,
                'permissions'        => $meta['permissions'],
                'permissions_type'   => $meta['permissions_type'],
                'transport'          => $transport,
                'handler'            => $handler,
            ];
        }

        return $declarations;
    }

    /**
     * @param array<int,array<string,mixed>>    $tokens
     * @param int                               $start
     * @param int                               $end
     * @param array<int,int>                    $pairs
     * @param string                            $relativeFile
     * @param int                               $routeLine
     * @param array<int,array<string,string>>   $errors
     * @return array{permissions:array<int,string>,permissions_type:string|null}
     */
    private static function extractMeta(
        array $tokens,
        $start,
        $end,
        array $pairs,
        $relativeFile,
        $routeLine,
        array &$errors
    ) {
        $result = [
            'permissions'      => [],
            'permissions_type' => null,
        ];
        $metaName = null;
        for ($cursor = $start; $cursor <= $end; $cursor++) {
            if (
                $tokens[$cursor]['id'] === T_STRING
                && strtolower($tokens[$cursor]['text']) === 'meta'
            ) {
                $metaName = $cursor;
                break;
            }
        }

        if ($metaName === null) {
            return $result;
        }

        $metaOpen = self::nextSignificant($tokens, $metaName);
        $arrayOpen = $metaOpen === null ? null : self::nextSignificant($tokens, $metaOpen);
        if (
            $metaOpen === null
            || $tokens[$metaOpen]['text'] !== '('
            || $arrayOpen === null
            || $tokens[$arrayOpen]['text'] !== '['
            || !isset($pairs[$arrayOpen])
        ) {
            self::addError(
                $errors,
                $relativeFile . ':' . $routeLine,
                'route meta must use a static short array for permission inventory'
            );
            return $result;
        }

        $arrayClose = $pairs[$arrayOpen];
        for ($cursor = $arrayOpen + 1; $cursor < $arrayClose; $cursor++) {
            if ($tokens[$cursor]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $key = self::decodeLiteral($tokens[$cursor]['text']);
            if (!in_array($key, ['permissions', 'permissions_type'], true)) {
                continue;
            }

            $arrow = self::nextSignificant($tokens, $cursor);
            $valueIndex = $arrow === null ? null : self::nextSignificant($tokens, $arrow);
            if (
                $arrow === null
                || $tokens[$arrow]['id'] !== T_DOUBLE_ARROW
                || $valueIndex === null
            ) {
                continue;
            }

            if ($tokens[$valueIndex]['id'] === T_CONSTANT_ENCAPSED_STRING) {
                $value = self::decodeLiteral($tokens[$valueIndex]['text']);
                if ($key === 'permissions') {
                    $result['permissions'] = $value === null ? [] : [$value];
                } else {
                    $result['permissions_type'] = $value;
                }
                continue;
            }

            if (
                $key === 'permissions'
                && $tokens[$valueIndex]['text'] === '['
                && isset($pairs[$valueIndex])
            ) {
                $values = [];
                for ($item = $valueIndex + 1; $item < $pairs[$valueIndex]; $item++) {
                    if ($tokens[$item]['id'] === T_CONSTANT_ENCAPSED_STRING) {
                        $decoded = self::decodeLiteral($tokens[$item]['text']);
                        if ($decoded !== null) {
                            $values[] = $decoded;
                        }
                    }
                }
                $result['permissions'] = $values;
            }
        }

        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $tokens
     * @param int                            $start
     * @param int                            $end
     * @param string                         $method
     * @param array<int,int>                 $pairs
     * @return array{value:string,line:int}|null
     */
    private static function findChainedLiteral(
        array $tokens,
        $start,
        $end,
        $method,
        array $pairs
    ) {
        for ($cursor = $start; $cursor <= $end; $cursor++) {
            if (
                $tokens[$cursor]['id'] !== T_STRING
                || strtolower($tokens[$cursor]['text']) !== strtolower($method)
            ) {
                continue;
            }

            $open = self::nextSignificant($tokens, $cursor);
            $literal = $open === null ? null : self::nextSignificant($tokens, $open);
            $value = $literal === null ? null : self::decodeLiteral($tokens[$literal]['text']);
            if (
                $open !== null
                && $tokens[$open]['text'] === '('
                && isset($pairs[$open])
                && $value !== null
            ) {
                return [
                    'value' => $value,
                    'line'  => $tokens[$cursor]['line'],
                ];
            }
        }

        return null;
    }

    /**
     * @param string $source
     * @return array<int,array{id:int|null,text:string,line:int}>
     */
    private static function tokenize($source)
    {
        $rawTokens = token_get_all($source);
        $tokens = [];
        $currentLine = 1;
        foreach ($rawTokens as $rawToken) {
            if (is_array($rawToken)) {
                $text = $rawToken[1];
                $line = $rawToken[2];
                $tokens[] = [
                    'id'   => $rawToken[0],
                    'text' => $text,
                    'line' => $line,
                ];
                $currentLine = $line + substr_count($text, "\n");
            } else {
                $tokens[] = [
                    'id'   => null,
                    'text' => $rawToken,
                    'line' => $currentLine,
                ];
                $currentLine += substr_count($rawToken, "\n");
            }
        }

        return $tokens;
    }

    /**
     * @param array<int,array<string,mixed>> $tokens
     * @return array<int,int>
     */
    private static function matchingPairs(array $tokens)
    {
        $stacks = [
            '(' => [],
            '[' => [],
            '{' => [],
        ];
        $openForClose = [
            ')' => '(',
            ']' => '[',
            '}' => '{',
        ];
        $pairs = [];

        foreach ($tokens as $index => $token) {
            $text = $token['text'];
            if (isset($stacks[$text])) {
                $stacks[$text][] = $index;
                continue;
            }
            if (isset($openForClose[$text])) {
                $openText = $openForClose[$text];
                if ($stacks[$openText]) {
                    $open = array_pop($stacks[$openText]);
                    $pairs[$open] = $index;
                    $pairs[$index] = $open;
                }
            }
        }

        return $pairs;
    }

    /**
     * @param array<int,array<string,mixed>> $tokens
     * @param int                            $index
     * @return int|null
     */
    private static function nextSignificant(array $tokens, $index)
    {
        $count = count($tokens);
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            if (
                $tokens[$cursor]['id'] === T_WHITESPACE
                || $tokens[$cursor]['id'] === T_COMMENT
                || $tokens[$cursor]['id'] === T_DOC_COMMENT
            ) {
                continue;
            }
            return $cursor;
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $tokens
     * @param int                            $index
     * @param array<int,string>              $methods
     * @return int|null
     */
    private static function routerMethodAt(array $tokens, $index, array $methods)
    {
        if (
            !isset($tokens[$index])
            || $tokens[$index]['id'] !== T_VARIABLE
            || $tokens[$index]['text'] !== '$router'
        ) {
            return null;
        }

        $operator = self::nextSignificant($tokens, $index);
        $name = $operator === null ? null : self::nextSignificant($tokens, $operator);
        if (
            $operator === null
            || $name === null
            || $tokens[$operator]['text'] !== '->'
            || $tokens[$name]['id'] !== T_STRING
            || !in_array(strtolower($tokens[$name]['text']), $methods, true)
        ) {
            return null;
        }

        return $name;
    }

    /**
     * @param array<int,array<string,mixed>> $tokens
     * @param int                            $start
     * @return int
     */
    private static function findStatementEnd(array $tokens, $start)
    {
        $count = count($tokens);
        for ($cursor = $start; $cursor < $count; $cursor++) {
            if ($tokens[$cursor]['text'] === ';') {
                return $cursor;
            }
        }

        return $count - 1;
    }

    /**
     * @param array<int,array<string,mixed>> $tokens
     * @param int                            $start
     * @param int                            $end
     * @return string
     */
    private static function compactTokens(array $tokens, $start, $end)
    {
        $value = '';
        for ($cursor = $start; $cursor <= $end; $cursor++) {
            if (
                $tokens[$cursor]['id'] === T_WHITESPACE
                || $tokens[$cursor]['id'] === T_COMMENT
                || $tokens[$cursor]['id'] === T_DOC_COMMENT
            ) {
                continue;
            }
            $value .= $tokens[$cursor]['text'];
        }

        return $value;
    }

    /**
     * @param string $literal
     * @return string|null
     */
    private static function decodeLiteral($literal)
    {
        if (!is_string($literal) || strlen($literal) < 2) {
            return null;
        }
        $quote = $literal[0];
        if (($quote !== "'" && $quote !== '"') || substr($literal, -1) !== $quote) {
            return null;
        }
        $body = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
        }

        return stripcslashes($body);
    }

    /**
     * @param array<int,string> $parts
     * @return string
     */
    private static function joinRoute(array $parts)
    {
        $clean = [];
        foreach ($parts as $part) {
            $part = trim((string) $part, '/');
            if ($part !== '') {
                $clean[] = $part;
            }
        }

        return implode('/', $clean);
    }

    /**
     * @param array<int,array<string,string>> $errors
     * @param string                          $location
     * @param string                          $reason
     * @return void
     */
    private static function addError(array &$errors, $location, $reason)
    {
        $errors[] = [
            'location' => (string) $location,
            'reason'   => (string) $reason,
        ];
    }
}
