import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface Insight {
  type: 'tip' | 'positive' | 'caution';
  title: string;
  message: string;
  icon: string;
}

/**
 * PersonalInsightsBoxComponent
 *
 * Displays 3-5 auto-generated coaching insights and recommendations.
 * Insights are based on activity patterns, phone usage, inactivity, arrivals/departures.
 *
 * Types: 'tip' (suggestion), 'positive' (encouragement), 'caution' (warning)
 */
@Component({
  selector: 'app-personal-insights-box',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './personal-insights-box.component.html',
  styleUrls: ['./personal-insights-box.component.scss'],
})
export class PersonalInsightsBoxComponent {
  @Input() summary: any;
  @Input() timeline: any;
  @Input() userName: string = 'User';

  insights: Insight[] = [
    {
      type: 'positive',
      title: 'Great Focus This Week!',
      message: 'You had 3 focus blocks over 60 minutes — excellent work maintaining deep work sessions.',
      icon: 'star',
    },
    {
      type: 'tip',
      title: 'Phone Usage Pattern',
      message:
        'Consider scheduling a dedicated break time instead of spreading phone use throughout the day. This can boost focus.',
      icon: 'lightbulb',
    },
    {
      type: 'caution',
      title: 'Time Management Notice',
      message: 'You left early on Tue and Wed. Make sure to plan your day to complete tasks on time.',
      icon: 'clock',
    },
    {
      type: 'tip',
      title: 'Your Productive Peak',
      message: 'Your most productive time is 10am-12pm. Schedule important work during this window.',
      icon: 'trending-up',
    },
  ];
}
