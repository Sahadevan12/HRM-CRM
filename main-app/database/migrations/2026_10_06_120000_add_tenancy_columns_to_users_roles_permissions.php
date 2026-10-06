<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('email');
            $table->string('mobile_no', 20)->nullable()->after('slug');
            $table->string('type')->default('company')->index()->after('mobile_no'); // superadmin|company|staff|client|vendor
            $table->string('avatar')->nullable();
            $table->string('lang', 10)->default('en');
            $table->unsignedBigInteger('active_plan')->default(0);
            $table->date('plan_expire_date')->nullable();
            $table->date('trial_expire_date')->nullable();
            $table->boolean('is_trial_done')->default(false);
            $table->integer('total_user')->default(0); // -1 = unlimited
            $table->boolean('is_disable')->default(false);
            $table->boolean('is_enable_login')->default(true);
            $table->boolean('active_status')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->cascadeOnDelete();
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->string('label')->nullable()->after('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('creator_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index(); // tenant; null = system role
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->string('module')->nullable()->index();   // grouping in the permission matrix
            $table->string('label')->nullable();
            $table->string('add_on')->nullable()->index();   // owning module (null = core)
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['module', 'label', 'add_on']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['label', 'description', 'creator_id', 'created_by']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('creator_id');
            $table->dropColumn([
                'slug', 'mobile_no', 'type', 'avatar', 'lang', 'active_plan', 'plan_expire_date',
                'trial_expire_date', 'is_trial_done', 'total_user', 'is_disable', 'is_enable_login',
                'active_status', 'last_seen_at',
            ]);
        });
    }
};
