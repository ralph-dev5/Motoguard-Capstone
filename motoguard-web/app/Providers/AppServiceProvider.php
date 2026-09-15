<?php

namespace App\Providers;

use App\Contracts\SmsGateway;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\SemaphoreSmsGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsGateway::class, fn () => match (config('services.sms.driver')) {
            'semaphore' => new SemaphoreSmsGateway(
                (string) config('services.semaphore.key'),
                config('services.semaphore.sender_name'),
            ),
            default => new LogSmsGateway,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->lockSupabaseDataApi();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Supabase exposes every public table through its REST API. Enabling row level security with no
     * policies blocks that API, while Laravel (connected as the table owner) is unaffected.
     */
    protected function lockSupabaseDataApi(): void
    {
        Event::listen(MigrationsEnded::class, function (): void {
            if (DB::getDriverName() !== 'pgsql') {
                return;
            }

            DB::unprepared(<<<'SQL'
                DO $$
                DECLARE t record;
                BEGIN
                    FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND NOT rowsecurity LOOP
                        EXECUTE format('ALTER TABLE public.%I ENABLE ROW LEVEL SECURITY', t.tablename);
                    END LOOP;
                END $$;
                SQL);
        });
    }
}
