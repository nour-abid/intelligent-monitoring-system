import { inject, Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { AttendanceResponse, AttendanceParams } from '../../features/attendance/models/attendance.model';

@Injectable({ providedIn: 'root' })
export class AttendanceService {
  private readonly http = inject(HttpClient);
  private readonly base = '/api/monitoring/attendance';

  getAttendance(params: AttendanceParams = {}): Observable<AttendanceResponse> {
    let p = new HttpParams();
    if (params.start) p = p.set('start', params.start);
    if (params.end)   p = p.set('end',   params.end);
    return this.http.get<AttendanceResponse>(this.base, { params: p });
  }
}
