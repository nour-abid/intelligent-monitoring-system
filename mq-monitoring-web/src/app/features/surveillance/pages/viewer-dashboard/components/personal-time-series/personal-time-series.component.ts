import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * PersonalTimeSeriesComponent
 *
 * Displays a line chart with daily trend data for the selected metric.
 * Supports 4 metrics: working time, phone usage, inactivity events, focus score.
 */
@Component({
  selector: 'app-personal-time-series',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './personal-time-series.component.html',
  styleUrls: ['./personal-time-series.component.scss'],
})
export class PersonalTimeSeriesComponent {
  @Input() timeline: any;
  @Input() selectedMetric: 'working_time' | 'phone_usage' | 'inactivity' | 'focus_score' = 'working_time';
  @Input() dateRange: any;

  // Mock chart data — replace with real implementation using Chart.js or plotly
  chartData = {
    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
    values: [480, 510, 450, 480, 520],
    unit: 'minutes',
  };
}
