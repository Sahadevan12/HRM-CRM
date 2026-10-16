<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // an item without rows in its *_department table is for EVERYONE; otherwise only for the listed departments
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('announcement_category_id')->nullable()->constrained('announcement_categories')->nullOnDelete();
            $table->text('body');
            $table->date('start_date');
            $table->date('end_date')->nullable();                         // null = until removed
            $table->boolean('requires_acknowledgment')->default(false);
            $this->tenant($table);
        });

        Schema::create('announcement_department', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->primary(['announcement_id', 'department_id']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('event_type_id')->nullable()->constrained('event_types')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time')->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $this->tenant($table);
        });

        Schema::create('event_department', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->primary(['event_id', 'department_id']);
        });

        // company documents (policies, handbooks ...) kept on the PRIVATE disk and shared with every employee
        Schema::create('hrm_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->boolean('requires_acknowledgment')->default(false);
            $this->tenant($table);
        });

        Schema::create('acknowledgments', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12);                                   // announcement | document
            $table->unsignedBigInteger('ref_id');
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->dateTime('acknowledged_at');
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();

            $table->unique(['kind', 'ref_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        foreach (['acknowledgments', 'hrm_documents', 'event_department', 'events', 'announcement_department', 'announcements'] as $t) {
            Schema::dropIfExists($t);
        }
    }

    private function tenant(Blueprint $table): void
    {
        $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
        $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
        $table->timestamps();
    }
};
