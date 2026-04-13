import { Component, Input, computed } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface KPICard {
  title: string;
  value: string;
  subtitle: string;
  color: 'primary' | 'accent' | 'success' | 'warning';
  icon: string;
}

/**
 * PersonalStatsCardsComponent
 *
 * Displays 4 KPI cards for the current user:
 * 1. Observed Time (Today)
 * 2. Top Activity (Today)
 * 3. Phone Usage (Today)
 * 4. Focus Score (Today)
 *
 * Cards are arranged in a responsive 2x2 grid, collapsing to 1 column on mobile.
 */
@Component({
  selector: 'app-personal-stats-cards',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './personal-stats-cards.component.html',
  styleUrls: ['./personal-stats-cards.component.scss'],
})
export class PersonalStatsCardsComponent {
  @Input() overview: any;
  @Input() timeline: any;
  @Input() summary: any;

  readonly cards = computed(() => {
    const data = this.buildKPIData();
    return data;
  });

  private buildKPIData(): KPICard[] {
    // Mock implementation — replace with real data extraction
    return [
      {
        title: 'Observed Time',
        value: this.formatSeconds(this.getObservedSeconds()),
        subtitle: 'Last 5 days avg: 7h 45m',
        color: 'primary',
        icon: 'clock',
      },
      {
        title: 'Top Activity',
        value: this.getTopActivity(),
        subtitle: `${this.getTopActivityDuration()}% of observed time`,
        color: 'accent',
        icon: 'trending-up',
      },
      {
        title: 'Phone Usage',
        value: `${this.getPhoneUsageMinutes()}m`,
        subtitle: `${this.getPhoneUsagePercent()}% of observed time`,
        color: 'warning',
        icon: 'smartphone',
      },
      {
        title: 'Focus Score',
        value: `${this.getFocusScorePercent()}%`,
        subtitle: this.getFocusScoreLabel(),
        color: 'success',
        icon: 'target',
      },
    ];
  }

  private getObservedSeconds(): number {
    const dist = this.overview?.distribution;
    const total = this.overview?.total_sec || 0;
    return total;
  }

  private getTopActivity(): string {
    const dist = this.overview?.distribution || {};
    let top = 'Working';
    let maxShare = 0;

    Object.entries(dist).forEach(([activity, share]: [string, any]) => {
      if (share > maxShare) {
        maxShare = share;
        top = activity;
      }
    });

    return top;
  }

  private getTopActivityDuration(): number {
    const dist = this.overview?.distribution || {};
    const topShare = Math.max(...Object.values(dist || {})) || 0;
    return Math.round(topShare * 100);
  }

  private getPhoneUsageMinutes(): number {
    const dist = this.overview?.distribution || {};
    const phoneSeconds = (dist['Using_Phone'] || 0) * (this.overview?.total_sec || 0);
    return Math.round(phoneSeconds / 60);
  }

  private getPhoneUsagePercent(): number {
    const dist = this.overview?.distribution || {};
    return Math.round((dist['Using_Phone'] || 0) * 100);
  }

  private getFocusScorePercent(): number {
    const workingShare = this.overview?.distribution?.['Working'] || 0;
    return Math.round(workingShare * 100);
  }

  private getFocusScoreLabel(): string {
    const score = this.getFocusScorePercent();

    if (score >= 80) return 'Excellent';
    if (score >= 60) return 'Good';
    if (score >= 40) return 'Moderate';
    return 'Low';
  }

  private formatSeconds(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    return `${hours}h ${mins}m`;
  }
}
