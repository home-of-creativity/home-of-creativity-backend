<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if (! Schema::hasColumn('clients', 'company_name')) {
                $table->string('company_name')->nullable()->index();
            }
            if (! Schema::hasColumn('clients', 'odoo_lead_id')) {
                $table->string('odoo_lead_id')->nullable()->index();
            }
            if (! Schema::hasColumn('clients', 'company_activity')) {
                $table->string('company_activity')->nullable();
            }
            if (! Schema::hasColumn('clients', 'odoo_stage_name')) {
                $table->string('odoo_stage_name')->nullable();
            }
        });

        Schema::table('pricing_categories', function (Blueprint $table): void {
            if (! Schema::hasColumn('pricing_categories', 'requires_full_payment')) {
                $table->boolean('requires_full_payment')->default(false);
            }
            if (! Schema::hasColumn('pricing_categories', 'allows_renewal')) {
                $table->boolean('allows_renewal')->default(false);
            }
        });

        Schema::table('pricing_packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('pricing_packages', 'allows_partial_payment')) {
                $table->boolean('allows_partial_payment')->nullable();
            }
        });

        Schema::table('requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('requests', 'pricing_package_id')) {
                $table->foreignId('pricing_package_id')->nullable()->constrained('pricing_packages')->nullOnDelete();
            }
            if (! Schema::hasColumn('requests', 'billing_period')) {
                $table->string('billing_period')->nullable();
            }
            if (! Schema::hasColumn('requests', 'payment_plan')) {
                $table->string('payment_plan')->nullable();
            }
            if (! Schema::hasColumn('requests', 'requires_full_payment')) {
                $table->boolean('requires_full_payment')->default(false);
            }
            if (! Schema::hasColumn('requests', 'allows_renewal')) {
                $table->boolean('allows_renewal')->default(false);
            }
            if (! Schema::hasColumn('requests', 'amount_total')) {
                $table->decimal('amount_total', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('requests', 'amount_paid')) {
                $table->decimal('amount_paid', 12, 2)->default(0);
            }
            if (! Schema::hasColumn('requests', 'amount_remaining')) {
                $table->decimal('amount_remaining', 12, 2)->default(0);
            }
            if (! Schema::hasColumn('requests', 'subscription_starts_at')) {
                $table->timestamp('subscription_starts_at')->nullable();
            }
            if (! Schema::hasColumn('requests', 'subscription_ends_at')) {
                $table->timestamp('subscription_ends_at')->nullable();
            }
            if (! Schema::hasColumn('requests', 'google_drive_folder_id')) {
                $table->string('google_drive_folder_id')->nullable();
            }
            if (! Schema::hasColumn('requests', 'receipt_reupload_required')) {
                $table->boolean('receipt_reupload_required')->default(false);
            }
            if (! Schema::hasColumn('requests', 'receipt_reupload_reason')) {
                $table->string('receipt_reupload_reason')->nullable();
            }
            if (! Schema::hasColumn('requests', 'odoo_won_at')) {
                $table->timestamp('odoo_won_at')->nullable();
            }
        });

        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'kind')) {
                $table->string('kind')->default('full')->index();
            }
            if (! Schema::hasColumn('invoices', 'amount')) {
                $table->decimal('amount', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'status')) {
                $table->string('status')->default('issued')->index();
            }
            if (! Schema::hasColumn('invoices', 'subscription_id')) {
                $table->unsignedBigInteger('subscription_id')->nullable()->index();
            }
        });

        Schema::table('request_files', function (Blueprint $table): void {
            if (! Schema::hasColumn('request_files', 'drive_file_id')) {
                $table->string('drive_file_id')->nullable()->index();
            }
        });

        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
                $table->string('billing_period')->nullable();
                $table->timestamp('starts_at')->nullable()->index();
                $table->timestamp('ends_at')->nullable()->index();
                $table->decimal('amount', 12, 2)->default(0);
                $table->decimal('amount_paid', 12, 2)->default(0);
                $table->decimal('amount_remaining', 12, 2)->default(0);
                $table->string('payment_plan')->nullable();
                $table->string('status')->default('active')->index();
                $table->boolean('renewal_declined')->default(false);
                $table->timestamps();
            });
        } else {
            Schema::table('subscriptions', function (Blueprint $table): void {
                if (! Schema::hasColumn('subscriptions', 'amount_paid')) {
                    $table->decimal('amount_paid', 12, 2)->default(0);
                }
                if (! Schema::hasColumn('subscriptions', 'amount_remaining')) {
                    $table->decimal('amount_remaining', 12, 2)->default(0);
                }
                if (! Schema::hasColumn('subscriptions', 'payment_plan')) {
                    $table->string('payment_plan')->nullable();
                }
            });
        }

        if (! Schema::hasTable('payment_reminders')) {
            Schema::create('payment_reminders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
                $table->string('kind')->index();
                $table->timestamp('due_at')->index();
                $table->timestamp('last_sent_at')->nullable();
                $table->unsignedTinyInteger('send_count')->default(0);
                $table->string('google_event_id')->nullable();
                $table->timestamp('completed_at')->nullable()->index();
                $table->timestamps();

                $table->index(['kind', 'completed_at']);
            });
        }

        if (! Schema::hasTable('ops_settings')) {
            Schema::create('ops_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('drive_deliveries')) {
            Schema::create('drive_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
                $table->string('drive_file_id')->unique();
                $table->string('name')->nullable();
                $table->string('mime_type')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_deliveries');
        Schema::dropIfExists('payment_reminders');
        Schema::dropIfExists('ops_settings');
        Schema::dropIfExists('subscriptions');

        Schema::table('request_files', function (Blueprint $table): void {
            if (Schema::hasColumn('request_files', 'drive_file_id')) {
                $table->dropColumn('drive_file_id');
            }
        });

        Schema::table('invoices', function (Blueprint $table): void {
            foreach (['kind', 'amount', 'status', 'subscription_id'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('requests', function (Blueprint $table): void {
            if (Schema::hasColumn('requests', 'pricing_package_id')) {
                $table->dropConstrainedForeignId('pricing_package_id');
            }
            foreach ([
                'billing_period',
                'payment_plan',
                'requires_full_payment',
                'allows_renewal',
                'amount_total',
                'amount_paid',
                'amount_remaining',
                'subscription_starts_at',
                'subscription_ends_at',
                'google_drive_folder_id',
                'receipt_reupload_required',
                'receipt_reupload_reason',
                'odoo_won_at',
            ] as $column) {
                if (Schema::hasColumn('requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('pricing_packages', function (Blueprint $table): void {
            if (Schema::hasColumn('pricing_packages', 'allows_partial_payment')) {
                $table->dropColumn('allows_partial_payment');
            }
        });

        Schema::table('pricing_categories', function (Blueprint $table): void {
            foreach (['requires_full_payment', 'allows_renewal'] as $column) {
                if (Schema::hasColumn('pricing_categories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('clients', function (Blueprint $table): void {
            foreach (['company_name', 'odoo_lead_id', 'company_activity', 'odoo_stage_name'] as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
