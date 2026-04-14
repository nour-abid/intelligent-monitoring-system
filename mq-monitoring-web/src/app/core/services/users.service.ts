import { inject, Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { User } from '../models/auth.model';

export interface CreateUserPayload {
  name: string;
  email: string;
  password: string;
  role: 'admin' | 'superviseur' | 'viewer';
  is_active: boolean;
  supervisor_id: number | null;
  surveillance_identity: string | null;
  attendance_identity: string | null;
}

export interface UpdateUserPayload {
  name?: string;
  email?: string;
  password?: string;
  role?: 'admin' | 'superviseur' | 'viewer';
  is_active?: boolean;
  supervisor_id?: number | null;
  surveillance_identity?: string | null;
  attendance_identity?: string | null;
}

export interface IdentityPhoto {
  id: number;
  user_id: number;
  original_filename: string;
  mime_type: string;
  size_bytes: number;
  /** stored → processing → ready | failed */
  processing_status: 'stored' | 'processing' | 'ready' | 'failed';
  /** Rejection reason (e.g. NO_FACE) or exception message when failed. */
  processing_error: string | null;
  /** Classified head pose detected by the embedding service. */
  detected_pose: string | null;
  processed_at: string | null;
  created_at: string;
  /** API route that streams the actual image file. */
  url: string;
}

export interface EnrollmentStatus {
  surveillance_identity: string | null;
  /** false when the user has no surveillance_identity set — photos will be unusable by the pipeline */
  has_identity_mapping: boolean;
  /** Which of the 5 required poses have been detected in ready photos. */
  pose_coverage: {
    covered: string[];
    missing: string[];
  };
}

/** Result returned by POST /api/face/validate-frame */
export interface FrameValidation {
  /** True when all readiness checks pass — safe to capture. */
  ready: boolean;
  /** Human-readable status line for the live feedback overlay. */
  message: string;
  face_count: number;
  predicted_pose: string | null;
  matches_expected: boolean;
  /** Head pose angles in degrees (from InsightFace). */
  yaw: number;
  pitch: number;
  roll: number;
  quality_score: number;
  face_size_ratio: number;
  sharpness: number;
}

@Injectable({ providedIn: 'root' })
export class UsersService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/users';

  list(): Observable<User[]> {
    return this.http
      .get<{ users: User[] }>(this.base)
      .pipe(map((r) => r.users));
  }

  create(payload: CreateUserPayload): Observable<User> {
    return this.http.post<User>(this.base, payload);
  }

  update(id: number, payload: UpdateUserPayload): Observable<User> {
    return this.http.patch<User>(`${this.base}/${id}`, payload);
  }

  // ── Identity photo endpoints ─────────────────────────────────────────

  listPhotos(userId: number): Observable<{ photos: IdentityPhoto[]; enrollment_status: EnrollmentStatus }> {
    return this.http
      .get<{ photos: IdentityPhoto[]; enrollment_status: EnrollmentStatus }>(`${this.base}/${userId}/photos`);
  }

  uploadPhotos(userId: number, files: File[]): Observable<IdentityPhoto[]> {
    const form = new FormData();
    files.forEach((f) => form.append('photos[]', f, f.name));
    return this.http
      .post<{ photos: IdentityPhoto[] }>(`${this.base}/${userId}/photos`, form)
      .pipe(map((r) => r.photos));
  }

  deletePhoto(photoId: number): Observable<void> {
    return this.http.delete<void>(`/api/photos/${photoId}`);
  }

  /**
   * Send a raw webcam frame to the Python face-validation endpoint.
   * The endpoint only runs the face detector — no embedding is generated.
   */
  validateFrame(blob: Blob, expectedPose: string): Observable<FrameValidation> {
    const form = new FormData();
    form.append('image', blob, 'frame.jpg');
    form.append('expected_pose', expectedPose);
    return this.http.post<FrameValidation>('/api/face/validate-frame', form);
  }
}
