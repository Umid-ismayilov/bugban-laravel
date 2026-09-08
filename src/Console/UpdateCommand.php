<?php

namespace Bugban\Laravel\Console;

use Bugban\Sdk\Bugban;
use Illuminate\Console\Command;

/**
 * php artisan bugban:update            upgrade every bugban/* package to the newest release
 * php artisan bugban:update --check    only report (exit 10 when an update is available)
 * php artisan bugban:update --yes      no confirmation (cron / CI)
 * php artisan bugban:update --dry-run  show what composer would do
 *
 * Thin wrapper over the core Updater so Symfony/CI/Yii users get the same
 * behaviour from `vendor/bin/bugban update`.
 */
class UpdateCommand extends Command
{
    protected $signature = 'bugban:update {--check : Only check, do not install} {--yes : Do not ask for confirmation} {--dry-run : Show what would change}';

    protected $description = 'Upgrade the Bugban SDK packages (bugban/*) to the newest published release';

    public function handle()
    {
        if (!class_exists('Bugban\\Sdk\\Support\\Updater') || !method_exists('Bugban\\Sdk\\Bugban', 'checkForUpdate')) {
            $this->error('bugban/php-sdk >= 1.7.0 is required for self-update. Run: composer require bugban/php-sdk:^1.7.0 bugban/laravel:^1.7.0');

            return 1;
        }

        $check = Bugban::checkForUpdate();
        if ($check['error'] !== null) {
            $this->error('Bugban: ' . $check['error']);

            return 1;
        }
        if (!$check['update_available']) {
            $this->info('Bugban SDK is up to date (' . $check['current'] . ').');

            return 0;
        }
        $this->line('Bugban SDK ' . $check['current'] . ' -> <info>' . $check['latest'] . '</info> is available.');
        if ($this->option('check')) {
            return 10;
        }
        $dry = (bool) $this->option('dry-run');
        if (!$dry && !$this->option('yes') && !$this->confirm('Run composer require for bugban/* ^' . $check['latest'] . ' now?', true)) {
            $this->line('Aborted.');

            return 1;
        }

        $out = $this->output;
        $res = Bugban::update($dry, function ($line) use ($out) {
            $out->writeln('  ' . $line);
        });
        if ($res['ok']) {
            $this->info($res['message']);
            if (!$dry && $res['mode'] !== 'none') {
                $this->comment('Restart queue workers / Octane / Horizon so they load the new code (php artisan queue:restart).');
            }

            return 0;
        }
        $this->error($res['message']);

        return 1;
    }
}
