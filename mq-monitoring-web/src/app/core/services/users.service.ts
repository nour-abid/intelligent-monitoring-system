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
}
