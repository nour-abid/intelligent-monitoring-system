import { Component, input, output } from '@angular/core';
import { IdentityRow } from '../../models/identities.model';
import { DurationPipe } from '../../../../shared/pipes/duration.pipe';
import { activityBadgeClass } from '../../utils/activity-colors.util';

@Component({
  selector: 'app-identity-table',
  standalone: true,
  imports: [DurationPipe],
  templateUrl: './identity-table.component.html',
  styleUrl: './identity-table.component.scss',
})
export class IdentityTableComponent {
  readonly rows = input.required<IdentityRow[]>();

  /** Emits the identity_name when the user clicks a row or the view button. */
  readonly identitySelected = output<string>();

  select(name: string): void {
    this.identitySelected.emit(name);
  }

  /** CSS badge modifier class for the top activity pill. */
  badgeClass(activity: string): string {
    return activityBadgeClass(activity);
  }

  /** Confidence as a percentage string. */
  confidencePct(value: number): string {
    return value > 0 ? `${(value * 100).toFixed(0)}%` : '—';
  }

  /** Smart behaviour flags for an identity row. */
  flags(row: IdentityRow): string[] {
    const tags: string[] = [];
    const rows = this.rows();
    if (!rows.length || row.total_sec === 0) return tags;

    const maxSec = Math.max(...rows.map(r => r.total_sec));
    if (row.total_sec === maxSec) tags.push('Most active');

    const phonePct    = (row.activities['Using_Phone'] ?? 0) / row.total_sec;
    const inactivePct = (row.activities['Inactive']    ?? 0) / row.total_sec;

    if (phonePct    > 0.25) tags.push('High phone usage');
    if (inactivePct > 0.40) tags.push('Mostly inactive');

    const sorted = rows.slice().sort((a, b) => a.total_sec - b.total_sec);
    const median = sorted[Math.floor(sorted.length / 2)]?.total_sec ?? 0;
    if (median > 0 && row.total_sec < median * 0.45 && !tags.includes('Most active')) {
      tags.push('Low activity');
    }

    return tags;
  }
}
