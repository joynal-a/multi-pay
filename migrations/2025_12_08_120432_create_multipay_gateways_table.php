<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the gateway-credentials table ONLY if the host app doesn't
     * already have one (table/column names come from config/multipay.php,
     * so apps with an existing table keep theirs untouched).
     */
    public function up(): void
    {
        $table = (string) config('multipay.data_table', 'gateways');
        $column = (string) config('multipay.json_column', 'data');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->id();
            $blueprint->string('name')->unique();
            $blueprint->json($column)->nullable();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        // Intentionally left empty: the table may pre-date this package.
    }
};
