<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // when a deal was won / lost (null while it is active): the reports count won and lost value in that month
        Schema::table('deals', function (Blueprint $table) {
            $table->dateTime('closed_at')->nullable()->after('status');
            $table->index(['created_by', 'status', 'closed_at']);
        });

        // deals decided before this column existed: the last update is the best guess
        DB::table('deals')->whereIn('status', ['won', 'lost'])->update(['closed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['created_by', 'status', 'closed_at']);
            $table->dropColumn('closed_at');
        });
    }
};
