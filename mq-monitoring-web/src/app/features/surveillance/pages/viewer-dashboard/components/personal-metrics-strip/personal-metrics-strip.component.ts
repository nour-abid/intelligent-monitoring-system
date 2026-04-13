import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * PersonalMetricsStripComponent
 *
 * Displays 6 horizontal metrics for the current user:
 * 1. Late Arrivals (This Month)
 * 2. Early Leaves (This Month)
 * 3. Inactivity Events (Today)
 * 4. Longest Focus Block (Today)
 * 5. Meetings (Today)
 * 6. Total Break Time (Today)
 *
 * Horizontally scrollable on mobile.
 */
@Component({
  selector: 'app-personal-metrics-strip',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './personal-metrics-strip.component.html',
  styleUrls: ['./personal-metrics-strip.component.scss'],
})
export class PersonalMetricsStripComponent {
  @Input() overview: any;
  @Input() timeline: any;
  @Input() summary: any;

  metrics = [
    { label: 'Late Arrivals', value: '2', unit: 'This month' },
    { label: 'Early Leaves', value: '1', unit: 'This month' },
    { label: 'Inactivity Events', value: '5', unit: 'Today' },
    { label: 'Longest Focus', value: '2h 15m', unit: 'Today' },
    { label: 'Meetings', value: '3', unit: 'Today' },
    { label: 'Break Time', value: '45m', unit: 'Today' },
  ];
}
