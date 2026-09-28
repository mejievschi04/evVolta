<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Services\ReportDocumentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportDocumentService $reportDocumentService,
    ) {
    }

    public function stationsDaily(Request $request): Response
    {
        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        $report = $this->reportDocumentService->stationsDaily(
            Carbon::createFromFormat('Y-m-d', $data['date'])->startOfDay()
        );

        return $this->pdfResponse($report['pdf'], $report['filename']);
    }

    public function stationsMonthly(Request $request): Response
    {
        $data = $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        $report = $this->reportDocumentService->stationsMonthly(
            Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth()
        );

        return $this->pdfResponse($report['pdf'], $report['filename']);
    }

    public function walletTopups(Request $request): Response
    {
        $data = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);

        $report = $this->reportDocumentService->walletTopups(
            Carbon::createFromFormat('Y-m-d', $data['from'])->startOfDay(),
            Carbon::createFromFormat('Y-m-d', $data['to'])->endOfDay(),
        );

        return $this->pdfResponse($report['pdf'], $report['filename']);
    }

    private function pdfResponse(string $pdf, string $filename): Response
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
