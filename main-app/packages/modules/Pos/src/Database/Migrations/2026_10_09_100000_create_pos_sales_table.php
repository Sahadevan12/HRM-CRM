<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A POS sale IS a (posted) sales invoice in `documents`; this row only adds the counter details.
        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained('documents')->cascadeOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_method', 20);            // cash | card | bank_transfer | credit (on account)
            $table->decimal('amount_paid', 14, 2)->default(0);   // what stays with us (never more than the total)
            $table->decimal('amount_tendered', 14, 2)->default(0); // what the customer handed over (cash can exceed the total)
            $table->decimal('change_due', 14, 2)->default(0);
            $table->foreignId('created_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sales');
    }
};
