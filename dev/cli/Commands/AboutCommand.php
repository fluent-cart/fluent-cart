<?php

namespace Dev\Cli\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

class AboutCommand extends BaseCommand
{
    protected static $defaultName = 'app:about';

    protected function configure()
    {
        $this
            ->setAliases(['about'])
            ->setDescription('Displays information about the application.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {   
        $this->maybeLoadWordPress();

        $readMe = file_get_contents($this->root . '/vendor/wpfluent/framework/README.md');

        $about = array_merge([
            'PHP version' => PHP_VERSION
        ], require $this->root . '/config/app.php');

        if (preg_match('/version - \d+\.\d+\.\d+/i', $readMe, $matches)) {
            $pieces = explode('-', $matches[0]);
            $about = array_merge([
                'Framework version' => trim(end($pieces))
            ], $about);
        }

        $terminal = new Terminal();
        $terminalWidth = $terminal->getWidth();

        foreach ($about as $key => $value) {
            $keyText = ucwords(str_replace('_', ' ', $key));
            $valueText = (string) $value;

            $dotCount = max(
                0, $terminalWidth - strlen($keyText) - strlen($valueText) - 2
            );

            $dots = str_repeat('.', $dotCount);

            $output->writeln(sprintf(
                '<info>%s</info> <fg=gray>%s</> <fg=white>%s</>',
                $keyText,
                $dots,
                $valueText
            ));
        }

        return static::SUCCESS;
    }
}
