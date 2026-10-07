<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // which allowance / deduction applies to whom; `value` is an amount or a percentage of the basic salary (see the component)
        Schema::create('employee_salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained('salary_components')->cascadeOnDelete();
            $table->decimal('value', 12, 2);
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'salary_component_id'], 'emp_salary_component_unique');
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('title');
            $table->decimal('amount', 12, 2);
            $table->decimal('installment', 12, 2);                       // taken from every payslip until repaid
            $table->decimal('repaid', 12, 2)->default(0);
            $table->date('start_month');                                  // first day of the first month with a deduction
            $table->string('status', 10)->default('active');              // active | completed | cancelled
            $table->text('notes')->nullable();
            $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        // one batch per company and month
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7);                                   // YYYY-MM
            $table->string('status', 10)->default('draft');               // draft -> approved -> paid
            $table->decimal('total_earnings', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);       // statutory-type deductions, absence, loans
            $table->decimal('total_net', 14, 2)->default(0);
            $table->date('paid_on')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['created_by', 'month']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('payrolls')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete(); // history must survive: terminate, do not delete
            $table->decimal('basic_salary', 12, 2);                       // contractual monthly basic
            $table->unsignedSmallInteger('working_days');                 // working days of the month the employee was employed
            $table->decimal('unpaid_days', 5, 1)->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->decimal('earnings', 12, 2);                           // basic earned + allowances + overtime
            $table->decimal('deductions', 12, 2);
            $table->decimal('net_pay', 12, 2);
            $table->timestamps();

            $table->unique(['payroll_id', 'employee_id']);
        });

        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->string('kind', 12);                                   // basic | allowance | overtime | deduction | absence | loan
            $table->string('label');
            $table->decimal('amount', 12, 2);                             // always positive; `kind` says earning or deduction
            $table->foreignId('loan_id')->nullable()->constrained('loans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('employee_salary_components');
    }
};
