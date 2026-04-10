<?php

namespace App\Providers;

use App\Events\BehaviorAlertEvent;
use App\Listeners\StoreBehaviorAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Persist every fired alert so the frontend can load history on init.
        Event::listen(BehaviorAlertEvent::class, StoreBehaviorAlert::class);

        // Apply safety PRAGMAs to the surveillance SQLite connection.
        try {
            $pdo = DB::connection('surveillance')->getPdo();
            $pdo->exec('PRAGMA journal_mode=WAL;');
            $pdo->exec('PRAGMA busy_timeout=5000;');
            $pdo->exec('PRAGMA synchronous=NORMAL;');
            $pdo->exec('PRAGMA query_only=ON;');
        } catch (Throwable) {
            // DB file absent or locked during early setup — not fatal at boot.
        }

        // Laravel 10+ uses Symfony Mailer which ignores the legacy 'stream' key
        // in mail.php. Register a custom SMTP transport that disables SSL peer
        // verification — required because mail.marqenti.tn presents a certificate
        // that PHP's OpenSSL rejects (chain incomplete or mismatched CN).
        Mail::extend('smtp-nossl', function () {
            $transport = new EsmtpTransport(
                config('mail.mailers.smtp.host', 'localhost'),
                (int) config('mail.mailers.smtp.port', 465),
                true // TLS/SSL
            );
            $transport->setUsername(config('mail.mailers.smtp.username', ''));
            $transport->setPassword(config('mail.mailers.smtp.password', ''));
            // Disable certificate verification on the underlying stream.
            $transport->getStream()->setStreamOptions([
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ]);
            return $transport;
        });
    }
}
