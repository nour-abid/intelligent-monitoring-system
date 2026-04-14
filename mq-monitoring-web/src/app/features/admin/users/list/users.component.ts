import { Component, computed, ElementRef, HostListener, inject, OnDestroy, OnInit, signal, ViewChild } from '@angular/core';
import { FormsModule, NgForm } from '@angular/forms';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { User } from '../../../../core/models/auth.model';
import { UsersService, UpdateUserPayload, IdentityPhoto, EnrollmentStatus } from '../../../../core/services/users.service';
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
export class UsersComponent implements OnInit, OnDestroy {
  private readonly service = inject(UsersService);
  private readonly http    = inject(HttpClient);

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

  // ── Identity photo enrollment state ──────────────────────────────────
  /** Photos loaded for the currently-open edit form (null = not yet loaded). */
  readonly photos            = signal<IdentityPhoto[] | null>(null);
  readonly enrollmentStatus  = signal<EnrollmentStatus | null>(null);
  readonly photosLoading     = signal(false);
  readonly photosError       = signal<string | null>(null);
  readonly uploadInProgress  = signal(false);
  readonly deletingPhotoId   = signal<number | null>(null);
  /** Set after a successful creation to show the "upload photos now" banner. */
  readonly createSuccess     = signal(false);
  /**
   * Blob Object URLs keyed by photo id — used in <img> to serve authenticated images.
   * Plain browser <img src> has no bearer token; fetching via HttpClient does.
   */
  private readonly photoSrcMap = signal<Record<number, string>>({});
  /** Shown briefly after a successful upload batch to confirm processing started. */
  readonly uploadSuccess = signal(false);

  /** setInterval handle for background embedding-status polling; null when idle. */
  private pollTimer: ReturnType<typeof setInterval> | null = null;

  // ── Guided camera capture ─────────────────────────────────────────────────
  @ViewChild('cameraVideo') private cameraVideoRef?: ElementRef<HTMLVideoElement>;
  @ViewChild('cameraCanvas') private cameraCanvasRef?: ElementRef<HTMLCanvasElement>;

  /** Whether the guided camera modal is open. */
  readonly cameraOpen = signal(false);
  /** Captures taken so far in the current camera session (data URLs for preview). */
  readonly cameraCaptures = signal<string[]>([]);
  /** Index into CAMERA_POSES for the current instruction. */
  readonly cameraPoseIdx = signal(0);
  /** Countdown seconds until auto-capture (null = no countdown running). */
  readonly cameraCountdown = signal<number | null>(null);
  /** True while uploading captured frames. */
  readonly cameraUploading = signal(false);
  /** Error message inside the camera modal. */
  readonly cameraError = signal<string | null>(null);

  private cameraStream: MediaStream | null = null;
  private countdownTimer: ReturnType<typeof setInterval> | null = null;
  private captureBlobs: Blob[] = [];

  /** Guided pose instructions shown in order. */
  readonly CAMERA_POSES: { icon: string; label: string; hint: string }[] = [
    { icon: '😐', label: 'Face forward',        hint: 'Look straight at the camera with a neutral expression.' },
    { icon: '↖️', label: 'Turn slightly left',  hint: 'Rotate your head gently to the left.' },
    { icon: '↗️', label: 'Turn slightly right', hint: 'Rotate your head gently to the right.' },
    { icon: '⬆️', label: 'Tilt slightly up',    hint: 'Lift your chin slightly and look above the lens.' },
    { icon: '⬇️', label: 'Tilt slightly down',  hint: 'Lower your chin slightly and look below the lens.' },
    { icon: '🙂', label: 'Soft smile',          hint: 'Keep your face forward and smile naturally.' },
    { icon: '🌓', label: 'Slight side angle',   hint: 'Show a mild three-quarter angle, not a full profile.' },
  ];

  protected formData: FormData = emptyForm();

  // ── Derived ──────────────────────────────────────────────────────────────
  readonly supervisorOptions = computed<User[]>(() =>
    this.users().filter((u) => u.role === 'superviseur')
  );

  /** Number of photos that have completed embedding (status = 'ready'). */
  readonly readyCount = computed<number>(() =>
    (this.photos() ?? []).filter((p) => p.processing_status === 'ready').length
  );

  // ── Photo count constraints ───────────────────────────────────────────────
  static readonly MIN_PHOTOS   =  5;   // minimum for embedding to run
  static readonly IDEAL_PHOTOS = 10;   // recommended sweet spot
  static readonly MAX_PHOTOS   = 20;   // hard cap

  /** Exposed to template (static members are not directly accessible in Angular templates). */
  protected readonly minPhotos   = UsersComponent.MIN_PHOTOS;
  protected readonly idealPhotos = UsersComponent.IDEAL_PHOTOS;
  protected readonly maxPhotos   = UsersComponent.MAX_PHOTOS;
  /** Array of MAX_PHOTOS length used to render the segmented count bar. */
  protected readonly photoSlots  = Array.from({ length: UsersComponent.MAX_PHOTOS });

  readonly photoCount = computed<number>(() => (this.photos() ?? []).length);

  /**
   * 'insufficient' — below MIN_PHOTOS, embedding won't produce good results
   * 'ok'           — between MIN and IDEAL
   * 'ideal'        — at or above IDEAL, below MAX
   * 'full'         — at MAX, no more uploads allowed
   */
  readonly photoCountStatus = computed<'insufficient' | 'ok' | 'ideal' | 'full'>(() => {
    const n = this.photoCount();
    if (n >= UsersComponent.MAX_PHOTOS)   return 'full';
    if (n >= UsersComponent.IDEAL_PHOTOS) return 'ideal';
    if (n >= UsersComponent.MIN_PHOTOS)   return 'ok';
    return 'insufficient';
  });

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

  ngOnDestroy(): void {
    this.stopPolling();
    this.stopCamera();
  }

  // ── Public actions ───────────────────────────────────────────────────────

  openCreate(): void {
    this.formData = emptyForm();
    this.editingId.set(null);
    this.formError.set(null);
    this.createSuccess.set(false);
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
    this.createSuccess.set(false);
    this.uploadSuccess.set(false);
    this.formOpen.set(true);
    // Load photos for this user immediately when the edit form opens.
    this.loadPhotos(user.id);
  }

  closeForm(): void {
    this.stopCamera();
    this.formOpen.set(false);
    this.editingId.set(null);
    this.formError.set(null);
    this.createSuccess.set(false);
    this.uploadSuccess.set(false);
    this.stopPolling();
    // Revoke all blob URLs to free memory.
    Object.values(this.photoSrcMap()).forEach((u) => URL.revokeObjectURL(u));
    this.photoSrcMap.set({});
    // Clear photo state so the next open starts fresh.
    this.photos.set(null);
    this.enrollmentStatus.set(null);
    this.photosError.set(null);
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
            // Transition into Edit mode for the new user so the admin can
            // immediately upload identity photos without reopening the modal.
            this.editingId.set(created.id);
            this.formData.password = ''; // clear password field — not shown in edit mode
            this.formError.set(null);
            this.createSuccess.set(true);
            this.loadPhotos(created.id);
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

  // ── Identity photo enrollment methods ─────────────────────────────────

  loadPhotos(userId: number): void {
    this.photosLoading.set(true);
    this.photosError.set(null);
    this.service.listPhotos(userId).subscribe({
      next: (res) => {
        // Guard: discard response if the form was closed or switched to a
        // different user before this request completed (stale response).
        if (this.editingId() !== userId) return;
        this.photos.set(res.photos);
        this.enrollmentStatus.set(res.enrollment_status);
        this.fetchObjectUrls(res.photos);
        this.photosLoading.set(false);
        // Auto-start polling if any photo hasn't settled yet (e.g. a previous
        // upload is still being processed when the edit form is opened).
        const anyPending = res.photos.some(
          (p) => p.processing_status === 'stored' || p.processing_status === 'processing'
        );
        if (anyPending) this.startPolling();
      },
      error: (err: unknown) => {
        if (this.editingId() !== userId) return;
        this.photosError.set(this.extractError(err));
        this.photosLoading.set(false);
      },
    });
  }

  onPhotoFilesSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (!input.files || input.files.length === 0) return;
    const userId = this.editingId();
    if (userId === null) return;

    const files = Array.from(input.files);
    // Reset the input so the same file can be re-selected after deletion.
    input.value = '';

    // Reject .jfif files — browsers report them as image/jpeg but they are
    // not supported by the enrollment pipeline.
    const hasJfif = files.some((f) => f.name.toLowerCase().endsWith('.jfif'));
    if (hasJfif) {
      this.photosError.set(
        'JFIF files are not supported. Please use JPEG, PNG, or WebP.'
      );
      return;
    }

    // Enforce the 20-photo hard cap before uploading.
    const currentCount = (this.photos() ?? []).length;
    const slots = UsersComponent.MAX_PHOTOS - currentCount;
    if (slots <= 0) {
      this.photosError.set(`Maximum of ${UsersComponent.MAX_PHOTOS} photos reached. Delete some before adding more.`);
      return;
    }
    const accepted = files.slice(0, slots);
    const trimmed  = files.length > slots;
    if (trimmed) {
      this.photosError.set(
        `Only ${slots} slot(s) remaining — the last ${files.length - slots} file(s) were skipped to stay within the ${UsersComponent.MAX_PHOTOS}-photo limit.`
      );
    }

    // Enforce the 5-photo minimum — reject if this upload would still leave
    // the total below the minimum required for face embedding to work.
    const totalAfterUpload = currentCount + accepted.length;
    if (totalAfterUpload < UsersComponent.MIN_PHOTOS) {
      const stillNeeded = UsersComponent.MIN_PHOTOS - currentCount;
      this.photosError.set(
        `Please select at least ${stillNeeded} more photo(s). A minimum of ${UsersComponent.MIN_PHOTOS} is required for face recognition.`
      );
      return;
    }

    this.uploadInProgress.set(true);
    if (!trimmed) this.photosError.set(null);
    this.uploadSuccess.set(false);

    this.service.uploadPhotos(userId, accepted).subscribe({
      next: (newPhotos) => {
        this.photos.update((current) => [...(current ?? []), ...newPhotos]);
        this.fetchObjectUrls(newPhotos);
        this.uploadInProgress.set(false);
        this.uploadSuccess.set(true);
        // Start polling so the UI reflects embedding progress without a manual refresh.
        this.startPolling();
      },
      error: (err: unknown) => {
        this.photosError.set(this.extractError(err));
        this.uploadInProgress.set(false);
      },
    });
  }

  deletePhoto(photoId: number): void {
    this.deletingPhotoId.set(photoId);
    this.service.deletePhoto(photoId).subscribe({
      next: () => {
        this.photos.update((current) =>
          (current ?? []).filter((p) => p.id !== photoId)
        );
        // Revoke blob URL for the deleted photo to free memory.
        const src = this.photoSrcMap()[photoId];
        if (src) URL.revokeObjectURL(src);
        this.photoSrcMap.update((m) => { const n = { ...m }; delete n[photoId]; return n; });
        this.deletingPhotoId.set(null);
      },
      error: (err: unknown) => {
        this.photosError.set(this.extractError(err));
        this.deletingPhotoId.set(null);
      },
    });
  }

  formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  /** Returns the blob Object URL for a photo, or '' while loading. */
  photoSrc(photoId: number): string {
    return this.photoSrcMap()[photoId] ?? '';
  }

  // ── Guided camera capture ─────────────────────────────────────────────────

  async openCamera(): Promise<void> {
    this.cameraError.set(null);
    this.cameraCaptures.set([]);
    this.captureBlobs = [];
    this.cameraPoseIdx.set(0);
    this.cameraCountdown.set(null);
    this.cameraUploading.set(false);
    this.cameraOpen.set(true);

    try {
      this.cameraStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: false,
      });
    } catch {
      this.cameraError.set('Camera access denied. Allow camera permission and try again.');
      return;
    }

    // Attach stream to video element after Angular renders it.
    setTimeout(() => {
      const video = this.cameraVideoRef?.nativeElement;
      if (video && this.cameraStream) {
        video.srcObject = this.cameraStream;
        video.play();
      }
    }, 50);
  }

  /** Stop the stream, clear the countdown, close the modal. */
  closeCamera(): void {
    this.stopCountdown();
    this.stopCamera();
    this.cameraOpen.set(false);
  }

  /** Start a 3-second countdown then auto-capture the current pose. */
  startCountdown(): void {
    if (this.cameraCountdown() !== null) return;
    this.cameraCountdown.set(3);
    this.countdownTimer = setInterval(() => {
      const current = this.cameraCountdown();
      if (current === null) { this.stopCountdown(); return; }
      if (current <= 1) {
        this.stopCountdown();
        this.captureFrame();
      } else {
        this.cameraCountdown.set(current - 1);
      }
    }, 1000);
  }

  /** Capture the current video frame immediately (no countdown). */
  captureFrame(): void {
    const video  = this.cameraVideoRef?.nativeElement;
    const canvas = this.cameraCanvasRef?.nativeElement;
    if (!video || !canvas) return;

    this.cameraCountdown.set(null);
    canvas.width  = video.videoWidth  || 1280;
    canvas.height = video.videoHeight || 720;
    canvas.getContext('2d')!.drawImage(video, 0, 0);

    // Store data URL for preview strip.
    const dataUrl = canvas.toDataURL('image/jpeg', 0.92);
    this.cameraCaptures.update((c) => [...c, dataUrl]);

    // Store blob for upload.
    canvas.toBlob((blob) => {
      if (blob) this.captureBlobs.push(blob);
    }, 'image/jpeg', 0.92);

    // Advance to next pose or stay on last.
    const next = this.cameraPoseIdx() + 1;
    if (next < this.CAMERA_POSES.length) {
      this.cameraPoseIdx.set(next);
    }
  }

  removeCapture(index: number): void {
    this.cameraCaptures.update((c) => c.filter((_, i) => i !== index));
    this.captureBlobs.splice(index, 1);
    // Step back pose index so user can re-capture the removed pose.
    const newPose = Math.max(0, Math.min(this.cameraPoseIdx() - 1, this.CAMERA_POSES.length - 1));
    this.cameraPoseIdx.set(Math.max(this.cameraCaptures().length, newPose));
  }

  /** Upload all captured blobs via the existing photo upload endpoint. */
  uploadCameraCaptures(): void {
    const userId = this.editingId();
    if (userId === null || this.captureBlobs.length === 0) return;

    // Enforce the 20-photo hard cap before uploading.
    const currentCount = (this.photos() ?? []).length;
    const slots = UsersComponent.MAX_PHOTOS - currentCount;
    if (slots <= 0) {
      this.cameraError.set(`Maximum of ${UsersComponent.MAX_PHOTOS} photos already reached. Delete some first.`);
      return;
    }

    this.cameraUploading.set(true);
    this.cameraError.set(null);

    // Convert blobs to File objects so UsersService.uploadPhotos() accepts them.
    const blobsToUpload = this.captureBlobs.slice(0, slots);
    const files = blobsToUpload.map(
      (blob, i) => new File([blob], `camera-capture-${i + 1}.jpg`, { type: 'image/jpeg' })
    );

    this.service.uploadPhotos(userId, files).subscribe({
      next: (newPhotos) => {
        this.photos.update((current) => [...(current ?? []), ...newPhotos]);
        this.fetchObjectUrls(newPhotos);
        this.uploadSuccess.set(true);
        this.startPolling();
        this.cameraUploading.set(false);
        this.closeCamera();
      },
      error: (err: unknown) => {
        this.cameraError.set(this.extractError(err));
        this.cameraUploading.set(false);
      },
    });
  }

  private stopCountdown(): void {
    if (this.countdownTimer !== null) {
      clearInterval(this.countdownTimer);
      this.countdownTimer = null;
    }
    this.cameraCountdown.set(null);
  }

  private stopCamera(): void {
    this.stopCountdown();
    if (this.cameraStream) {
      this.cameraStream.getTracks().forEach((t) => t.stop());
      this.cameraStream = null;
    }
    this.cameraOpen.set(false);
  }

  // ── Polling ───────────────────────────────────────────────────────────────

  /**
   * Start a 2.5-second background poll that silently refreshes photo statuses.
   * Calling this when a poll is already running is a no-op.
   */
  private startPolling(): void {
    if (this.pollTimer !== null) return;
    this.pollTimer = setInterval(() => this.pollPhotos(), 2500);
  }

  private stopPolling(): void {
    if (this.pollTimer !== null) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  /**
   * One poll tick: silently re-fetches photos for the user currently being
   * edited and merges status updates into the `photos` signal.
   * Stops automatically once every photo has settled (ready or failed).
   */
  private pollPhotos(): void {
    const userId = this.editingId();
    if (userId === null) { this.stopPolling(); return; }

    this.service.listPhotos(userId).subscribe({
      next: (res) => {
        // Guard: discard response if the form was closed or switched to a
        // different user before this HTTP response arrived.  Without this
        // check an in-flight poll request from a previous form session can
        // overwrite the current session's fresh `loadPhotos()` data with
        // stale statuses (e.g. 'stored' instead of 'ready'), causing the
        // status badges to flip back unexpectedly.
        if (this.editingId() !== userId) return;

        // Full replace with authoritative DB state (photoSrcMap is kept
        // separately so blob URLs are not lost by this assignment).
        this.photos.set(res.photos);
        this.enrollmentStatus.set(res.enrollment_status);
        // Fetch blob URLs for any new photos that arrived since last poll.
        this.fetchObjectUrls(res.photos);

        // Stop when every photo has a terminal status.
        const allSettled = res.photos.length > 0 && res.photos.every(
          (p) => p.processing_status === 'ready' || p.processing_status === 'failed'
        );
        if (allSettled) {
          this.stopPolling();
          this.uploadSuccess.set(false);
        }
      },
      // Silently swallow poll errors — the user already sees the last known state.
      error: () => {},
    });
  }

  // ── Private ──────────────────────────────────────────────────────────────

  /**
   * Fetch each photo as a blob via HttpClient (which carries the bearer token)
   * and store a blob: URL in photoSrcMap for use in <img> tags.
   *
   * IMPORTANT: Use a relative /api/... path — NOT the absolute URL returned by
   * Laravel's route() helper. The absolute URL bypasses the Angular dev-server
   * proxy, causing cross-origin failures. The relative path is rewritten by
   * apiBaseUrlInterceptor in production and proxied in development.
   */
  private fetchObjectUrls(photos: IdentityPhoto[]): void {
    const existing = this.photoSrcMap();
    photos.forEach((photo) => {
      if (existing[photo.id]) return; // already fetched
      // Build a relative URL so it goes through the proxy / interceptor chain.
      const apiUrl = `/api/photos/${photo.id}/image`;
      this.http.get(apiUrl, { responseType: 'blob' }).subscribe({
        next: (blob) => {
          console.log(`[EnrollmentPhoto] fetched id=${photo.id} size=${blob.size} type=${blob.type}`);
          if (blob.size === 0) {
            console.warn(`[EnrollmentPhoto] blob for id=${photo.id} is empty — image will not display`);
            return;
          }
          const url = URL.createObjectURL(blob);
          this.photoSrcMap.update((m) => ({ ...m, [photo.id]: url }));
        },
        error: (err) => {
          console.error(`[EnrollmentPhoto] failed to fetch id=${photo.id}`, err);
        },
      });
    });
  }

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
