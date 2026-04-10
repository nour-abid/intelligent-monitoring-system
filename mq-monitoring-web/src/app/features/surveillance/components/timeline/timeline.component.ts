import { Component, input } from '@angular/core';
import { TimelineSegment } from '../../models/timeline.model';
import { DurationPipe } from '../../../../shared/pipes/duration.pipe';
import {
  activityBadgeClass,
  activityColor,
} from '../../utils/activity-colors.util';
import { formatTimestamp } from '../../../../core/utils/duration.util';

@Component({
  selector: 'app-timeline',
  standalone: true,
  imports: [DurationPipe],
  templateUrl: './timeline.component.html',
  styleUrl: './timeline.component.scss',
})
export class TimelineComponent {
  readonly segments = input.required<TimelineSegment[]>();

  /** Format a raw timestamp string for display. */
  ts(value: string): string {
    return formatTimestamp(value);
  }

  /** CSS badge modifier class for an activity. */
  badgeClass(activity: string): string {
    return activityBadgeClass(activity);
  }

  /** Left-border accent colour for the segment row. */
  accentColor(activity: string): string {
    return activityColor(activity);
  }

  /** Confidence as display string. */
  confidenceStr(value: number): string {
    return value > 0 ? `${(value * 100).toFixed(0)}%` : '—';
  }

  /** Pretty-print the identity_source field. */
  sourceLabel(source: string): string {
    const map: Record<string, string> = {
      face:                    'Face',
      reassoc_face_confirmed:  'Reassoc (face)',
      reassoc_short_term:      'Reassoc (short)',
      manual:                  'Manual',
      none:                    '—',
    };
    return map[source] ?? source;
  }

  /** Replace underscore with space and capitalize for trigger labels. */
  triggerLabel(trigger: string): string {
    const label = trigger.replace(/_/g, ' ');
    return label.charAt(0).toUpperCase() + label.slice(1);
  }
}
