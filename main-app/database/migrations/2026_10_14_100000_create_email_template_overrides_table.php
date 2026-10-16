<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The templates themselves live in code (modules register them); a company only stores what it CHANGED
        Schema::create('email_template_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80);
            $table->string('subject');
            $table->text('body');
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['created_by', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_overrides');
    }
};
