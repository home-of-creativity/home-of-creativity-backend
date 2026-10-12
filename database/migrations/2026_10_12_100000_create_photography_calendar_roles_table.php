<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A calendar with no rows stays open to the whole photography tab.
        Schema::create('photography_calendar_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('calendar_id');
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->boolean('can_edit')->default(false);
            $table->timestamps();
            $table->unique(['calendar_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photography_calendar_roles');
    }
};
