<?php

use App\Actions\CollapseDuplicateClients;
use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')->where('odoo_lead_id', '')->update(['odoo_lead_id' => null]);
        DB::table('clients')->where('odoo_partner_id', '')->update(['odoo_partner_id' => null]);

        app(CollapseDuplicateClients::class)->handle();

        Client::onlyTrashed()->update([
            'odoo_lead_id' => null,
            'odoo_partner_id' => null,
        ]);

        Schema::table('clients', function (Blueprint $table): void {
            $existing = array_column(Schema::getIndexes('clients'), 'name');
            if (! in_array('clients_odoo_lead_id_unique', $existing, true)) {
                $table->unique('odoo_lead_id');
            }
            if (! in_array('clients_odoo_partner_id_unique', $existing, true)) {
                $table->unique('odoo_partner_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $existing = array_column(Schema::getIndexes('clients'), 'name');
            if (in_array('clients_odoo_lead_id_unique', $existing, true)) {
                $table->dropUnique('clients_odoo_lead_id_unique');
            }
            if (in_array('clients_odoo_partner_id_unique', $existing, true)) {
                $table->dropUnique('clients_odoo_partner_id_unique');
            }
        });
    }
};
