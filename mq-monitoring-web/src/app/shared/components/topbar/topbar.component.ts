import { DatePipe } from '@angular/common';
import { Component, HostListener, inject, signal } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { AlertService } from '../../../core/services/alert.service';
import { ThemeService } from '../../../core/services/theme.service';
import { AlertItem, EvidenceMeta } from '../../../core/models/alert.model';
import { ReplayModalComponent } from '../replay-modal/replay-modal.component';

@Component({
  selector: 'app-topbar',
  standalone: true,
  imports: [RouterLink, RouterLinkActive, DatePipe, ReplayModalComponent],
  templateUrl: './topbar.component.html',
  styleUrl: './topbar.component.scss',
})
export class TopbarComponent {
  protected readonly auth  = inject(AuthService);
  protected readonly alert = inject(AlertService);
  protected readonly theme = inject(ThemeService);

  protected panelOpen      = signal(false);
  protected replayAlertId  = signal<number | null>(null);
  protected replayLabel    = signal('');
  protected replayMeta     = signal<EvidenceMeta | null>(null);

  togglePanel(): void {
    this.panelOpen.update(o => !o);
    if (this.panelOpen()) {
      this.alert.markAllRead();
    }
  }

  /** Close the panel when user clicks outside it. */
  @HostListener('document:click', ['$event'])
  onDocumentClick(event: Event): void {
    const target = event.target as HTMLElement;
    if (!target.closest('.topbar__alerts')) {
      this.panelOpen.set(false);
    }
  }

  openReplay(a: AlertItem): void {
    this.panelOpen.set(false);
    this.replayLabel.set(`${a.identity} — ${this.alertLabel(a.type)}`);
    this.replayAlertId.set(a.serverId!);
    this.replayMeta.set({
      identity_name:    a.identity,
      event_type:       a.type,
      started_at:       a.timestamp,
      duration_minutes: a.duration_minutes,
    });
  }

  closeReplay(): void {
    this.replayAlertId.set(null);
    this.replayMeta.set(null);
  }

  logout(): void {
    this.auth.logout();
  }

  protected alertLabel(type: string): string {
    switch (type) {
      case 'inactive':     return 'Inactive';
      case 'phone':        return 'Using Phone';
      case 'late_arrival': return 'Late Arrival';
      default:             return type;
    }
  }
}
