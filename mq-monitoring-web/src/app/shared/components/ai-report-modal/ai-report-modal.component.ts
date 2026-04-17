import {
  Component,
  inject,
  Input,
  Output,
  EventEmitter,
  signal,
  OnInit,
  HostListener,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { AiService } from '../../../core/services/ai.service';
import { ActivityStat } from '../../../features/surveillance/models/overview.model';

/** Flat KPI row forwarded from the parent's kpiCards() computed. */
export interface ReportKpi { label: string; value: string; sub?: string; }
/** Pre-computed SVG coordinate from the parent's timeSeriesData() computed. */
export interface ReportChartPoint { x: number; y: number; value: number; date: string; }

@Component({
  selector: 'app-ai-report-modal',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './ai-report-modal.component.html',
  styleUrl: './ai-report-modal.component.scss',
})
export class AiReportModalComponent implements OnInit {
  /** The serialised employee data to send to the AI for report generation. */
  @Input({ required: true }) data!: string;
  /** Display title for the report (e.g. the employee name + date range). */
  @Input() title = 'AI-Formulated Report';
  /** KPI summary cards — pass kpiCards() from the parent (structural typing is sufficient). */
  @Input() kpis: ReportKpi[] = [];
  /** Activity stats for the distribution chart — pass activityStats() from the parent. */
  @Input() activityStats: ActivityStat[] = [];
  /** Pre-computed SVG points for the daily trend chart — pass timeSeriesData() from the parent. */
  @Input() chartPoints: ReportChartPoint[] = [];
  /** SVG polyline points string — pass timeSeriesPolyline() from the parent. */
  @Input() chartPolyline = '';
  /** Y-axis maximum label — pass timeSeriesMaxLabel() from the parent. */
  @Input() chartMaxLabel = '';
  /** Metric unit suffix (h / min / %) — pass metricUnit() from the parent. */
  @Input() chartUnit = '';
  /** Human-readable name of the metric being charted, e.g. "Working Time". */
  @Input() chartLabel = 'Working Time';
  /** Formatted first date label for X-axis (DD/MM). */
  @Input() chartFirstDate = '';
  /** Formatted last date label for X-axis (DD/MM). */
  @Input() chartLastDate = '';
  /** Emitted when the user closes the modal. */
  @Output() closed = new EventEmitter<void>();

  private readonly ai = inject(AiService);

  readonly status   = signal<'loading' | 'ready' | 'error'>('loading');
  readonly report   = signal('');
  readonly errorMsg  = signal('');
  readonly copying   = signal(false);
  /** True when the backend returned fallback=true (AI unavailable, analytics still shown). */
  readonly isFallback = signal(false);
  readonly today = new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });

  ngOnInit(): void {
    this.ai.generateReport(this.data).subscribe({
      next: (res) => {
        if (res.fallback) {
          // Structured fallback — keep KPI/charts visible, show in-doc notice.
          this.isFallback.set(true);
          this.status.set('ready');
        } else if (res.error) {
          this.errorMsg.set(res.error);
          this.status.set('error');
        } else {
          this.report.set(res.report ?? '');
          this.status.set('ready');
        }
      },
      error: () => {
        this.errorMsg.set('Failed to reach the AI service. Please try again.');
        this.status.set('error');
      },
    });
  }

  close(): void {
    this.closed.emit();
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.close();
  }

  /** Copy the raw markdown report to clipboard. */
  copyToClipboard(): void {
    navigator.clipboard.writeText(this.report()).then(() => {
      this.copying.set(true);
      setTimeout(() => this.copying.set(false), 2000);
    });
  }

  /**
   * Print the AI report document in an isolated window.
   * Clones the white-paper .arm-doc element and renders it with matching CSS.
   */
  print(): void {
    const docEl = document.querySelector<HTMLElement>('.arm-doc');
    if (!docEl) return;
    const content = docEl.outerHTML;
    const printWin = window.open('', '_blank', 'width=1000,height=750');
    if (!printWin) return; // popup blocker

    printWin.document.write(`<!DOCTYPE html><html lang="en">
<head><meta charset="utf-8"><title>AI Report \u2014 ${this.title}</title><style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;background:#f0f2f5;padding:32px;min-height:100vh}
@media print{body{padding:0;background:#fff}}
.arm-doc{background:#fff;color:#111;border-radius:6px;box-shadow:0 4px 24px rgba(0,0,0,.15);max-width:820px;margin:0 auto;padding:40px 48px;font-size:13px;line-height:1.65}
@media print{.arm-doc{box-shadow:none;padding:20px 24px;max-width:none}}
.arm-doc__header{display:flex;align-items:center;justify-content:space-between;padding-bottom:14px;border-bottom:2px solid #c41230;margin-bottom:28px}
.arm-doc__brand{display:flex;align-items:center;gap:10px}
.arm-doc__brand-icon{width:22px;height:22px}
.arm-doc__brand-name{font-size:1rem;font-weight:800;color:#c41230}
.arm-doc__company{text-align:right}
.arm-doc__company-title{font-size:.78rem;font-weight:600;color:#444}
.arm-doc__company-sub{font-size:.7rem;color:#999;margin-top:2px}
.arm-doc__title-block{text-align:center;margin-bottom:30px}
.arm-doc__title{font-size:1.5rem;font-weight:800;color:#c41230;letter-spacing:-.02em;margin-bottom:6px}
.arm-doc__subtitle{font-size:.85rem;color:#666;font-style:italic}
.arm-doc__section{margin-bottom:28px}
.arm-doc__section-title{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#c41230;padding-bottom:8px;border-bottom:1.5px solid #eee;margin-bottom:16px}
.arm-doc__footer{margin-top:32px;padding-top:12px;border-top:1px solid #eee;font-size:.68rem;color:#bbb;text-align:center}
.arm-kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:4px}
.arm-kpi-item{border:1px solid #e8e8e8;border-radius:8px;padding:14px;background:#fafafa}
.arm-kpi-label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#888}
.arm-kpi-value{font-size:1.35rem;font-weight:800;color:#111;margin:6px 0 3px}
.arm-kpi-sub{font-size:.68rem;color:#aaa}
.arm-charts-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.arm-chart-card{border:1px solid #eee;border-radius:8px;padding:14px;background:#fafafa}
.arm-chart-card__label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-bottom:12px}
.arm-dist{display:flex;flex-direction:column;gap:11px}
.arm-dist__head{display:flex;align-items:center;gap:6px;margin-bottom:5px}
.arm-dist__dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0}
.arm-dist__name{font-size:.78rem;font-weight:600;color:#333}
.arm-dist__spacer{flex:1}
.arm-dist__dur{font-size:.72rem;color:#999}
.arm-dist__pct{font-size:.78rem;font-weight:700;color:#111;min-width:40px;text-align:right}
.arm-dist__track{background:#f0f0f0;border-radius:100px;height:7px;overflow:hidden}
.arm-dist__bar{height:100%;border-radius:100px;min-width:3px}
.arm-ts-chart{width:100%;height:auto;display:block}
.ts-chart__grid{stroke:#eee;stroke-width:1}
.ts-chart__baseline{stroke:#ddd;stroke-width:1.5}
.ts-chart__line{stroke:#c41230;stroke-width:2}
.ts-chart__dot{fill:#c41230}
.ts-chart__axis-label{fill:#aaa;font-size:10px;font-family:inherit}
.ts-chart__date-label{fill:#ccc;font-size:9px;font-family:inherit}
.arm-report{color:#222;font-size:.9rem;line-height:1.75}
.arm-report h2{font-size:1rem;font-weight:700;color:#c41230;margin:22px 0 8px;padding-bottom:5px;border-bottom:2px solid #f0d0d0}
.arm-report h3{font-size:.9rem;font-weight:600;color:#333;margin:14px 0 5px}
.arm-report p{margin:9px 0}
.arm-report ul{padding-left:20px;margin:6px 0;list-style:disc}
.arm-report li{margin:3px 0}
.arm-report strong{font-weight:700;color:#111}
.arm-report em{font-style:italic;color:#555}
.arm-report code{background:#f5f5f5;border:1px solid #e0e0e0;border-radius:3px;padding:1px 5px;font-size:.82rem;font-family:monospace}
.arm-report table{width:100%;border-collapse:collapse;font-size:.82rem;margin:10px 0}
.arm-report th,.arm-report td{border:1px solid #e0e0e0;padding:7px 10px;text-align:left}
.arm-report th{background:#c41230;color:#fff;font-weight:600;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em}
.arm-report tr:nth-child(even) td{background:#fafafa}
</style></head>
<body>${content}</body></html>`);
    printWin.document.close();
    printWin.focus();
    setTimeout(() => { printWin.print(); printWin.close(); }, 600);
  }

  /** Activity colour map — light theme palette. */
  private readonly DIST_COLORS: Record<string, string> = {
    Working:     '#10b981',
    Using_Phone: '#ef4444',
    Inactive:    '#f59e0b',
  };

  /** Returns the bar fill colour for an activity key. */
  distColor(activity: string): string {
    return this.DIST_COLORS[activity] ?? '#94a3b8';
  }

  /** Converts activity key → human-readable label (Using_Phone → Using Phone). */
  distLabel(activity: string): string {
    return activity.replace(/_/g, ' ');
  }

  /** Converts a 0–1 share fraction to a percentage string. */
  distPct(share: number): string {
    return `${(share * 100).toFixed(1)}%`;
  }

  /** Very simple markdown → HTML for display (headings, bold, lists, tables). */
  renderMarkdown(text: string): string {
    return text
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/^## (.+)$/gm, '<h2>$1</h2>')
      .replace(/^### (.+)$/gm, '<h3>$1</h3>')
      .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.*?)\*/g, '<em>$1</em>')
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/^\s*[-*] (.+)$/gm, '<li>$1</li>')
      .replace(/^\s*(\d+)\. (.+)$/gm, '<li>$2</li>')
      .replace(/(<li>.*?<\/li>\s*)+/gs, s => `<ul>${s}</ul>`)
      .replace(/\n{2,}/g, '</p><p>')
      .replace(/\n/g, '<br>');
  }
}
