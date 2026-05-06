<?php

namespace Arca\PaymentGateways\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateFromV1Command extends Command
{
    protected $signature = 'payment-gateways:migrate-from-v1
        {--dry-run : Show what would be changed without modifying the database}
        {--no-backup : Skip creating backup table}';

    protected $description = 'Migrate the payments table from v1.x schema to v2.0';

    protected const array STATUS_MAP = [
        'Paid' => 'paid',
        'Pending' => 'pending',
        'Canceled' => 'failed',
        'Reversed' => 'reversed',
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('payments')) {
            $this->error('The payments table does not exist.');

            return self::FAILURE;
        }

        if (! $this->isV1Schema()) {
            $this->error('The payments table does not appear to be a v1.x schema (missing "type" or "voucher" column).');

            return self::FAILURE;
        }

        if ($this->isAlreadyMigrated()) {
            $this->warn('The payments table appears to already have v2.0 columns. Skipping.');

            return self::SUCCESS;
        }

        $count = DB::table('payments')->count();
        $this->components->info("Found {$count} payment(s) to migrate.");

        if ($this->option('dry-run')) {
            $this->dryRun();

            return self::SUCCESS;
        }

        if (! $this->option('no-backup')) {
            $this->backup();
        }

        $this->migrateSchema();
        $this->migrateData();
        $this->cleanupOldColumns();

        $this->newLine();
        $this->components->info('Migration complete! Your payments table is now v2.0 compatible.');
        $this->components->warn('Remember to republish the config: php artisan vendor:publish --tag="payment-gateways-config" --force');

        return self::SUCCESS;
    }

    protected function isV1Schema(): bool
    {
        return Schema::hasColumn('payments', 'type')
            && Schema::hasColumn('payments', 'voucher');
    }

    protected function isAlreadyMigrated(): bool
    {
        return Schema::hasColumn('payments', 'gateway')
            && Schema::hasColumn('payments', 'currency');
    }

    protected function dryRun(): void
    {
        $this->components->twoColumnDetail('<fg=yellow>Column renames</>');
        $this->components->twoColumnDetail('type', 'gateway');
        $this->components->twoColumnDetail('voucher', 'metadata');
        $this->components->twoColumnDetail('comments', 'description');
        $this->components->twoColumnDetail('token', 'external_id');
        $this->components->twoColumnDetail('model_id', 'payable_id');
        $this->components->twoColumnDetail('model_type', 'payable_type');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>New columns</>');
        $this->components->twoColumnDetail('currency', "VARCHAR(3) DEFAULT 'CLP'");
        $this->components->twoColumnDetail('paid_at', 'TIMESTAMP NULLABLE');
        $this->components->twoColumnDetail('expires_at', 'TIMESTAMP NULLABLE');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>Status mapping</>');
        foreach (self::STATUS_MAP as $old => $new) {
            $count = DB::table('payments')->where('status', $old)->count();
            $this->components->twoColumnDetail("{$old} → {$new}", "{$count} row(s)");
        }

        $unmapped = DB::table('payments')
            ->whereNotIn('status', array_keys(self::STATUS_MAP))
            ->count();

        if ($unmapped > 0) {
            $this->warn("  {$unmapped} row(s) with unmapped status values (will default to 'pending')");
        }
    }

    protected function backup(): void
    {
        $backupTable = 'payments_v1_backup';

        if (Schema::hasTable($backupTable)) {
            $this->components->warn("Backup table [{$backupTable}] already exists, skipping backup.");

            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        match ($driver) {
            'mysql' => DB::statement("CREATE TABLE {$backupTable} AS SELECT * FROM payments"),
            'pgsql' => DB::statement("CREATE TABLE {$backupTable} AS SELECT * FROM payments"),
            'sqlite' => DB::statement("CREATE TABLE {$backupTable} AS SELECT * FROM payments"),
            default => $this->warn("Unsupported driver [{$driver}] for backup, skipping."),
        };

        $backupCount = DB::table($backupTable)->count();
        $this->components->info("Backup created: {$backupTable} ({$backupCount} rows)");
    }

    protected function migrateSchema(): void
    {
        $this->components->task('Renaming columns', function () {
            Schema::table('payments', function ($table) {
                $table->renameColumn('type', 'gateway');
            });

            Schema::table('payments', function ($table) {
                $table->renameColumn('comments', 'description');
            });

            Schema::table('payments', function ($table) {
                $table->renameColumn('token', 'external_id');
            });

            if (Schema::hasColumn('payments', 'model_id')) {
                Schema::table('payments', function ($table) {
                    $table->renameColumn('model_id', 'payable_id');
                });

                Schema::table('payments', function ($table) {
                    $table->renameColumn('model_type', 'payable_type');
                });
            }
        });

        $this->components->task('Renaming voucher to metadata', function () {
            Schema::table('payments', function ($table) {
                $table->renameColumn('voucher', 'metadata');
            });
        });

        $this->components->task('Adding new columns', function () {
            Schema::table('payments', function ($table) {
                if (! Schema::hasColumn('payments', 'currency')) {
                    $table->string('currency', 3)->default('CLP')->after('amount');
                }

                if (! Schema::hasColumn('payments', 'paid_at')) {
                    $table->timestamp('paid_at')->nullable()->after('metadata');
                }

                if (! Schema::hasColumn('payments', 'expires_at')) {
                    $table->timestamp('expires_at')->nullable()->after('paid_at');
                }
            });
        });

        $this->components->task('Adding index on external_id', function () {
            Schema::table('payments', function ($table) {
                $table->index('external_id');
            });
        });
    }

    protected function migrateData(): void
    {
        $this->components->task('Mapping status values', function () {
            foreach (self::STATUS_MAP as $old => $new) {
                DB::table('payments')
                    ->where('status', $old)
                    ->update(['status' => $new]);
            }

            DB::table('payments')
                ->whereNotIn('status', array_values(self::STATUS_MAP))
                ->update(['status' => 'pending']);
        });

        $this->components->task('Backfilling paid_at for approved payments', function () {
            DB::table('payments')
                ->where('status', 'paid')
                ->whereNull('paid_at')
                ->update(['paid_at' => DB::raw('updated_at')]);
        });

        $this->components->task('Normalizing gateway names', function () {
            $normalizations = [
                'Webpay' => 'webpay',
                'Getnet' => 'getnet',
                'Paypal' => 'paypal',
                'PayPal' => 'paypal',
                'Flow' => 'flow',
                'Niubiz' => 'niubiz',
                'MercadoPago' => 'mercadopago',
            ];

            foreach ($normalizations as $old => $new) {
                DB::table('payments')
                    ->where('gateway', $old)
                    ->update(['gateway' => $new]);
            }
        });
    }

    protected function cleanupOldColumns(): void
    {
        $this->components->task('Updating column types', function () {
            Schema::table('payments', function ($table) {
                $table->bigInteger('amount')->change();
                $table->text('description')->nullable()->change();
                $table->string('external_id')->nullable()->change();
            });
        });
    }
}
