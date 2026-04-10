import { Component, input } from '@angular/core';

export interface KpiCardDef {
  label: string;
  value: string;
  sub?: string;
  /** Optional SVG path data for a small icon */
  icon?: string;
  /** Theme class: 'primary' | 'success' | 'warning' | 'neutral' */
  theme?: 'primary' | 'success' | 'warning' | 'neutral';
}

@Component({
  selector: 'app-kpi-cards',
  standalone: true,
  imports: [],
  templateUrl: './kpi-cards.component.html',
  styleUrl: './kpi-cards.component.scss',
})
export class KpiCardsComponent {
  // Accepts a pre-built card definitions array so this component stays
  // purely presentational — no data fetching, no service injection.
  readonly cards = input.required<KpiCardDef[]>();
}
