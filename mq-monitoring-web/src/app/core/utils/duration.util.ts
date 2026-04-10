/**
 * Format a duration in seconds into a human-readable string.
 * Examples:
 *   0      → '0m'
 *   45     → '<1m'
 *   90     → '1m'
 *   3600   → '1h'
 *   3660   → '1h 1m'
 *   7320   → '2h 2m'
 */
export function formatDuration(seconds: number): string {
  if (!seconds || seconds <= 0) return '0m';
  if (seconds < 60) return '<1m';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  if (h > 0 && m > 0) return `${h}h ${m}m`;
  if (h > 0) return `${h}h`;
  return `${m}m`;
}

/** Format a date to 'YYYY-MM-DD' (HTML date input value). */
export function toDateInputValue(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/** Expand a 'YYYY-MM-DD' date string to 'YYYY-MM-DD 00:00:00' (start of day). */
export function toStartOfDay(date: string): string {
  return `${date} 00:00:00`;
}

/** Expand a 'YYYY-MM-DD' date string to 'YYYY-MM-DD 23:59:59' (end of day). */
export function toEndOfDay(date: string): string {
  return `${date} 23:59:59`;
}

/** Named date-range presets available in the filter bar. */
export type DatePresetKey = 'today' | 'yesterday' | 'last7' | 'month';

/** Compute { start, end } 'YYYY-MM-DD' strings for a named preset. */
export function datePreset(key: DatePresetKey): { start: string; end: string } {
  const now = new Date();
  switch (key) {
    case 'today':
      return { start: toDateInputValue(now), end: toDateInputValue(now) };
    case 'yesterday': {
      const d = new Date(now); d.setDate(d.getDate() - 1);
      return { start: toDateInputValue(d), end: toDateInputValue(d) };
    }
    case 'last7': {
      const d = new Date(now); d.setDate(d.getDate() - 6);
      return { start: toDateInputValue(d), end: toDateInputValue(now) };
    }
    case 'month': {
      const d = new Date(now.getFullYear(), now.getMonth(), 1);
      return { start: toDateInputValue(d), end: toDateInputValue(now) };
    }
  }
}

/** Format a 'YYYY-MM-DD HH:MM:SS' timestamp to a short display string. */
export function formatTimestamp(ts: string): string {
  if (!ts) return '—';
  // SQLite stores as 'YYYY-MM-DD HH:MM:SS'; keep it human-readable.
  return ts.replace('T', ' ').substring(0, 16); // 'YYYY-MM-DD HH:MM'
}
