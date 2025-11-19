<?php

namespace Dev\Cli\Commands;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\NodeVisitor\NameResolver;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FixStaticCommand extends BaseCommand
{
    protected static $defaultName = 'app:fix';

    protected function configure()
    {
        $this
            ->setAliases(['fix'])
            ->setDescription('Fixes the autoload_static.php file after composer dump optimization.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        require $this->root . '/vendor/autoload.php';
        
        $output->writeln('<info>Please wait a few moments...</info>');

        $filePath = $this->root . '/vendor/composer/autoload_static.php';
        $classSourceCode = @file_get_contents($filePath);
        if ($classSourceCode === false) {
            $output->writeln("<error>Could not read $filePath</error>");
            return static::FAILURE;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        try {
            $composerJsonPath = $this->root . '/composer.json';
            $composerJson = @file_get_contents($composerJsonPath);
            $ns = $composerJson
                ? (json_decode($composerJson, true)['extra']['wpfluent']['namespace']['current'] ?? null)
                : null;

            if (!$ns) {
                $output->writeln("<error>Namespace not found in composer.json (extra.wpfluent.namespace.current)</error>");
                return static::FAILURE;
            }

            $stmts = $parser->parse($classSourceCode);
            $classData = ['props' => [], 'methods' => [], 'className' => ''];

            $traverser->addVisitor(new class($ns, $filePath, $classData) extends \PhpParser\NodeVisitorAbstract {
                private $ns;
                private $filePath;
                private $classData;

                public function __construct(string $ns, string $filePath, array &$classData)
                {
                    $this->ns = $ns;
                    $this->filePath = $filePath;
                    $this->classData = &$classData;
                }

                public function enterNode(Node $node)
                {
                    if ($node instanceof Node\Stmt\Class_) {
                        $this->classData['className'] = $node->name->name;
                        return null;
                    }

                    if ($node instanceof Node\Stmt\ClassMethod) {
                        $this->classData['methods'][] = $this->getFormattedCode(
                            $node
                        );
                        return null;
                    }

                    if ($node instanceof Node\Stmt\Property) {
                        $name = $node->props[0]->name->name;
                        $vis = "\t".$this->getPropertyVisibility($node);
                        $default = $this->getPropertyDefaultValue($node);

                        if ($name === 'prefixDirsPsr4') {
                            $default = preg_replace_callback(
                                '/([\'"]' . preg_quote($this->ns, '/') . '\\\\.*?[\'"]\s*=>\s*array\s*\(.*?\))/s',
                                fn($m) => $m[0],
                                $default
                            );
                        }

                        $this->classData['props'][$name] = [
                            'visibility' => $vis,
                            'propertyName' => $name,
                            'defaultValue' => $default,
                        ];
                    }

                    return null;
                }

                private function getFormattedCode(Node $node): string
                {
                    $start = $node->getAttribute('startFilePos');
                    $end = $node->getAttribute('endFilePos');
                    $content = file_get_contents($this->filePath);
                    
                    return ($start !== null && $end !== null)
                        ? substr($content, $start, $end - $start + 1)
                        : '';
                }

                private function getPropertyVisibility(Node\Stmt\Property $prop): string
                {
                    if ($prop->isPublic()) return 'public static';
                    if ($prop->isProtected()) return 'protected static';
                    if ($prop->isPrivate()) return 'private static';
                    return 'public static';
                }

                private function getPropertyDefaultValue(Node\Stmt\Property $prop): string
                {
                    return isset($prop->props[0]->default)
                        ? $this->getFormattedCode($prop->props[0]->default)
                        : 'null';
                }
            });

            $traverser->traverse($stmts);

            $this->regenerateAutoloadStatic($filePath, $ns, $classData);
            $output->writeln('<info>Fixed the composer optimized namepsaces successfully.</info>');
            return static::SUCCESS;

        } catch (Error $e) {
            $output->writeln(
                '<error>Parse error: ' . $e->getMessage() . '</error>'
            );
            return static::FAILURE;
        }
    }

    private function regenerateAutoloadStatic(string $path, string $ns, array $classData): void
    {
        $clsName = $classData['className'];
        $props = $classData['props'];
        $methods = $classData['methods'];

        $propsCode = '';

        foreach ($props as $p) {
            if ($p['visibility'] && $p['defaultValue']) {
                $propsCode .= "{$p['visibility']} \${$p['propertyName']} = {$p['defaultValue']};\n\n";
            }
        }

        $methodCode = "\t".implode(PHP_EOL, $methods);

        $classCode = $this->getTemplate($clsName, $propsCode, $methodCode);

        file_put_contents($path, $classCode);
    }

    private function getTemplate(string $clsName, string $propsCode, string $methodCode): string
    {
        return <<<PHP
<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class {$clsName}
{
{$propsCode}{$methodCode}
}

PHP;
    }
}
