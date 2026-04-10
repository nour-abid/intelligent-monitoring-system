import { computed, effect, inject, Injectable, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { AuthService } from './auth.service';
import { AlertItem, AlertHistoryRow } from '../models/alert.model';
import { AlertToastService } from './alert-toast.service';
import { environment } from '../../../environments/environment';

/** Maximum alerts kept in memory. Oldest are discarded. */
const MAX_ALERTS = 50;

/**
 * AlertService
 *
 * Manages the realtime WebSocket connection to Laravel Reverb and
 * accumulates incoming behavior alerts.
 *
 * Lifecycle:
 *  - Automatically connects when `auth.user()` becomes non-null.
 *  - Automatically disconnects and clears alerts on logout.
 *  - Subscribes to the private channel `alerts.{userId}` which scopes
 *    delivery to the authenticated user (admin or superviseur).
 *
 * Usage:
 *  - `alertService.alerts()` — signal<AlertItem[]>
 *  - `alertService.unreadCount()` — computed count of unread alerts
 *  - `alertService.markAllRead()` — mark all current alerts as read
 */
@Injectable({ providedIn: 'root' })
export class AlertService {
  private readonly auth        = inject(AuthService);
  private readonly toastService = inject(AlertToastService);
  private readonly http        = inject(HttpClient);

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private echo: Echo<any> | null = null;

  readonly alerts      = signal<AlertItem[]>([]);
  readonly unreadCount = computed(() => this.alerts().filter(a => !a.read).length);

  constructor() {
    // React to login / logout by connecting or disconnecting.
    effect(() => {
      const user  = this.auth.user();
      const token = this.auth.token();

      if (user && token) {
        this.loadHistory();
        this.connect(user.id, token);
      } else {
        this.echo?.disconnect();
        this.echo = null;
        this.alerts.set([]);
      }
    }, { allowSignalWrites: true });
  }

  markAllRead(): void {
    this.alerts.update(list => list.map(a => ({ ...a, read: true })));
  }

  fetchReplayBlob(alertId: number): Observable<Blob> {
    return this.http.get(
      `/api/monitoring/surveillance/alerts/${alertId}/replay`,
      { responseType: 'blob' }
    );
  }

  // -------------------------------------------------------------------------
  // Private
  // -------------------------------------------------------------------------

  /**
   * Loads persisted alert history from the backend and merges it into
   * the in-memory list.  History items are pre-marked as read since
   * the user has implicitly seen them in a prior session.
   *
   * Deduplication: uses serverId so re-calling loadHistory (e.g. on
   * reconnect) never adds duplicate rows.
   */
  private loadHistory(): void {
    this.http.get<{ alerts: AlertHistoryRow[] }>('/api/alerts').subscribe({
      next: ({ alerts }) => {
        const items: AlertItem[] = alerts.map(h => ({
          id:                `hist-${h.id}`,
          serverId:          h.id,
          type:              h.alert_type as AlertItem['type'],
          identity:          h.identity_name,
          duration_minutes:  h.duration_minutes,
          threshold_minutes: h.threshold_minutes,
          timestamp:         h.fired_at,
          read:              true,
        }));

        this.alerts.update(current => {
          const existingServerIds = new Set(
            current.filter(a => a.serverId != null).map(a => a.serverId!)
          );
          const fresh = items.filter(h => !existingServerIds.has(h.serverId!));
          return [...fresh, ...current].slice(0, MAX_ALERTS);
        });
      },
      error: () => { /* silently ignore — realtime still works */ },
    });
  }

  private connect(userId: number, token: string): void {
    if (this.echo) return; // already connected

    // Expose Pusher globally — required by laravel-echo's pusher broadcaster.
    (window as unknown as Record<string, unknown>)['Pusher'] = Pusher;

    this.echo = new Echo({
      broadcaster:       'pusher',
      key:               environment.reverbKey,
      cluster:           'mt1',       // required by pusher-js; ignored when wsHost is set
      wsHost:            environment.reverbHost,
      wsPort:            environment.reverbPort,
      wssPort:           environment.reverbPort,
      forceTLS:          environment.reverbScheme === 'https',
      enabledTransports: ['ws'],
      disableStats:      true,
      authEndpoint:      environment.reverbAuthEndpoint,
      auth: {
        headers: { Authorization: `Bearer ${token}` },
      },
    });

    this.echo
      .private(`alerts.${userId}`)
      .listen('.behavior.alert', (payload: Omit<AlertItem, 'id' | 'read' | 'serverId'>) => {
        const alert: AlertItem = {
          ...payload,
          id:       crypto.randomUUID(),
          serverId: undefined,   // set later if a reload occurs and history includes it
          read:     false,
        };
        this.alerts.update(list => [alert, ...list].slice(0, MAX_ALERTS));
        this.toastService.show(alert);
      });
  }
}
