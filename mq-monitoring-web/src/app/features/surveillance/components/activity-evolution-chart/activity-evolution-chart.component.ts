import { Component, computed, input } from '@angular/core';
import { BaseChartDirective } from 'ng2-charts';
import type { ChartData, ChartOptions } from 'chart.js';
import type { ActivityEvolutionPoint } from '../../models/summary.model';
import { activityColor } from '../../utils/activity-colors.util';

function formatLabel(raw: string): string {
  if (raw.includes(' ')) {
    const time = raw.split(' ')[1] ?? raw;
    return time.substring(0, 5); // 'HH:MM'
  }
  const d = new Date(raw + 'T00:00:00');
  if (isNaN(d.getTime())) return raw;
  return d.toLocaleDateString('en-GB', { month: 'short', day: 'numeric' });
}

function formatSec(sec: number): string {
  if (sec < 60)   return `${sec}s`;
  if (sec < 3600) return `${Math.round(sec / 60)}m`;
  const h = Math.floor(sec / 3600);
  const m = Math.round((sec % 3600) / 60);
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

interface Dataset {
  key:   keyof Omit<ActivityEvolutionPoint, 'label'>;
  label: string;
}

const DATASETS: Dataset[] = [
  { key: 'working',     label: 'Working'     },
  { key: 'meeting',     label: 'Meeting'     },
  { key: 'inactive',    label: 'Inactive'    },
  { key: 'using_phone', label: 'Using Phone' },
];

function hexToRgba(hex: string, alpha: number): string {
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return `rgba(${r},${g},${b},${alpha})`;
}

@Component({
  selector: 'app-activity-evolution-chart',
  standalone: true,
  imports: [BaseChartDirective],
  template: `
    @if (points().length === 0) {
      <p class="aec-empty">No activity data in this period.</p>
    } @else {
      <div class="aec-wrap">
        <canvas baseChart type="line" [data]="chartData()" [options]="chartOptions"></canvas>
      </div>
    }
  `,
  styles: [`
    .aec-wrap { position: relative; height: 200px; }
    .aec-empty {
      font-size:  13px;
      color:      var(--color-text-3);
      text-align: center;
      padding:    var(--sp-3, 0.75rem) 0;
      margin:     0;
    }
  `],
})
export class ActivityEvolutionChartComponent {
  readonly points = input.required<ActivityEvolutionPoint[]>();

  readonly chartData = computed<ChartData<'line'>>(() => {
    const p = this.points();
    const dense = p.length > 30;

    return {
      labels: p.map(x => formatLabel(x.label)),
      datasets: DATASETS.map(ds => {
        const actKey = ds.key === 'using_phone' ? 'Using_Phone'
                     : ds.key === 'working'     ? 'Working'
                     : ds.key === 'meeting'     ? 'Meeting'
                     : 'Inactive';
        const color = activityColor(actKey);
        return {
          label:                ds.label,
          data:                 p.map(x => Math.round(x[ds.key] / 60)), // seconds → minutes
          borderColor:          color,
          backgroundColor:      hexToRgba(color, 0.18),
          fill:                 true,
          tension:              0.35,
          pointRadius:          dense ? 0 : 2,
          pointHoverRadius:     4,
          pointBackgroundColor: color,
          borderWidth:          2,
        };
      }),
    };
  });

  readonly chartOptions: ChartOptions<'line'> = {
    responsive:          true,
    maintainAspectRatio: false,
    animation:           false,
    interaction: {
      mode:      'index',
      intersect: false,
    },
    plugins: {
      legend: {
        position: 'top',
        labels:   { color: '#94a3b8', font: { size: 11 }, boxWidth: 10, padding: 8 },
      },
      tooltip: {
        callbacks: {
          label: (ctx) => {
            const mins = ctx.raw as number;
            if (mins === 0) return '';
            const h = Math.floor(mins / 60);
            const m = mins % 60;
            return ` ${ctx.dataset.label}: ${h > 0 ? h + 'h ' : ''}${m}m`;
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
        stacked: true,
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
