<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('against_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('subject');
            $table->date('complaint_date');
            $table->text('description');
            $table->string('status');
            $table->text('resolution')->nullable();
            $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
