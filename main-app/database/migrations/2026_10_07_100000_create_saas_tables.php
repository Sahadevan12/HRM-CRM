<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add-on modules known to the platform (synced from packages/workdo/*/module.json)
        Schema::create('add_ons', function (Blueprint $table) {
            $table->id();
            $table->string('module')->unique();       // folder / namespace name, e.g. "Hello"
            $table->string('name');                   // display alias
            $table->string('package_name')->nullable();
            $table->decimal('monthly_price', 10, 2)->default(0);
            $table->decimal('yearly_price', 10, 2)->default(0);
            $table->boolean('is_enable')->default(true);  // platform-wide switch (superadmin)
            $table->boolean('for_admin')->default(false); // superadmin-only module
            $table->integer('priority')->default(10);
            $table->timestamps();
        });

        // Which modules each company can use (granted by its plan)
        Schema::create('user_active_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('module');
            $table->timestamps();

            $table->unique(['user_id', 'module']);
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('monthly_price', 10, 2)->default(0);
            $table->decimal('yearly_price', 10, 2)->default(0);
            $table->integer('max_users')->default(5);       // -1 = unlimited
            $table->boolean('free_plan')->default(false);
            $table->boolean('trial')->default(false);
            $table->unsignedInteger('trial_days')->default(0);
            $table->json('modules')->nullable();            // ["Hello","Hrm",...]
            $table->boolean('is_disable')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['percentage', 'flat'])->default('percentage');
            $table->decimal('discount', 10, 2);
            $table->unsignedInteger('usage_limit')->nullable();  // total uses, null = unlimited
            $table->date('expiry_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('plan_name');
            $table->enum('duration', ['month', 'year', 'trial', 'free']);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('final_price', 10, 2)->default(0);
            $table->string('coupon_code')->nullable();
            $table->enum('payment_type', ['free', 'trial', 'bank_transfer']);
            $table->enum('payment_status', ['pending', 'paid', 'rejected'])->default('pending');
            $table->timestamps();
        });

        Schema::create('user_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_transfer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('attachment')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('response_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['bank_transfer_payments', 'user_coupons', 'orders', 'coupons', 'plans', 'user_active_modules', 'add_ons'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
