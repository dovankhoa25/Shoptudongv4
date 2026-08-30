<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gem_transactions')) {
            return;
        }

        Schema::table('gem_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('gem_transactions', 'cancel_requested_at')) {
                $table->timestamp('cancel_requested_at')->nullable()->after('last_synced_at');
            }

            if (! Schema::hasColumn('gem_transactions', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('cancel_requested_at');
            }
        });

        $hasCancellationIndex = collect(Schema::getIndexes('gem_transactions'))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === 'gem_transactions_cancel_refund_index');

        if (! $hasCancellationIndex) {
            Schema::table('gem_transactions', function (Blueprint $table): void {
                $table->index(
                    ['status', 'cancel_requested_at', 'refunded_at'],
                    'gem_transactions_cancel_refund_index',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('gem_transactions')) {
            return;
        }

        $hasCancellationIndex = collect(Schema::getIndexes('gem_transactions'))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === 'gem_transactions_cancel_refund_index');

        Schema::table('gem_transactions', function (Blueprint $table) use ($hasCancellationIndex): void {
            if ($hasCancellationIndex) {
                $table->dropIndex('gem_transactions_cancel_refund_index');
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('gem_transactions', 'cancel_requested_at') ? 'cancel_requested_at' : null,
                Schema::hasColumn('gem_transactions', 'refunded_at') ? 'refunded_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
