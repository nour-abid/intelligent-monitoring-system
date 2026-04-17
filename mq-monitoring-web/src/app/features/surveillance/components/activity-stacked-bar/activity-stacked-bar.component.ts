import { Component, computed, input } from '@angular/core';
import { BaseChartDirective } from 'ng2-charts';
import type { ChartData, ChartOptions } from 'chart.js';
import type { IdentityEntry } from '../../models/identities.model';
import { activityColor } from '../../utils/activity-colors.util';

const ACTIVITY_ORDER = ['Working', 'Inactive', 'Using_Phone'] as const;

@Component({
  selector: 'app-activity-stacked-bar',
  standalone: true,
  imports: [BaseChartDirective],
  template: `
    @if (visibleRows().length === 0) {
      <p class="asb-empty">No employee data in this period.</p>
    } @else {
      <div class="asb-wrap" [style.height]="containerHeight()">
        <canvas baseChart type="bar" [data]="chartData()" [options]="chartOptions"></canvas>
      </div>
    }
  `,
  styles: [`
    .asb-wrap { position: relative; }
    .asb-empty {
      font-size:  13px;
      color:      var(--color-text-3);
      text-align: center;
      padding:    var(--sp-3, 0.75rem) 0;
      margin:     0;
    }
  `],
})
export class ActivityStackedBarComponent {
  readonly rows = input.required<IdentityEntry[]>();

  readonly visibleRows = computed(() =>
    this.rows()
      .filter(r => r.identity_name !== 'Unknown' && r.total_sec > 0)
      .sort((a, b) => b.total_sec - a.total_sec)
      .slice(0, 8),
  );

  /** Dynamic height: 40px per row + 60px for legend/labels, min 140px. */
  readonly containerHeight = computed(() =>
    `${Math.max(this.visibleRows().length * 40 + 60, 140)}px`,
  );

  readonly chartData = computed<ChartData<'bar'>>(() => {
    const employees = this.visibleRows();
    return {
      labels: employees.map(e => e.identity_name),
      datasets: ACTIVITY_ORDER.map(act => ({
        label:           act.replace(/_/g, ' '),
        data:            employees.map(e => Math.round((e.activities[act] ?? 0) / 60)),
        backgroundColor: activityColor(act),
        borderWidth:     0,
        borderRadius:    2,
      })),
    };
  });

  readonly chartOptions: ChartOptions<'bar'> = {
    responsive:          true,
    maintainAspectRatio: false,
    animation:           false,
    indexAxis:           'y',
    scales: {
      x: {
        stacked: true,
        ticks: {
          color:    '#94a3b8',
          font:     { size: 10 },
          callback: (v) => `${v}m`,
        },
        grid:   { color: 'rgba(255,255,255,0.04)' },
        border: { display: false },
      },
      y: {
        stacked: true,
        ticks:  { color: '#94a3b8', font: { size: 11 } },
        grid:   { display: false },
        border: { display: false },
      },
    },
    plugins: {
      legend: {
        position: 'top',
        labels:   { color: '#94a3b8', font: { size: 11 }, boxWidth: 10, padding: 8 },
      },
      tooltip: {
        mode:      'index',
        intersect: false,
        callbacks: {
          label: (ctx) => {
            const mins = ctx.raw as number;
            if (mins === 0) return '';
            const h   = Math.floor(mins / 60);
            const m   = mins % 60;
            const dur = h > 0 ? `${h}h ${m}m` : `${m}m`;
            return ` ${ctx.dataset.label}: ${dur}`;
          },
          footer: (items) => {
            const total = items.reduce((sum, item) => sum + (item.raw as number), 0);
            if (total === 0) return '';
            const h   = Math.floor(total / 60);
            const m   = total % 60;
            return `Total: ${h > 0 ? h + 'h ' : ''}${m}m`;
          },
        },
      },
    },
  };
}
