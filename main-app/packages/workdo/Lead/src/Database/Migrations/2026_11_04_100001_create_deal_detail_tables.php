<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // what the deal is about: sources, labels and products (many to many)
        Schema::create('deal_sources', function (Blueprint $table) {
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('sources')->restrictOnDelete();
            $table->primary(['deal_id', 'source_id']);
        });

        Schema::create('deal_labels', function (Blueprint $table) {
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('labels')->restrictOnDelete();
            $table->primary(['deal_id', 'label_id']);
        });

        Schema::create('deal_products', function (Blueprint $table) {
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->primary(['deal_id', 'product_id']);
        });

        Schema::create('deal_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->string('name');
            $table->date('due_date');
            $table->time('due_time')->nullable();
            $table->string('priority', 10)->default('medium');             // low | medium | high
            $table->string('status', 10)->default('on_going');             // on_going | completed
            $this->owner($table);
        });

        Schema::create('deal_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->string('subject');
            $table->string('call_type', 10);                               // inbound | outbound
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->text('description')->nullable();
            $table->text('result')->nullable();
            $this->owner($table);
        });

        Schema::create('deal_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->string('to');
            $table->string('subject');
            $table->text('description')->nullable();
            $this->owner($table);
        });

        Schema::create('deal_discussions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->text('comment');
            $this->owner($table);
        });

        Schema::create('deal_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $this->owner($table);
        });
    }

    public function down(): void
    {
        foreach (['deal_files', 'deal_discussions', 'deal_emails', 'deal_calls', 'deal_tasks', 'deal_products', 'deal_labels', 'deal_sources'] as $t) {
            Schema::dropIfExists($t);
        }
    }

    private function owner(Blueprint $table): void
    {
        $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
        $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
        $table->timestamps();
    }
};
