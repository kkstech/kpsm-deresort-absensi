import * as SecureStore from 'expo-secure-store';
import * as SQLite from 'expo-sqlite';

export class ApiError extends Error {
  constructor(public status: number, public code: string | undefined, message: string) {
    super(message);
  }
}

type UnauthorizedCallback = (message?: string) => void;
let unauthorizedHandler: UnauthorizedCallback | null = null;

export function onUnauthorized(handler: UnauthorizedCallback | null): void {
  unauthorizedHandler = handler;
}

export function normalizeBaseUrl(url: string): string {
  return url.trim().replace(/\/+$/, '').replace(/\/api\/v1\/?$/i, '').replace(/\/api\/?$/i, '');
}

export async function request<T>(baseUrl: string, path: string, token?: string | null, options: { method?: string; body?: unknown } = {}): Promise<T> {
  const isForm = options.body != null && (
    (typeof FormData !== 'undefined' && options.body instanceof FormData) ||
    (typeof options.body === 'object' && typeof (options.body as any).append === 'function')
  );
  const cleanBase = normalizeBaseUrl(baseUrl);
  let response: Response;
  try {
    response = await fetch(`${cleanBase}/api/v1/${path.replace(/^\//, '')}`, {
      method: options.method ?? 'GET',
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...(options.body && !isForm ? { 'Content-Type': 'application/json' } : {}),
      },
      body: options.body ? (isForm ? (options.body as any) : JSON.stringify(options.body)) : undefined,
    });
  } catch (error: any) {
    const rawMsg = error instanceof Error ? error.message : String(error ?? '');
    const isNetworkAbort = rawMsg.toLowerCase().includes('network') || rawMsg.toLowerCase().includes('failed') || rawMsg.toLowerCase().includes('abort');
    const msg = isNetworkAbort
      ? `Server tidak dapat dijangkau (${cleanBase}). Periksa alamat server dan koneksi.`
      : (rawMsg ? `Koneksi gagal: ${rawMsg}` : 'Server tidak dapat dijangkau. Periksa alamat server dan koneksi.');
    throw new ApiError(0, 'NETWORK_ERROR', msg);
  }

  let data: any = null;
  const contentType = response.headers.get('content-type') || '';
  if (contentType.includes('json')) {
    try {
      data = await response.json();
    } catch {
      // If server returned text/html warning before JSON, extract JSON payload
      try {
        const text = await response.text();
        const jsonMatch = text.match(/\{[\s\S]*\}/);
        if (jsonMatch) {
          data = JSON.parse(jsonMatch[0]);
        }
      } catch {}
    }
  }
  if (!response.ok) {
    let msg = data?.message ?? 'Permintaan gagal.';
    if (data?.details && typeof data.details === 'object') {
      const detailsStr = [...new Set(Object.values(data.details).flat().filter(Boolean))].join(' ');
      if (detailsStr) msg = msg === 'Data belum valid.' ? detailsStr : `${msg} (${detailsStr})`;
    }
    if (response.status === 401 && path !== 'auth/login') {
      const sessionMsg = 'Sesi login Anda telah berakhir atau perangkat telah dikeluarkan. Silakan masuk kembali.';
      unauthorizedHandler?.(sessionMsg);
    }
    throw new ApiError(response.status, data?.code, msg);
  }
  return data as T;
}

const keys = { token: 'auth-token', url: 'api-url', device: 'device-uuid' };
export const authStore = {
  token: async (): Promise<string | null> => {
    try {
      return await SecureStore.getItemAsync(keys.token);
    } catch {
      return null;
    }
  },
  saveToken: async (value?: string | null): Promise<void> => {
    if (!value || typeof value !== 'string' || !value.trim()) {
      try {
        await SecureStore.deleteItemAsync(keys.token);
      } catch {}
      return;
    }
    await SecureStore.setItemAsync(keys.token, value.trim());
  },
  clearToken: async (): Promise<void> => {
    try {
      await SecureStore.deleteItemAsync(keys.token);
    } catch {}
  },
  url: async (): Promise<string | null> => {
    try {
      return await SecureStore.getItemAsync(keys.url);
    } catch {
      return null;
    }
  },
  saveUrl: async (value?: string | null): Promise<void> => {
    if (!value || typeof value !== 'string' || !value.trim()) {
      try {
        await SecureStore.deleteItemAsync(keys.url);
      } catch {}
      return;
    }
    await SecureStore.setItemAsync(keys.url, value.trim());
  },
  device: async (): Promise<string> => {
    try {
      let value = await SecureStore.getItemAsync(keys.device);
      if (!value || typeof value !== 'string' || !value.trim()) {
        value = uuid();
        await SecureStore.setItemAsync(keys.device, value);
      }
      return value;
    } catch {
      return uuid();
    }
  },
};

export function uuid(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
    const random = Math.floor(Math.random() * 16);
    return (char === 'x' ? random : (random & 3) | 8).toString(16);
  });
}

export type Colleague = {
  id: number;
  code: string;
  name: string;
  department?: string | { id?: number; name?: string } | null;
  position?: string | { id?: number; name?: string } | null;
  email?: string | null;
  phone?: string | null;
};

export type ShiftExchangeSchedule = {
  id: number;
  work_date: string;
  template_name: string;
  status: string;
  revision: number;
  scheduled_minutes: number;
  sessions: Array<{
    id: number;
    seq: number;
    local_start: string;
    local_end: string;
  }>;
};

export type ShiftExchangeItem = {
  id: number;
  status: 'PENDING' | 'APPROVED' | 'REJECTED' | 'CANCELLED';
  swap_mode: 'direct' | 'delegation';
  vacancy_action?: 'roster_off' | 'regular' | 'day_off' | null;
  reason: string;
  decision_reason?: string | null;
  decided_by_name?: string | null;
  decided_at?: string | null;
  created_at: string;
  is_requester: boolean;
  employee: Colleague | null;
  target_employee: Colleague | null;
  assignment: ShiftExchangeSchedule | null;
  target_assignment: ShiftExchangeSchedule | null;
};

export type SwapPreviewMove = {
  work_date: string;
  shift: string;
  from_employee: string;
  to_employee: string;
  cross_unit: boolean;
};

export type SwapPreviewVacancy = {
  employee_id: number;
  work_date: string;
  name: string;
  existing_leave?: string | null;
};

export type SwapPreviewBlocker = {
  code: string;
  message: string;
  assignment_id?: number;
};

export type SwapPreviewResult = {
  moves: SwapPreviewMove[];
  cross_unit_confirmation_required: boolean;
  vacancies: SwapPreviewVacancy[];
  blockers: SwapPreviewBlocker[];
  preview_token: string;
  can_commit: boolean;
};

export type OutboxStatus = 'pending' | 'uncertain' | 'synced' | 'rejected' | 'needs_correction' | 'correction_submitted';
export type OutboxRow = { event_uuid: string; batch_uuid: string; payload: string; created_at: string; first_sent_at: string | null; local_sequence: number; status: OutboxStatus; message: string | null };

export type OvertimeItem = {
  id: number;
  order_number: string;
  employee_id: number;
  shift_session_id?: number | null;
  work_date: string;
  title: string;
  start_at: string;
  end_at: string;
  local_start: string;
  local_end: string;
  planned_minutes: number;
  actual_minutes: number;
  verified_minutes: number;
  is_day_off: boolean;
  status: 'PENDING' | 'APPROVED' | 'REJECTED' | 'CANCELLED';
  consent_status: 'PENDING' | 'ACCEPTED' | 'DECLINED';
  consent_notes?: string | null;
  notes?: string | null;
  decision_reason?: string | null;
  decision_by_name?: string | null;
  created_at: string;
};

export type OvertimeRequestPayload = {
  work_date: string;
  start_time: string;
  end_time: string;
  title: string;
  notes?: string;
  is_cross_day?: boolean;
};

export async function fetchOvertimes(baseUrl: string, token: string): Promise<OvertimeItem[]> {
  const res = await request<{ data: OvertimeItem[] }>(baseUrl, 'overtime', token);
  return res.data ?? [];
}

export async function submitOvertimeRequest(baseUrl: string, token: string, payload: OvertimeRequestPayload): Promise<OvertimeItem> {
  const res = await request<{ message: string; data: OvertimeItem }>(baseUrl, 'overtime', token, {
    method: 'POST',
    body: payload,
  });
  return res.data;
}

export async function submitOvertimeConsent(baseUrl: string, token: string, orderId: number, accepted: boolean, notes?: string): Promise<OvertimeItem> {
  const res = await request<{ message: string; data: OvertimeItem }>(baseUrl, `overtime/${orderId}/consent`, token, {
    method: 'POST',
    body: { accepted, notes },
  });
  return res.data;
}

export async function cancelOvertime(baseUrl: string, token: string, orderId: number): Promise<void> {
  await request<{ message: string }>(baseUrl, `overtime/${orderId}/cancel`, token, {
    method: 'POST',
  });
}

let database: Promise<SQLite.SQLiteDatabase> | undefined;

async function db(): Promise<SQLite.SQLiteDatabase> {
  database ??= SQLite.openDatabaseAsync('deresort-attendance.db');
  const connection = await database;
  await connection.execAsync(`CREATE TABLE IF NOT EXISTS attendance_outbox (
    event_uuid TEXT PRIMARY KEY, batch_uuid TEXT NOT NULL, payload TEXT NOT NULL,
    created_at TEXT NOT NULL, first_sent_at TEXT, local_sequence INTEGER NOT NULL,
    status TEXT NOT NULL, message TEXT
  );
  CREATE TABLE IF NOT EXISTS app_cache (cache_key TEXT PRIMARY KEY, value TEXT NOT NULL, saved_at TEXT NOT NULL);`);
  return connection;
}

export async function saveCache(key: string, value: unknown): Promise<void> {
  await (await db()).runAsync('INSERT OR REPLACE INTO app_cache(cache_key,value,saved_at) VALUES(?,?,?)', key, JSON.stringify(value ?? null), new Date().toISOString());
}

export async function readCache<T>(key: string): Promise<T | null> {
  const row = await (await db()).getFirstAsync<{ value: string }>('SELECT value FROM app_cache WHERE cache_key = ?', key);
  return row ? JSON.parse(row.value) as T : null;
}

export async function clearCache(): Promise<void> {
  try {
    await (await db()).runAsync('DELETE FROM app_cache');
  } catch {}
}

export async function enqueueEvent(payload: Record<string, unknown>, status: 'pending' | 'uncertain'): Promise<OutboxRow> {
  const connection = await db();
  const latest = await connection.getFirstAsync<{ value: number }>('SELECT COALESCE(MAX(local_sequence),0) AS value FROM attendance_outbox');
  const local_sequence = (latest?.value ?? 0) + 1;
  const enriched: Record<string, unknown> = { ...payload, ...(status === 'pending' ? { captured_offline: true, device_at: new Date().toISOString() } : {}), local_sequence };
  const row: OutboxRow = {
    event_uuid: String(enriched.event_uuid), batch_uuid: uuid(), payload: JSON.stringify(enriched),
    created_at: new Date().toISOString(), first_sent_at: status === 'uncertain' ? new Date().toISOString() : null,
    local_sequence, status, message: null,
  };
  await connection.runAsync('INSERT OR IGNORE INTO attendance_outbox(event_uuid,batch_uuid,payload,created_at,first_sent_at,local_sequence,status,message) VALUES(?,?,?,?,?,?,?,?)',
    row.event_uuid, row.batch_uuid, row.payload, row.created_at, row.first_sent_at, row.local_sequence, row.status, row.message);
  return row;
}

export async function outboxRows(): Promise<OutboxRow[]> {
  return (await db()).getAllAsync<OutboxRow>('SELECT * FROM attendance_outbox ORDER BY local_sequence');
}

export async function updateOutbox(eventUuid: string, status: OutboxStatus, message: string | null = null): Promise<void> {
  await (await db()).runAsync('UPDATE attendance_outbox SET status = ?, message = ? WHERE event_uuid = ?', status, message, eventUuid);
}
