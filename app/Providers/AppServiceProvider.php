<?php

namespace App\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * @var list<string>
     */
    private const BLOCKED_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (in_array($event->command, self::BLOCKED_COMMANDS, true)) {
                throw new RuntimeException(
                    $event->command.' is disabled. It deletes database data.'
                );
            }
        });
    }
}
