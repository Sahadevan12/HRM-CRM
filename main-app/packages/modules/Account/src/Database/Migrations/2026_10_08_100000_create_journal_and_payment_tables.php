<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30);
            $table->date('journal_date');
            $table->string('entry_type', 10)->default('manual');         // automatic (from a document/payment) | manual
            $table->string('reference_type', 30)->nullable();            // sales_invoice, purchase_return, customer_payment ...
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description');
            $table->decimal('total_debit', 14, 2)->default(0);
            $table->decimal('total_credit', 14, 2)->default(0);
            $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['created_by', 'number']);
            // one automatic entry per source document: re-running a listener can never double-book
            $table->unique(['created_by', 'reference_type', 'reference_id']);
            $table->index(['created_by', 'journal_date']);
        });

        Schema::create('journal_entry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->timestamps();

            $table->index('account_id');
        });

        // A payment settles one posted invoice (customer payment -> sales invoice, vendor payment -> purchase invoice)
        Schema::create('account_payments', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);                                  // customer | vendor
            $table->foreignId('party_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete(); // paid documents are never deleted in practice (posted = immutable)
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete(); // bank / cash account
            $table->date('payment_date');
            $table->decimal('amount', 14, 2);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['created_by', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_payments');
        Schema::dropIfExists('journal_entry_items');
        Schema::dropIfExists('journal_entries');
    }
};
