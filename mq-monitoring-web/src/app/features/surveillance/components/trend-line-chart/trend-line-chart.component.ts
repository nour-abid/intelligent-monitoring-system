import { Component, computed, input } from '@angular/core';
import { BaseChartDirective } from 'ng2-charts';
import type { ChartData, ChartOptions } from 'chart.js';
import type { AlertTrendPoint } from '../../models/summary.model';

/** Converts 'YYYY-MM-DD HH:MM:SS' → 'HH:MM' and 'YYYY-MM-DD' → 'Mon DD'. */
function formatLabel(raw: string): string {
  if (raw.includes(' ')) {
    const time = raw.split(' ')[1] ?? raw;
    return time.substring(0, 5); // 'HH:MM'
  }
  const d = new Date(raw + 'T00:00:00');
  if (isNaN(d.getTime())) return raw;
  return d.toLocaleDateString('en-GB', { month: 'short', day: 'numeric' });
}

@Component({
  selector: 'app-trend-line-chart',
  standalone: true,
  imports: [BaseChartDirective],
  template: `
    @if (points().length === 0) {
      <p class="tlc-empty">No alerts in this period.</p>
    } @else {
      <div class="tlc-wrap">
        <canvas baseChart type="line" [data]="chartData()" [options]="chartOptions"></canvas>
      </div>
    }
  `,
  styles: [`
    .tlc-wrap { position: relative; height: 140px; }
    .tlc-empty {
      font-size:  13px;
      color:      var(--color-text-3);
      text-align: center;
      padding:    var(--sp-3, 0.75rem) 0;
      margin:     0;
    }
  `],
})
export class TrendLineChartComponent {
  readonly points = input.required<AlertTrendPoint[]>();

  readonly chartData = computed<ChartData<'line'>>(() => {
    const p = this.points();
    return {
      labels: p.map(x => formatLabel(x.label)),
      datasets: [{
        data:                p.map(x => x.count),
        borderColor:         '#c41230',
        backgroundColor:     'rgba(196,18,48,0.10)',
        fill:                true,
        tension:             0.35,
        pointRadius:         p.length > 30 ? 0 : 3,
        pointHoverRadius:    5,
        pointBackgroundColor:'#c41230',
        borderWidth:         2,
      }],
    };
  });

  readonly chartOptions: ChartOptions<'line'> = {
    responsive:          true,
    maintainAspectRatio: false,
    animation:           false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: (ctx) => ` ${ctx.raw} alert${(ctx.raw as number) !== 1 ? 's' : ''}`,
        },
      },
    },
    scales: {
      x: {
        ticks:  { color: '#94a3b8', font: { size: 9 }, maxRotation: 0, maxTicksLimit: 8 },
        grid:   { display: false },
        border: { display: false },
      },
      y: {
        ticks:        { color: '#94a3b8', font: { size: 9 } },
        grid:         { color: 'rgba(148,163,184,0.10)' },
        border:       { display: false },
        beginAtZero:  true,
      },
    },
  };
}
