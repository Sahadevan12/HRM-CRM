<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $this->tenant($table);

            $table->unique(['created_by', 'name']);
        });

        // stages are ordered per pipeline (`order` is 1..n, rewritten by the drag and drop endpoint)
        foreach (['lead_stages', 'deal_stages'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
                $table->unsignedInteger('order')->default(0);
                $this->tenant($table);

                $table->index(['pipeline_id', 'order']);
            });
        }

        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 7)->default('#64748b');                // #rrggbb
            $table->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $this->tenant($table);
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $this->tenant($table);

            $table->unique(['created_by', 'name']);
        });
    }

    public function down(): void
    {
        foreach (['sources', 'labels', 'deal_stages', 'lead_stages', 'pipelines'] as $t) {
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
