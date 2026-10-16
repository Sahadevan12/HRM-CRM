<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One table for every trade document; `type` = sales_invoice | purchase_invoice | sales_proposal | sales_return | purchase_return
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->index();
            $table->string('number', 30);
            $table->foreignId('party_id')->constrained('users')->restrictOnDelete(); // customer (client) or vendor
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('documents')->nullOnDelete(); // return -> invoice, invoice -> proposal
            $table->date('doc_date');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 14, 2)->default(0);        // sum of qty * price
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);    // subtotal - discount + tax
            $table->decimal('paid_amount', 14, 2)->default(0);     // maintained by the Account module
            $table->text('notes')->nullable();
            $table->text('reason')->nullable();                    // returns
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('creator_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['created_by', 'type', 'number']);
            $table->index(['created_by', 'type', 'status']);
        });

        Schema::create('document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('source_item_id')->nullable()->constrained('document_items')->nullOnDelete(); // return line -> invoice line
            $table->string('name');                                // snapshot, survives product renames
            $table->decimal('quantity', 14, 2);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);    // qty*price - discount + tax
            $table->timestamps();
        });

        Schema::create('document_item_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_item_id')->constrained('document_items')->cascadeOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained('product_taxes')->nullOnDelete();
            $table->string('name');                                // snapshot of the tax at the time of the document
            $table->decimal('rate', 6, 2);
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_item_taxes');
        Schema::dropIfExists('document_items');
        Schema::dropIfExists('documents');
    }
};
