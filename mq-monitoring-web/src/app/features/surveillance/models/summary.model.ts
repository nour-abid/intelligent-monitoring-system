/** Response from GET /api/monitoring/surveillance/summary */

export interface DashboardKpis {
  total_alerts:     number;
  active_employees: number;
  late_arrivals:    number;
  early_leaves:     number;
}

/** One bucket in the alert trend chart. */
export interface AlertTrendPoint {
  /** 'YYYY-MM-DD HH:00' (hourly granularity) or 'YYYY-MM-DD' (daily). */
  label: string;
  count: number;
}

export interface AlertsByTypeItem {
  type:  string;
  count: number;
}

export interface TopEmployeeItem {
  identity:     string;
  display_name: string | null;
  count:        number;
}

export interface ActivityDistributionItem {
  activity:  string;
  total_sec: number;
  share:     number; // 0..1
}

export interface ObservationTrendPoint {
  label:     string;
  /** Number of surveillance events recorded in this bucket — drives bar height. */
  count:     number;
  /** Total observed duration in seconds — used for tooltip. */
  total_sec: number;
}

export interface TopObservedEmployee {
  identity:     string;
  display_name: string | null;
  total_sec:    number;
}

/** One time bucket in the stacked activity evolution chart. */
export interface ActivityEvolutionPoint {
  label:       string;
  working:     number; // seconds
  meeting:     number;
  inactive:    number;
  using_phone: number;
}

export interface DashboardCharts {
  alert_trend:             AlertTrendPoint[];
  alerts_by_type:          AlertsByTypeItem[];
  top_affected_employees:  TopEmployeeItem[];
  activity_distribution:   ActivityDistributionItem[];
  observation_trend:       ObservationTrendPoint[];
  top_observed_employees:  TopObservedEmployee[];
  activity_evolution:      ActivityEvolutionPoint[];
}

export interface DashboardSummaryResponse {
  kpis:   DashboardKpis;
  charts: DashboardCharts;
}

export interface DashboardSummaryParams {
  start?:           string;
  end?:             string;
  include_unknown?: boolean;
}
