<?php

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * PersonExportService
 *
 * Builds a professional personal analytics XLSX workbook for the Identity Detail page.
 *
 * Sheets:
 *   1. Summary          — KPIs, focus score card, computed insights
 *   2. Daily Breakdown  — per-day table (Working / Phone / Inactive / Total / Focus)
 *   3. Activity Log     — sequential event segments, cleaned activity names
 *   4. Alerts           — severity-coded highlights / anomalies
 *   5. Timeline Gantt   — grid with 30-min coloured slots, legend
 */
class PersonExportService
{
    // ── Brand palette ─────────────────────────────────────────────────────────
    private const HDR_BG    = '1A3A5C';
    private const HDR_FG    = 'FFFFFF';
    private const SEC_BG    = '2C5F8A';
    private const ACCENT_BG = 'E8F0FB';
    private const ALT_BG    = 'F4F7FC';
    private const CRIT_BG   = 'F8D7DA';
    private const CRIT_FG   = '721C24';
    private const WARN_BG   = 'FFF3CD';
    private const WARN_FG   = '856404';
    private const OK_BG     = 'D4EDDA';
    private const OK_FG     = '155724';
    private const INFO_BG   = 'D1ECF1';
    private const INFO_FG   = '0C5460';

    // ── Gantt activity colours ─────────────────────────────────────────────────
    private const GANTT_COLORS = [
        'Working'     => '27AE60',   // green
        'Using_Phone' => 'E74C3C',   // red
        'Inactive'    => 'E67E22',   // orange
    ];
    private const GANTT_DEFAULT = '95A5A6';  // grey

    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * @param string $identityName  Employee display name
     * @param string $start         Period start (ISO datetime)
     * @param string $end           Period end   (ISO datetime)
     * @param array  $summary       From SurveillanceAnalyticsService::summary()
     * @param array  $daily         From SurveillanceAnalyticsService::daily()['daily']
     * @param array  $segments      From SurveillanceAnalyticsService::timeline()['segments']
     * @param array  $highlights    From EmployeeHighlightsService::highlights()['highlights']
     */
    public function build(
        string $identityName,
        string $start,
        string $end,
        array  $summary,
        array  $daily,
        array  $segments,
        array  $highlights,
    ): Spreadsheet {
        $ss = new Spreadsheet();
        $ss->getProperties()
            ->setCreator('MQ Monitoring')
            ->setTitle("{$identityName} — Personal Report")
            ->setDescription("Period: {$start} — {$end}");

        $this->buildSummarySheet($ss->getActiveSheet(), $identityName, $start, $end, $summary, $daily);
        $this->buildDailySheet($ss->createSheet(), $daily);
        $this->buildTimelineSheet($ss->createSheet(), $segments);
        $this->buildAlertsSheet($ss->createSheet(), $highlights);
        $this->buildGanttSheet($ss->createSheet(), $segments);

        $ss->setActiveSheetIndex(0);
        return $ss;
    }

    // ── Sheet 1: Summary ─────────────────────────────────────────────────────

    private function buildSummarySheet(
        Worksheet $ws,
        string    $name,
        string    $start,
        string    $end,
        array     $summary,
        array     $daily,
    ): void {
        $ws->setTitle('Summary');

        $acts     = $summary['activities'] ?? [];
        $totalSec = (float) ($summary['total_sec'] ?? 0);
        $working  = (float) ($acts['Working']     ?? 0);
        $phone    = (float) ($acts['Using_Phone'] ?? 0);
        $inactive = (float) ($acts['Inactive']    ?? 0);
        $evtCount = (int)   ($summary['event_count'] ?? 0);
        $base     = $working + $phone + $inactive;
        $focus    = $base > 0 ? (int) round($working / $base * 100) : null;
        [$focusBg, $focusFg, $focusLabel] = $this->focusTier($focus);

        $ws->getColumnDimension('A')->setWidth(30);
        $ws->getColumnDimension('B')->setWidth(20);
        $ws->getColumnDimension('C')->setWidth(18);

        // Banner
        $ws->mergeCells('A1:C1');
        $ws->setCellValue('A1', strtoupper($name) . '  —  PERSONAL ANALYTICS REPORT');
        $this->applyStyle($ws, 'A1:C1', [
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF' . self::HDR_FG]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::HDR_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $ws->getRowDimension(1)->setRowHeight(32);

        $this->writeMetaRow($ws, 2, 'Reporting Period', $start . '  →  ' . $end);
        $this->writeMetaRow($ws, 3, 'Generated',        now()->format('Y-m-d H:i'));

        $ws->getRowDimension(4)->setRowHeight(8);

        // Focus score KPI card
        $ws->mergeCells('A5:C5');
        $ws->setCellValue('A5', 'FOCUS SCORE');
        $this->applySectionTitle($ws, 'A5:C5');

        $ws->setCellValue('A6', $focus !== null ? $focus . '%' : '—');
        $ws->setCellValue('B6', $focusLabel);
        $ws->mergeCells('A6:A6');
        $this->applyStyle($ws, 'A6', [
            'font'      => ['bold' => true, 'size' => 22, 'color' => ['argb' => 'FF' . $focusFg]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $focusBg]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $this->applyStyle($ws, 'B6', [
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FF' . $focusFg]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $focusBg]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $ws->getRowDimension(6)->setRowHeight(36);

        $ws->getRowDimension(7)->setRowHeight(8);

        // KPI table
        $ws->mergeCells('A8:C8');
        $ws->setCellValue('A8', 'KEY PERFORMANCE INDICATORS');
        $this->applySectionTitle($ws, 'A8:C8');

        $ws->setCellValue('A9', 'Metric');
        $ws->setCellValue('B9', 'Value');
        $ws->setCellValue('C9', '% of Core Time');
        $this->applyHeaderRow($ws, 'A9:C9');
        $ws->freezePane('A10');

        $kpis = [
            ['Total Observed Time',  $this->fmt($totalSec), null],
            ['Total Event Segments', number_format($evtCount),    null],
            ['Working Time',         $this->fmt($working),  $base > 0 ? round($working  / $base * 100, 1) . '%' : '—'],
            ['Phone Usage',          $this->fmt($phone),    $base > 0 ? round($phone    / $base * 100, 1) . '%' : '—'],
            ['Inactive Time',        $this->fmt($inactive), $base > 0 ? round($inactive / $base * 100, 1) . '%' : '—'],
        ];

        $row = 10;
        foreach ($kpis as $i => [$label, $val, $pct]) {
            $ws->setCellValue("A{$row}", $label);
            $ws->setCellValue("B{$row}", $val);
            $ws->setCellValue("C{$row}", $pct ?? '');
            $this->applyStyle($ws, "B{$row}", ['font' => ['bold' => true]]);
            if ($i % 2 === 1) {
                $this->applyStyle($ws, "A{$row}:C{$row}", $this->altRowStyle());
            }
            $row++;
        }
        $this->applyStyle($ws, 'A9:C' . ($row - 1), ['borders' => $this->thinBorders()]);

        $ws->getRowDimension($row)->setRowHeight(8);
        $row++;

        // Insights
        $ws->mergeCells("A{$row}:C{$row}");
        $ws->setCellValue("A{$row}", 'INSIGHTS');
        $this->applySectionTitle($ws, "A{$row}:C{$row}");
        $row++;

        foreach ($this->computePersonInsights($focus, $working, $phone, $inactive, $totalSec, $daily) as $insight) {
            $ws->mergeCells("A{$row}:C{$row}");
            $ws->setCellValue("A{$row}", '• ' . $insight['text']);
            $this->applyStyle($ws, "A{$row}:C{$row}", [
                'font'      => ['size' => 10, 'bold' => $insight['bold']],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::ACCENT_BG]],
                'alignment' => ['wrapText' => true, 'horizontal' => Alignment::HORIZONTAL_LEFT],
            ]);
            $ws->getRowDimension($row)->setRowHeight(20);
            $row++;
        }
    }

    // ── Sheet 2: Daily Breakdown ──────────────────────────────────────────────

    private function buildDailySheet(Worksheet $ws, array $daily): void
    {
        $ws->setTitle('Daily Breakdown');

        $ws->mergeCells('A1:G1');
        $ws->setCellValue('A1', 'DAILY ACTIVITY BREAKDOWN');
        $this->applyBanner($ws, 'A1:G1', 12);

        $headers = ['Date', 'Working', 'Phone Usage', 'Inactive', 'Other', 'Total', 'Focus Score'];
        $col = 'A';
        foreach ($headers as $h) {
            $ws->setCellValue("{$col}2", $h);
            $col++;
        }
        $this->applyHeaderRow($ws, 'A2:G2');
        $ws->setAutoFilter('A2:G2');
        $ws->freezePane('A3');

        $ws->getColumnDimension('A')->setWidth(14);
        foreach (range('B', 'G') as $c) {
            $ws->getColumnDimension($c)->setWidth(14);
        }

        $row = 3;
        foreach ($daily as $i => $day) {
            $date     = $day['date']      ?? '';
            $acts     = $day['activities'] ?? [];
            $total    = (float) ($day['total_sec'] ?? 0);
            $working  = (float) ($acts['Working']     ?? 0);
            $phone    = (float) ($acts['Using_Phone'] ?? 0);
            $inactive = (float) ($acts['Inactive']    ?? 0);
            $other    = $total - $working - $phone - $inactive;
            $base     = $working + $phone + $inactive;
            $focus    = $base > 0 ? (int) round($working / $base * 100) : null;
            [$focusBg, $focusFg] = array_slice($this->focusTier($focus), 0, 2);

            $ws->setCellValue("A{$row}", $date);
            $ws->setCellValue("B{$row}", $this->fmt($working));
            $ws->setCellValue("C{$row}", $this->fmt($phone));
            $ws->setCellValue("D{$row}", $this->fmt($inactive));
            $ws->setCellValue("E{$row}", $other > 0 ? $this->fmt($other) : '—');
            $ws->setCellValue("F{$row}", $this->fmt($total));
            $ws->setCellValue("G{$row}", $focus !== null ? $focus . '%' : '—');

            if ($i % 2 === 1) {
                $this->applyStyle($ws, "A{$row}:F{$row}", $this->altRowStyle());
            }

            $this->applyStyle($ws, "G{$row}", [
                'font'      => ['bold' => true, 'color' => ['argb' => 'FF' . $focusFg]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $focusBg]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $row++;
        }

        if ($row > 3) {
            $this->applyStyle($ws, 'A2:G' . ($row - 1), ['borders' => $this->thinBorders()]);
        }
    }

    // ── Sheet 3: Activity Log ─────────────────────────────────────────────────

    private function buildTimelineSheet(Worksheet $ws, array $segments): void
    {
        $ws->setTitle('Activity Log');

        $ws->mergeCells('A1:F1');
        $ws->setCellValue('A1', 'ACTIVITY LOG — EVENT SEGMENTS');
        $this->applyBanner($ws, 'A1:F1', 12);

        $headers = ['#', 'Activity', 'Start Time', 'End Time', 'Duration', 'Camera'];
        $col = 'A';
        foreach ($headers as $h) {
            $ws->setCellValue("{$col}2", $h);
            $col++;
        }
        $this->applyHeaderRow($ws, 'A2:F2');
        $ws->setAutoFilter('A2:F2');
        $ws->freezePane('A3');

        $ws->getColumnDimension('A')->setWidth(6);
        $ws->getColumnDimension('B')->setWidth(18);
        $ws->getColumnDimension('C')->setWidth(20);
        $ws->getColumnDimension('D')->setWidth(20);
        $ws->getColumnDimension('E')->setWidth(14);
        $ws->getColumnDimension('F')->setWidth(16);

        $row = 3;
        foreach ($segments as $i => $seg) {
            $rawAct   = $seg['activity']  ?? '';
            $act      = $this->cleanName($rawAct);
            $startTs  = $seg['start_time'] ?? '';
            $endTs    = $seg['end_time']   ?? '';
            $dur      = (float) ($seg['duration_sec'] ?? 0);
            $camera   = $seg['camera_id'] ?? $seg['camera'] ?? '—';

            $ws->setCellValue("A{$row}", $i + 1);
            $ws->setCellValue("B{$row}", $act);
            $ws->setCellValue("C{$row}", $startTs);
            $ws->setCellValue("D{$row}", $endTs);
            $ws->setCellValue("E{$row}", $this->fmt($dur));
            $ws->setCellValue("F{$row}", $camera);

            if ($i % 2 === 1) {
                $this->applyStyle($ws, "A{$row}:F{$row}", $this->altRowStyle());
            }

            // Colour-code the Activity cell by type
            $actColor = self::GANTT_COLORS[$rawAct] ?? self::GANTT_DEFAULT;
            $this->applyStyle($ws, "B{$row}", [
                'font' => ['color' => ['argb' => 'FF' . $actColor], 'bold' => true],
            ]);

            $row++;
        }

        if ($row > 3) {
            $this->applyStyle($ws, 'A2:F' . ($row - 1), ['borders' => $this->thinBorders()]);
        }
    }

    // ── Sheet 4: Alerts ───────────────────────────────────────────────────────

    private function buildAlertsSheet(Worksheet $ws, array $highlights): void
    {
        $ws->setTitle('Alerts & Highlights');

        $ws->mergeCells('A1:F1');
        $ws->setCellValue('A1', 'ALERTS & HIGHLIGHTS');
        $this->applyBanner($ws, 'A1:F1', 12);

        $headers = ['#', 'Severity', 'Type', 'Date', 'Time', 'Summary'];
        $col = 'A';
        foreach ($headers as $h) {
            $ws->setCellValue("{$col}2", $h);
            $col++;
        }
        $this->applyHeaderRow($ws, 'A2:F2');
        $ws->setAutoFilter('A2:F2');
        $ws->freezePane('A3');

        $ws->getColumnDimension('A')->setWidth(6);
        $ws->getColumnDimension('B')->setWidth(12);
        $ws->getColumnDimension('C')->setWidth(20);
        $ws->getColumnDimension('D')->setWidth(14);
        $ws->getColumnDimension('E')->setWidth(12);
        $ws->getColumnDimension('F')->setWidth(50);

        $row = 3;
        foreach ($highlights as $i => $h) {
            $severity = strtolower($h['severity'] ?? 'info');
            $type     = $h['type']    ?? '';
            $date     = $h['date']    ?? '';
            $time     = $h['time']    ?? '';
            $summary  = $h['summary'] ?? $h['description'] ?? '';

            [$bg, $fg] = match ($severity) {
                'critical', 'high' => [self::CRIT_BG, self::CRIT_FG],
                'warning', 'medium' => [self::WARN_BG, self::WARN_FG],
                'info', 'low'       => [self::INFO_BG, self::INFO_FG],
                default            => [self::ALT_BG,  '333333'],
            };

            $ws->setCellValue("A{$row}", $i + 1);
            $ws->setCellValue("B{$row}", ucfirst($severity));
            $ws->setCellValue("C{$row}", $this->cleanName($type));
            $ws->setCellValue("D{$row}", $date);
            $ws->setCellValue("E{$row}", $time);
            $ws->setCellValue("F{$row}", $summary);

            $this->applyStyle($ws, "A{$row}:F{$row}", [
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $bg]],
            ]);
            $this->applyStyle($ws, "B{$row}", [
                'font'      => ['bold' => true, 'color' => ['argb' => 'FF' . $fg]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $this->applyStyle($ws, "F{$row}", [
                'alignment' => ['wrapText' => true, 'horizontal' => Alignment::HORIZONTAL_LEFT],
            ]);
            $ws->getRowDimension($row)->setRowHeight(30);

            $row++;
        }

        if ($row > 3) {
            $this->applyStyle($ws, 'A2:F' . ($row - 1), ['borders' => $this->thinBorders()]);
        }
    }

    // ── Sheet 5: Timeline Gantt ───────────────────────────────────────────────

    /**
     * Gantt layout:
     *   Col A  — Activity label (display name)
     *   Col B  — Start time (HH:MM)
     *   Col C  — End time   (HH:MM)
     *   Col D  — Duration
     *   Col E+ — 30-minute time-slot columns, header = "HH:MM"
     *
     * Cells in slot columns are filled with the activity's brand colour when the
     * segment occupies that slot.  Freeze at column E so activity labels stay
     * visible while scrolling over time slots.
     *
     * Multi-day data: show the most recent 3 calendar days, with a day-separator
     * row between them.
     */
    private function buildGanttSheet(Worksheet $ws, array $segments): void
    {
        $ws->setTitle('Timeline Gantt');

        // ── Fixed info columns ─────────────────────────────────────────────────
        $ws->getColumnDimension('A')->setWidth(20);
        $ws->getColumnDimension('B')->setWidth(8);
        $ws->getColumnDimension('C')->setWidth(8);
        $ws->getColumnDimension('D')->setWidth(10);

        // ── Slot column setup ─────────────────────────────────────────────────
        // 30-minute slots, work hours only 06:00–22:00 = 32 visible columns
        $slotMinutes  = 30;
        $dayStartMin  = 6 * 60;   // 06:00 in minutes
        $dayEndMin    = 22 * 60;  // 22:00 in minutes
        $slotsPerDay  = (int) (($dayEndMin - $dayStartMin) / $slotMinutes);  // 32
        $slotColStart = 5;  // Excel col index 5 = col E (1-based)

        foreach (range(0, $slotsPerDay - 1) as $s) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($slotColStart + $s);
            $ws->getColumnDimension($col)->setWidth(4);
        }

        // ── Banner (merged across all columns) ────────────────────────────────
        $lastSlotCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($slotColStart + $slotsPerDay - 1);
        $ws->mergeCells("A1:{$lastSlotCol}1");
        $ws->setCellValue('A1', 'TIMELINE GANTT VIEW  (30-min slots)');
        $this->applyBanner($ws, "A1:{$lastSlotCol}1", 12);

        // Slot header labels (row 2)
        $ws->setCellValue('A2', 'Activity');
        $ws->setCellValue('B2', 'Start');
        $ws->setCellValue('C2', 'End');
        $ws->setCellValue('D2', 'Duration');
        foreach (range(0, $slotsPerDay - 1) as $s) {
            $totalMin = $dayStartMin + $s * $slotMinutes;
            $h        = (int) ($totalMin / 60);
            $m        = $totalMin % 60;
            $label    = sprintf('%02d:%02d', $h, $m);
            $col      = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($slotColStart + $s);
            $ws->setCellValue("{$col}2", $label);
            $this->applyStyle($ws, "{$col}2", [
                'font'      => ['bold' => true, 'size' => 7, 'color' => ['argb' => 'FF' . self::HDR_FG]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::HDR_BG]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'textRotation' => 90],
            ]);
        }
        $this->applyHeaderRow($ws, 'A2:D2');
        $ws->freezePane('E3');
        $ws->getRowDimension(2)->setRowHeight(48);

        // ── Group segments by date — keep at most 3 most-recent dates ─────────
        $byDate = [];
        foreach ($segments as $seg) {
            $start = $seg['start_time'] ?? '';
            if ($start === '') continue;
            $date = substr($start, 0, 10);
            $byDate[$date][] = $seg;
        }
        krsort($byDate);  // most recent first

        $dateGroups = array_slice($byDate, 0, 3, true);
        krsort($dateGroups);  // chronological order for display

        // ── Write rows ────────────────────────────────────────────────────────
        $row = 3;

        foreach ($dateGroups as $date => $segs) {
            // Day separator row
            $ws->mergeCells("A{$row}:{$lastSlotCol}{$row}");
            $ws->setCellValue("A{$row}", '  ' . $date);
            $this->applyStyle($ws, "A{$row}:{$lastSlotCol}{$row}", [
                'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF' . self::HDR_FG]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::SEC_BG]],
            ]);
            $ws->getRowDimension($row)->setRowHeight(18);
            $row++;

            foreach ($segs as $i => $seg) {
                $rawAct   = $seg['activity']   ?? '';
                $act      = $this->cleanName($rawAct);
                $startTs  = $seg['start_time'] ?? '';
                $endTs    = $seg['end_time']   ?? '';
                $dur      = (float) ($seg['duration_sec'] ?? 0);

                // Times as HH:MM
                $startHHMM = strlen($startTs) >= 16 ? substr($startTs, 11, 5) : substr($startTs, 0, 5);
                $endHHMM   = strlen($endTs)   >= 16 ? substr($endTs,   11, 5) : substr($endTs,   0, 5);

                $ws->setCellValue("A{$row}", $act);
                $ws->setCellValue("B{$row}", $startHHMM);
                $ws->setCellValue("C{$row}", $endHHMM);
                $ws->setCellValue("D{$row}", $this->fmt($dur));

                $this->applyStyle($ws, "A{$row}", ['font' => ['size' => 9]]);
                $this->applyStyle($ws, "B{$row}:D{$row}", [
                    'font'      => ['size' => 9],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                if ($i % 2 === 1) {
                    $this->applyStyle($ws, "A{$row}:D{$row}", $this->altRowStyle());
                }

                // Fill Gantt slot cells
                $color    = self::GANTT_COLORS[$rawAct] ?? self::GANTT_DEFAULT;
                $startMin = $this->hhmmToMinutes($startHHMM);
                $endMin   = $this->hhmmToMinutes($endHHMM);

                if ($endMin > $startMin) {
                    $firstSlot = max(0, (int) floor(($startMin - $dayStartMin) / $slotMinutes));
                    $lastSlot  = min($slotsPerDay - 1, (int) ceil(($endMin - $dayStartMin) / $slotMinutes) - 1);

                    for ($s = $firstSlot; $s <= $lastSlot; $s++) {
                        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($slotColStart + $s);
                        $this->applyStyle($ws, "{$col}{$row}", [
                            'fill' => [
                                'fillType'   => Fill::FILL_SOLID,
                                'startColor' => ['argb' => 'FF' . $color],
                            ],
                        ]);
                    }
                }

                $ws->getRowDimension($row)->setRowHeight(16);
                $row++;
            }
        }

        // ── Legend ────────────────────────────────────────────────────────────
        $row++;
        $ws->setCellValue("A{$row}", 'Legend:');
        $this->applyStyle($ws, "A{$row}", ['font' => ['bold' => true, 'size' => 9]]);
        $row++;

        $legendItems = [
            'Working'     => self::GANTT_COLORS['Working'],
            'Using Phone' => self::GANTT_COLORS['Using_Phone'],
            'Inactive'    => self::GANTT_COLORS['Inactive'],
            'Other'       => self::GANTT_DEFAULT,
        ];
        foreach ($legendItems as $label => $color) {
            $ws->setCellValue("A{$row}", '');
            $ws->setCellValue("B{$row}", $label);
            $this->applyStyle($ws, "A{$row}", [
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $color]],
            ]);
            $this->applyStyle($ws, "B{$row}", ['font' => ['size' => 9]]);
            $ws->getRowDimension($row)->setRowHeight(14);
            $row++;
        }
    }

    // ── Insight computation ───────────────────────────────────────────────────

    private function computePersonInsights(
        ?int  $focus,
        float $working,
        float $phone,
        float $inactive,
        float $totalSec,
        array $daily,
    ): array {
        $insights = [];

        // Focus level narrative
        if ($focus !== null) {
            $level = match (true) {
                $focus >= 75 => 'excellent',
                $focus >= 55 => 'good',
                $focus >= 40 => 'moderate',
                default      => 'critical',
            };
            $insights[] = [
                'text' => "The employee's overall focus score for this period is {$focus}%, reflecting {$level} performance.",
                'bold' => in_array($level, ['excellent', 'critical'], true),
            ];
        }

        // Phone usage
        $base = $working + $phone + $inactive;
        if ($base > 0 && $phone > 0) {
            $phonePct = round($phone / $base * 100);
            if ($phonePct >= 20) {
                $insights[] = [
                    'text' => "Mobile device usage constitutes {$phonePct}% of core tracked time, exceeding the recommended threshold. A management review is advised.",
                    'bold' => $phonePct >= 30,
                ];
            }
        }

        // Best & worst days
        $dayFocuses = [];
        foreach ($daily as $day) {
            $acts = $day['activities'] ?? [];
            $w    = (float) ($acts['Working']     ?? 0);
            $p    = (float) ($acts['Using_Phone'] ?? 0);
            $inac = (float) ($acts['Inactive']    ?? 0);
            $b    = $w + $p + $inac;
            if ($b > 0) {
                $dayFocuses[$day['date'] ?? ''] = (int) round($w / $b * 100);
            }
        }

        if (count($dayFocuses) >= 2) {
            arsort($dayFocuses);
            $bestDate  = array_key_first($dayFocuses);
            $bestScore = reset($dayFocuses);
            $worstDate = array_key_last($dayFocuses);
            $worstScore = end($dayFocuses);
            $insights[] = [
                'text' => "Peak performance day: {$bestDate} (focus score {$bestScore}%).  Lowest performance day: {$worstDate} (focus score {$worstScore}%).",
                'bold' => false,
            ];
        }

        if (empty($insights)) {
            $insights[] = ['text' => 'Insufficient data to generate personalised insights.', 'bold' => false];
        }

        return $insights;
    }

    // ── Style helpers ─────────────────────────────────────────────────────────

    private function applyBanner(Worksheet $ws, string $range, int $fontSize): void
    {
        $this->applyStyle($ws, $range, [
            'font'      => ['bold' => true, 'size' => $fontSize, 'color' => ['argb' => 'FF' . self::HDR_FG]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::HDR_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        preg_match('/(\d+)/', $range, $m);
        if (isset($m[1])) {
            $ws->getRowDimension((int) $m[1])->setRowHeight(26);
        }
    }

    private function writeMetaRow(Worksheet $ws, int $row, string $label, string $value): void
    {
        $ws->setCellValue("A{$row}", $label);
        $ws->setCellValue("B{$row}", $value);
        $this->applyStyle($ws, "A{$row}", ['font' => ['bold' => true, 'color' => ['argb' => 'FF555555']]]);
        $this->applyStyle($ws, "B{$row}", ['font' => ['color' => ['argb' => 'FF222222']]]);
    }

    private function applySectionTitle(Worksheet $ws, string $range): void
    {
        $this->applyStyle($ws, $range, [
            'font'      => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FF' . self::HDR_FG]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::SEC_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        preg_match('/(\d+)/', $range, $m);
        if (isset($m[1])) {
            $ws->getRowDimension((int) $m[1])->setRowHeight(20);
        }
    }

    private function applyHeaderRow(Worksheet $ws, string $range): void
    {
        $this->applyStyle($ws, $range, [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FF' . self::HDR_FG]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::HDR_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => $this->thinBorders(),
        ]);
    }

    private function altRowStyle(): array
    {
        return [
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::ALT_BG]],
        ];
    }

    private function thinBorders(): array
    {
        return [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color'       => ['argb' => 'FFB8C8D8'],
            ],
        ];
    }

    private function applyStyle(Worksheet $ws, string $range, array $styles): void
    {
        $ws->getStyle($range)->applyFromArray($styles);
    }

    // ── Data helpers ──────────────────────────────────────────────────────────

    private function cleanName(string $raw): string
    {
        return str_replace('_', ' ', $raw);
    }

    private function fmt(float $seconds): string
    {
        if ($seconds <= 0)   return '0s';
        if ($seconds < 60)   return round($seconds) . 's';
        if ($seconds < 3600) return (int) round($seconds / 60) . 'm';
        $h = (int) ($seconds / 3600);
        $m = (int) (($seconds % 3600) / 60);
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }

    /** Returns [bg, fg, label] for a focus score. */
    private function focusTier(?int $focus): array
    {
        if ($focus === null) return [self::ALT_BG, '555555', '—'];
        return match (true) {
            $focus < 40 => [self::CRIT_BG, self::CRIT_FG, 'Critical'],
            $focus < 70 => [self::WARN_BG, self::WARN_FG, 'Watch'],
            default     => [self::OK_BG,   self::OK_FG,   'Good'],
        };
    }

    /** Convert "HH:MM" string to total minutes. */
    private function hhmmToMinutes(string $hhmm): int
    {
        if (strlen($hhmm) < 5) return 0;
        [$h, $m] = explode(':', $hhmm, 2);
        return (int) $h * 60 + (int) $m;
    }
}
