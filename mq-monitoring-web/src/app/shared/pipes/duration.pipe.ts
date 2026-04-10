import { Pipe, PipeTransform } from '@angular/core';
import { formatDuration } from '../../core/utils/duration.util';

/** Transforms a raw seconds number to a human-readable duration string. */
@Pipe({ name: 'duration', standalone: true })
export class DurationPipe implements PipeTransform {
  transform(seconds: number | null | undefined): string {
    if (seconds == null) return '—';
    return formatDuration(seconds);
  }
}
