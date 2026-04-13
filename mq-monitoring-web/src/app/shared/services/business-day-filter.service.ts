import { Injectable } from '@angular/core';

export interface DateRangeResult {
  start: Date;
  end: Date;
  description: string;
}

/**
 * BusinessDayFilterService
 *
 * Provides date range calculations that respect business hours (Mon-Fri)
 * and exclude public holidays. Used by viewer dashboard and other role-based
 * analytics components.
 *
 * All returned dates are normalized to start of day (00:00:00) and end of day (23:59:59).
 *
 * Usage:
 *   constructor(private businessDayFilter: BusinessDayFilterService) {}
 *
 *   const range = this.businessDayFilter.getDateRange('last7', excludeWeekends: true);
 *   // Returns last 7 business days (excluding Sat/Sun and holidays)
 */
@Injectable({
  providedIn: 'root',
})
export class BusinessDayFilterService {
  /**
   * Public holidays in Tunisia (2026)
   * Add or modify as needed for your organization's calendar.
   */
  private readonly HOLIDAYS_2026 = new Set([
    '2026-01-14', // Revolution Day (Fête de la Révolution)
    '2026-03-20', // Independence Day (Fête de l'Indépendance)
    '2026-04-09', // Martyrs' Day (Journée des Martyrs)
    '2026-05-01', // Labour Day (Fête du Travail)
    '2026-07-25', // Republic Day (Fête de la République)
    '2026-08-13', // Women's Day (Fête de la Femme)
    '2026-10-15', // Evacuation Day (Ben Ali Period - if observed)
    '2026-11-07', // Evacuation Commemoration
  ]);

  /**
   * Get a date range based on preset and business day filter.
   *
   * @param preset Preset option: 'today', 'yesterday', 'week', 'month', 'last7', 'last30'
   * @param excludeWeekends If true, exclude Sat/Sun from the range
   * @returns DateRangeResult with start, end, and human-readable description
   */
  getDateRange(
    preset: 'today' | 'yesterday' | 'week' | 'month' | 'last7' | 'last30',
    excludeWeekends: boolean = true
  ): DateRangeResult {
    let start: Date;
    let end: Date;
    let description = '';

    const now = new Date();
    const today = this.normalizeToStartOfDay(now);

    switch (preset) {
      case 'today':
        start = today;
        end = this.normalizeToEndOfDay(today);
        description = 'Today';
        break;

      case 'yesterday':
        start = this.normalizeToStartOfDay(
          new Date(today.getTime() - 24 * 60 * 60 * 1000)
        );
        end = this.normalizeToEndOfDay(start);
        description = 'Yesterday';
        break;

      case 'week': {
        // Monday to Friday of current week
        const dayOfWeek = today.getDay();
        const diff = today.getDate() - dayOfWeek + (dayOfWeek === 0 ? -6 : 1);
        start = this.normalizeToStartOfDay(new Date(today.setDate(diff)));
        end = this.normalizeToEndOfDay(
          new Date(start.getTime() + 4 * 24 * 60 * 60 * 1000)
        ); // Friday
        description = 'This Week (Mon-Fri)';
        break;
      }

      case 'last7': {
        end = this.normalizeToEndOfDay(today);
        start = this.normalizeToStartOfDay(
          new Date(today.getTime() - 6 * 24 * 60 * 60 * 1000)
        );
        description = 'Last 7 Days';
        break;
      }

      case 'month': {
        start = this.normalizeToStartOfDay(
          new Date(today.getFullYear(), today.getMonth(), 1)
        );
        end = this.normalizeToEndOfDay(today);
        description = `This Month (${this.formatMonthYear(today)})`;
        break;
      }

      case 'last30': {
        end = this.normalizeToEndOfDay(today);
        start = this.normalizeToStartOfDay(
          new Date(today.getTime() - 29 * 24 * 60 * 60 * 1000)
        );
        description = 'Last 30 Days';
        break;
      }

      default:
        throw new Error(`Unknown preset: ${preset}`);
    }

    if (excludeWeekends) {
      // Adjust start to first business day
      start = this.nextBusinessDay(start);
      // Adjust end to last business day
      end = this.previousBusinessDay(end);
    }

    return { start, end, description };
  }

  /**
   * Get a custom date range with business day filtering.
   *
   * @param startDate Start date (will be normalized to start of day)
   * @param endDate End date (will be normalized to end of day)
   * @param excludeWeekends If true, exclude Sat/Sun from the range
   * @returns DateRangeResult
   */
  getCustomRange(
    startDate: Date,
    endDate: Date,
    excludeWeekends: boolean = true
  ): DateRangeResult {
    let start = this.normalizeToStartOfDay(startDate);
    let end = this.normalizeToEndOfDay(endDate);

    if (excludeWeekends) {
      start = this.nextBusinessDay(start);
      end = this.previousBusinessDay(end);
    }

    return {
      start,
      end,
      description: `${this.formatDate(start)} to ${this.formatDate(end)}`,
    };
  }

  /**
   * Check if a given date is a business day (Mon-Fri, not a holiday).
   */
  isBusinessDay(date: Date): boolean {
    const dayOfWeek = date.getDay();
    const isWeekend = dayOfWeek === 0 || dayOfWeek === 6; // Sunday=0, Saturday=6
    const dateStr = this.formatDate(date, 'yyyy-MM-dd');
    const isHoliday = this.HOLIDAYS_2026.has(dateStr);

    return !isWeekend && !isHoliday;
  }

  /**
   * Get all business days in a range.
   * Useful for generating x-axis labels for charts.
   */
  getBusinessDaysInRange(start: Date, end: Date): Date[] {
    const businessDays: Date[] = [];
    const current = new Date(start);

    while (current <= end) {
      if (this.isBusinessDay(current)) {
        businessDays.push(new Date(current));
      }
      current.setDate(current.getDate() + 1);
    }

    return businessDays;
  }

  /**
   * Move to the next business day (forward in time).
   */
  nextBusinessDay(date: Date): Date {
    const next = new Date(date);

    while (!this.isBusinessDay(next)) {
      next.setDate(next.getDate() + 1);
    }

    return next;
  }

  /**
   * Move to the previous business day (backward in time).
   */
  previousBusinessDay(date: Date): Date {
    const prev = new Date(date);

    while (!this.isBusinessDay(prev)) {
      prev.setDate(prev.getDate() - 1);
    }

    return prev;
  }

  /**
   * Normalize a date to start of day (00:00:00).
   */
  normalizeToStartOfDay(date: Date): Date {
    const normalized = new Date(date);
    normalized.setHours(0, 0, 0, 0);
    return normalized;
  }

  /**
   * Normalize a date to end of day (23:59:59.999).
   */
  normalizeToEndOfDay(date: Date): Date {
    const normalized = new Date(date);
    normalized.setHours(23, 59, 59, 999);
    return normalized;
  }

  /**
   * Format a date as YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.
   */
  formatDate(
    date: Date,
    format: 'yyyy-MM-dd' | 'yyyy-MM-dd HH:MM:SS' = 'yyyy-MM-dd'
  ): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    if (format === 'yyyy-MM-dd') {
      return `${year}-${month}-${day}`;
    }

    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');

    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
  }

  /**
   * Format month and year for display (e.g., "April 2026").
   */
  private formatMonthYear(date: Date): string {
    const months = [
      'January',
      'February',
      'March',
      'April',
      'May',
      'June',
      'July',
      'August',
      'September',
      'October',
      'November',
      'December',
    ];
    return `${months[date.getMonth()]} ${date.getFullYear()}`;
  }

  /**
   * Add holidays for a different year or update holidays dynamically.
   */
  addHoliday(dateStr: string): void {
    this.HOLIDAYS_2026.add(dateStr); // Format: 'yyyy-MM-dd'
  }

  removeHoliday(dateStr: string): void {
    this.HOLIDAYS_2026.delete(dateStr);
  }
}
