<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php ini_set("memory_limit", -1);

// -----------------------------------------------------------------------------
// 1. Composer Autoload & Imports
// -----------------------------------------------------------------------------
require __DIR__ . '/../../vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeVisitor\NameResolver;



// -----------------------------------------------------------------------------
// 2. Main Function
// -----------------------------------------------------------------------------
return function ($inDir, $namespace, $report) {

    // Determine WordPress root dynamically
    $wpRoot = realpath(__DIR__ . '/../../../../..');

    // PhpParser printer, traverser, and parser setup
    $printer = new PrettyPrinter\Standard();
    $traverser = new NodeTraverser();
    $parser = (new ParserFactory())->createForNewestSupportedVersion();

    // NameResolver handles class/function name resolution
    $traverser->addVisitor(new NameResolver());



    // -------------------------------------------------------------------------
    // 3. Node Visitor: Handles adding namespace prefixes
    // -------------------------------------------------------------------------
    $traverser->addVisitor(new class(
        $namespace, $wpRoot
    ) extends NodeVisitorAbstract {

        private string $ns;
        private string $root;
        private array $funcs;
        private array $internals;

        public function __construct(string $namespace, string $wpRoot)
        {
            $this->ns = $namespace;
            $this->root = $wpRoot;

            // Load WP compat.php functions to skip prefixing
            $this->funcs = $this->getListOfExcludableFunctions();

            // Collect all PHP internal classes, interfaces, functions, constants
            $this->internals = $this->getAllBuiltinEntitiesOfPhp();
        }

        // ---------------------------------------------------------------------
        // Called for every node in the PHP AST
        // ---------------------------------------------------------------------
        public function enterNode(Node $node)
        {
            // Prefix Namespaces / Classes / Use statements
            if ($node instanceof Node\Name) {
                $name = $node->toString();

                if ($this->isInternal($name)) {
                    return $node;
                }

                if ($this->shouldQualifyName($name)) {
                    $name = trim(str_replace($this->ns, '', $name), '\\');
                    $name = ($node instanceof Namespace_ || $node->isQualified())
                        ? $this->qualifyName($name, false)
                        : $this->qualifyName($node->toString());
                    return new Node\Name($name);
                }

                return $node;
            }

            // Prefix user-defined function calls
            if ($this->isUserDefinedFuncCall($node)) {
                $name = $node->name->toString();
                if ($this->shouldPrefixFunction($name, $node)) {
                    $node->name = new Node\Name\FullyQualified(
                        $this->fullyQualifiedName($name)
                    );
                }
                return $node;
            }
        }

        // ---------------------------------------------------------------------
        // Helpers for namespacing logic
        // ---------------------------------------------------------------------
        private function shouldQualifyName(string $name): bool
        {
            return !str_starts_with($name, $this->ns) && strpos($name, '\\') !== false;
        }

        private function qualifyName(string $name, bool $leadingSlash = true): string
        {
            $qualified = rtrim($this->ns, '\\') . '\\' . trim($name, '\\');
            return $leadingSlash ? '\\' . $qualified : $qualified;
        }

        private function isInternal(string $name): bool
        {
            return in_array($name, $this->internals, true);
        }

        private function isExcludableFn(string $name): bool
        {
            return !in_array($name, $this->funcs, true);
        }

        private function isUserDefinedFuncCall(Node $node): bool
        {
            return $node instanceof FuncCall
                && method_exists($node->name, 'toString')
                && $node->getAttribute('startLine') !== null;
        }

        private function shouldPrefixFunction(string $name, Node $node): bool
        {
            return !$this->isInternal($name)
                && $this->isExcludableFn($name)
                && !str_starts_with($name, $this->ns);
        }

        private function fullyQualifiedName(string $name): string
        {
            return trim($this->ns, '\\') . '\\' . trim($name, '\\');
        }

        // ---------------------------------------------------------------------
        // Get all PHP internal classes/interfaces/functions/constants
        // ---------------------------------------------------------------------
        private function getAllBuiltinEntitiesOfPhp(): array
        {
            $classes = array_filter(
                get_declared_classes(),
                fn($c) => (new ReflectionClass($c))->isInternal()
            );

            $interfaces = array_filter(
                get_declared_interfaces(),
                fn($i) => (new ReflectionClass($i))->isInternal()
            );

            $functions = get_defined_functions()['internal'];

            $constants = get_defined_constants(true);
            unset($constants['user']);

            return array_merge($classes, $interfaces, $functions, $constants);
        }

        // ---------------------------------------------------------------------
        // Get WordPress functions from compat.php to skip prefixing
        // ---------------------------------------------------------------------
        private function getListOfExcludableFunctions(): array
        {
            $compatFile = $this->root . '/wp-includes/compat.php';
            $wpFunctions = [];

            if (file_exists($compatFile)) {
                $content = file_get_contents($compatFile);
                preg_match_all(
                    '/\bfunction\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/',
                    $content,
                    $matches
                );
                $wpFunctions = $matches[1];
            }

            return $wpFunctions;
        }
    });



    // -------------------------------------------------------------------------
    // 4. Collect all PHP files recursively
    // -------------------------------------------------------------------------
    $phpFiles = new RegexIterator(
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($inDir)
        ),
        '/\.php$/'
    );



    // -------------------------------------------------------------------------
    // 5. Process each PHP file
    // -------------------------------------------------------------------------
    foreach ($phpFiles as $file) {
        try {
            $path = $file->getPathName();
            $code = file_get_contents($path);

            // -----------------------------------------------------------------
            // 5a. Add namespace if missing
            // -----------------------------------------------------------------
            if (preg_match('/\bfunction\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/', $code)) {
                if (!preg_match("/\bnamespace\s+$namespace\b/", $code)) {
                    $code = strpos($code, 'namespace') !== false
                        ? preg_replace('/namespace (.+?);/', "namespace $namespace\\\\$1;", $code, 1)
                        : "<?php\n\nnamespace $namespace;\n\n" . str_replace('<?php', '', $code);
                }

                // -------------------------------------------------------------
                // 5b. Analyze tokens to see if file contains classes, traits, interfaces
                // --------------------------------------------------------------
                $allTokens = token_get_all($code);
                $tokens = [];
                foreach ($allTokens as $t) {
                    if (is_array($t)) $tokens[] = $t[0];
                }
                $tokens = array_values(array_unique($tokens));

                // --------------------------------------------------------------
                // 5c. Update Composer autoload files
                // --------------------------------------------------------------
                $ds = DIRECTORY_SEPARATOR;
                if (!array_intersect($tokens, [T_CLASS, T_TRAIT, T_INTERFACE])) {

                    $pathParts = explode('vendor', $path);
                    $vendorRoot = $pathParts[0] . 'vendor';
                    $relativeDir = pathinfo(trim($pathParts[1], $ds))['dirname'];
                    $dirsToCheck = explode($ds, $relativeDir);

                    while (!empty($dirsToCheck)) {
                        $currentPath = $vendorRoot . $ds . implode($ds, $dirsToCheck);
                        $composerJson = $currentPath . $ds . 'composer.json';

                        if (file_exists($composerJson)) {
                            $data = json_decode(
                                file_get_contents($composerJson), true
                            );

                            if (isset($data['autoload']['files'])) {
                                $filesKey = $data['autoload']['files'];
                                $fileName = $file->getFilename();
                                $nameExists = in_array($fileName, $filesKey);

                                if (!$nameExists) {
                                    foreach ($filesKey as $fn) {
                                        if (
                                            str_contains($fn, $fileName)
                                            && str_contains(
                                                $file->getPathname(), $fn
                                            )
                                        ) {
                                            $nameExists = true;
                                            $fileName = $fn;
                                            break;
                                        }
                                    }
                                }

                                if ($nameExists) {
                                    $reportData = (array) json_decode(file_get_contents($report), true
                                    );
                                    
                                    $reportData[$composerJson] = [
                                        'name' => $fileName,
                                        'package' => $data['name'],
                                        'path' => $path,
                                    ];

                                    file_put_contents(
                                        $report,
                                        json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                                        )
                                    );
                                }
                            }
                        }

                        array_pop($dirsToCheck);
                    }

                    // -----------------------------------------------------------
                    // 5d. Update function_exists calls
                    // -----------------------------------------------------------
                    preg_match_all(
                        '/\bfunction_exists\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
                        $code,
                        $matches,
                        PREG_OFFSET_CAPTURE
                    );

                    foreach (array_reverse($matches[1]) as $match) {
                        $fnName = $match[0];
                        if (!str_starts_with($fnName, $namespace)) {
                            $code = substr_replace($code, $namespace . '\\' . $fnName, $match[1], strlen($fnName));
                        }
                    }

                    file_put_contents($path, $code);
                }
            }

            // -----------------------------------------------------------------
            // 5e. Apply AST transformations
            // -----------------------------------------------------------------
            file_put_contents(
                $path,
                $printer->prettyPrintFile(
                    $traverser->traverse($parser->parse($code))
                )
            );

        } catch (Error $e) {
            echo "Error Occurred\nFile: {$e->getFile()}\nLine: {$e->getLine()}\nMessage: {$e->getMessage()}\n";
            continue;
        }
    }
};
