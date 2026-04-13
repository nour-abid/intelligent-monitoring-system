import {
  Component,
  EventEmitter,
  HostListener,
  inject,
  Input,
  OnDestroy,
  OnInit,
  Output,
  signal,
} from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { AlertService } from '../../../core/services/alert.service';
import { EvidenceMeta, ReplayState } from '../../../core/models/alert.model';

@Component({
  selector: 'app-replay-modal',
  standalone: true,
  imports: [CommonModule, DatePipe],
  templateUrl: './replay-modal.component.html',
  styleUrl: './replay-modal.component.scss',
})
export class ReplayModalComponent implements OnInit, OnDestroy {
  @Input({ required: true }) alertId!: number;
  @Input() alertLabel = '';
  /** Optional structured metadata to display in the right-hand Evidence panel. */
  @Input() metadata: EvidenceMeta | null = null;
  @Output() closed = new EventEmitter<void>();

  private readonly alertService = inject(AlertService);

  readonly state = signal<ReplayState>({ status: 'idle' });

  ngOnInit(): void {
    this.state.set({ status: 'loading' });
    this.alertService.fetchReplayBlob(this.alertId).subscribe({
      next: (blob) => {
        const objectUrl = URL.createObjectURL(blob);
        this.state.set({ status: 'ready', objectUrl });
      },
      error: (err) => {
        const body = err?.error;
        if (err?.status === 404 && body?.code === 'no_replay_source') {
          this.state.set({ status: 'unavailable', reason: 'No replay source has been registered for this alert.' });
        } else {
          const reason = body?.message ?? 'Failed to load the replay clip.';
          this.state.set({ status: 'error', reason });
        }
      },
    });
  }

  ngOnDestroy(): void {
    const s = this.state();
    if (s.status === 'ready') {
      URL.revokeObjectURL(s.objectUrl);
    }
  }

  close(): void {
    this.closed.emit();
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.close();
  }

  /** Human-readable label for an event type slug. */
  eventLabel(type: string | undefined): string {
    if (!type) return '—';
    switch (type.toLowerCase()) {
      case 'phone':
      case 'using_phone': return 'Phone Use';
      case 'inactive':   return 'Inactivity';
      case 'late_arrival': return 'Late Arrival';
      case 'early_leave':  return 'Early Departure';
      default: return type.replace(/_/g, ' ');
    }
  }

  /** CSS modifier for the event-type badge colour. */
  eventBadgeMod(type: string | undefined): string {
    switch ((type ?? '').toLowerCase()) {
      case 'phone':
      case 'using_phone': return 'phone';
      case 'inactive':    return 'inactive';
      case 'late_arrival': return 'late';
      case 'early_leave':  return 'early';
      default: return 'default';
    }
  }

  /** Format a duration given in minutes as "X min" or "Xh Ym". */
  durationLabel(minutes: number | undefined): string {
    if (minutes == null) return '—';
    const m = Math.round(minutes);
    if (m < 60) return `${m} min`;
    const h = Math.floor(m / 60);
    const rem = m % 60;
    return rem > 0 ? `${h} h ${rem} min` : `${h} h`;
  }

  /** First two uppercase characters for the identity avatar. */
  initials(name: string | undefined): string {
    if (!name) return '?';
    return name.trim().slice(0, 2).toUpperCase();
  }
}
