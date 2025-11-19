<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php ini_set("memory_limit", -1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../../../../../wp-load.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeVisitor\NameResolver;

return function($inDir, $namespace, $report) {
    $printer = new PrettyPrinter\Standard;
    $traverser = new PhpParser\NodeTraverser;
    $parser = (new ParserFactory())->createForNewestSupportedVersion();

    $traverser->addVisitor(new PhpParser\NodeVisitor\NameResolver);
    
    $traverser->addVisitor(new class($namespace) extends NodeVisitorAbstract {
        private $ns = '';
        private $funcs = [];
        private $internals = [];
        
        public function __construct($namespace) {
            $this->ns = $namespace;
            $this->funcs = $this->getListOfExcludableFunctions();
            $this->internals = $this->getAllBuiltinEntitiesOfPhp();
        }

        public function enterNode(Node $node) {
            // Add the given namespace prefix to
            // namespace and use statements
            if ($node instanceof Node\Name) {

                $name = $node->toString();
                
                if ($this->isInternal($name)) {
                    return $node;
                }

                if (!str_starts_with($name, $this->ns)) {
                    if (strpos($name, '\\') !== false) {
                        $name = trim(str_replace($this->ns, '', $name), '\\');
                        if ($node instanceof Namespace_ || $node->isQualified()) {
                            $name = trim(
                                rtrim($this->ns, '\\') . '\\' . $name, '\\'
                            );
                        } else {
                            $name = '\\'.trim(
                                rtrim($this->ns, '\\') . '\\' . $node->toString(), '\\'
                            );
                        }
                        $node = new Node\Name($name);
                    }
                }

                return $node;
            }

            // Add namespace prefix to function calls
            if ($node instanceof FuncCall && method_exists($node->name, 'toString')) {
                
                if ($node->getAttribute('startLine') !== null) {

                    $name = $node->name->toString();

                    if (!$this->isInternal($name) && $this->isExcludableFn($name)) {

                        if (!str_contains($name, $this->ns)) {

                            $name = trim(str_replace($this->ns, '', $name), '\\');

                            $ns = '\\'.trim(rtrim($this->ns, '\\').'\\' . $name, '\\');

                            $node->name = new Node\Name(str_replace('\\\\', '\\', $ns));
                        }
                    }
                }

                return $node;
            }
        }

        private function isInternal($name) {
            return in_array($name, $this->internals, true);
        }

        private function isExcludableFn($name){
            return !in_array($name, $this->funcs);
        }
        
        private function getAllBuiltinEntitiesOfPhp() {
            $classes = array_filter(get_declared_classes(), function($c) {
                return (new ReflectionClass($c))->isInternal();
            });
            $interfaces = array_filter(get_declared_interfaces(), function($i) {
                return (new ReflectionClass($i))->isInternal();
            });
            $functions = get_defined_functions()['internal'];
            $constants = get_defined_constants(true);
            unset($constants['user']);
            return array_merge($classes, $interfaces, $functions, $constants);
        }
        
        private function getListOfExcludableFunctions() {
            $funcs = [];
            if (file_exists(ABSPATH.'wp-includes/compat.php')) {
                $file_content = file_get_contents(ABSPATH.'wp-includes/compat.php');
                $pattern = '/\bfunction\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/';
                preg_match_all($pattern, $file_content, $matches);
                $funcs = $matches[1];
            }
            return $funcs;
        }
    });

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($inDir)
    );

    $files = new RegexIterator($files, '/\.php$/');

    foreach ($files as $file) {
        try {
            $code = file_get_contents($path = $file->getPathName());

            // Add namespace to user-defined function files
            // Check if function defination exists
            $pattern = '/\bfunction\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(/';
            if (preg_match($pattern, $code, $matches)) {
                if (!preg_match("/\bnamespace\s+$namespace\b/", $code)) {
                    if (strpos($code, 'namespace') !== false) {
                        $code = preg_replace(
                            '/namespace (.+?);/', "namespace $namespace\\\\$1;", $code, 1
                        );
                    } else {
                        $code = str_replace('<?php', '', $code);
                        $code = "<?php\n\nnamespace $namespace;\n\n" . $code;
                    }
                }

                $ds = DIRECTORY_SEPARATOR;
                $tokens = array_map(function($t) {
                    if (is_array($t) && isset($t[0])) return $t[0];
                }, token_get_all($code));

                $tokens = array_values(array_unique(array_filter($tokens)));

                // Prepare for updating composer files in autoload->files section
                if (!array_intersect($tokens, [T_CLASS, T_TRAIT, T_INTERFACE])) {
                    $pieces = explode('vendor', $path);
                    $root = $pieces[0] . 'vendor';
                    $dirName = pathinfo(trim($pieces[1], $ds))['dirname'];
                    $parts = explode($ds, $dirName);
                    
                    do {
                        $imploded = implode($ds, $parts);
                        $f = $root.$ds.$imploded.$ds.'composer.json';
                        if (!file_exists($f)) continue;

                        $data = json_decode(file_get_contents($f), true);

                        if (isset($data['autoload']['files'])) {
                            $filesKey = $data['autoload']['files'];
                            $fileGetFilename = $file->getFilename();
                            if (
                                !($nameExists = in_array(
                                    $fileGetFilename, $filesKey
                                ))
                            ) {
                                foreach ($filesKey as $fn) {
                                    if (str_contains($fn, $file->getFilename())) {
                                        if (str_contains($file->getPathname(), $fn)) {
                                            $nameExists = true;
                                            $fileGetFilename = $fn;
                                            break;
                                        }
                                    }
                                }
                            }

                            if ($nameExists) {
                                $newReportComposerData = (array) json_decode(
                                    file_get_contents($report), true
                                );
                                file_put_contents(
                                    $report, json_encode(
                                        array_merge($newReportComposerData, [
                                            $f => [
                                                'name' => $fileGetFilename,
                                                'package' => $data['name'],
                                                'path' => $path
                                            ]
                                        ]),
                                        JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES
                                    )
                                );
                            }
                        }
                    } while (array_pop($parts));

                    // Change the function_exists('fn')
                    // to function_exists('Ns\fn')
                    $pattern = '/\bfunction_exists\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/';
                    if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                        foreach (array_reverse($matches[1]) as $match) {
                            $originalFunctionName = $match[0];
                            if (str_starts_with($match[0], $namespace)) continue;
                            $replacement = $namespace . '\\' . $originalFunctionName;
                            $code = substr_replace(
                                $code, $replacement, $match[1], strlen($originalFunctionName)
                            );
                        }
                        file_put_contents($file->getPathname(), $code);
                    }
                }
            }

            file_put_contents($path, $printer->prettyPrintFile(
                $traverser->traverse($parser->parse($code))
            ));

        } catch (Error $e) {
            echo 'Error Occured'.PHP_EOL;
            echo 'File: '.$e->getFile().PHP_EOL;
            echo 'Line: '.$e->getLine().PHP_EOL;
            echo 'Message: '.$e->getMessage().PHP_EOL;
            continue;
        }
    }
};
