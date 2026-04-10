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
import { CommonModule } from '@angular/common';
import { AlertService } from '../../../core/services/alert.service';
import { ReplayState } from '../../../core/models/alert.model';

@Component({
  selector: 'app-replay-modal',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './replay-modal.component.html',
  styleUrl: './replay-modal.component.scss',
})
export class ReplayModalComponent implements OnInit, OnDestroy {
  @Input({ required: true }) alertId!: number;
  @Input() alertLabel = '';
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
}
