export type AttendanceStatus = 'on_time' | 'late' | 'absent' | 'early_leave';

export interface AttendanceRow {
  name:          string;
  identity:      string;
  date:          string;
  status:        AttendanceStatus;
  checkin_time:  string | null;
  checkout_time: string | null;
}

export interface AttendanceKpis {
  total_on_time:     number;
  total_late:        number;
  total_absent:      number;
  total_early_leave: number;
  on_time_pct:       number;
}

export interface AttendanceResponse {
  kpis: AttendanceKpis;
  rows: AttendanceRow[];
}

export interface AttendanceParams {
  start?: string;
  end?:   string;
}
