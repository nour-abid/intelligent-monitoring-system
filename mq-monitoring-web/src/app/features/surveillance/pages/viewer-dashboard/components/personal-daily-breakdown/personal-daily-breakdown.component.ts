import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * PersonalDailyBreakdownComponent
 *
 * Displays a day-by-day breakdown table (or card list on mobile) with:
 * - Date, Observed Time, Top Activity, Phone Usage, Focus Score, Late Arrival, Early Leave
 */
@Component({
  selector: 'app-personal-daily-breakdown',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './personal-daily-breakdown.component.html',
  styleUrls: ['./personal-daily-breakdown.component.scss'],
})
export class PersonalDailyBreakdownComponent {
  @Input() timeline: any;
  @Input() dateRange: any;

  dailyData = [
    { date: 'Mon, Apr 7', time: '8h 45m', topActivity: 'Working', phone: '32m', focus: '72%', late: false, early: false },
    { date: 'Tue, Apr 8', time: '8h 32m', topActivity: 'Working', phone: '28m', focus: '68%', late: false, early: false },
    { date: 'Wed, Apr 9', time: '7h 15m', topActivity: 'Working', phone: '45m', focus: '64%', late: true, early: false },
    { date: 'Thu, Apr 10', time: '8h 50m', topActivity: 'Working', phone: '22m', focus: '75%', late: false, early: false },
    { date: 'Fri, Apr 11', time: '8h 20m', topActivity: 'Working', phone: '38m', focus: '70%', late: false, early: true },
  ];
}
