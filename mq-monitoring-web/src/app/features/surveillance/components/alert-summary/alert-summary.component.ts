import { Component, computed, input } from '@angular/core';
import { DashboardSummaryResponse } from '../../models/summary.model';
import { TrendLineChartComponent } from '../trend-line-chart/trend-line-chart.component';
import { AlertsBarChartComponent } from '../alerts-bar-chart/alerts-bar-chart.component';

@Component({
  selector: 'app-alert-summary',
  standalone: true,
  imports: [
    TrendLineChartComponent,
    AlertsBarChartComponent,
  ],
  templateUrl: './alert-summary.component.html',
  styleUrl: './alert-summary.component.scss',
})
export class AlertSummaryComponent {
  readonly data = input.required<DashboardSummaryResponse>();

  // ── Top affected employees (horizontal bars) ────────────────────────────

  readonly topEmployees = computed(() => {
    const items = this.data().charts.top_affected_employees;
    const max   = Math.max(...items.map(i => i.count), 1);
    return items.map(item => ({
      ...item,
      label:    item.display_name ?? item.identity,
      widthPct: `${((item.count / max) * 100).toFixed(1)}%`,
    }));
  });
}
