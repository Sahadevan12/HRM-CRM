<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->date('follow_up_date')->nullable();
            // a pipeline or stage that still holds leads cannot be deleted (the setup page explains why)
            $table->foreignId('pipeline_id')->constrained('pipelines')->restrictOnDelete();
            $table->foreignId('lead_stage_id')->constrained('lead_stages')->restrictOnDelete();
            $table->unsignedInteger('order')->default(0);                  // position inside the stage column
            $table->boolean('is_active')->default(true);
            $table->boolean('is_converted')->default(false);               // set when the lead became a deal
            $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['pipeline_id', 'lead_stage_id', 'order']);
        });

        // the staff members working on a lead
        Schema::create('user_leads', function (Blueprint $table) {
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['lead_id', 'user_id']);
        });

        Schema::create('lead_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);                                    // created | moved | assigned | updated | ...
            $table->string('remark', 500);
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        // which pipeline a user last looked at
        Schema::create('crm_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->foreignId('default_pipeline_id')->nullable()->constrained('pipelines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['crm_preferences', 'lead_activity_logs', 'user_leads', 'leads'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
