<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'billing:generate-monthly';
    protected $description = 'Generate monthly invoices for all users';

    public function __construct(private readonly BillingService $billingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = $this->billingService->generateMonthlyInvoices();

        $this->info("Monthly invoices generated: {$count}");

        return self::SUCCESS;
    }
}
