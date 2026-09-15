<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('odoo_employee_id')->nullable()->after('clickup_user_id');
            $table->index('odoo_employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['odoo_employee_id']);
            $table->dropColumn('odoo_employee_id');
        });
    }
};
