<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // free | trial | bank_transfer | stripe | razorpay: a plain string, so a new payment method needs no migration
            $table->string('payment_type', 20)->change();

            // the id the gateway gave to this payment (Stripe checkout session / Razorpay order) and the final payment id
            $table->string('gateway_reference')->nullable()->after('payment_status')->index();
            $table->string('gateway_payment_id')->nullable()->after('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['gateway_reference', 'gateway_payment_id']);
        });
    }
};
