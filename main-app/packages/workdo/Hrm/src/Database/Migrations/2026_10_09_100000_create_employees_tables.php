<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An employee = a login user (type staff) + this HR profile
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('employee_code', 30);
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();                    // male | female | other
            $table->date('date_of_joining')->nullable();
            $table->string('employment_type', 20)->default('full_time'); // full_time | part_time | contract | intern
            $table->string('status', 20)->default('active');             // active | inactive | resigned | terminated
            $table->string('phone', 30)->nullable();
            $table->string('address_line')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('emergency_name')->nullable();
            $table->string('emergency_relationship')->nullable();
            $table->string('emergency_phone', 30)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_holder')->nullable();
            $table->string('account_number')->nullable();
            $table->string('bank_code')->nullable();                     // IFSC / SWIFT / routing
            $table->string('tax_id')->nullable();
            $table->decimal('basic_salary', 12, 2)->default(0);          // per month
            $table->decimal('hourly_rate', 10, 2)->default(0);           // overtime
            $table->text('notes')->nullable();
            $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['created_by', 'employee_code']);
            $table->index(['created_by', 'status']);
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('employee_document_types')->nullOnDelete();
            $table->string('title');
            $table->string('file_path');                                  // on the private "local" disk, never public
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('employees');
    }
};
