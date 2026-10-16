<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // where the money came from, when the company wants it in its books (null = not recorded in accounting)
            $table->string('disbursed_from', 10)->nullable()->after('status');
            $table->date('disbursed_on')->nullable()->after('disbursed_from');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['disbursed_from', 'disbursed_on']);
        });
    }
};
