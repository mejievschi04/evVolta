<?php

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\WalletTopup;
use App\Support\LocalTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Backoffice reports as Excel workbooks: seller header, then real date / number cells
 * so the data can be sorted, filtered and summed in Excel.
 */
class ReportDocumentService
{
    private int $reportRowIndex = 0;

    private int $reportColumnCount = 0;

    private const FORMAT_MONEY = '#,##0.00';

    private const FORMAT_KWH = '#,##0.000';

    /**
     * Charging per day × station for one month.
     *
     * @return array{content: string, filename: string}
     */
    public function stationsMonthly(Carbon $month): array
    {
        $month = LocalTime::of($month);
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $rows = $this->stationDayAggregates($from, $to);

        $bold = (new Style())->setFontBold();
        $header = $this->headerStyle();
        $money = (new Style())->setFormat(self::FORMAT_MONEY);
        $moneyBold = (new Style())->setFontBold()->setFormat(self::FORMAT_MONEY);
        $kwh = (new Style())->setFormat(self::FORMAT_KWH);
        $kwhBold = (new Style())->setFontBold()->setFormat(self::FORMAT_KWH);
        $integerBold = (new Style())->setFontBold();
        $date = (new Style())->setFormat('dd.mm.yyyy');

        $content = $this->writeWorkbook(
            [14, 30, 40, 12, 14, 22],
            'RAPORT LUNAR PE STATII',
            'Perioada: '.$month->translatedFormat('F Y'),
            function (Writer $writer) use ($rows, $bold, $header, $money, $moneyBold, $kwh, $kwhBold, $integerBold, $date): void {
                $this->writeStyledRow($writer, Row::fromValues(['Data', 'Statie', 'Adresa statiei', 'Sesiuni', 'kWh', 'Venit facturat (MDL)'], $header));

                foreach ($rows as $row) {
                    $this->writeStyledRow($writer, new Row([
                        $row['date'] !== 'unknown'
                            ? Cell::fromValue(new \DateTimeImmutable($row['date']), $date)
                            : Cell::fromValue('-'),
                        Cell::fromValue($row['station_name']),
                        Cell::fromValue($row['station_address']),
                        Cell::fromValue($row['sessions_count']),
                        Cell::fromValue($row['total_kwh'], $kwh),
                        Cell::fromValue($row['revenue'], $money),
                    ]));
                }

                if ($rows->isEmpty()) {
                    $this->writeStyledRow($writer, Row::fromValues(['Nicio sesiune in interval.']));
                }

                $this->writeStyledRow($writer, new Row([
                    Cell::fromValue('Total luna', $bold),
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                    Cell::fromValue((int) $rows->sum('sessions_count'), $integerBold),
                    Cell::fromValue(round((float) $rows->sum('total_kwh'), 3), $kwhBold),
                    Cell::fromValue(round((float) $rows->sum('revenue'), 2), $moneyBold),
                ]));

                $byStation = $rows->groupBy(fn ($row) => $row['station_id'] ?? 'unknown');
                if ($byStation->isNotEmpty()) {
                    $this->writeStyledRow($writer, Row::fromValues([]));
                    $this->writeStyledRow($writer, Row::fromValues(['Pe statii', '', 'Adresa statiei', 'Sesiuni', 'kWh', 'Venit facturat (MDL)'], $header));
                    foreach ($byStation as $station => $group) {
                        $this->writeStyledRow($writer, new Row([
                            Cell::fromValue($group->first()['station_name']),
                            Cell::fromValue(''),
                            Cell::fromValue($group->first()['station_address']),
                            Cell::fromValue((int) $group->sum('sessions_count')),
                            Cell::fromValue(round((float) $group->sum('total_kwh'), 3), $kwh),
                            Cell::fromValue(round((float) $group->sum('revenue'), 2), $money),
                        ]));
                    }
                }
            },
        );

        return [
            'content' => $content,
            'filename' => sprintf('raport-statii-lunar-%s.xlsx', $month->format('Y-m')),
        ];
    }

    public function chargingSessions(Carbon $from, Carbon $to): array
    {
        $from = LocalTime::of($from)->startOfDay();
        $to = LocalTime::of($to)->endOfDay();
        $sessions = ChargingSession::query()
            ->with(['user' => fn ($query) => $query->withTrashed(), 'station', 'invoice'])
            ->whereNotNull('end_time')
            ->whereBetween('end_time', [LocalTime::utc($from), LocalTime::utc($to)])
            ->orderBy('end_time')->orderBy('id')->get();
        $money = (new Style())->setFormat(self::FORMAT_MONEY);
        $bold = (new Style())->setFontBold();
        $moneyBold = (new Style())->setFontBold()->setFormat(self::FORMAT_MONEY);
        $quantity = (new Style())->setFormat(self::FORMAT_KWH);
        $quantityBold = (new Style())->setFontBold()->setFormat(self::FORMAT_KWH);
        $tariff = (new Style())->setFormat('#,##0');
        $date = (new Style())->setFormat('dd.mm.yyyy hh:mm');
        $content = $this->writeWorkbook(
            [22, 14, 24, 46, 18, 16, 22, 20, 22],
            'RAPORT ALIMENTARI MASINI',
            sprintf('Perioada: %s – %s', $from->format('d.m.Y'), $to->format('d.m.Y')),
            function (Writer $writer) use ($sessions, $money, $bold, $moneyBold, $date, $quantity, $quantityBold, $tariff): void {
                $this->writeStyledRow($writer, Row::fromValues(['Data', 'ID client', 'Client', 'Statia', 'Unitatea de masura', 'Cantitatea', 'Denumirea tarifului', 'Tarif (MDL/kWh)', 'Suma totala (MDL)'], $this->headerStyle()));
                $total = 0.0;
                $kwh = 0.0;
                foreach ($sessions as $session) {
                    // Only invoiced amounts are fiscal revenue; never infer a payment method from wallet top-ups.
                    $tariffType = $session->tariff_type ?? $session->user?->account_type;
                    $amount = $session->invoice?->invoice_type === 'session'
                        ? (float) $session->invoice->total_amount
                        : ($tariffType === \App\Models\User::ACCOUNT_TYPE_SERVICE ? 0.0 : null);
                    $total += $amount ?? 0.0;
                    $kwh += (float) $session->kwh_consumed;
                    $station = $session->station;
                    $stationLabel = $station?->name ?? 'Fara statie';
                    if (filled($station?->location)) {
                        $stationLabel .= ' · '.$station->location;
                    }
                    $this->writeStyledRow($writer, new Row([
                        Cell::fromValue(new \DateTimeImmutable(LocalTime::of($session->end_time)->format('Y-m-d H:i:s')), $date),
                        Cell::fromValue($session->user?->public_id),
                        Cell::fromValue($session->user?->name ?? 'Fara client'),
                        Cell::fromValue($stationLabel),
                        Cell::fromValue('kWh'),
                        Cell::fromValue((float) $session->kwh_consumed, $quantity),
                        Cell::fromValue(match ($tariffType) {
                            \App\Models\User::ACCOUNT_TYPE_SERVICE => 'Serviciu',
                            \App\Models\User::ACCOUNT_TYPE_CUSTOMER => 'Client',
                            \App\Models\User::ACCOUNT_TYPE_PERSONAL => 'Personal',
                            default => 'Nespecificat',
                        }),
                        Cell::fromValue($tariffType === \App\Models\User::ACCOUNT_TYPE_SERVICE ? 0.0 : ($session->price_per_kwh ?? 'Nespecificat'), $tariff),
                        Cell::fromValue($amount ?? 'Nefacturat', $money),
                    ]));
                }
                if ($sessions->isEmpty()) {
                    $this->writeStyledRow($writer, Row::fromValues(['Nicio alimentare in interval.']));
                }
                $this->writeStyledRow($writer, new Row([
                    Cell::fromValue(sprintf('Total (%d alimentari)', $sessions->count()), $bold),
                    Cell::fromValue(''), Cell::fromValue(''), Cell::fromValue(''),
                    Cell::fromValue('kWh', $bold),
                    Cell::fromValue(round($kwh, 3), $quantityBold),
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                    Cell::fromValue(round($total, 2), $moneyBold),
                ]));
            }
        );

        return ['content' => $content, 'filename' => sprintf('raport-alimentari-masini-%s-%s.xlsx', $from->format('Ymd'), $to->format('Ymd'))];
    }

    /**
     * One row per paid top-up.
     *
     * @return array{content: string, filename: string}
     */
    public function walletTopups(Carbon $from, Carbon $to): array
    {
        $from = LocalTime::of($from)->startOfDay();
        $to = LocalTime::of($to)->endOfDay();
        $rows = $this->topupRows($from, $to);

        $bold = (new Style())->setFontBold();
        $header = $this->headerStyle();
        $money = (new Style())->setFormat(self::FORMAT_MONEY);
        $moneyBold = (new Style())->setFontBold()->setFormat(self::FORMAT_MONEY);
        $date = (new Style())->setFormat('dd.mm.yyyy hh:mm');
        $day = (new Style())->setFormat('dd.mm.yyyy');

        $content = $this->writeWorkbook(
            [8, 16, 24, 16, 28, 18, 22],
            'RAPORT ALIMENTARI CONT',
            sprintf('Perioada: %s – %s', $from->format('d.m.Y'), $to->format('d.m.Y')),
            function (Writer $writer) use ($rows, $bold, $header, $money, $moneyBold, $date, $day): void {
                $index = 0;
                $byMethod = $rows->groupBy('method')->sortKeys();
                foreach ($byMethod as $method => $group) {
                    $this->writeStyledRow($writer, Row::fromValues(['Plati '.$method], $header));
                    $this->writeStyledRow($writer, Row::fromValues(['Nr.', 'Data', 'Data achitarii', 'ID client', 'Client', 'Metoda', 'Suma (MDL)'], $header));
                    foreach ($group as $row) {
                        $this->writeStyledRow($writer, new Row([
                            Cell::fromValue(++$index),
                            Cell::fromValue($row['paid_at_local']->setTime(0, 0), $day),
                            Cell::fromValue($row['paid_at_local'], $date),
                            Cell::fromValue($row['user_public_id']),
                            Cell::fromValue($row['user_name']),
                            Cell::fromValue($row['method']),
                            Cell::fromValue($row['amount'], $money),
                        ]));
                    }
                    $this->writeStyledRow($writer, new Row([
                        Cell::fromValue('Subtotal '.$method, $bold),
                        Cell::fromValue(''),
                        Cell::fromValue(''),
                        Cell::fromValue(''),
                        Cell::fromValue(''),
                        Cell::fromValue(''),
                        Cell::fromValue(round((float) $group->sum('amount'), 2), $moneyBold),
                    ]));
                    $this->writeStyledRow($writer, Row::fromValues([]));
                }

                if ($rows->isEmpty()) {
                    $this->writeStyledRow($writer, Row::fromValues(['Nicio alimentare in interval.']));
                }

                $this->writeStyledRow($writer, new Row([
                    Cell::fromValue(sprintf('Suma totala (%d alimentari)', $rows->count()), $bold),
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                    Cell::fromValue(''),
                    Cell::fromValue(round((float) $rows->sum('amount'), 2), $moneyBold),
                ]));

                $byMethod = $rows->groupBy('method')->sortKeys();
                if ($byMethod->isNotEmpty()) {
                    $this->writeStyledRow($writer, Row::fromValues([]));
                    $this->writeStyledRow($writer, Row::fromValues(['Pe metode de plata', '', '', '', '', 'Alimentari', 'Suma (MDL)'], $header));
                    foreach ($byMethod as $method => $group) {
                        $this->writeStyledRow($writer, new Row([
                            Cell::fromValue((string) $method),
                            Cell::fromValue(''),
                            Cell::fromValue(''),
                            Cell::fromValue(''),
                            Cell::fromValue(''),
                            Cell::fromValue($group->count()),
                            Cell::fromValue(round((float) $group->sum('amount'), 2), $money),
                        ]));
                    }
                }
            },
        );

        return [
            'content' => $content,
            'filename' => sprintf('raport-alimentari-%s-%s.xlsx', $from->format('Ymd'), $to->format('Ymd')),
        ];
    }

    /**
     * One row per calendar day × station (sessions closed that day).
     *
     * @return Collection<int, array{date: string, station_id: int|null, station_name: string, sessions_count: int, total_kwh: float, revenue: float}>
     */
    private function stationDayAggregates(Carbon $from, Carbon $to): Collection
    {
        $sessions = ChargingSession::query()
            ->with(['station:id,name,location', 'invoice:id,source_session_id,total_amount,invoice_type'])
            ->whereNotNull('end_time')
            ->whereBetween('end_time', [LocalTime::utc($from), LocalTime::utc($to)])
            ->get();

        $grouped = $sessions->groupBy(function (ChargingSession $session) {
            $day = LocalTime::of($session->end_time)?->format('Y-m-d') ?? 'unknown';
            $stationId = (int) ($session->station_id ?? 0);

            return $day.'|'.$stationId;
        });

        return $grouped->map(function (Collection $group, string $key) {
            [$day, $stationKey] = explode('|', $key, 2);
            $stationId = (int) $stationKey;
            $name = $stationId > 0
                ? (string) ($group->first()?->station?->name ?? 'Statie #'.$stationId)
                : 'Fara statie';

            $revenue = round($group->sum(function (ChargingSession $session) {
                $invoice = $session->invoice;
                if ($invoice && $invoice->invoice_type === 'session') {
                    return (float) $invoice->total_amount;
                }

                return 0.0;
            }), 2);

            return [
                'date' => $day,
                'station_id' => $stationId > 0 ? $stationId : null,
                'station_name' => $name,
                'station_address' => (string) ($group->first()?->station?->location ?? ''),
                'sessions_count' => $group->count(),
                'total_kwh' => round((float) $group->sum('kwh_consumed'), 3),
                'revenue' => $revenue,
            ];
        })
            ->sortBy([
                ['date', 'asc'],
                ['station_name', 'asc'],
            ])
            ->values();
    }

    /**
     * One row per paid top-up, in payment order (local time).
     *
     * @return Collection<int, array{id: int, paid_at: string, paid_at_local: \DateTimeImmutable, user_name: string, user_email: string, method: string, amount: float}>
     */
    private function topupRows(Carbon $from, Carbon $to): Collection
    {
        return WalletTopup::query()
            ->with('user:id,public_id,name,email')
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [LocalTime::utc($from), LocalTime::utc($to)])
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get()
            ->map(fn (WalletTopup $topup) => [
                'id' => (int) $topup->id,
                'paid_at' => LocalTime::of($topup->paid_at)->format('d.m.Y H:i'),
                // Local wall-clock time as a naive date: Excel cells carry no timezone.
                'paid_at_local' => new \DateTimeImmutable(LocalTime::of($topup->paid_at)->format('Y-m-d H:i:s')),
                'user_name' => (string) ($topup->user?->name ?? ($topup->user_id ? 'User #'.$topup->user_id : 'Fara utilizator')),
                'user_public_id' => $topup->user?->public_id,
                'user_email' => (string) ($topup->user?->email ?? ''),
                'method' => WalletTopup::paymentMethodLabel($topup->payment_method),
                'amount' => round((float) $topup->amount, 2),
            ])
            ->values();
    }

    /**
     * Seller header, title and period, then the report body; returns the .xlsx bytes.
     *
     * Labels always start in column A (text flows over empty cells): some viewers stop
     * rendering at rows whose first cell is further right.
     *
     * @param  list<float>  $columnWidths
     * @param  callable(Writer): void  $body
     */
    private function writeWorkbook(array $columnWidths, string $title, string $period, callable $body): string
    {
        $seller = $this->seller();

        $this->reportRowIndex = 0;
        $this->reportColumnCount = count($columnWidths);
        $options = new Options();
        $options->DEFAULT_ROW_STYLE = $this->bodyStyle();
        $options->setPageSetup(new \OpenSpout\Writer\XLSX\Options\PageSetup(
            \OpenSpout\Writer\XLSX\Options\PageOrientation::LANDSCAPE,
            \OpenSpout\Writer\XLSX\Options\PaperSize::A4,
            fitToHeight: 0,
            fitToWidth: 1,
        ));
        foreach ($columnWidths as $index => $width) {
            $options->setColumnWidth($width, $index + 1);
        }

        $path = tempnam(sys_get_temp_dir(), 'report');
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Raport');
        $writer->getCurrentSheet()->setSheetView(
            (new \OpenSpout\Writer\XLSX\Entity\SheetView())->setShowGridLines(false)
        );

        try {
            $this->writeStyledRow($writer, Row::fromValues([mb_strtoupper($seller['name'])], (new Style())->setFontBold()));
            $this->writeStyledRow($writer, Row::fromValues([]));
            $this->writeStyledRow($writer, Row::fromValues([$title], (new Style())->setFontBold()->setFontSize(18)->setFontColor('17324D')));
            $this->writeStyledRow($writer, Row::fromValues([sprintf(
                '%s · Generat: %s · V Charge',
                $period,
                LocalTime::now()->format('d.m.Y H:i')
            )]));
            $this->writeStyledRow($writer, Row::fromValues([]));

            $body($writer);
        } finally {
            $writer->close();
        }

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    private function bodyStyle(): Style
    {
        return (new Style())->setFontName('Calibri')->setFontSize(11)->setFontColor('000000')
            ->setCellAlignment(\OpenSpout\Common\Entity\Style\CellAlignment::CENTER)
            ->setCellVerticalAlignment(\OpenSpout\Common\Entity\Style\CellVerticalAlignment::CENTER)
            ->setShouldWrapText();
    }

    private function writeStyledRow(Writer $writer, Row $row): void
    {
        $rowNumber = ++$this->reportRowIndex;
        $cells = $row->getCells();
        if ($cells === []) {
            $row->setHeight(10);
            $writer->addRow($row);

            return;
        }
        $first = $cells[0]->getValue();
        $label = is_string($first) ? $first : '';
        $isTotal = preg_match('/^(Subtotal |Total|Suma totala)/', $label) === 1;
        $isSection = str_starts_with($label, 'Plati ');
        $isHeader = $row->getStyle()->getBackgroundColor() !== null;
        $style = clone $row->getStyle();
        $style->setFontName('Calibri')
            ->setCellAlignment(\OpenSpout\Common\Entity\Style\CellAlignment::CENTER)
            ->setCellVerticalAlignment(\OpenSpout\Common\Entity\Style\CellVerticalAlignment::CENTER)
            ->setShouldWrapText();
        $row->setHeight($rowNumber <= 4 ? 30 : ($isHeader ? 40 : 36));
        if ($isTotal) {
            $style->setFontBold()->setFontColor('000000')->setBackgroundColor('FFFFFF');
            $row->setHeight(34);
            $firstValueColumn = 1;
            while ($firstValueColumn < count($cells) && $cells[$firstValueColumn]->getValue() === '') {
                $firstValueColumn++;
            }
            if ($firstValueColumn > 1) {
                $writer->getOptions()->mergeCells(0, $rowNumber, $firstValueColumn - 1, $rowNumber);
            }
        }
        if (count($cells) === 1) {
            $writer->getOptions()->mergeCells(0, $rowNumber, $this->reportColumnCount - 1, $rowNumber);
            if ($isSection) {
                $style->setBackgroundColor('FFFFFF')->setFontColor('000000')->setFontBold();
                $row->setHeight(28);
            }
        } else {
            $style->setBorder(new \OpenSpout\Common\Entity\Style\Border(
                new \OpenSpout\Common\Entity\Style\BorderPart('top', 'BFBFBF', 'thin', 'solid'),
                new \OpenSpout\Common\Entity\Style\BorderPart('bottom', 'BFBFBF', 'thin', 'solid'),
                new \OpenSpout\Common\Entity\Style\BorderPart('left', 'BFBFBF', 'thin', 'solid'),
                new \OpenSpout\Common\Entity\Style\BorderPart('right', 'BFBFBF', 'thin', 'solid')
            ));
        }
        $row->setStyle($style);
        $writer->addRow($row);
    }

    private function headerStyle(): Style
    {
        return $this->bodyStyle()->setFontBold()->setFontColor('000000')->setBackgroundColor('F2F2F2');
    }

    /**
     * Same seller identity as on invoices (INVOICE_SELLER_* / LEGAL_COMPANY_*).
     *
     * @return array{name: string, idno: string}
     */
    private function seller(): array
    {
        $snapshot = app(InvoiceFiscalCalculator::class)->sellerSnapshot();

        return [
            'name' => $snapshot['seller_name'] !== '' ? $snapshot['seller_name'] : (string) config('legal.company_name', 'Volta SRL'),
            'idno' => $snapshot['seller_idno'] !== '' ? $snapshot['seller_idno'] : (string) config('legal.company_idno', ''),
        ];
    }
}
