import * as SecureStore from 'expo-secure-store';
import * as SQLite from 'expo-sqlite';

export class ApiError extends Error {
  constructor(public status: number, public code: string | undefined, message: string) {
    super(message);
  }
}

export async function request<T>(baseUrl: string, path: string, token?: string | null, options: { method?: string; body?: unknown } = {}): Promise<T> {
  const form = typeof FormData !== 'undefined' && options.body instanceof FormData;
  let response: Response;
  try {
    response = await fetch(`${baseUrl.replace(/\/$/, '')}/api/v1/${path.replace(/^\//, '')}`, {
      method: options.method ?? 'GET',
      headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(options.body && !form ? { 'Content-Type': 'application/json' } : {}) },
      body: options.body ? (form ? options.body as FormData : JSON.stringify(options.body)) : undefined,
    });
  } catch {
    throw new ApiError(0, 'NETWORK_ERROR', 'Server tidak dapat dijangkau. Periksa alamat server dan koneksi.');
  }
  const data = response.headers.get('content-type')?.includes('json') ? await response.json() : null;
  if (!response.ok) throw new ApiError(response.status, data?.code, data?.message ?? 'Permintaan gagal.');
  return data as T;
}

const keys = { token: 'auth-token', url: 'api-url', device: 'device-uuid' };
export const authStore = {
  token: () => SecureStore.getItemAsync(keys.token),
  saveToken: (value: string) => SecureStore.setItemAsync(keys.token, value),
  clearToken: () => SecureStore.deleteItemAsync(keys.token),
  url: () => SecureStore.getItemAsync(keys.url),
  saveUrl: (value: string) => SecureStore.setItemAsync(keys.url, value),
  device: async () => {
    let value = await SecureStore.getItemAsync(keys.device);
    if (!value) {
      value = uuid();
      await SecureStore.setItemAsync(keys.device, value);
    }
    return value;
  },
};

export function uuid(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
    const random = Math.floor(Math.random() * 16);
    return (char === 'x' ? random : (random & 3) | 8).toString(16);
  });
}

export type OutboxStatus = 'pending' | 'uncertain' | 'synced' | 'rejected' | 'needs_correction' | 'correction_submitted';
export type OutboxRow = { event_uuid: string; batch_uuid: string; payload: string; created_at: string; first_sent_at: string | null; local_sequence: number; status: OutboxStatus; message: string | null };
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
  await (await db()).runAsync('INSERT OR REPLACE INTO app_cache(cache_key,value,saved_at) VALUES(?,?,?)', key, JSON.stringify(value), new Date().toISOString());
}

export async function readCache<T>(key: string): Promise<T | null> {
  const row = await (await db()).getFirstAsync<{ value: string }>('SELECT value FROM app_cache WHERE cache_key = ?', key);
  return row ? JSON.parse(row.value) as T : null;
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
