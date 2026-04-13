<?php

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend as ChartLegend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title as ChartTitle;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * OverviewExportService
 *
 * Builds a professional multi-sheet XLSX workbook for the Admin Overview page.
 *
 * Sheets:
 *   1. Overview   — performance overview, KPIs, insights, activity-distribution pie chart
 *   2. Rankings   — team ranking table with bar chart of focus scores
 *   3+. [Name]    — one formatted sheet per accessible employee
 */
class OverviewExportService
{
    // ── Brand palette ─────────────────────────────────────────────────────────
    private const HDR_BG    = '1A3A5C';   // deep navy  — main header bg
    private const HDR_FG    = 'FFFFFF';   // white      — header text
    private const SEC_BG    = '2C5F8A';   // mid-blue   — section header bg
    private const ACCENT_BG = 'E8F0FB';   // light blue — insight / KPI card bg
    private const ALT_BG    = 'F4F7FC';   // near-white — alternating row
    private const CRIT_BG   = 'F8D7DA';
    private const CRIT_FG   = '721C24';
    private const WARN_BG   = 'FFF3CD';
    private const WARN_FG   = '856404';
    private const OK_BG     = 'D4EDDA';
    private const OK_FG     = '155724';

    // ── Public entry point ────────────────────────────────────────────────────

    public function build(
        string $start,
        string $end,
        array  $overview,
        array  $identities,
    ): Spreadsheet {
        $ss = new Spreadsheet();
        $ss->getProperties()
            ->setCreator('MQ Monitoring')
            ->setTitle('Overview Report')
            ->setDescription("Period: {$start} — {$end}");

        // Dedicated hidden sheet — all chart source data lives here so
        // visible sheets never expose raw numeric tables.
        $chartWs = $ss->createSheet();
        $chartWs->setTitle('_ChartData');
        $chartWs->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $this->buildOverviewSheet($ss->getSheet(0), $start, $end, $overview, $identities, $chartWs);

        $rankings = $ss->createSheet();
        $this->buildRankingsSheet($rankings, $identities, $chartWs);

        foreach ($identities as $entry) {
            $empSheet = $ss->createSheet();
            $this->buildEmployeeSheet($empSheet, $entry, $start, $end);
        }

        $ss->setActiveSheetIndex(0);
        return $ss;
    }

    // ── Sheet 1: Overview ─────────────────────────────────────────────────────

    private function buildOverviewSheet(
        Worksheet $ws,
        string    $start,
        string    $end,
        array     $overview,
        array     $identities,
        Worksheet $chartWs,
    ): void {
        $ws->setTitle('Overview');

        $totals   = $overview['totals']      ?? [];
        $totalSec = (float) ($overview['total_sec']   ?? 0);
        $evtCount = (int)   ($overview['event_count'] ?? 0);

        $ws->getColumnDimension('A')->setWidth(32);
        $ws->getColumnDimension('B')->setWidth(20);
        $ws->getColumnDimension('C')->setWidth(18);

        // ── Row 1: Brand banner ───────────────────────────────────────────────
        $ws->mergeCells('A1:N1');
        $ws->setCellValue('A1', 'MQ MONITORING  •  PERFORMANCE OVERVIEW REPORT');
        $this->applyStyle($ws, 'A1:N1', [
            'font'      => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FF' . self::HDR_FG]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::HDR_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $ws->getRowDimension(1)->setRowHeight(32);

        // ── Rows 2-4: Meta strip ──────────────────────────────────────────────
        $this->writeMetaRow($ws, 2, 'Reporting Period', $start . '  →  ' . $end);
        $this->writeMetaRow($ws, 3, 'Generated', now()->format('Y-m-d H:i'));
        $this->writeMetaRow($ws, 4, 'Employees Tracked', (string) count(array_filter(
            $identities,
            fn ($e) => ($e['identity_name'] ?? '') !== 'Unknown'
        )));

        // ── Row 5: Spacer ─────────────────────────────────────────────────────
        $ws->getRowDimension(5)->setRowHeight(8);

        // ── Row 6: Section title ──────────────────────────────────────────────
        $ws->mergeCells('A6:C6');
        $ws->setCellValue('A6', 'KEY PERFORMANCE METRICS');
        $this->applySectionTitle($ws, 'A6:C6');

        // ── Row 7: Column headers ─────────────────────────────────────────────
        $ws->setCellValue('A7', 'Metric');
        $ws->setCellValue('B7', 'Duration');
        $ws->setCellValue('C7', 'Share of Total');
        $this->applyHeaderRow($ws, 'A7:C7');

        // ── Rows 8+: KPI data rows ────────────────────────────────────────────
        $row = 8;
        $this->writeKpiRow($ws, $row++, 'Total Observed Time',  $this->fmt($totalSec),       null,   0);
        $this->writeKpiRow($ws, $row++, 'Total Event Segments', number_format($evtCount), null,       1);

        $sortedTotals = $totals;
        arsort($sortedTotals);
        foreach ($sortedTotals as $rawAct => $sec) {
            $pct = $totalSec > 0 ? round((float) $sec / $totalSec * 100, 1) : 0;
            $this->writeKpiRow($ws, $row, $this->cleanName($rawAct), $this->fmt((float) $sec), $pct . '%', $row % 2);
            $row++;
        }
        $this->applyStyle($ws, 'A7:C' . ($row - 1), ['borders' => $this->thinBorders()]);

        // ── Spacer ────────────────────────────────────────────────────────────
        $row++;
        $ws->getRowDimension($row)->setRowHeight(8);
        $row++;

        // ── Insights section ──────────────────────────────────────────────────
        $insightTitle = $row;
        $ws->mergeCells("A{$row}:C{$row}");
        $ws->setCellValue("A{$row}", 'INSIGHTS & RECOMMENDATIONS');
        $this->applySectionTitle($ws, "A{$row}:C{$row}");
        $row++;

        foreach ($this->computeOverviewInsights($totals, $totalSec, $identities) as $insight) {
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

        // ── Pie chart source data → hidden _ChartData sheet (cols A/B) ───────────
        $chartWs->setCellValue('A1', 'Activity');
        $chartWs->setCellValue('B1', 'Seconds');
        $pieRow = 2;
        foreach ($totals as $rawAct => $sec) {
            $chartWs->setCellValue("A{$pieRow}", $this->cleanName($rawAct));
            $chartWs->setCellValue("B{$pieRow}", (int) round((float) $sec));
            $pieRow++;
        }

        if ($pieRow > 2) {
            $this->addPieChart($ws, 2, $pieRow - 1);
        }
    }

    private function addPieChart(Worksheet $ws, int $startRow, int $endRow): void
    {
        $labels = new DataSeriesValues(
            DataSeriesValues::DATASERIES_TYPE_STRING,
            "'_ChartData'!\$A\${$startRow}:\$A\${$endRow}",
            null,
            $endRow - $startRow + 1
        );
        $values = new DataSeriesValues(
            DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'_ChartData'!\$B\${$startRow}:\$B\${$endRow}",
            null,
            $endRow - $startRow + 1
        );

        $series = new DataSeries(
            DataSeries::TYPE_PIECHART,
            null,
            range(0, 0),
            [],
            [$labels],
            [$values]
        );

        $chart = new Chart(
            'pieDistribution',
            new ChartTitle('Activity Distribution'),
            new ChartLegend(ChartLegend::POSITION_RIGHT, null, false),
            new PlotArea(null, [$series])
        );
        $chart->setTopLeftPosition('E2');
        $chart->setBottomRightPosition('N30');
        $ws->addChart($chart);
    }

    // ── Sheet 2: Rankings ─────────────────────────────────────────────────────

    private function buildRankingsSheet(Worksheet $ws, array $identities, Worksheet $chartWs): void
    {
        $ws->setTitle('TEAM RANKING');

        $ranked = $identities;
        usort($ranked, fn (array $a, array $b) =>
            ($this->focusScore($b) ?? -1) <=> ($this->focusScore($a) ?? -1)
        );

        // Banner
        $ws->mergeCells('A1:H1');
        $ws->setCellValue('A1', 'MQ MONITORING  •  TEAM RANKING — FOCUS SCORE');
        $this->applyStyle($ws, 'A1:H1', [
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF' . self::HDR_FG]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::HDR_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $ws->getRowDimension(1)->setRowHeight(28);

        // Column headers
        foreach ([
            'A2' => 'Rank',
            'B2' => 'Employee',
            'C2' => 'Total Time',
            'D2' => 'Working %',
            'E2' => 'Phone %',
            'F2' => 'Inactive %',
            'G2' => 'Focus Score',
            'H2' => 'Status',
        ] as $cell => $label) {
            $ws->setCellValue($cell, $label);
        }
        $this->applyHeaderRow($ws, 'A2:H2');
        $ws->setAutoFilter('A2:H2');
        $ws->freezePane('A3');

        // Bar chart source data → hidden _ChartData sheet (cols D/E)
        $chartWs->setCellValue('D1', 'Employee');
        $chartWs->setCellValue('E1', 'Focus Score');
        $cdRow   = 2;
        $dataRow = 3;
        foreach ($ranked as $rank => $entry) {
            $acts     = $entry['activities'] ?? [];
            $total    = (float) ($entry['total_sec'] ?? 0);
            $working  = (float) ($acts['Working']     ?? 0);
            $phone    = (float) ($acts['Using_Phone'] ?? 0);
            $inactive = (float) ($acts['Inactive']    ?? 0);
            $base     = $working + $phone + $inactive;

            $workPct  = $base > 0 ? round($working  / $base * 100, 1) : 0;
            $phonePct = $base > 0 ? round($phone    / $base * 100, 1) : 0;
            $inactPct = $base > 0 ? round($inactive / $base * 100, 1) : 0;
            $focus    = $this->focusScore($entry);
            $name     = $entry['identity_name'] ?? '—';

            [$focusBg, $focusFg, $status] = $this->focusTier($focus);

            $ws->setCellValue("A{$dataRow}", $rank + 1);
            $ws->setCellValue("B{$dataRow}", $name);
            $ws->setCellValue("C{$dataRow}", $this->fmt($total));
            $ws->setCellValue("D{$dataRow}", $workPct . '%');
            $ws->setCellValue("E{$dataRow}", $phonePct . '%');
            $ws->setCellValue("F{$dataRow}", $inactPct . '%');
            $ws->setCellValue("G{$dataRow}", $focus !== null ? $focus . '%' : '—');
            $ws->setCellValue("H{$dataRow}", $status);
            // Bar chart source
            $chartWs->setCellValue("D{$cdRow}", $name);
            $chartWs->setCellValue("E{$cdRow}", $focus ?? 0);
            $cdRow++;

            if ($dataRow % 2 === 0) {
                $this->applyStyle($ws, "A{$dataRow}:H{$dataRow}", $this->altRowStyle());
            }

            // Focus score: large bold semantic colour
            $this->applyStyle($ws, "G{$dataRow}", [
                'font'      => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF' . $focusFg]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $focusBg]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $this->applyStyle($ws, "H{$dataRow}", [
                'font'      => ['bold' => true, 'color' => ['argb' => 'FF' . $focusFg]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $focusBg]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $dataRow++;
        }

        if ($dataRow > 3) {
            $this->applyStyle($ws, 'A2:H' . ($dataRow - 1), ['borders' => $this->thinBorders()]);
        }

        $ws->getColumnDimension('A')->setWidth(6);
        $ws->getColumnDimension('B')->setWidth(22);
        $ws->getColumnDimension('C')->setWidth(14);
        $ws->getColumnDimension('D')->setWidth(12);
        $ws->getColumnDimension('E')->setWidth(12);
        $ws->getColumnDimension('F')->setWidth(12);
        $ws->getColumnDimension('G')->setWidth(14);
        $ws->getColumnDimension('H')->setWidth(12);
        if ($cdRow > 2) {
            $this->addBarChart($ws, 2, $cdRow - 1, $dataRow - 1);
        }
    }

    private function addBarChart(Worksheet $ws, int $startRow, int $endRow, int $tableEndRow): void
    {
        $labels = new DataSeriesValues(
            DataSeriesValues::DATASERIES_TYPE_STRING,
            "'_ChartData'!\$D\${$startRow}:\$D\${$endRow}",
            null,
            $endRow - $startRow + 1
        );
        $values = new DataSeriesValues(
            DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'_ChartData'!\$E\${$startRow}:\$E\${$endRow}",
            null,
            $endRow - $startRow + 1
        );
        $seriesLabel = new DataSeriesValues(
            DataSeriesValues::DATASERIES_TYPE_STRING,
            null,
            null,
            0,
            ['Focus Score (%)']
        );

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            range(0, 0),
            [$seriesLabel],
            [$labels],
            [$values]
        );
        $series->setPlotDirection(DataSeries::DIRECTION_BAR);

        $chart = new Chart(
            'focusBarChart',
            new ChartTitle('Team Focus Score Ranking'),
            new ChartLegend(ChartLegend::POSITION_BOTTOM, null, false),
            new PlotArea(null, [$series])
        );
        $chart->setTopLeftPosition('A' . ($tableEndRow + 3));
        $chart->setBottomRightPosition('H' . ($tableEndRow + 22));
        $ws->addChart($chart);
    }

    // ── Sheets 3+: Per-employee ───────────────────────────────────────────────

    private function buildEmployeeSheet(
        Worksheet $ws,
        array     $entry,
        string    $start,
        string    $end,
    ): void {
        $name  = $entry['identity_name'] ?? 'Unknown';
        $safe  = mb_substr(preg_replace('/[\/\\\?\*\[\]:]/', '-', $name), 0, 31);
        $ws->setTitle($safe);

        $acts  = $entry['activities'] ?? [];
        $total = (float) ($entry['total_sec'] ?? 0);
        $focus = $this->focusScore($entry);
        [$focusBg, $focusFg, $status] = $this->focusTier($focus);

        $ws->getColumnDimension('A')->setWidth(28);
        $ws->getColumnDimension('B')->setWidth(18);
        $ws->getColumnDimension('C')->setWidth(16);
        $ws->getColumnDimension('D')->setWidth(18);

        // Banner
        $ws->mergeCells('A1:D1');
        $ws->setCellValue('A1', strtoupper($name) . '  —  EMPLOYEE ACTIVITY REPORT');
        $this->applyStyle($ws, 'A1:D1', [
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF' . self::HDR_FG]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::HDR_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $ws->getRowDimension(1)->setRowHeight(28);

        // Meta rows
        $this->writeMetaRow($ws, 2, 'Period',             $start . '  →  ' . $end);
        $this->writeMetaRow($ws, 3, 'Total Tracked Time', $this->fmt($total));
        $this->writeMetaRow($ws, 4, 'Event Segments',     number_format((int) ($entry['event_count'] ?? 0)));

        // Focus score KPI card
        $ws->setCellValue('A5', 'Focus Score');
        $ws->setCellValue('B5', $focus !== null ? $focus . '%' : '—');
        $ws->setCellValue('C5', $status);
        $this->applyStyle($ws, 'A5', ['font' => ['bold' => true, 'size' => 11]]);
        $this->applyStyle($ws, 'B5', [
            'font'      => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FF' . $focusFg]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $focusBg]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $this->applyStyle($ws, 'C5', [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FF' . $focusFg]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . $focusBg]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $ws->getRowDimension(5)->setRowHeight(26);

        // Spacer
        $ws->getRowDimension(6)->setRowHeight(8);

        // Activity breakdown
        $ws->mergeCells('A7:D7');
        $ws->setCellValue('A7', 'ACTIVITY BREAKDOWN');
        $this->applySectionTitle($ws, 'A7:D7');

        $ws->setCellValue('A8', 'Activity');
        $ws->setCellValue('B8', 'Duration');
        $ws->setCellValue('C8', '% of Total');
        $ws->setCellValue('D8', '% of Core Time');
        $this->applyHeaderRow($ws, 'A8:D8');
        $ws->setAutoFilter('A8:D8');
        $ws->freezePane('A9');

        $working  = (float) ($acts['Working']     ?? 0);
        $phone    = (float) ($acts['Using_Phone'] ?? 0);
        $inactive = (float) ($acts['Inactive']    ?? 0);
        $core     = $working + $phone + $inactive;

        $sorted = $acts;
        arsort($sorted);

        $row = 9;
        $i   = 0;
        foreach ($sorted as $rawAct => $sec) {
            $sec     = (float) $sec;
            $act     = $this->cleanName($rawAct);
            $pct     = $total > 0 ? round($sec / $total * 100, 1) : 0;
            $corePct = $core > 0 && in_array($rawAct, ['Working', 'Using_Phone', 'Inactive'], true)
                ? round($sec / $core * 100, 1) . '%'
                : '—';

            $ws->setCellValue("A{$row}", $act);
            $ws->setCellValue("B{$row}", $this->fmt($sec));
            $ws->setCellValue("C{$row}", $pct . '%');
            $ws->setCellValue("D{$row}", $corePct);

            if ($i++ % 2 === 1) {
                $this->applyStyle($ws, "A{$row}:D{$row}", $this->altRowStyle());
            }
            $row++;
        }

        if ($row > 9) {
            $this->applyStyle($ws, 'A8:D' . ($row - 1), ['borders' => $this->thinBorders()]);
        }
    }

    // ── Insight computation ───────────────────────────────────────────────────

    private function computeOverviewInsights(array $totals, float $totalSec, array $identities): array
    {
        $insights = [];

        // Productivity level
        $workSec = (float) ($totals['Working'] ?? 0);
        if ($totalSec > 0) {
            $workPct = round($workSec / $totalSec * 100);
            $level   = match (true) {
                $workPct >= 75 => 'excellent',
                $workPct >= 55 => 'good',
                $workPct >= 35 => 'moderate',
                default        => 'low',
            };
            $insights[] = [
                'text' => "Overall workforce productivity is classified as {$level}: {$workPct}% of total observed time was recorded as active working.",
                'bold' => in_array($level, ['excellent', 'low'], true),
            ];
        }

        // Phone usage warning
        $phoneSec = (float) ($totals['Using_Phone'] ?? 0);
        if ($totalSec > 0 && $phoneSec > 0) {
            $phonePct = round($phoneSec / $totalSec * 100);
            if ($phonePct >= 20) {
                $insights[] = [
                    'text' => "Mobile device usage accounts for {$phonePct}% of total observed time, exceeding the recommended threshold. A formal policy review is recommended.",
                    'bold' => $phonePct >= 30,
                ];
            }
        }

        // Inactivity alert
        $inactiveSec = (float) ($totals['Inactive'] ?? 0);
        if ($totalSec > 0 && $inactiveSec > 0) {
            $inactPct = round($inactiveSec / $totalSec * 100);
            if ($inactPct >= 25) {
                $insights[] = [
                    'text' => "Periods of inactivity account for {$inactPct}% of total observed time. A root-cause analysis is recommended to identify contributing factors.",
                    'bold' => $inactPct >= 40,
                ];
            }
        }

        // Team focus score distribution
        $knownIdentities = array_filter(
            $identities,
            fn ($e) => ($e['identity_name'] ?? '') !== 'Unknown'
        );
        $scores = array_values(array_filter(
            array_map(fn ($e) => $this->focusScore($e), $knownIdentities),
            fn ($s) => $s !== null
        ));

        if (count($scores) > 0) {
            $avg    = (int) round(array_sum($scores) / count($scores));
            $atRisk = count(array_filter($scores, fn ($s) => $s < 40));
            $txt = "The average team focus score for this period is {$avg}%.";
            if ($atRisk > 0) {
                $txt .= " {$atRisk} employee(s) fall below the 40% critical threshold and require immediate supervisory attention.";
            }
            $insights[] = ['text' => $txt, 'bold' => $atRisk > 0];
        }

        if (empty($insights)) {
            $insights[] = ['text' => 'No significant patterns detected in this period.', 'bold' => false];
        }

        return $insights;
    }

    // ── Style helpers ─────────────────────────────────────────────────────────

    private function writeMetaRow(Worksheet $ws, int $row, string $label, string $value): void
    {
        $ws->setCellValue("A{$row}", $label);
        $ws->setCellValue("B{$row}", $value);
        $this->applyStyle($ws, "A{$row}", ['font' => ['bold' => true, 'color' => ['argb' => 'FF555555']]]);
        $this->applyStyle($ws, "B{$row}", ['font' => ['color' => ['argb' => 'FF222222']]]);
    }

    private function writeKpiRow(
        Worksheet $ws,
        int       $row,
        string    $label,
        string    $value,
        ?string   $pct,
        int       $parity,
    ): void {
        $ws->setCellValue("A{$row}", $label);
        $ws->setCellValue("B{$row}", $value);
        $ws->setCellValue("C{$row}", $pct ?? '');
        $this->applyStyle($ws, "B{$row}", ['font' => ['bold' => true]]);
        if ($parity % 2 === 1) {
            $this->applyStyle($ws, "A{$row}:C{$row}", $this->altRowStyle());
        }
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

    /** Returns [bg, fg, label] for a focus score value. */
    private function focusTier(?int $focus): array
    {
        if ($focus === null) return [self::ALT_BG, '555555', '—'];
        return match (true) {
            $focus < 40 => [self::CRIT_BG, self::CRIT_FG, 'Critical'],
            $focus < 70 => [self::WARN_BG, self::WARN_FG, 'Watch'],
            default     => [self::OK_BG,   self::OK_FG,   'Good'],
        };
    }

    private function focusScore(array $entry): ?int
    {
        $acts     = $entry['activities'] ?? [];
        $working  = (float) ($acts['Working']     ?? 0);
        $phone    = (float) ($acts['Using_Phone'] ?? 0);
        $inactive = (float) ($acts['Inactive']    ?? 0);
        $base     = $working + $phone + $inactive;
        return $base > 0 ? (int) round($working / $base * 100) : null;
    }
}
