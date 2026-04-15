import { computed, inject, Injectable, signal } from '@angular/core';
import { AiService, ChartData, ChatTurn } from './ai.service';
import { ChatContextService } from './chat-context.service';

// ── Public interfaces ────────────────────────────────────────────────────────

export interface ChatMessage {
  role: 'user' | 'assistant';
  text: string;
  html?: string;
  chartSpecs?: ChartSpec[];
  loading?: boolean;
  error?: boolean;
  typing?: boolean;
}

export interface ChartSpec {
  type: 'bar' | 'line' | 'pie';
  title: string;
  labels: string[];
  values: number[];
  colors: string[];
  unit?: string;   // 'sec' | '%' | 'count' — controls value label formatting
}

export type MessageSegment = { type: 'html'; html: string } | { type: 'chart'; chartIndex: number };

// ── Service ──────────────────────────────────────────────────────────────────

@Injectable({ providedIn: 'root' })
export class ChatStateService {
  private readonly ai     = inject(AiService);
  readonly ctxSvc = inject(ChatContextService);

  private typeTimer: ReturnType<typeof setTimeout> | null = null;

  // ── Signals ──────────────────────────────────────────────────────────────
  readonly messages = signal<ChatMessage[]>([
    {
      role: 'assistant',
      text: "Hello! I'm Marqi, your analytics companion. Ask me anything about the employee data currently loaded in the dashboard.",
      html: "<p>Hello! I'm Marqi, your analytics companion. Ask me anything about the employee data currently loaded in the dashboard.</p>",
    },
  ]);

  readonly loading = signal(false);

  readonly contextLabel        = this.ctxSvc.contextLabel;
  readonly hasContext           = computed(() => !!this.ctxSvc.context());
  readonly identityNames        = this.ctxSvc.identityNames;
  readonly selectedIdentity     = this.ctxSvc.selectedIdentity;
  readonly contextLoading       = this.ctxSvc.contextLoading;
  readonly identitiesAttempted  = this.ctxSvc.identitiesAttempted;

  // ── Actions ──────────────────────────────────────────────────────────────

  /** Switch identity context (clears chat and fetches new data). */
  switchIdentity(identity: string): void {
    this.ctxSvc.selectIdentity(identity);
    this.stopTyping();
    this.loading.set(false);
    const label = identity === 'global' ? 'all employees' : identity;
    this.messages.set([{
      role: 'assistant',
      text: `Context switched to ${label}. What would you like to know?`,
      html: `<p>Context switched to <strong>${label}</strong>. What would you like to know?</p>`,
    }]);
  }

  /** Load identity list if not already loaded. */
  ensureIdentitiesLoaded(): void {
    this.ctxSvc.loadIdentities();
  }

  /** Force-reload identity list (e.g. user clicks "Retry" after failed load). */
  retryIdentities(): void {
    this.ctxSvc.forceReloadIdentities();
  }

  send(text: string): void {
    if (!text.trim() || this.loading()) return;

    // Append user turn
    this.messages.update(msgs => [
      ...msgs,
      { role: 'user', text, html: `<p>${this.escapeHtml(text)}</p>` },
    ]);

    // Append loading placeholder
    this.messages.update(msgs => [...msgs, { role: 'assistant', text: '', loading: true }]);
    this.loading.set(true);

    // Build history (exclude placeholder + last user msg)
    const allMsgs = this.messages();
    const history: ChatTurn[] = allMsgs
      .slice(0, -2)
      .filter(m => !m.loading)
      .map(m => ({ role: m.role, text: m.text }));

    this.ai.sendMessage(text, this.ctxSvc.context(), history, this.ctxSvc.selectedIdentity() || undefined, this.ctxSvc.dateStart(), this.ctxSvc.dateEnd()).subscribe({
      next: (res) => {
        this.loading.set(false);
        const replyText = res.reply ?? res.error ?? 'No response received.';
        const isError   = !!res.error;
        const msgIndex  = this.messages().length - 1;

        if (isError) {
          const { html, chartSpecs } = this.renderMarkdown(replyText);
          this.messages.update(msgs => [
            ...msgs.slice(0, -1),
            { role: 'assistant', text: replyText, html, chartSpecs, error: true },
          ]);
        } else {
          // Map server-provided ChartData to the frontend ChartSpec shape.
          const serverChart: ChartSpec | undefined = res.chart
            ? {
                type:   res.chart.type as 'bar' | 'line' | 'pie',
                title:  res.chart.title,
                labels: res.chart.labels,
                values: res.chart.values,
                colors: res.chart.colors?.length
                  ? res.chart.colors
                  : this.defaultColors(res.chart.labels.length),
                unit: res.chart.unit,
              }
            : undefined;
          this.typeEffect(msgIndex, replyText, serverChart);
        }
      },
      error: () => {
        this.loading.set(false);
        this.messages.update(msgs => [
          ...msgs.slice(0, -1),
          { role: 'assistant', text: 'Connection error.', html: '<p>Connection error. Please try again.</p>', error: true },
        ]);
      },
    });
  }

  clearChat(): void {
    this.stopTyping();
    this.loading.set(false);
    this.messages.set([{
      role: 'assistant',
      text: 'Chat cleared. Ask me anything about the currently loaded data.',
      html: '<p>Chat cleared. Ask me anything about the currently loaded data.</p>',
    }]);
  }

  // ── Typing animation ───────────────────────────────────────────────────

  private typeEffect(msgIndex: number, fullText: string, serverChart?: ChartSpec): void {
    this.stopTyping();

    // Extract chart blocks first — they will be added at the final step
    const chartSpecs: ChartSpec[] = [];
    const cleanText = fullText.replace(/```chart\s*([\s\S]*?)```/g, (_, json) => {
      try {
        const spec = JSON.parse(json.trim()) as ChartSpec;
        if (!spec.colors || spec.colors.length < spec.labels.length) {
          spec.colors = this.defaultColors(spec.labels.length);
        }
        chartSpecs.push(spec);
      } catch { /* skip malformed */ }
      return ''; // strip during typing
    });

    const words = cleanText.split(/(\s+)/);
    let wordPos = 0;
    const wordsPerStep = 4;

    const step = () => {
      wordPos = Math.min(wordPos + wordsPerStep, words.length);
      const partial = words.slice(0, wordPos).join('');
      const html = this.markdownToHtml(partial);

      this.messages.update(msgs => {
        const next = [...msgs];
        next[msgIndex] = { ...next[msgIndex], html, loading: false, typing: true };
        return next;
      });

      if (wordPos < words.length) {
        this.typeTimer = setTimeout(step, 22);
      } else {
        // Final render: merge LLM-embedded chart blocks + server-resolved chart
        const { html: finalHtml } = this.renderMarkdown(fullText);
        const allSpecs = [...chartSpecs];
        let markerHtml = '';
        if (serverChart) {
          markerHtml = `[[CHART_${allSpecs.length}]]`;
          allSpecs.push(serverChart);
        }
        this.messages.update(msgs => {
          const next = [...msgs];
          next[msgIndex] = {
            role: 'assistant',
            text: fullText,
            html: finalHtml + markerHtml,
            chartSpecs: allSpecs.length ? allSpecs : undefined,
            typing: false,
          };
          return next;
        });
      }
    };

    step();
  }

  private stopTyping(): void {
    if (this.typeTimer) {
      clearTimeout(this.typeTimer);
      this.typeTimer = null;
    }
  }

  // ── Template helpers ──────────────────────────────────────────────────

  chartBarWidth(spec: ChartSpec, index: number): string {
    const max = Math.max(...spec.values, 1);
    return `${(spec.values[index] / max) * 100}%`;
  }

  chartBarLabel(value: number, unit?: string): string {
    if (unit === '%')     return `${value.toFixed(1)}%`;
    if (unit === 'count') return String(Math.round(value));
    // default: treat value as seconds
    if (value >= 3600) return `${(value / 3600).toFixed(1)}h`;
    if (value >= 60)   return `${Math.round(value / 60)}m`;
    return `${Math.round(value)}s`;
  }

  splitSegments(msg: ChatMessage): MessageSegment[] {
    if (!msg.chartSpecs?.length) {
      return [{ type: 'html', html: msg.html ?? '' }];
    }
    const parts = (msg.html ?? '').split(/(\[\[CHART_\d+\]\])/);
    return parts.map(p => {
      const m = p.match(/\[\[CHART_(\d+)\]\]/);
      if (m) return { type: 'chart' as const, chartIndex: +m[1] };
      return { type: 'html' as const, html: p };
    });
  }

  // ── Markdown rendering ────────────────────────────────────────────────

  renderMarkdown(text: string): { html: string; chartSpecs: ChartSpec[] } {
    const chartSpecs: ChartSpec[] = [];

    const withoutCharts = text.replace(/```chart\s*([\s\S]*?)```/g, (_, json) => {
      try {
        const spec = JSON.parse(json.trim()) as ChartSpec;
        if (!spec.colors || spec.colors.length < spec.labels.length) {
          spec.colors = this.defaultColors(spec.labels.length);
        }
        chartSpecs.push(spec);
      } catch { /* skip */ }
      return `[[CHART_${chartSpecs.length - 1}]]`;
    });

    return { html: this.markdownToHtml(withoutCharts), chartSpecs };
  }

  private markdownToHtml(text: string): string {
    let html = text
      .replace(/```[\w]*\n?([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
      .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.*?)\*/g, '<em>$1</em>')
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/^## (.+)$/gm, '<h3>$1</h3>')
      .replace(/^### (.+)$/gm, '<h4>$1</h4>')
      .replace(/^\s*[-*] (.+)$/gm, '<li>$1</li>')
      .replace(/^\s*\d+\. (.+)$/gm, '<li>$1</li>')
      .replace(/(<li>.*<\/li>\s*)+/g, s => `<ul>${s}</ul>`)
      .replace(/\n{2,}/g, '</p><p>')
      .replace(/\n/g, '<br>');

    if (!html.startsWith('<')) html = `<p>${html}</p>`;
    return html;
  }

  private escapeHtml(text: string): string {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  private defaultColors(count: number): string[] {
    const palette = ['#10b981', '#6b7280', '#ef4444', '#f59e0b', '#6366f1', '#ec4899', '#14b8a6'];
    return Array.from({ length: count }, (_, i) => palette[i % palette.length]);
  }
}
