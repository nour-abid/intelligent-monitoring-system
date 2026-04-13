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
  selectIdentity(identity: string): void {
    this.selectedIdentity.set(identity);
    this.contextLoading.set(true);

    this.ai.getContext(identity || 'global').subscribe({
      next: (res) => {
        this.identityNames.set(res.identities);
        this.context.set(res.context);
        this.contextLabel.set(res.label);
        this.contextLoading.set(false);
      },
      error: (err) => {
        // Still set selected identity even if context load fails
        this.contextLoading.set(false);
        console.warn('Could not load context:', err);
        // Optimistically set a generic context message
        this.context.set('Ready to chat. Ask me questions!');
        this.contextLabel.set(identity === 'global' ? 'All Employees' : identity);
      },
    });
  }

  /** Bootstrap: load identity list and default to global. */
  loadIdentities(): void {
    if (this.identityNames().length > 0) return; // already loaded
    this.contextLoading.set(true);
    this.ai.getContext('global').subscribe({
      next: (res) => {
        this.identityNames.set(res.identities);
        this.contextLoading.set(false);
        // Auto-select global if we have identities, but don't fail silently if we don't
        if (res.identities.length > 0) {
          this.selectedIdentity.set('global');
          this.context.set(res.context);
          this.contextLabel.set(res.label);
        }
      },
      error: (err) => {
        // Fail silently - context is optional
        this.contextLoading.set(false);
        console.warn('Could not load identity list for context selector:', err);
      },
    });
  }
}
