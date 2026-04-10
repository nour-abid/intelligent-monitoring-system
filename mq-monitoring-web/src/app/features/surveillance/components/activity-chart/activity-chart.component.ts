import { Component, input } from '@angular/core';
import { ActivityStat } from '../../models/overview.model';
import { activityColor } from '../../utils/activity-colors.util';

@Component({
  selector: 'app-activity-chart',
  standalone: true,
  imports: [],
  templateUrl: './activity-chart.component.html',
  styleUrl: './activity-chart.component.scss',
})
export class ActivityChartComponent {
  readonly stats = input.required<ActivityStat[]>();

  /** Returns the fill colour for a given activity label. */
  color(activity: string): string {
    return activityColor(activity);
  }

  /**
   * Returns a percentage string for the CSS bar width.
   * share is 0–1; multiply by 100 for %.
   */
  pct(share: number): string {
    return `${(share * 100).toFixed(1)}%`;
  }

  /**
   * Make the activity label display-friendly:
   * 'Using_Phone' → 'Using Phone'
   */
  displayLabel(activity: string): string {
    return activity.replace(/_/g, ' ');
  }
}
