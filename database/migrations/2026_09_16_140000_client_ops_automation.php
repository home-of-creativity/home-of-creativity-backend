<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('name');
            $table->string('odoo_lead_id')->nullable()->after('odoo_partner_id');
        });

        Schema::table('pricing_categories', function (Blueprint $table) {
            $table->boolean('requires_full_payment')->default(false)->after('is_published');
            $table->boolean('allows_renewal')->default(false)->after('requires_full_payment');
        });

        Schema::table('pricing_packages', function (Blueprint $table) {
            $table->boolean('allows_partial_payment')->nullable()->after('is_published');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('pricing_package_id')->nullable()->after('client_id')->constrained('pricing_packages')->nullOnDelete();
            $table->string('billing_period', 32)->nullable()->after('pricing_package_id');
            $table->string('payment_plan', 16)->nullable()->after('billing_period');
            $table->decimal('amount_total', 12, 2)->nullable()->after('quotation_amount');
            $table->decimal('amount_paid', 12, 2)->nullable()->after('amount_total');
            $table->decimal('amount_remaining', 12, 2)->nullable()->after('amount_paid');
            $table->boolean('requires_full_payment')->default(false)->after('amount_remaining');
            $table->boolean('allows_renewal')->default(false)->after('requires_full_payment');
            $table->timestamp('subscription_starts_at')->nullable()->after('allows_renewal');
            $table->timestamp('subscription_ends_at')->nullable()->after('subscription_starts_at');
            $table->string('google_drive_folder_id')->nullable()->after('subscription_ends_at');
            $table->timestamp('receipt_reupload_requested_at')->nullable()->after('google_drive_folder_id');
            $table->unsignedBigInteger('receipt_target_file_id')->nullable()->after('receipt_reupload_requested_at');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('invoice_number');
            $table->string('kind', 32)->nullable()->after('amount');
            $table->unsignedBigInteger('subscription_id')->nullable()->after('kind');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->string('billing_period', 32)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('status', 32)->default('active');
            $table->boolean('renewal_declined')->default(false);
            $table->timestamps();
        });

        Schema::create('payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('kind', 32);
            $table->timestamp('due_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedTinyInteger('send_count')->default(0);
            $table->string('google_event_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'due_at', 'completed_at']);
        });

        Schema::create('drive_delivered_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            $table->string('drive_file_id')->unique();
            $table->string('name')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ops_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('pricing_categories')) {
            DB::table('pricing_categories')
                ->where('slug', 'reach')
                ->update(['requires_full_payment' => true]);
        }

        if (Schema::hasTable('pricing_packages')) {
            $reachCategoryIds = DB::table('pricing_categories')->where('slug', 'reach')->pluck('id');
            if ($reachCategoryIds->isNotEmpty()) {
                $subIds = DB::table('pricing_subcategories')
                    ->whereIn('category_id', $reachCategoryIds)
                    ->pluck('id');
                if ($subIds->isNotEmpty()) {
                    DB::table('pricing_packages')
                        ->whereIn('subcategory_id', $subIds)
                        ->update(['allows_partial_payment' => false]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_delivered_files');
        Schema::dropIfExists('payment_reminders');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('ops_settings');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['amount', 'kind', 'subscription_id']);
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pricing_package_id');
            $table->dropColumn([
                'billing_period',
                'payment_plan',
                'amount_total',
                'amount_paid',
                'amount_remaining',
                'requires_full_payment',
                'allows_renewal',
                'subscription_starts_at',
                'subscription_ends_at',
                'google_drive_folder_id',
                'receipt_reupload_requested_at',
                'receipt_target_file_id',
            ]);
        });

        Schema::table('pricing_packages', function (Blueprint $table) {
            $table->dropColumn('allows_partial_payment');
        });

        Schema::table('pricing_categories', function (Blueprint $table) {
            $table->dropColumn(['requires_full_payment', 'allows_renewal']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'odoo_lead_id']);
        });
    }
};
