<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Services\ReportDocumentService;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportDocumentService $reportDocumentService,
    ) {
    }

    public function stationsMonthly(Request $request): Response
    {
        $data = $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        $report = $this->reportDocumentService->stationsMonthly(
            LocalTime::month($data['month'])
        );

        return $this->xlsxResponse($report['content'], $report['filename']);
    }

    public function chargingSessions(Request $request): Response
    {
        $data = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);
        $report = $this->reportDocumentService->chargingSessions(
            LocalTime::day($data['from']), LocalTime::day($data['to'])
        );

        return $this->xlsxResponse($report['content'], $report['filename']);
    }

    public function walletTopups(Request $request): Response
    {
        $data = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);

        $report = $this->reportDocumentService->walletTopups(
            LocalTime::day($data['from']),
            LocalTime::day($data['to'])->endOfDay(),
        );

        return $this->xlsxResponse($report['content'], $report['filename']);
    }

    private function xlsxResponse(string $content, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
