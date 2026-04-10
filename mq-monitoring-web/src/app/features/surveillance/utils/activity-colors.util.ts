/**
 * Canonical activity → colour mapping.
 * Used by both the chart and the badge utility. Keep in one place so every
 * component stays consistent.
 */
export const ACTIVITY_COLORS: Record<string, string> = {
  Working:     '#10b981', // emerald
  Meeting:     '#3b82f6', // blue
  Inactive:    '#f59e0b', // amber
  Using_Phone: '#ef4444', // red
  Unknown:     '#94a3b8', // slate
};

/** CSS badge modifier class (maps to .badge--<modifier> in styles.scss) */
export const ACTIVITY_BADGE_CLASS: Record<string, string> = {
  Working:     'badge--working',
  Meeting:     'badge--meeting',
  Inactive:    'badge--inactive',
  Using_Phone: 'badge--using_phone',
  Unknown:     'badge--unknown',
};

/** Returns a chart-bar fill colour, falling back to slate for unknown labels. */
export function activityColor(activity: string): string {
  return ACTIVITY_COLORS[activity] ?? '#94a3b8';
}

/** Returns a badge CSS class modifier, falling back to unknown style. */
export function activityBadgeClass(activity: string): string {
  return ACTIVITY_BADGE_CLASS[activity] ?? 'badge--unknown';
}
