<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gold_transactions')) {
            return;
        }

        Schema::table('gold_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('gold_transactions', 'cancel_requested_at')) {
                $table->timestamp('cancel_requested_at')->nullable()->after('last_synced_at');
            }

            if (! Schema::hasColumn('gold_transactions', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('cancel_requested_at');
            }
        });

        $hasCancellationIndex = collect(Schema::getIndexes('gold_transactions'))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === 'gold_transactions_cancel_refund_index');

        if (! $hasCancellationIndex) {
            Schema::table('gold_transactions', function (Blueprint $table): void {
                $table->index(
                    ['status', 'cancel_requested_at', 'refunded_at'],
                    'gold_transactions_cancel_refund_index',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('gold_transactions')) {
            return;
        }

        $hasCancellationIndex = collect(Schema::getIndexes('gold_transactions'))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === 'gold_transactions_cancel_refund_index');

        Schema::table('gold_transactions', function (Blueprint $table) use ($hasCancellationIndex): void {
            if ($hasCancellationIndex) {
                $table->dropIndex('gold_transactions_cancel_refund_index');
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('gold_transactions', 'cancel_requested_at') ? 'cancel_requested_at' : null,
                Schema::hasColumn('gold_transactions', 'refunded_at') ? 'refunded_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
