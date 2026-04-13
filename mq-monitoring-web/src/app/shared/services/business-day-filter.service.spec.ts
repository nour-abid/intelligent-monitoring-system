import { TestBed } from '@angular/core/testing';
import { BusinessDayFilterService } from './business-day-filter.service';

describe('BusinessDayFilterService', () => {
  let service: BusinessDayFilterService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [BusinessDayFilterService],
    });
    service = TestBed.inject(BusinessDayFilterService);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });

  describe('isBusinessDay', () => {
    it('should return true for Monday', () => {
      // April 7, 2026 is a Monday
      const date = new Date(2026, 3, 7);
      expect(service.isBusinessDay(date)).toBe(true);
    });

    it('should return true for Friday', () => {
      // April 3, 2026 is a Friday
      const date = new Date(2026, 3, 3);
      expect(service.isBusinessDay(date)).toBe(true);
    });

    it('should return false for Saturday', () => {
      // April 4, 2026 is a Saturday
      const date = new Date(2026, 3, 4);
      expect(service.isBusinessDay(date)).toBe(false);
    });

    it('should return false for Sunday', () => {
      // April 5, 2026 is a Sunday
      const date = new Date(2026, 3, 5);
      expect(service.isBusinessDay(date)).toBe(false);
    });

    it('should return false for holidays', () => {
      // January 14, 2026 is Revolution Day (holiday)
      const date = new Date(2026, 0, 14);
      expect(service.isBusinessDay(date)).toBe(false);
    });
  });

  describe('normalizeToStartOfDay', () => {
    it('should set time to 00:00:00', () => {
      const date = new Date(2026, 3, 7, 15, 30, 45);
      const normalized = service.normalizeToStartOfDay(date);

      expect(normalized.getHours()).toBe(0);
      expect(normalized.getMinutes()).toBe(0);
      expect(normalized.getSeconds()).toBe(0);
      expect(normalized.getMilliseconds()).toBe(0);
    });
  });

  describe('normalizeToEndOfDay', () => {
    it('should set time to 23:59:59.999', () => {
      const date = new Date(2026, 3, 7, 10, 30, 45);
      const normalized = service.normalizeToEndOfDay(date);

      expect(normalized.getHours()).toBe(23);
      expect(normalized.getMinutes()).toBe(59);
      expect(normalized.getSeconds()).toBe(59);
      expect(normalized.getMilliseconds()).toBe(999);
    });
  });

  describe('nextBusinessDay', () => {
    it('should move Friday to Monday', () => {
      // April 3, 2026 is Friday
      const friday = new Date(2026, 3, 3);
      const next = service.nextBusinessDay(friday);

      // April 6, 2026 is Monday
      expect(next.getDate()).toBe(6);
      expect(next.getMonth()).toBe(3);
    });

    it('should skip weekend days', () => {
      // April 4, 2026 is Saturday
      const saturday = new Date(2026, 3, 4);
      const next = service.nextBusinessDay(saturday);

      // April 6, 2026 is Monday
      expect(next.getDate()).toBe(6);
    });

    it('should skip holidays', () => {
      // January 13, 2026 (Tuesday before Revolution Day)
      const jan13 = new Date(2026, 0, 13);
      const next = service.nextBusinessDay(jan13);

      // Should skip Jan 14 (holiday) and land on Jan 15 (Thursday)
      expect(next.getDate()).toBe(15);
    });
  });

  describe('previousBusinessDay', () => {
    it('should move Monday to Friday of previous week', () => {
      // April 6, 2026 is Monday
      const monday = new Date(2026, 3, 6);
      const prev = service.previousBusinessDay(monday);

      // April 3, 2026 is Friday
      expect(prev.getDate()).toBe(3);
    });

    it('should skip weekend days backward', () => {
      // April 5, 2026 is Sunday
      const sunday = new Date(2026, 3, 5);
      const prev = service.previousBusinessDay(sunday);

      // April 3, 2026 is Friday
      expect(prev.getDate()).toBe(3);
    });
  });

  describe('formatDate', () => {
    it('should format date as yyyy-MM-dd', () => {
      const date = new Date(2026, 3, 7);
      const formatted = service.formatDate(date);

      expect(formatted).toBe('2026-04-07');
    });

    it('should format date as yyyy-MM-dd HH:MM:SS', () => {
      const date = new Date(2026, 3, 7, 14, 30, 45);
      const formatted = service.formatDate(date, 'yyyy-MM-dd HH:MM:SS');

      expect(formatted).toBe('2026-04-07 14:30:45');
    });

    it('should pad single-digit months and days', () => {
      const date = new Date(2026, 0, 5, 9, 5, 3);
      const formatted = service.formatDate(date);

      expect(formatted).toBe('2026-01-05');
    });
  });

  describe('getDateRange', () => {
    it('should return today range', () => {
      const range = service.getDateRange('today', false);

      expect(range.start.getDate()).toBe(range.end.getDate());
      expect(range.start.getHours()).toBe(0);
      expect(range.end.getHours()).toBe(23);
    });

    it('should handle yesterday', () => {
      const range = service.getDateRange('yesterday', false);
      const today = new Date();
      const expectedDate = new Date(today);
      expectedDate.setDate(today.getDate() - 1);

      expect(range.start.toDateString()).toBe(expectedDate.toDateString());
    });

    it('should exclude weekends when requested', () => {
      // April 4, 2026 is Saturday; April 5, 2026 is Sunday
      // last7 should skip them when excludeWeekends is true
      const range = service.getDateRange('last7', true);

      // Both start and end should be business days
      expect(service.isBusinessDay(range.start)).toBe(true);
      expect(service.isBusinessDay(range.end)).toBe(true);
    });

    it('should include weekends when not requested', () => {
      const today = new Date(2026, 3, 4); // Saturday
      // For testing, we manually check that without excluding weekends,
      // a Saturday can be part of the range
      const range = service.getDateRange('last7', false);

      // Range should span 7 calendar days
      const dayDiff =
        (range.end.getTime() - range.start.getTime()) / (1000 * 60 * 60 * 24);
      expect(dayDiff).toBeCloseTo(7, 0);
    });
  });

  describe('getBusinessDaysInRange', () => {
    it('should return only business days', () => {
      // April 6-12, 2026 (Mon-Sun, with Wed-Fri included)
      const start = new Date(2026, 3, 6); // Monday
      const end = new Date(2026, 3, 12); // Sunday

      const businessDays = service.getBusinessDaysInRange(start, end);

      // Should be Mon-Fri (5 days), excluding Sat-Sun
      expect(businessDays.length).toBe(5);

      businessDays.forEach((day) => {
        expect(service.isBusinessDay(day)).toBe(true);
      });
    });

    it('should exclude holidays from the range', () => {
      // January 10-20, 2026 (includes Jan 14 holiday)
      const start = new Date(2026, 0, 10); // Saturday
      const end = new Date(2026, 0, 20); // Tuesday

      const businessDays = service.getBusinessDaysInRange(start, end);

      // Check that Jan 14 is not included
      const jan14Included = businessDays.some((day) => day.getDate() === 14);
      expect(jan14Included).toBe(false);
    });
  });

  describe('addHoliday / removeHoliday', () => {
    it('should add a custom holiday', () => {
      const customDate = '2026-06-15';
      service.addHoliday(customDate);

      const date = new Date(2026, 5, 15); // June 15
      expect(service.isBusinessDay(date)).toBe(false);
    });

    it('should remove a holiday', () => {
      const customDate = '2026-06-15';
      service.addHoliday(customDate);
      service.removeHoliday(customDate);

      const date = new Date(2026, 5, 15); // June 15
      expect(service.isBusinessDay(date)).toBe(true); // Should be Monday
    });
  });
});
