import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { FormsModule } from '@angular/forms';

import { AttendanceService } from '../../../../core/services/attendance.service';
import { AttendanceRow, AttendanceKpis, AttendanceStatus } from '../../models/attendance.model';
import { AuthService } from '../../../../core/services/auth.service';
import { toDateInputValue, DatePresetKey, datePreset } from '../../../../core/utils/duration.util';

type LoadState = 'idle' | 'loading' | 'success' | 'error';

const STATUS_LABEL: Record<AttendanceStatus, string> = {
  on_time:     'On Time',
  late:        'Late',
  absent:      'Absent',
  early_leave: 'Early Leave',
};

@Component({
  selector: 'app-attendance-page',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './attendance-page.component.html',
  styleUrl: './attendance-page.component.scss',
})
export class AttendancePageComponent implements OnInit {
  private readonly svc       = inject(AttendanceService);
  private readonly authSvc   = inject(AuthService);
  private readonly router    = inject(Router);

  readonly isAdmin = computed(() => this.authSvc.user()?.role === 'admin');

  // ── Filter state ──────────────────────────────────────────────────────────
  readonly today         = toDateInputValue(new Date());
  activePreset           = 'today';
  filterStart            = this.today;
  filterEnd              = this.today;

  // ── Data signals ──────────────────────────────────────────────────────────
  loadState  = signal<LoadState>('idle');
  errorMsg   = signal('');
  kpis       = signal<AttendanceKpis | null>(null);
  rows       = signal<AttendanceRow[]>([]);
  searchText = signal('');
  statusFilter = signal<AttendanceStatus | 'all'>('all');

  // ── Derived ───────────────────────────────────────────────────────────────
  filteredRows = computed(() => {
    let data = this.rows();
    const q  = this.searchText().toLowerCase().trim();
    const sf = this.statusFilter();

    if (q)         data = data.filter(r => r.name.toLowerCase().includes(q) || r.identity.toLowerCase().includes(q));
    if (sf !== 'all') data = data.filter(r => r.status === sf);
    return data;
  });

  statusLabel(s: AttendanceStatus): string {
    return STATUS_LABEL[s] ?? s;
  }

  ngOnInit(): void {
    this.load();
  }

  // ── Preset handling (same pattern as dashboard) ───────────────────────────
  setPreset(key: DatePresetKey): void {
    this.activePreset = key;
    const { start, end } = datePreset(key);
    this.filterStart = start;
    this.filterEnd   = end;
    this.load();
  }

  setCustom(): void {
    this.activePreset = 'custom';
  }

  onStartChange(v: string): void {
    this.filterStart  = v;
    this.activePreset = 'custom';
  }

  onEndChange(v: string): void {
    this.filterEnd    = v;
    this.activePreset = 'custom';
  }

  load(): void {
    this.loadState.set('loading');
    this.svc.getAttendance({ start: this.filterStart, end: this.filterEnd }).subscribe({
      next: (res) => {
        this.kpis.set(res.kpis);
        this.rows.set(res.rows);
        this.loadState.set('success');
      },
      error: (err) => {
        this.errorMsg.set(err?.error?.message ?? 'Failed to load attendance data.');
        this.loadState.set('error');
      },
    });
  }
}
