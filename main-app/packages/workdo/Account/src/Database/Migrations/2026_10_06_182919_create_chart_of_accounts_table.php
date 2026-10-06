<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('type');
            $table->boolean('is_bank')->default(false);
            $table->boolean('is_system')->default(false);   // seeded defaults: code/type fixed, cannot be deleted
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['created_by', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
