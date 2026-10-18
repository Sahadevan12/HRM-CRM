<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "When <trigger> on a lead/deal, if <conditions>, then <actions>"
        Schema::create('crm_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('module', 10);          // lead | deal
            $table->string('trigger', 30);         // created | stage_changed | won | lost
            $table->string('match', 3)->default('all'); // all | any
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('run_count')->default(0);
            $table->dateTime('last_run_at')->nullable();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['created_by', 'module', 'trigger', 'is_active']);
        });

        Schema::create('crm_workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('crm_workflows')->cascadeOnDelete();
            $table->string('record_type', 10);
            $table->unsignedBigInteger('record_id');
            $table->string('status', 10);          // ok | failed
            $table->string('message', 500)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at')->nullable();
            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_workflow_runs');
        Schema::dropIfExists('crm_workflows');
    }
};
