import { Component, computed, input } from '@angular/core';
import { BaseChartDirective } from 'ng2-charts';
import type { ChartData, ChartOptions, ChartType } from 'chart.js';
import type { ChartSpec } from '../../../../core/services/chat-state.service';

function formatValue(value: number, unit?: string): string {
  if (unit === '%')     return `${value.toFixed(1)}%`;
  if (unit === 'count') return String(Math.round(value));
  // seconds
  if (value >= 3600) return `${(value / 3600).toFixed(1)}h`;
  if (value >= 60)   return `${Math.round(value / 60)}m`;
  return `${Math.round(value)}s`;
}

@Component({
  selector: 'app-chat-chart',
  standalone: true,
  imports: [BaseChartDirective],
  template: `
    <div class="cc-wrap">
      @if (spec().title) {
        <p class="cc-title">{{ spec().title }}</p>
      }
      @if (hasData()) {
        <div class="cc-canvas-wrap" [class.cc-canvas-wrap--pie]="isPie()">
          <canvas baseChart
            [type]="chartJsType()"
            [data]="chartData()"
            [options]="chartOptions()">
          </canvas>
        </div>
      } @else {
        <div class="cc-no-data">No data available for this period</div>
      }
    </div>
  `,
  styles: [`
    .cc-wrap {
      background: var(--color-bg, #0f1117);
      border: 1px solid var(--color-border, #252d3d);
      border-radius: 10px;
      padding: 16px;
      margin: 10px 0;
      min-width: 260px;
    }
    .cc-title {
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--color-text-2, #8892a8);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin: 0 0 12px;
    }
    .cc-canvas-wrap {
      position: relative;
      height: 220px;
    }
    .cc-canvas-wrap--pie {
      height: 200px;
    }
    .cc-no-data {
      height: 60px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.8rem;
      color: var(--color-text-3, #4a5568);
      font-style: italic;
    }
  `],
})
export class ChatChartComponent {
  readonly spec = input.required<ChartSpec>();

  readonly hasData = computed(() => this.spec().values.length > 0);

  readonly isPie = computed(() =>
    this.spec().type === 'pie' || this.spec().type === 'line'
      ? this.spec().type === 'pie'
      : false
  );

  readonly chartJsType = computed<ChartType>(() => {
    const t = this.spec().type;
    if (t === 'pie') return 'doughnut';
    if (t === 'line') return 'line';
    return 'bar';
  });

  readonly chartData = computed<ChartData>(() => {
    const s     = this.spec();
    const type  = this.chartJsType();
    const unit  = s.unit;

    if (type === 'doughnut') {
      return {
        labels:   s.labels,
        datasets: [{
          data:            s.values,
          backgroundColor: s.colors,
          borderWidth:     2,
          borderColor:     '#0f1117',
          hoverOffset:     8,
        }],
      };
    }

    if (type === 'line') {
      const color = s.colors[0] ?? '#6366f1';
      return {
        labels:   s.labels,
        datasets: [{
          data:                s.values,
          borderColor:         color,
          backgroundColor:     color + '22',
          fill:                true,
          tension:             0.35,
          pointRadius:         s.labels.length > 20 ? 0 : 3,
          pointHoverRadius:    5,
          pointBackgroundColor: color,
          borderWidth:         2,
        }],
      };
    }

    // bar
    return {
      labels:   s.labels,
      datasets: [{
        data:            s.values,
        backgroundColor: s.colors,
        borderRadius:    4,
        borderSkipped:   false,
      }],
    };
  });

  readonly chartOptions = computed<ChartOptions>(() => {
    const s    = this.spec();
    const type = this.chartJsType();
    const unit = s.unit;

    const tooltipLabel = (ctx: any) => ` ${formatValue(ctx.raw as number, unit)}`;

    if (type === 'doughnut') {
      return {
        responsive:           true,
        maintainAspectRatio:  false,
        animation:            false,
        cutout:               '60%',
        plugins: {
          legend: {
            display:  true,
            position: 'right',
            labels: {
              color:     '#8892a8',
              font:      { size: 11 },
              boxWidth:  12,
              padding:   10,
            },
          },
          tooltip: {
            callbacks: { label: (ctx: any) => ` ${ctx.label}: ${formatValue(ctx.raw as number, unit)}` },
          },
        },
      };
    }

    const scaleBase = {
      ticks:  { color: '#8892a8', font: { size: 10 } },
      grid:   { color: '#252d3d' },
      border: { display: false },
    };

    return {
      responsive:           true,
      maintainAspectRatio:  false,
      animation:            false,
      indexAxis:            (s.labels.length > 6 && type !== 'line') ? 'y' as const : 'x' as const,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: { label: tooltipLabel },
        },
      },
      scales: {
        x: { ...scaleBase, ticks: { ...scaleBase.ticks, maxRotation: 0, maxTicksLimit: 10 } },
        y: { ...scaleBase, ticks: { ...scaleBase.ticks, callback: (v: any) => formatValue(Number(v), unit) } },
      },
    };
  });
}
