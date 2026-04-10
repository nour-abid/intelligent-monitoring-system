export interface User {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'superviseur' | 'viewer';
  supervisor_id: number | null;
  is_active: boolean;
  surveillance_identity: string | null;
  attendance_identity: string | null;
}
