import { computed, inject, Injectable, signal } from '@angular/core';
import { AiService } from './ai.service';

/**
 * ChatContextService
 *
 * Manages the data context sent to the AI and supports two modes:
 *
 *  1. **Page-driven context** — a page component calls setContext() directly
 *     (e.g. the identity-detail page pushes the current timeline data).
 *
 *  2. **User-selector context** — the chat UI lets the user pick "Global"
 *     or a specific identity; this service fetches the relevant surveillance
 *     data from GET /api/ai/context.
 *
 * The chat sidebar and full-page chat both read `context()` and
 * `contextLabel()` signals to display context status and send to Gemini.
 */
@Injectable({ providedIn: 'root' })
export class ChatContextService {
  private readonly ai = inject(AiService);

  /** Current page data context as a plain-text string sent to the AI. */
  readonly context = signal<string>('');

  /** Human-readable label shown in the chatbot header. */
  readonly contextLabel = signal<string>('');

  /** Available identity names for the selector dropdown. */
  readonly identityNames = signal<string[]>([]);

  /** Currently selected identity: 'global' | identity name | '' (page-driven). */
  readonly selectedIdentity = signal<string>('');

  /** Whether the context is currently loading from the API. */
  readonly contextLoading = signal(false);

  /**
   * True after at least one load attempt completed (success or error).
   * Used to show "No employees available" vs a blank during initial load.
   */
  readonly identitiesAttempted = signal(false);

  /** True when context was set by the user selector (not by a page). */
  readonly isSelectorMode = computed(() => this.selectedIdentity() !== '');

  // ── Page-driven context (used by identity-detail, etc.) ──────────────

  setContext(data: string, label = ''): void {
    this.context.set(data);
    this.contextLabel.set(label);
    // Clear selector mode when a page pushes context
    this.selectedIdentity.set('');
  }

  clearContext(): void {
    this.context.set('');
    this.contextLabel.set('');
    this.selectedIdentity.set('');
  }

  // ── User-selector context ───────────────────────────────────────────

  /** Load identity list + context for the given selection. */
  selectIdentity(identity: string, start?: string, end?: string): void {
    this.selectedIdentity.set(identity);
    this.contextLoading.set(true);
    console.log('[ChatContext] selectIdentity:', identity, 'start:', start, 'end:', end);

    this.ai.getContext(identity || 'global', start, end).subscribe({
      next: (res) => {
        console.log('[ChatContext] selectIdentity resolved — identities:', res.identities, 'label:', res.label);
        this.identityNames.set(res.identities);
        this.context.set(res.context);
        this.contextLabel.set(res.label);
        this.contextLoading.set(false);
      },
      error: (err) => {
        this.contextLoading.set(false);
        console.warn('[ChatContext] selectIdentity failed:', err?.status, err?.message);
        this.context.set('Ready to chat. Ask me questions!');
        this.contextLabel.set(identity === 'global' ? 'All Employees' : identity);
      },
    });
  }

  /**
   * Called by the dashboard after a successful Load to synchronise the chat
   * context label and data window with the active filter range.
   *
   * - Updates selectedIdentity to 'global' (team view) or keeps current individual.
   * - Passes start/end so the backend fetches the same window the dashboard shows.
   * - Updates contextLabel with a human-readable date range string.
   */
  pushDashboardRange(label: string, start?: string, end?: string): void {
    // Only push if not already in page-driven mode (identity-detail overrides).
    // Selector mode ('global' or named identity) always syncs with the dashboard.
    const currentIdentity = this.selectedIdentity() || 'global';
    console.log('[ChatContext] pushDashboardRange — label:', label, 'identity:', currentIdentity, 'start:', start, 'end:', end);
    this.selectIdentity(currentIdentity, start, end);
  }

  /**
   * Bootstrap: load identity list defaulting to global scope.
   *
   * Guards:
   *  - Already loading  → skip to avoid concurrent requests
   *  - Already have names → skip unless forceReload is true
   *
   * BUG FIX: always sets context + contextLabel from the API response,
   * even when identities is empty, so the UI never shows "No data context"
   * for a valid (but empty-identity) successful response.
   */
  loadIdentities(forceReload = false, start?: string, end?: string): void {
    if (this.contextLoading()) {
      console.log('[ChatContext] loadIdentities: already loading, skipping');
      return;
    }
    if (!forceReload && this.identityNames().length > 0) {
      console.log('[ChatContext] loadIdentities: already loaded', this.identityNames().length, 'identities, skipping');
      return;
    }

    this.contextLoading.set(true);
    console.log('[ChatContext] loadIdentities: calling GET /api/ai/context?identity=global', 'start:', start, 'end:', end);

    this.ai.getContext('global', start, end).subscribe({
      next: (res) => {
        console.log('[ChatContext] loadIdentities resolved — identities:', res.identities, 'label:', res.label, 'context length:', res.context?.length);
        this.identityNames.set(res.identities);
        this.contextLoading.set(false);
        this.identitiesAttempted.set(true);

        // ── KEY FIX ───────────────────────────────────────────────────────
        // Always set context + label from the response so hasContext() is true
        // and the header shows the correct label — even when identities = [].
        if (res.context) {
          this.context.set(res.context);
          this.contextLabel.set(res.label);
        }

        // Auto-select global only when identities are present and nothing
        // is currently selected (don't overwrite page-driven state).
        if (res.identities.length > 0 && !this.selectedIdentity()) {
          this.selectedIdentity.set('global');
        }
      },
      error: (err) => {
        this.contextLoading.set(false);
        this.identitiesAttempted.set(true);
        console.warn('[ChatContext] loadIdentities failed — status:', err?.status, 'message:', err?.message);
      },
    });
  }

  /** Force-reload identities (clears cached list first). Used by the retry button. */
  forceReloadIdentities(): void {
    this.identityNames.set([]);
    this.identitiesAttempted.set(false);
    this.loadIdentities(true);
  }
}
