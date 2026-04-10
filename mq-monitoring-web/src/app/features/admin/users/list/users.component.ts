import { Component, computed, HostListener, inject, OnInit, signal } from '@angular/core';
import { FormsModule, NgForm } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { User } from '../../../../core/models/auth.model';
import { UsersService, UpdateUserPayload } from '../../../../core/services/users.service';
import { UserStatsModalComponent } from '../popup/user-stats-modal.component';
type LoadState = 'loading' | 'success' | 'error';

interface FormData {
  name: string;
  email: string;
  password: string;
  role: 'admin' | 'superviseur' | 'viewer';
  is_active: boolean;
  supervisor_id: number | null;
  surveillance_identity: string | null;
  attendance_identity: string | null;
}

function emptyForm(): FormData {
  return {
    name: '',
    email: '',
    password: '',
    role: 'viewer',
    is_active: true,
    supervisor_id: null,
    surveillance_identity: null,
    attendance_identity: null,
  };
}

@Component({
  selector: 'app-admin-users',
  standalone: true,
  imports: [FormsModule, UserStatsModalComponent],
  templateUrl: './users.component.html',
  styleUrl: './users.component.scss',
})
export class UsersComponent implements OnInit {
  private readonly service = inject(UsersService);

  // ── Page state ───────────────────────────────────────────────────────────
  readonly users     = signal<User[]>([]);
  readonly loadState = signal<LoadState>('loading');
  readonly loadError = signal<string>('');

  // ── Form state ───────────────────────────────────────────────────────────
  readonly formOpen   = signal(false);
  readonly editingId  = signal<number | null>(null);
  readonly formError   = signal<string | null>(null);
  readonly submitting  = signal(false);
  readonly toggleError = signal<string | null>(null);

  // ── Stats modal state
  /** The surveillance_identity whose stats popup is open, or null if closed. */
  readonly statsIdentity = signal<string | null>(null);

  protected formData: FormData = emptyForm();

  // ── Derived ──────────────────────────────────────────────────────────────
  readonly supervisorOptions = computed<User[]>(() =>
    this.users().filter((u) => u.role === 'superviseur')
  );

  // Active/inactive filter — client-side, no re-fetch needed.
  readonly statusFilter = signal<'all' | 'active' | 'inactive'>('all');

  // Search term — client-side, applied after status filter.
  readonly searchTerm = signal('');

  readonly filteredUsers = computed<User[]>(() => {
    const all = this.users();
    const f   = this.statusFilter();
    if (f === 'active')   return all.filter((u) => u.is_active);
    if (f === 'inactive') return all.filter((u) => !u.is_active);
    return all;
  });

  /** Status-filtered users further narrowed by the search term. */
  readonly searchedUsers = computed<User[]>(() => {
    const term = this.searchTerm().trim().toLowerCase();
    if (!term) return this.filteredUsers();
    return this.filteredUsers().filter((u) =>
      u.name.toLowerCase().includes(term) ||
      u.email.toLowerCase().includes(term) ||
      (u.surveillance_identity ?? '').toLowerCase().includes(term) ||
      (u.attendance_identity   ?? '').toLowerCase().includes(term)
    );
  });

  // ── Pagination ───────────────────────────────────────────────────
  static readonly PAGE_SIZE = 10;
  readonly currentPage = signal(1);

  readonly pageCount = computed<number>(() =>
    Math.max(1, Math.ceil(this.searchedUsers().length / UsersComponent.PAGE_SIZE))
  );

  /** Clamp current page to valid range whenever searchedUsers or pageCount change. */
  readonly pagedUsers = computed<User[]>(() => {
    const filtered = this.searchedUsers();
    const count    = Math.max(1, Math.ceil(filtered.length / UsersComponent.PAGE_SIZE));
    const page     = Math.min(this.currentPage(), count);
    const start    = (page - 1) * UsersComponent.PAGE_SIZE;
    return filtered.slice(start, start + UsersComponent.PAGE_SIZE);
  });

  prevPage(): void {
    this.currentPage.update((p) => Math.max(1, p - 1));
  }

  nextPage(): void {
    this.currentPage.update((p) => Math.min(this.pageCount(), p + 1));
  }

  // ── Lifecycle ────────────────────────────────────────────────────────────
  ngOnInit(): void {
    this.loadUsers();
  }

  // ── Public actions ───────────────────────────────────────────────────────

  openCreate(): void {
    this.formData = emptyForm();
    this.editingId.set(null);
    this.formError.set(null);
    this.formOpen.set(true);
  }

  openEdit(user: User): void {
    this.formData = {
      name:                  user.name,
      email:                 user.email,
      password:              '',
      role:                  user.role,
      is_active:             user.is_active,
      supervisor_id:         user.supervisor_id,
      surveillance_identity: user.surveillance_identity,
      attendance_identity:   user.attendance_identity,
    };
    this.editingId.set(user.id);
    this.formError.set(null);
    this.formOpen.set(true);
  }

  closeForm(): void {
    this.formOpen.set(false);
    this.editingId.set(null);
    this.formError.set(null);
  }

  @HostListener('document:keydown.escape')
  onEscapeKey(): void {
    if (this.formOpen()) this.closeForm();
    else if (this.statsIdentity()) this.closeStatsModal();
  }

  submitForm(form: NgForm): void {
    if (form.invalid || this.submitting()) return;

    this.formError.set(null);
    this.submitting.set(true);

    const id = this.editingId();

    if (id === null) {
      // ── Create ──────────────────────────────────────────────────────────
      this.service
        .create({
          name:                  this.formData.name,
          email:                 this.formData.email,
          password:              this.formData.password,
          role:                  this.formData.role,
          is_active:             this.formData.is_active,
          supervisor_id:         this.formData.supervisor_id,
          surveillance_identity: this.formData.surveillance_identity || null,
          attendance_identity:   this.formData.attendance_identity   || null,
        })
        .subscribe({
          next: (created) => {
            this.users.update((list) =>
              [...list, created].sort((a, b) => a.name.localeCompare(b.name))
            );
            this.submitting.set(false);
            this.closeForm();
          },
          error: (err: unknown) => {
            this.formError.set(this.extractError(err));
            this.submitting.set(false);
          },
        });
    } else {
      // ── Update ──────────────────────────────────────────────────────────
      const payload: UpdateUserPayload = {
        name:                  this.formData.name,
        email:                 this.formData.email,
        role:                  this.formData.role,
        is_active:             this.formData.is_active,
        supervisor_id:         this.formData.supervisor_id,
        surveillance_identity: this.formData.surveillance_identity || null,
        attendance_identity:   this.formData.attendance_identity   || null,
      };

      // Only send password if the admin typed a new one.
      if (this.formData.password) {
        payload.password = this.formData.password;
      }

      this.service.update(id, payload).subscribe({
        next: (updated) => {
          this.users.update((list) =>
            list.map((u) => (u.id === updated.id ? updated : u))
          );
          this.submitting.set(false);
          this.closeForm();
        },
        error: (err: unknown) => {
          this.formError.set(this.extractError(err));
          this.submitting.set(false);
        },
      });
    }
  }

  toggleActive(user: User): void {
    this.service.update(user.id, { is_active: !user.is_active }).subscribe({
      next: (updated) => {
        this.toggleError.set(null);
        this.users.update((list) =>
          list.map((u) => (u.id === updated.id ? updated : u))
        );
      },
      error: (err: unknown) => {
        this.toggleError.set(this.extractError(err));
      },
    });
  }

  /** Look up the human-readable supervisor name for display in the table. */
  supervisorName(supervisorId: number | null): string {
    if (supervisorId === null) return '—';
    return this.users().find((u) => u.id === supervisorId)?.name ?? '—';
  }

  onSearchChange(value: string): void {
    this.searchTerm.set(value);
    this.currentPage.set(1);
  }

  viewStats(identity: string | null | undefined): void {
    if (!identity) return;
    this.statsIdentity.set(identity);
  }

  closeStatsModal(): void {
    this.statsIdentity.set(null);
  }

  // ── Private ──────────────────────────────────────────────────────────────

  private loadUsers(): void {
    this.loadState.set('loading');
    this.loadError.set('');

    this.service.list().subscribe({
      next: (users) => {
        this.users.set(users);
        this.loadState.set('success');
      },
      error: (err: unknown) => {
        this.loadError.set(this.extractError(err));
        this.loadState.set('error');
      },
    });
  }

  private extractError(err: unknown): string {
    if (err instanceof HttpErrorResponse) {
      const body = err.error as { message?: string } | null;
      return body?.message ?? `Server error (${err.status}).`;
    }
    return 'An unexpected error occurred.';
  }
}
