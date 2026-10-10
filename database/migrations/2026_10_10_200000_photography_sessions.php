<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('pricing_packages', 'photography_sessions')) {
                $table->unsignedSmallInteger('photography_sessions')->default(0);
            }
        });

        Schema::table('requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('requests', 'photography_sessions')) {
                $table->unsignedSmallInteger('photography_sessions')->nullable();
            }
            if (! Schema::hasColumn('requests', 'photography_sessions_used')) {
                $table->unsignedSmallInteger('photography_sessions_used')->default(0);
            }
            if (! Schema::hasColumn('requests', 'photography_period_key')) {
                $table->string('photography_period_key')->nullable();
            }
            if (! Schema::hasColumn('requests', 'photography_next_period_key')) {
                $table->string('photography_next_period_key')->nullable();
            }
        });

        Schema::table('photography_bookings', function (Blueprint $table): void {
            foreach ([
                'period_key' => fn () => $table->string('period_key')->nullable(),
                'session_number' => fn () => $table->unsignedSmallInteger('session_number')->nullable(),
                'waiting_since' => fn () => $table->timestamp('waiting_since')->nullable(),
                'confirmed_at' => fn () => $table->timestamp('confirmed_at')->nullable(),
                'charged_at' => fn () => $table->timestamp('charged_at')->nullable(),
                'refunded_at' => fn () => $table->timestamp('refunded_at')->nullable(),
                'lead_override_by' => fn () => $table->unsignedBigInteger('lead_override_by')->nullable(),
                'calendar_failed_at' => fn () => $table->timestamp('calendar_failed_at')->nullable(),
                'clickup_failed_at' => fn () => $table->timestamp('clickup_failed_at')->nullable(),
                'client_notified_at' => fn () => $table->timestamp('client_notified_at')->nullable(),
                'client_notify_failed' => fn () => $table->boolean('client_notify_failed')->default(false),
            ] as $column => $add) {
                if (! Schema::hasColumn('photography_bookings', $column)) {
                    $add();
                }
            }
        });

        if (! Schema::hasTable('photography_booking_events')) {
            Schema::create('photography_booking_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('booking_id')->constrained('photography_bookings')->cascadeOnDelete();
                $table->string('action');
                $table->string('actor')->nullable();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('from_status')->nullable();
                $table->string('to_status')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('proposed_starts_at')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->index(['booking_id', 'id']);
            });
        }

        // Rows written before this change: an agreed shoot was already charged, and a
        // bare hold is a request that still waits for the photographer.
        DB::table('photography_bookings')->where('status', 'held')->update([
            'status' => 'pending_staff',
            'waiting_since' => now(),
        ]);
        DB::table('photography_bookings')
            ->whereIn('status', ['pending_staff', 'needs_client'])
            ->whereNull('waiting_since')
            ->update(['waiting_since' => now()]);
        DB::table('photography_bookings')
            ->where('status', 'confirmed')
            ->whereNull('charged_at')
            ->update([
                'charged_at' => DB::raw('updated_at'),
                'confirmed_at' => DB::raw('updated_at'),
            ]);

        $charged = DB::table('photography_bookings')
            ->select('request_id', DB::raw('count(*) as total'))
            ->whereNotNull('charged_at')
            ->whereNull('refunded_at')
            ->groupBy('request_id')
            ->pluck('total', 'request_id');
        foreach ($charged as $requestId => $total) {
            DB::table('requests')->where('id', $requestId)->update(['photography_sessions_used' => (int) $total]);
        }

        // Paid requests that are still open copy the package count once, only where
        // nobody has written a count yet.
        $packages = DB::table('pricing_packages')->where('photography_sessions', '>', 0)->pluck('photography_sessions', 'id');
        foreach ($packages as $packageId => $sessions) {
            DB::table('requests')
                ->where('pricing_package_id', $packageId)
                ->whereNull('photography_sessions')
                ->whereIn('status', ['payment_confirmed', 'in_progress', 'ready_for_review', 'revision_requested', 'completed'])
                ->update(['photography_sessions' => (int) $sessions, 'photography_period_key' => 'initial']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('photography_booking_events');

        Schema::table('photography_bookings', function (Blueprint $table): void {
            foreach ([
                'client_notify_failed',
                'client_notified_at',
                'clickup_failed_at',
                'calendar_failed_at',
                'lead_override_by',
                'refunded_at',
                'charged_at',
                'confirmed_at',
                'waiting_since',
                'session_number',
                'period_key',
            ] as $column) {
                if (Schema::hasColumn('photography_bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('requests', function (Blueprint $table): void {
            foreach (['photography_next_period_key', 'photography_period_key', 'photography_sessions_used', 'photography_sessions'] as $column) {
                if (Schema::hasColumn('requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('pricing_packages', function (Blueprint $table): void {
            if (Schema::hasColumn('pricing_packages', 'photography_sessions')) {
                $table->dropColumn('photography_sessions');
            }
        });
    }
};
