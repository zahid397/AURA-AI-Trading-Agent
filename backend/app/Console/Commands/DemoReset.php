<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * One-shot reset for the hackathon demo: wipes the database, re-runs every
 * migration, and reseeds the AURA demo scenario (see
 * database/seeders/DemoSeeder.php). Destructive — never run against a
 * database you care about.
 */
#[Signature('aura:demo-reset {--force : Skip the production confirmation prompt}')]
#[Description('Drop, re-migrate, and reseed the database with the AURA demo scenario (destructive).')]
class DemoReset extends Command
{
    use ConfirmableTrait;

    public function handle(): int
    {
        if (! $this->confirmToProceed('This will drop every table in the current database.')) {
            return self::FAILURE;
        }

        $this->call('migrate:fresh', [
            '--seed' => true,
            '--force' => true,
        ]);

        $this->newLine();
        $this->info('Demo data reset. Log in with demo@aura.trading / password.');

        return self::SUCCESS;
    }
}
