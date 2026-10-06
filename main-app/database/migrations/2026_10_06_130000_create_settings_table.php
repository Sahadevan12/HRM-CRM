<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // key/value settings per tenant. superadmin rows = platform (admin) settings.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->longText('value')->nullable();
            $table->boolean('is_public')->default(true); // public = safe to expose to guests (login page branding)
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['key', 'created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
