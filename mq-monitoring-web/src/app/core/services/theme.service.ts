import { DOCUMENT } from '@angular/common';
import { inject, Injectable, signal } from '@angular/core';

const STORAGE_KEY = 'mq-theme';

export type Theme = 'dark' | 'light';

@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly doc   = inject(DOCUMENT);
  private readonly _theme = signal<Theme>(this.readStored());

  /** Read-only signal — 'dark' | 'light' */
  readonly theme  = this._theme.asReadonly();
  /** Convenience helpers */
  readonly isDark  = () => this._theme() === 'dark';
  readonly isLight = () => this._theme() === 'light';

  /**
   * Called once by APP_INITIALIZER so the body class is set before
   * the first component renders, preventing a flash of wrong theme.
   */
  init(): void {
    this.applyClass(this._theme());
  }

  toggle(): void {
    const next: Theme = this._theme() === 'dark' ? 'light' : 'dark';
    this._theme.set(next);
    this.applyClass(next);
    try { localStorage.setItem(STORAGE_KEY, next); } catch { /* quota / private mode */ }
  }

  // ── Private helpers ──────────────────────────────────────────────────────

  private readStored(): Theme {
    try {
      const saved = localStorage.getItem(STORAGE_KEY);
      if (saved === 'light' || saved === 'dark') {
        return saved; // explicit user choice takes priority
      }
    } catch { /* localStorage unavailable */ }
    // No saved preference — follow the browser / OS setting.
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  private applyClass(theme: Theme): void {
    this.doc.documentElement.classList.toggle('light-theme', theme === 'light');
  }
}
