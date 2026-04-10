import { Component, computed, input } from '@angular/core';
import { BaseChartDirective } from 'ng2-charts';
import type { ChartData, ChartOptions } from 'chart.js';
import type { ActivityDistributionItem } from '../../models/summary.model';
import { activityColor } from '../../utils/activity-colors.util';

function formatSec(sec: number): string {
  if (sec < 60)   return `${sec}s`;
  if (sec < 3600) return `${Math.round(sec / 60)}m`;
  const h = Math.floor(sec / 3600);
  const m = Math.round((sec % 3600) / 60);
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

@Component({
  selector: 'app-activity-doughnut-chart',
  standalone: true,
  imports: [BaseChartDirective],
  template: `
    @if (items().length === 0) {
      <p class="adc-empty">No activity data in this period.</p>
    } @else {
      <div class="adc-wrap">
        <canvas baseChart type="doughnut" [data]="chartData()" [options]="chartOptions"></canvas>
        <div class="adc-center" aria-hidden="true">
          <span class="adc-center__pct">{{ topPct() }}</span>
          <span class="adc-center__name">{{ topActivity() }}</span>
        </div>
      </div>
      <ul class="adc-stats">
        @for (item of items(); track item.activity) {
          <li class="adc-stats__row">
            <span class="adc-stats__dot" [style.background]="itemColor(item.activity)"></span>
            <span class="adc-stats__name">{{ item.activity.replace('_', ' ') }}</span>
            <div class="adc-stats__track">
              <div class="adc-stats__fill" [style.width.%]="item.share * 100" [style.background]="itemColor(item.activity)"></div>
            </div>
            <span class="adc-stats__pct">{{ formatShare(item.share) }}</span>
            <span class="adc-stats__time">{{ formatSec(item.total_sec) }}</span>
          </li>
        }
      </ul>
    }
  `,
  styles: [`
    .adc-wrap { position: relative; height: 170px; }
    .adc-empty {
      font-size:  13px;
      color:      var(--color-text-3);
      text-align: center;
      padding:    var(--sp-3, 0.75rem) 0;
      margin:     0;
    }
    .adc-center {
      position:       absolute;
      top:            50%;
      left:           35%;
      transform:      translate(-50%, -50%);
      text-align:     center;
      pointer-events: none;
      line-height:    1.25;
    }
    .adc-center__pct  { display: block; font-size: 17px; font-weight: 700; color: var(--color-text-1, #f1f5f9); }
    .adc-center__name { display: block; font-size: 10px; color: var(--color-text-3, #94a3b8); margin-top: 1px; }
    .adc-stats {
      list-style: none;
      margin:     8px 0 0;
      padding:    0;
      display:    flex;
      flex-direction: column;
      gap:        5px;
    }
    .adc-stats__row {
      display:     flex;
      align-items: center;
      gap:         6px;
      font-size:   11px;
    }
    .adc-stats__dot {
      width:         7px;
      height:        7px;
      border-radius: 50%;
      flex-shrink:   0;
    }
    .adc-stats__name {
      width:       80px;
      flex-shrink: 0;
      color:       var(--color-text-2, #cbd5e1);
      white-space: nowrap;
      overflow:    hidden;
      text-overflow: ellipsis;
    }
    .adc-stats__track {
      flex:          1;
      height:        3px;
      border-radius: 2px;
      background:    var(--color-border, rgba(255,255,255,.08));
      overflow:      hidden;
    }
    .adc-stats__fill {
      height:        100%;
      border-radius: 2px;
      opacity:       0.75;
    }
    .adc-stats__pct {
      width:       32px;
      text-align:  right;
      flex-shrink: 0;
      color:       var(--color-text-1, #f1f5f9);
      font-variant-numeric: tabular-nums;
      font-weight: 600;
    }
    .adc-stats__time {
      width:       44px;
      text-align:  right;
      flex-shrink: 0;
      color:       var(--color-text-3, #94a3b8);
      font-variant-numeric: tabular-nums;
    }
  `],
})
export class ActivityDoughnutChartComponent {
  readonly items = input.required<ActivityDistributionItem[]>();

  readonly topActivity = computed(() => {
    const items = this.items();
    if (!items.length) return '';
    return items.reduce((a, b) => a.total_sec > b.total_sec ? a : b).activity.replace(/_/g, ' ');
  });

  readonly topPct = computed(() => {
    const items = this.items();
    if (!items.length) return '';
    const total = items.reduce((s, i) => s + i.total_sec, 0);
    const top   = items.reduce((a, b) => a.total_sec > b.total_sec ? a : b);
    return total > 0 ? `${Math.round((top.total_sec / total) * 100)}%` : '';
  });

  itemColor(activity: string): string { return activityColor(activity); }

  formatShare(share: number): string { return `${Math.round(share * 100)}%`; }

  formatSec(sec: number): string {
    if (sec < 60)   return `${sec}s`;
    if (sec < 3600) return `${Math.round(sec / 60)}m`;
    const h = Math.floor(sec / 3600);
    const m = Math.round((sec % 3600) / 60);
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
  }

  readonly chartData = computed<ChartData<'doughnut'>>(() => {
    const i = this.items();
    return {
      labels: i.map(x => x.activity.replace(/_/g, ' ')),
      datasets: [{
        data:            i.map(x => Math.round(x.total_sec)),
        backgroundColor: i.map(x => activityColor(x.activity)),
        borderColor:     'transparent',
        borderWidth:     2,
        hoverOffset:     6,
      }],
    };
  });

  readonly chartOptions: ChartOptions<'doughnut'> = {
    responsive:          true,
    maintainAspectRatio: false,
    animation:           false,
    cutout:              '65%',
    plugins: {
      legend: {
        position: 'right',
        labels: {
          color:    '#94a3b8',
          font:     { size: 11 },
          boxWidth: 8,
          padding:  8,
        },
      },
      tooltip: {
        callbacks: {
          label: (ctx) => {
            const sec   = ctx.raw as number;
            const total = (ctx.dataset.data as number[]).reduce((a, b) => a + b, 0);
            const pct   = total > 0 ? ((sec / total) * 100).toFixed(1) : '0';
            return ` ${formatSec(sec)}  (${pct}%)`;
          },
        },
      },
    },
  };
}
