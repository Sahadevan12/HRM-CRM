<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 14, 2)->default(0);
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();
            // a pipeline or stage that still holds deals cannot be deleted
            $table->foreignId('pipeline_id')->constrained('pipelines')->restrictOnDelete();
            $table->foreignId('deal_stage_id')->constrained('deal_stages')->restrictOnDelete();
            $table->unsignedInteger('order')->default(0);                  // position inside the stage column
            $table->string('status', 10)->default('active');               // active | won | lost
            $table->boolean('is_active')->default(true);
            // the lead this deal was made from (a lead converts once)
            $table->foreignId('lead_id')->nullable()->unique()->constrained('leads')->nullOnDelete();
            $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['pipeline_id', 'deal_stage_id', 'order']);
        });

        // staff working on the deal
        Schema::create('user_deals', function (Blueprint $table) {
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['deal_id', 'user_id']);
        });

        // the customers (users of type client) the deal is for
        Schema::create('client_deals', function (Blueprint $table) {
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['deal_id', 'user_id']);
        });

        Schema::create('deal_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->string('remark', 500);
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['deal_activity_logs', 'client_deals', 'user_deals', 'deals'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
