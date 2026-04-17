import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * PersonalActivityBreakdownComponent
 *
 * Displays a doughnut/pie chart showing activity distribution for the selected date range.
 * Shows: Working, Inactive, Using_Phone shares.
 */
@Component({
  selector: 'app-personal-activity-breakdown',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './personal-activity-breakdown.component.html',
  styleUrls: ['./personal-activity-breakdown.component.scss'],
})
export class PersonalActivityBreakdownComponent {
  @Input() overview: any;

  activities = [
    { name: 'Working',     value: 75, color: '#10b981', duration: '7h 45m' },
    { name: 'Inactive',    value: 14, color: '#f59e0b', duration: '1h 26m' },
    { name: 'Phone Usage', value: 11, color: '#ef4444', duration: '57m' },
  ];
}
