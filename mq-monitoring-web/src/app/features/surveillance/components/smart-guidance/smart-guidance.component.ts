import { Component, input } from '@angular/core';

export type GuidanceCategory = 'insight' | 'advice' | 'recognition';

export interface GuidanceMessage {
  category: GuidanceCategory;
  text:     string;
}

const CATEGORY_LABEL: Record<GuidanceCategory, string> = {
  recognition: 'Recognition',
  advice:      'Recommendation',
  insight:     'Insight',
};

/**
 * Operational Guidance — pure presentational component for admin and superviseur roles.
 * Renders role-aware guidance messages with professional category tags (no emojis).
 */
@Component({
  selector:   'app-smart-guidance',
  standalone: true,
  imports:    [],
  template: `
    <div class="sg-card">
      <div class="sg-card__header">
        <span class="sg-card__title">Operational Guidance</span>
        @if (messages().length > 0) {
          <span class="sg-card__count">{{ messages().length }} item{{ messages().length > 1 ? 's' : '' }}</span>
        }
      </div>
      @if (messages().length === 0) {
        <div class="sg-card__empty">No specific guidance for this period — all metrics within normal range.</div>
      } @else {
        <ul class="sg-card__list">
          @for (msg of messages(); track msg.text) {
            <li class="sg-card__item">
              <span class="sg-card__tag sg-card__tag--{{ msg.category }}">{{ catLabel(msg.category) }}</span>
              <span class="sg-card__text">{{ msg.text }}</span>
            </li>
          }
        </ul>
      }
    </div>
  `,
  styles: [`
    .sg-card {
      background:    var(--color-surface, #1e2a38);
      border:        1px solid var(--color-border, rgba(255,255,255,0.08));
      border-radius: 10px;
      overflow:      hidden;
      margin-bottom: 1rem;
    }

    .sg-card__header {
      display:         flex;
      align-items:     center;
      justify-content: space-between;
      padding:         0.75rem 1.125rem;
      border-bottom:   1px solid var(--color-border, rgba(255,255,255,0.06));
    }

    .sg-card__title {
      font-size:      0.75rem;
      font-weight:    700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color:          var(--color-text-secondary, #8b9ab0);
    }

    .sg-card__count {
      font-size:  0.6875rem;
      color:      var(--color-text-secondary, #8b9ab0);
      opacity:    0.7;
    }

    .sg-card__empty {
      padding:   0.875rem 1.125rem;
      font-size: 0.8125rem;
      color:     var(--color-text-secondary, #8b9ab0);
    }

    .sg-card__list {
      list-style: none;
      margin:     0;
      padding:    0.5rem 0;
    }

    .sg-card__item {
      display:     flex;
      align-items: baseline;
      gap:         0.625rem;
      padding:     0.5rem 1.125rem;
    }

    .sg-card__item + .sg-card__item {
      border-top: 1px solid var(--color-border, rgba(255,255,255,0.04));
    }

    .sg-card__tag {
      font-size:      0.6rem;
      font-weight:    700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      padding:        0.15rem 0.45rem;
      border-radius:  4px;
      white-space:    nowrap;
      flex-shrink:    0;
      border:         1px solid transparent;
    }

    .sg-card__tag--recognition {
      color:            #4ade80;
      background:       rgba(74, 222, 128, 0.1);
      border-color:     rgba(74, 222, 128, 0.2);
    }

    .sg-card__tag--advice {
      color:            #f59e0b;
      background:       rgba(245, 158, 11, 0.08);
      border-color:     rgba(245, 158, 11, 0.2);
    }

    .sg-card__tag--insight {
      color:            #60a5fa;
      background:       rgba(96, 165, 250, 0.08);
      border-color:     rgba(96, 165, 250, 0.2);
    }

    .sg-card__text {
      font-size:   0.8125rem;
      color:       var(--color-text-primary, #e2e8f0);
      line-height: 1.5;
      flex:        1;
    }
  `],
})
export class SmartGuidanceComponent {
  readonly messages = input.required<GuidanceMessage[]>();

  catLabel(cat: GuidanceCategory): string {
    return CATEGORY_LABEL[cat];
  }
}
