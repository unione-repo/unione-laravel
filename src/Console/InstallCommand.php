<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Console;

use Illuminate\Console\Command;

/**
 * php artisan unione:install - what a Flex recipe does in Symfony: publishes config/unione.php and adds
 * UNIONE_API_KEY to .env and .env.example (when they have no such line). Runs again safely.
 */
final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'unione:install {--force : Overwrite config/unione.php}';

    /** @var string */
    protected $description = 'Publish config/unione.php and add the UniOne variables to .env';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'unione-config', '--force' => (bool)$this->option('force')]);

        foreach (['.env', '.env.example'] as $file) {
            $path = $this->laravel->basePath($file);
            if (!\is_file($path)) {
                continue;
            }
            $content = (string)\file_get_contents($path);
            if (\preg_match('/^UNIONE_API_KEY=/m', $content) === 1) {
                continue;
            }
            $block = "UNIONE_API_KEY=\n# UNIONE_HOST=api.unione.io\n";
            \file_put_contents($path, ($content === '' ? '' : \rtrim($content, "\n") . "\n\n") . $block);
            $this->components->info("UNIONE_API_KEY added to $file.");
        }

        $this->components->info('Set UNIONE_API_KEY in .env and MAIL_MAILER=unione to send mail through UniOne.');

        return self::SUCCESS;
    }
}
