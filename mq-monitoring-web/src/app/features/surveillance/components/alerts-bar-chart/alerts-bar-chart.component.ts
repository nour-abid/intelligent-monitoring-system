import { Component, computed, input } from '@angular/core';
import { BaseChartDirective } from 'ng2-charts';
import type { ChartData, ChartOptions } from 'chart.js';
import type { AlertsByTypeItem } from '../../models/summary.model';

const ALERT_COLORS: Record<string, string> = {
  inactive:     '#f59e0b',
  phone:        '#ef4444',
  late_arrival: '#3b82f6',
  early_leave:  '#8b5cf6',
};

const ALERT_LABELS: Record<string, string> = {
  inactive:     'Inactive',
  phone:        'Using Phone',
  late_arrival: 'Late Arrival',
  early_leave:  'Early Leave',
};

@Component({
  selector: 'app-alerts-bar-chart',
  standalone: true,
  imports: [BaseChartDirective],
  template: `
    @if (items().length === 0) {
      <p class="abc-empty">No alerts in this period.</p>
    } @else {
      <div class="abc-wrap">
        <canvas baseChart type="bar" [data]="chartData()" [options]="chartOptions"></canvas>
      </div>
    }
  `,
  styles: [`
    .abc-wrap { position: relative; height: 160px; }
    .abc-empty {
      font-size:  13px;
      color:      var(--color-text-3);
      text-align: center;
      padding:    var(--sp-3, 0.75rem) 0;
      margin:     0;
    }
  `],
})
export class AlertsBarChartComponent {
  readonly items = input.required<AlertsByTypeItem[]>();

  readonly chartData = computed<ChartData<'bar'>>(() => {
    const i = this.items();
    return {
      labels: i.map(x => ALERT_LABELS[x.type] ?? x.type.replace(/_/g, ' ')),
      datasets: [{
        data:            i.map(x => x.count),
        backgroundColor: i.map(x => ALERT_COLORS[x.type] ?? '#94a3b8'),
        borderRadius:    4,
        borderSkipped:   false,
        barThickness:    20,
      }],
    };
  });

  readonly chartOptions: ChartOptions<'bar'> = {
    indexAxis:           'y',
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
        ticks:  { color: '#94a3b8', font: { size: 10 } },
        grid:   { color: 'rgba(148,163,184,0.10)' },
        border: { display: false },
        beginAtZero: true,
      },
      y: {
        ticks:  { color: '#94a3b8', font: { size: 11 } },
        grid:   { display: false },
        border: { display: false },
      },
    },
  };
}
