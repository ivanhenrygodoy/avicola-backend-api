<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mnt_usuarios', function (Blueprint $table) {
            $table->string('modo_tema', 20)->default('claro')->after('debe_cambiar_password');
        });
    }

    public function down(): void
    {
        Schema::table('mnt_usuarios', function (Blueprint $table) {
            $table->dropColumn('modo_tema');
        });
    }
};

