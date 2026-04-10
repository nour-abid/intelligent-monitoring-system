import { Injectable, signal } from '@angular/core';
import { AlertItem } from '../models/alert.model';

export type ToastAlert = Pick<AlertItem, 'id' | 'type' | 'identity' | 'duration_minutes' | 'threshold_minutes' | 'timestamp'>;

const TOAST_DURATION_MS = 8000;

/**
 * AlertToastService
 *
 * Manages the transient toast list for realtime alert pop-ups.
 * Each toast auto-dismisses after TOAST_DURATION_MS and attempts
 * to play a short notification sound via Web Audio API.
 *
 * Sound playback fails silently under browser autoplay restrictions.
 */
@Injectable({ providedIn: 'root' })
export class AlertToastService {
  readonly toasts = signal<ToastAlert[]>([]);

  show(alert: ToastAlert): void {
    this.toasts.update(list => [...list, alert]);
    this.playSound();
    this.showBrowserNotification(alert);
    setTimeout(() => this.dismiss(alert.id), TOAST_DURATION_MS);
  }

  dismiss(id: string): void {
    this.toasts.update(list => list.filter(t => t.id !== id));
  }

  // -------------------------------------------------------------------------

  private showBrowserNotification(alert: ToastAlert): void {
    // Only show when the page is not visible (user switched tab or minimized).
    if (!document.hidden) return;

    // Notifications API not supported in this browser.
    if (!('Notification' in window)) return;

    const show = () => {
      const label = this.notificationLabel(alert.type);
      new Notification(`MQ Monitoring — ${label}`, {
        body: `${alert.identity} · ${alert.duration_minutes} min (threshold: ${alert.threshold_minutes} min)`,
        icon: '/favicon.ico',
        tag:  alert.id,          // prevents duplicate popups for same event
        requireInteraction: false,
      });
    };

    if (Notification.permission === 'granted') {
      show();
      return;
    }

    // Do not request permission mid-flow if already denied.
    if (Notification.permission === 'denied') return;

    // Permission not yet requested — ask once, then show if granted.
    Notification.requestPermission().then(result => {
      if (result === 'granted') show();
    });
  }

  private notificationLabel(type: string): string {
    switch (type) {
      case 'inactive':     return 'Inactive Employee';
      case 'phone':        return 'Phone Usage';
      case 'late_arrival': return 'Late Arrival';
      default:             return 'Behavior Alert';
    }
  }

  private playSound(): void {
    try {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const AudioCtx = window.AudioContext ?? (window as any)['webkitAudioContext'];
      if (!AudioCtx) return;

      const ctx  = new AudioCtx() as AudioContext;
      const osc  = ctx.createOscillator();
      const gain = ctx.createGain();

      osc.connect(gain);
      gain.connect(ctx.destination);

      // Two-tone descending blip: 880 Hz → 660 Hz
      osc.type = 'sine';
      osc.frequency.setValueAtTime(880, ctx.currentTime);
      osc.frequency.setValueAtTime(660, ctx.currentTime + 0.15);
      gain.gain.setValueAtTime(0.10, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);

      osc.start(ctx.currentTime);
      osc.stop(ctx.currentTime + 0.45);
      osc.onended = () => ctx.close();
    } catch {
      // Silently ignore: autoplay policy, unsupported API, or sandboxed context.
    }
  }
}
