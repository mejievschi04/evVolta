<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('series', 16)->nullable()->after('invoice_number');
            $table->string('line_description')->nullable()->after('sessions_count');
            $table->string('unit', 16)->nullable()->after('line_description');
            $table->decimal('quantity', 12, 3)->nullable()->after('unit');
            $table->decimal('unit_price', 12, 4)->nullable()->after('quantity');
            $table->decimal('vat_rate', 5, 2)->nullable()->after('unit_price');
            $table->decimal('amount_net', 12, 2)->nullable()->after('vat_rate');
            $table->decimal('amount_vat', 12, 2)->nullable()->after('amount_net');
            $table->string('buyer_name')->nullable()->after('amount_vat');
            $table->string('buyer_email')->nullable()->after('buyer_name');
            $table->string('buyer_idno', 32)->nullable()->after('buyer_email');
            $table->string('seller_name')->nullable()->after('buyer_idno');
            $table->string('seller_idno', 32)->nullable()->after('seller_name');
            $table->string('seller_vat_code', 64)->nullable()->after('seller_idno');
            $table->timestamp('issued_at')->nullable()->after('seller_vat_code');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'series',
                'line_description',
                'unit',
                'quantity',
                'unit_price',
                'vat_rate',
                'amount_net',
                'amount_vat',
                'buyer_name',
                'buyer_email',
                'buyer_idno',
                'seller_name',
                'seller_idno',
                'seller_vat_code',
                'issued_at',
            ]);
        });
    }
};
