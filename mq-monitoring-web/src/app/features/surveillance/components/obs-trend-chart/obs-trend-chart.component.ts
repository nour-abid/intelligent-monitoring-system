import { Component, computed, input } from '@angular/core';
import { BaseChartDirective } from 'ng2-charts';
import type { ChartData, ChartOptions } from 'chart.js';
import type { ObservationTrendPoint } from '../../models/summary.model';

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
  selector: 'app-obs-trend-chart',
  standalone: true,
  imports: [BaseChartDirective],
  template: `
    @if (points().length === 0) {
      <p class="otc-empty">No surveillance data in this period.</p>
    } @else {
      <div class="otc-wrap">
        <canvas baseChart type="line" [data]="chartData()" [options]="chartOptions"></canvas>
      </div>
    }
  `,
  styles: [`
    .otc-wrap { position: relative; height: 140px; }
    .otc-empty {
      font-size:  13px;
      color:      var(--color-text-3);
      text-align: center;
      padding:    var(--sp-3, 0.75rem) 0;
      margin:     0;
    }
  `],
})
export class ObsTrendChartComponent {
  readonly points = input.required<ObservationTrendPoint[]>();

  readonly chartData = computed<ChartData<'line'>>(() => {
    const p = this.points();
    return {
      labels: p.map(x => formatLabel(x.label)),
      datasets: [{
        label:                'Activity',
        data:                 p.map(x => Math.round(x.total_sec / 60)),
        borderColor:          '#10b981',
        backgroundColor:      'rgba(16,185,129,0.10)',
        fill:                 true,
        tension:              0.35,
        pointRadius:          p.length > 30 ? 0 : 3,
        pointHoverRadius:     5,
        pointBackgroundColor: '#10b981',
        borderWidth:          2,
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
          label: (ctx) => {
            const mins = ctx.raw as number;
            const h    = Math.floor(mins / 60);
            const m    = mins % 60;
            return ` ${h > 0 ? h + 'h ' : ''}${m}m observed`;
          },
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
        ticks: {
          color:    '#94a3b8',
          font:     { size: 9 },
          precision: 0,
          callback: (v) => `${v}m`,
        },
        grid:   { color: 'rgba(255,255,255,0.04)' },
        border: { display: false },
        min:    0,
      },
    },
  };
}
