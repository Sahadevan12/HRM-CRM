<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a promotion is applied at once (the employee's designation changes) and kept as history
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('previous_designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('designation_id')->constrained('designations')->restrictOnDelete();
            $table->string('title');
            $table->date('promotion_date');
            $table->text('notes')->nullable();
            $this->tenant($table);
        });

        // resignation / termination / transfer: pending -> approved | rejected; an approved one is APPLIED on its effective date
        Schema::create('resignations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('notice_date');
            $table->date('last_working_date');
            $table->text('reason')->nullable();
            $this->workflow($table);
            $this->tenant($table);
        });

        Schema::create('terminations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 30);                                   // misconduct | performance | layoff | end_of_contract | other
            $table->date('notice_date');
            $table->date('termination_date');
            $table->text('reason')->nullable();
            $this->workflow($table);
            $this->tenant($table);
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('from_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->date('transfer_date');
            $table->text('reason')->nullable();
            $this->workflow($table);
            $this->tenant($table);
        });
    }

    public function down(): void
    {
        foreach (['transfers', 'terminations', 'resignations', 'promotions'] as $t) {
            Schema::dropIfExists($t);
        }
    }

    private function workflow(Blueprint $table): void
    {
        $table->string('status', 10)->default('pending');                 // pending | approved | rejected
        $table->text('decision_comment')->nullable();
        $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
        $table->dateTime('decided_at')->nullable();
        $table->dateTime('applied_at')->nullable();                       // when the change really happened to the employee
    }

    private function tenant(Blueprint $table): void
    {
        $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
        $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
        $table->timestamps();
    }
};
