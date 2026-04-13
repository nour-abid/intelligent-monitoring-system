import { Component, computed, input, signal } from '@angular/core';
import { NgClass } from '@angular/common';

/** Minimal shape the coach needs — satisfied by both ActivityTotal and ActivityDistributionItem. */
export interface CoachActivityInput {
  activity: string;
  share:    number; // 0..1
}

type ChallengeId = 'phone' | 'working' | 'inactive';
type ChallengeStatus = 'completed' | 'on-track' | 'below-target';

interface CoachChallenge {
  id: ChallengeId;
  label: string;
  description: string;
  metricLabel: string;
  currentPct: number;
  targetPct: number;
  progress: number;       // 0–100 clamped, represents how close to target
  status: ChallengeStatus;
  points: number;
  direction: 'higher-is-better' | 'lower-is-better';
}

const STATUS_LABEL: Record<ChallengeStatus, string> = {
  completed:     'Completed',
  'on-track':    'On Track',
  'below-target': 'Below Target',
};

@Component({
  selector: 'app-performance-coach',
  standalone: true,
  imports: [NgClass],
  template: `
    <div class="coach-card">
      <div class="coach-card__header">
        <div class="coach-card__title-group">
          <span class="coach-card__label">Performance Coach</span>
          <span class="coach-card__streak" [class.coach-card__streak--active]="streak() > 0">
            {{ streak() === 0 ? 'Start a streak' : streak() + '-day streak' }}
          </span>
        </div>
        <div class="coach-card__points-badge">
          <span class="coach-card__points-value">{{ totalPoints() }}</span>
          <span class="coach-card__points-unit">pts</span>
        </div>
      </div>

      <div class="coach-card__body">
        <div class="coach-card__section-label">Challenge of the Day</div>

        @if (challenge(); as ch) {
          <div class="coach-card__challenge">
            <div class="coach-card__challenge-header">
              <span class="coach-card__challenge-title">{{ ch.label }}</span>
              <span class="coach-card__reward">+{{ ch.points }} pts</span>
            </div>
            <span class="coach-card__challenge-desc">{{ ch.description }}</span>

            <div class="coach-card__progress-row">
              <div class="coach-card__progress-bar-wrap">
                <div
                  class="coach-card__progress-bar"
                  [class.coach-card__progress-bar--completed]="ch.status === 'completed'"
                  [class.coach-card__progress-bar--on-track]="ch.status === 'on-track'"
                  [class.coach-card__progress-bar--below]="ch.status === 'below-target'"
                  [style.width]="ch.progress + '%'"
                ></div>
              </div>
              <span class="coach-card__progress-pct">{{ ch.progress }}%</span>
            </div>

            <div class="coach-card__meta">
              <div class="coach-card__metric">
                <span class="coach-card__metric-label">Current</span>
                <span class="coach-card__metric-value">{{ ch.currentPct }}%</span>
              </div>
              <div class="coach-card__metric">
                <span class="coach-card__metric-label">Target</span>
                <span class="coach-card__metric-value coach-card__metric-value--target">{{ ch.direction === 'lower-is-better' ? '< ' : '> ' }}{{ ch.targetPct }}%</span>
              </div>
              <div
                class="coach-card__status-badge"
                [ngClass]="'coach-card__status-badge--' + ch.status"
              >
                {{ statusLabel(ch.status) }}
              </div>
            </div>
          </div>
        } @else {
          <div class="coach-card__empty">No activity data available for today's challenge.</div>
        }
      </div>

      @if (challenge()?.status === 'completed') {
        <div class="coach-card__footer coach-card__footer--success">
          Challenge complete — points awarded. Keep it up tomorrow.
        </div>
      } @else if (challenge()?.status === 'on-track') {
        <div class="coach-card__footer">
          You are on track. Maintaining this pace will complete the challenge.
        </div>
      } @else {
        <div class="coach-card__footer coach-card__footer--warning">
          Below target today. Focus on the highlighted metric to earn points.
        </div>
      }
    </div>
  `,
  styles: [`
    .coach-card {
      background: var(--color-surface, #1e2a38);
      border: 1px solid var(--color-border, rgba(255,255,255,0.08));
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: 1rem;
    }

    .coach-card__header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.875rem 1.25rem;
      border-bottom: 1px solid var(--color-border, rgba(255,255,255,0.08));
    }

    .coach-card__title-group {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }

    .coach-card__label {
      font-size: 0.8125rem;
      font-weight: 600;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      color: var(--color-text-secondary, #8b9ab0);
    }

    .coach-card__streak {
      font-size: 0.75rem;
      padding: 0.2rem 0.55rem;
      border-radius: 20px;
      background: var(--color-border, rgba(255,255,255,0.06));
      color: var(--color-text-secondary, #8b9ab0);
      border: 1px solid transparent;
      transition: all 0.2s;
    }

    .coach-card__streak--active {
      background: rgba(34, 197, 94, 0.1);
      border-color: rgba(34, 197, 94, 0.3);
      color: #4ade80;
    }

    .coach-card__points-badge {
      display: flex;
      align-items: baseline;
      gap: 0.2rem;
    }

    .coach-card__points-value {
      font-size: 1.375rem;
      font-weight: 700;
      color: var(--color-accent, #60a5fa);
      line-height: 1;
    }

    .coach-card__points-unit {
      font-size: 0.7rem;
      color: var(--color-text-secondary, #8b9ab0);
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .coach-card__body {
      padding: 1.125rem 1.25rem;
    }

    .coach-card__section-label {
      font-size: 0.6875rem;
      text-transform: uppercase;
      letter-spacing: 0.07em;
      color: var(--color-text-secondary, #8b9ab0);
      margin-bottom: 0.75rem;
    }

    .coach-card__challenge {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .coach-card__challenge-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.5rem;
    }

    .coach-card__challenge-title {
      font-size: 0.9375rem;
      font-weight: 600;
      color: var(--color-text-primary, #e2e8f0);
    }

    .coach-card__reward {
      font-size: 0.8125rem;
      font-weight: 600;
      color: var(--color-accent, #60a5fa);
      background: rgba(96, 165, 250, 0.1);
      border: 1px solid rgba(96, 165, 250, 0.2);
      border-radius: 20px;
      padding: 0.15rem 0.5rem;
      white-space: nowrap;
    }

    .coach-card__challenge-desc {
      font-size: 0.8125rem;
      color: var(--color-text-secondary, #8b9ab0);
      line-height: 1.5;
    }

    .coach-card__progress-row {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-top: 0.25rem;
    }

    .coach-card__progress-bar-wrap {
      flex: 1;
      height: 6px;
      background: var(--color-border, rgba(255,255,255,0.08));
      border-radius: 3px;
      overflow: hidden;
    }

    .coach-card__progress-bar {
      height: 100%;
      border-radius: 3px;
      transition: width 0.4s ease;
      background: var(--color-text-secondary, #8b9ab0);
    }

    .coach-card__progress-bar--completed {
      background: #4ade80;
    }

    .coach-card__progress-bar--on-track {
      background: #60a5fa;
    }

    .coach-card__progress-bar--below {
      background: #f59e0b;
    }

    .coach-card__progress-pct {
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--color-text-primary, #e2e8f0);
      min-width: 2.25rem;
      text-align: right;
    }

    .coach-card__meta {
      display: flex;
      align-items: center;
      gap: 1rem;
      margin-top: 0.375rem;
    }

    .coach-card__metric {
      display: flex;
      flex-direction: column;
      gap: 0.1rem;
    }

    .coach-card__metric-label {
      font-size: 0.6875rem;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--color-text-secondary, #8b9ab0);
    }

    .coach-card__metric-value {
      font-size: 0.875rem;
      font-weight: 600;
      color: var(--color-text-primary, #e2e8f0);
    }

    .coach-card__metric-value--target {
      color: var(--color-text-secondary, #8b9ab0);
    }

    .coach-card__status-badge {
      font-size: 0.7rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      padding: 0.2rem 0.6rem;
      border-radius: 20px;
      border: 1px solid transparent;
      margin-left: auto;
    }

    .coach-card__status-badge--completed {
      background: rgba(74, 222, 128, 0.12);
      border-color: rgba(74, 222, 128, 0.3);
      color: #4ade80;
    }

    .coach-card__status-badge--on-track {
      background: rgba(96, 165, 250, 0.1);
      border-color: rgba(96, 165, 250, 0.25);
      color: #93c5fd;
    }

    .coach-card__status-badge--below-target {
      background: rgba(245, 158, 11, 0.1);
      border-color: rgba(245, 158, 11, 0.25);
      color: #fbbf24;
    }

    .coach-card__footer {
      padding: 0.625rem 1.25rem;
      font-size: 0.8rem;
      color: var(--color-text-secondary, #8b9ab0);
      border-top: 1px solid var(--color-border, rgba(255,255,255,0.06));
      background: rgba(255,255,255,0.015);
    }

    .coach-card__footer--success {
      color: #4ade80;
      background: rgba(74, 222, 128, 0.04);
    }

    .coach-card__footer--warning {
      color: #fbbf24;
      background: rgba(245, 158, 11, 0.04);
    }

    .coach-card__empty {
      font-size: 0.8125rem;
      color: var(--color-text-secondary, #8b9ab0);
      padding: 0.5rem 0;
    }
  `],
})
export class PerformanceCoachComponent {
  readonly activityTotals = input.required<CoachActivityInput[]>();

  /** Accumulated session points (survives re-renders within the same component instance). */
  readonly coachPoints = signal(0);

  /** Consecutive "on-track or completed" days — initialised to 0, incremented on completion. */
  readonly streak = signal(0);

  /** Total points including session earnings from the current challenge. */
  readonly totalPoints = computed(() => this.coachPoints() + (this.challenge()?.points ?? 0));

  readonly challenge = computed<CoachChallenge | null>(() => {
    const totals = this.activityTotals();
    if (totals.length === 0) return null;

    const find = (act: string) => totals.find(t => t.activity === act);
    const phone    = find('Using_Phone');
    const working  = find('Working');
    const inactive = find('Inactive');

    const phoneShare    = phone?.share    ?? 0;
    const workingShare  = working?.share  ?? 0;
    const inactiveShare = inactive?.share ?? 0;

    // Priority: phone overuse → low working → high inactive → positive working reinforcement
    let id: ChallengeId;
    if (phoneShare > 0.15) {
      id = 'phone';
    } else if (workingShare < 0.60) {
      id = 'working';
    } else if (inactiveShare > 0.20) {
      id = 'inactive';
    } else {
      id = 'working'; // positive reinforcement when all targets met
    }

    let label: string;
    let description: string;
    let direction: 'higher-is-better' | 'lower-is-better';
    let currentPct: number;
    let targetPct: number;
    let rawProgress: number;

    switch (id) {
      case 'phone':
        label       = 'Reduce Phone Usage';
        description = 'Keep phone usage below 15% of your tracked work time today.';
        direction   = 'lower-is-better';
        currentPct  = Math.round(phoneShare * 100);
        targetPct   = 15;
        // Progress = how far from the threshold: 100 when at 0%, 0 when at target or above
        rawProgress = targetPct > 0
          ? Math.round(Math.max(0, (1 - phoneShare / 0.15)) * 100)
          : 100;
        break;

      case 'working':
        label       = 'Maintain Work Focus';
        description = 'Aim to spend more than 60% of your tracked time on focused work.';
        direction   = 'higher-is-better';
        currentPct  = Math.round(workingShare * 100);
        targetPct   = 60;
        rawProgress = Math.round(Math.min(100, (workingShare / 0.60) * 100));
        break;

      case 'inactive':
        label       = 'Reduce Idle Time';
        description = 'Keep inactive time below 20% of your tracked sessions today.';
        direction   = 'lower-is-better';
        currentPct  = Math.round(inactiveShare * 100);
        targetPct   = 20;
        rawProgress = targetPct > 0
          ? Math.round(Math.max(0, (1 - inactiveShare / 0.20)) * 100)
          : 100;
        break;
    }

    const progress = Math.min(100, Math.max(0, rawProgress));

    let status: ChallengeStatus;
    let points: number;
    if (progress >= 100) {
      status = 'completed';
      points = 30;
    } else if (progress >= 70) {
      status = 'on-track';
      points = 15;
    } else {
      status = 'below-target';
      points = 5;
    }

    return {
      id,
      label,
      description,
      metricLabel: id === 'phone' ? 'Phone usage' : id === 'working' ? 'Work focus' : 'Inactive time',
      currentPct,
      targetPct,
      progress,
      status,
      points,
      direction,
    };
  });

  statusLabel(status: ChallengeStatus): string {
    return STATUS_LABEL[status];
  }
}
