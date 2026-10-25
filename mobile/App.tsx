import { useEffect, useMemo, useState } from 'react';
import {
  Alert, Modal, Platform, Pressable, SafeAreaView, ScrollView, StatusBar, StyleSheet,
  Text as NativeText, TextInput, type TextProps, View,
} from 'react-native';
import * as DocumentPicker from 'expo-document-picker';
import * as Location from 'expo-location';
import * as Network from 'expo-network';
import { ApiError, authStore, enqueueEvent, outboxRows, readCache, request, saveCache, updateOutbox, uuid, type OutboxRow } from './src/api';

type Tab = 'Hari ini' | 'Jadwal' | 'Pengajuan' | 'Profil';
type Session = {
  id: number; seq: number; start_at: string; end_at: string; local_start: string; local_end: string; local_start_date?: string; local_end_date?: string;
  attendance_window?: { check_in_opens_at: string; check_in_closes_at: string; max_elapsed_minutes: number | null };
  attendance_policy?: { location_required: boolean; max_accuracy_meters: number | null; late_permission_proof_required: boolean; correction_proof_required: boolean };
  attendance?: { check_in_at?: string; check_out_at?: string; provisional_out_at?: string; verified_minutes: number; status: string; needs_review: boolean } | null;
};
type Assignment = { id: number; work_date: string; template_name: string; status: string; scheduled_minutes: number; sessions: Session[] };
type Profile = { id: number; name: string; email: string; role: string; employee: { id: number; code: string; name: string; department?: string; position?: string } | null };
type LeaveBalance = { year: number; opening_confirmed: boolean; regular: { entitled: number; used: number; reserved: number; available: number }; day_off: { entitled: number; used: number; reserved: number; available: number } };
type WorkRequest = { id: number; work_date: string; shift: string; type?: string; reason: string; status: string; decision_reason?: string; shift_session_id: number };
type Proof = { uri: string; name: string; mimeType?: string };
type ActionIntent = { assignment: Assignment; session: Session; eventType: 'CHECK_IN' | 'CHECK_OUT' };

const colors = { ink: '#142019', muted: '#657168', line: '#DDE4DE', canvas: '#F2F5F1', surface: '#FFFFFF', primary: '#173D2B', accent: '#D9F25F', successSoft: '#E6F1E9', warning: '#A65718', warningSoft: '#FFF1DC', blueSoft: '#E8F1FB' };
const Text = ({ style, ...props }: TextProps) => {
  const weight = String(StyleSheet.flatten(style)?.fontWeight ?? '400');
  const androidWeight = weight === 'bold' ? '700' : weight === '900' ? '800' : ['500', '600', '700', '800'].includes(weight) ? weight : '400';
  return <NativeText {...props} style={[{ fontFamily: Platform.OS === 'android' ? `plus-jakarta-${androidWeight}` : 'Plus Jakarta Sans' }, style]} />;
};
const localDate = (value: string | Date) => new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).format(typeof value === 'string' ? new Date(`${value.slice(0, 10)}T12:00:00+07:00`) : value);
const todayKey = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
const clock = (value?: string | null) => value ? new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '—';
const shortClock = (value: string) => value.replace('.', ':').replace(' (+1)', ' (+1)');
const money = (value: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value);
const dateRange = () => {
  const date = todayKey().split('-').map(Number);
  const year = date[0] ?? 2026; const month = date[1] ?? 1; const day = date[2] ?? 1;
  const from = new Date(Date.UTC(year, month - 1, day - 1, 5));
  const to = new Date(Date.UTC(year, month - 1, day + 30, 5));
  const key = (date: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(date);
  return { from: key(from), to: key(to) };
};
let syncingOutbox = false;

function Pill({ children, tone = 'success' }: { children: React.ReactNode; tone?: 'success' | 'warning' | 'neutral' | 'blue' }) {
  return <View style={[styles.pill, tone === 'warning' && styles.pillWarning, tone === 'neutral' && styles.pillNeutral, tone === 'blue' && styles.pillBlue]}><Text style={[styles.pillText, tone === 'warning' && styles.pillWarningText, tone === 'neutral' && styles.pillNeutralText, tone === 'blue' && styles.pillBlueText]}>{children}</Text></View>;
}

function TextField({ label, value, onChangeText, placeholder, multiline = false, keyboardType, secureTextEntry = false, autoCapitalize = 'sentences' }: {
  label: string; value: string; onChangeText: (value: string) => void; placeholder?: string; multiline?: boolean;
  keyboardType?: 'default' | 'email-address' | 'numeric' | 'numbers-and-punctuation'; secureTextEntry?: boolean; autoCapitalize?: 'none' | 'sentences' | 'words';
}) {
  return <View style={styles.field}><Text style={styles.fieldLabel}>{label}</Text><TextInput accessibilityLabel={label} value={value} onChangeText={onChangeText} placeholder={placeholder} placeholderTextColor="#87928A" multiline={multiline} keyboardType={keyboardType} secureTextEntry={secureTextEntry} autoCapitalize={autoCapitalize} style={[styles.input, multiline && styles.multiline]} /></View>;
}

export default function App() {
  const [baseUrl, setBaseUrl] = useState('http://10.0.2.2:8000');
  const [token, setToken] = useState<string | null>(null);
  const [deviceId, setDeviceId] = useState('');
  const [profile, setProfile] = useState<Profile | null>(null);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [balance, setBalance] = useState<LeaveBalance | null>(null);
  const [leaveRequests, setLeaveRequests] = useState<WorkRequest[]>([]);
  const [lateRequests, setLateRequests] = useState<WorkRequest[]>([]);
  const [corrections, setCorrections] = useState<WorkRequest[]>([]);
  const [outbox, setOutbox] = useState<OutboxRow[]>([]);
  const [online, setOnline] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [tab, setTab] = useState<Tab>('Hari ini');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loginBusy, setLoginBusy] = useState(false);
  const [intent, setIntent] = useState<ActionIntent | null>(null);
  const [busy, setBusy] = useState(false);
  const [requestKind, setRequestKind] = useState<'leave' | 'late' | 'correction'>('leave');
  const [workDate, setWorkDate] = useState('');
  const [leaveType, setLeaveType] = useState<'regular' | 'day_off'>('regular');
  const [reason, setReason] = useState('');
  const [minutes, setMinutes] = useState('');
  const [allowedUntil, setAllowedUntil] = useState('');
  const [sessionId, setSessionId] = useState<number | null>(null);
  const [requestedIn, setRequestedIn] = useState('');
  const [requestedOut, setRequestedOut] = useState('');
  const [proof, setProof] = useState<Proof | null>(null);

  const unfinishedSessions = useMemo(() => assignments.flatMap((assignment) => assignment.sessions
    .filter((session) => !session.attendance?.check_out_at && !session.attendance?.provisional_out_at)
    .map((session) => ({ assignment, session }))), [assignments]);
  const openSessions = useMemo(() => assignments.flatMap((assignment) => assignment.sessions.map((session) => ({ assignment, session }))), [assignments]);
  const pendingOutbox = outbox.filter((row) => ['pending', 'uncertain', 'needs_correction', 'rejected'].includes(row.status));

  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const [storedUrl, storedToken, device] = await Promise.all([authStore.url(), authStore.token(), authStore.device()]);
        if (!alive) return;
        if (storedUrl) setBaseUrl(storedUrl);
        setDeviceId(device);
        setToken(storedToken);
        setOutbox(await outboxRows());
      } catch (failure) {
        if (alive) setError(failure instanceof Error ? failure.message : 'Penyimpanan perangkat tidak tersedia.');
      }
    })();
    return () => { alive = false; };
  }, []);

  useEffect(() => {
    const subscription = Network.addNetworkStateListener((state) => {
      setOnline(state.isConnected !== false && state.isInternetReachable !== false);
    });
    Network.getNetworkStateAsync().then((state) => setOnline(state.isConnected !== false && state.isInternetReachable !== false)).catch(() => setOnline(false));
    return () => subscription.remove();
  }, []);

  useEffect(() => {
    if (!token) return;
    loadData(token, baseUrl);
  }, [token, baseUrl]);

  useEffect(() => {
    if (!token || !online) return;
    syncOutbox(token, baseUrl);
    const timer = setInterval(() => syncOutbox(token, baseUrl), 60_000);
    return () => clearInterval(timer);
  }, [token, baseUrl, online]);

  async function loadData(authToken = token, url = baseUrl) {
    if (!authToken || !url) return;
    setLoading(true);
    setError('');
    const range = dateRange();
    const tasks = [
      request<{ user: Profile }>(url, 'me', authToken),
      request<{ data: Assignment[] }>(url, `attendance/me?from=${range.from}&to=${range.to}`, authToken),
      request<{ data: LeaveBalance }>(url, 'me/leave-balance', authToken),
      request<{ data: WorkRequest[] }>(url, 'leaves', authToken),
      request<{ data: WorkRequest[] }>(url, 'late-permissions', authToken),
      request<{ data: WorkRequest[] }>(url, 'attendance-corrections', authToken),
    ];
    const keys = ['profile', 'assignments', 'balance', 'leaveRequests', 'lateRequests', 'corrections'];
    const results = await Promise.allSettled(tasks);
    for (let index = 0; index < results.length; index += 1) {
      const result = results[index];
      const key = keys[index];
      if (!result || !key) continue;
      try {
        if (result.status === 'fulfilled') {
          const value = result.value;
          const normalized = index === 0 ? (value as { user: Profile }).user : (value as { data: unknown }).data;
          await saveCache(key, normalized);
          applyCached(key, normalized);
        } else {
          const cached = await readCache<unknown>(key);
          if (cached) applyCached(key, cached);
          if (!error && result.reason instanceof Error) setError(result.reason.message);
        }
      } catch { /* Cache failure must not hide server data. */ }
    }
    setLoading(false);
    setOutbox(await outboxRows());
  }

  function applyCached(key: string, value: unknown) {
    if (key === 'profile') setProfile(value as Profile);
    if (key === 'assignments') setAssignments(value as Assignment[]);
    if (key === 'balance') setBalance(value as LeaveBalance);
    if (key === 'leaveRequests') setLeaveRequests(value as WorkRequest[]);
    if (key === 'lateRequests') setLateRequests(value as WorkRequest[]);
    if (key === 'corrections') setCorrections(value as WorkRequest[]);
  }

  async function login() {
    const url = baseUrl.trim().replace(/\/$/, '');
    if (!/^https?:\/\//i.test(url)) { setError('Alamat server harus dimulai dengan http:// atau https://.'); return; }
    setLoginBusy(true);
    setError('');
    try {
      const id = deviceId || await authStore.device();
      setDeviceId(id);
      const result = await request<{ token: string }>(url, 'auth/login', null, { method: 'POST', body: { email: email.trim(), password, device_uuid: id, device_name: Platform.OS === 'ios' ? 'iPhone' : Platform.OS === 'android' ? 'Android' : 'Perangkat mobile' } });
      await Promise.all([authStore.saveToken(result.token), authStore.saveUrl(url)]);
      setBaseUrl(url);
      setPassword('');
      setToken(result.token);
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Login gagal.');
    } finally { setLoginBusy(false); }
  }

  async function logout() {
    try { if (token && online) await request(baseUrl, 'auth/logout', token, { method: 'POST' }); } catch { /* Clear local access even without a connection. */ }
    await authStore.clearToken();
    setToken(null); setProfile(null); setAssignments([]); setBalance(null);
  }

  async function syncOutbox(authToken = token, url = baseUrl) {
    if (!authToken || !url || syncingOutbox) return;
    syncingOutbox = true;
    try {
      const rows = await outboxRows();
      for (const row of rows) {
        if (!['pending', 'uncertain'].includes(row.status)) continue;
        const payload = JSON.parse(row.payload) as Record<string, unknown>;
        if (row.status === 'uncertain') {
          try {
            const receipt = await request<{ received: boolean; data: { status: string; reason_code?: string } }>(url, `attendance/events/${row.event_uuid}`, authToken);
            await updateOutbox(row.event_uuid, receipt.data.status === 'REJECTED' ? 'rejected' : 'synced', receipt.data.reason_code ?? receipt.data.status);
            continue;
          } catch (failure) {
            if (!(failure instanceof ApiError) || failure.status !== 404) throw failure;
            const sent = row.first_sent_at ? new Date(row.first_sent_at).getTime() : Date.now();
            if (Date.now() - sent >= 120_000) {
              await updateOutbox(row.event_uuid, 'needs_correction', 'Server belum menerima event setelah 120 detik. Ajukan koreksi; event tidak dikirim ulang.');
            }
            break;
          }
        }
        try {
          const result = await request<{ results: Array<{ event_uuid: string; status: string; reason_code?: string; message?: string }> }>(url, 'attendance/sync', authToken, {
            method: 'POST', body: { batch_uuid: row.batch_uuid, events: [payload] },
          });
          const ack = result.results.find((item) => item.event_uuid === row.event_uuid);
          if (!ack) break;
          await updateOutbox(row.event_uuid, ack.status === 'REJECTED' ? 'rejected' : 'synced', ack.reason_code ?? ack.message ?? ack.status);
        } catch (failure) {
          if (failure instanceof ApiError && failure.status > 0) await updateOutbox(row.event_uuid, 'rejected', failure.message);
          break;
        }
      }
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 401) setError('Sesi login sudah habis. Masuk kembali untuk menyinkronkan data.');
    } finally {
      setOutbox(await outboxRows());
      syncingOutbox = false;
    }
  }

  async function captureLocation(session: Session) {
    if (!session.attendance_policy?.location_required) return undefined;
    const permission = await Location.requestForegroundPermissionsAsync();
    if (!permission.granted) throw new Error('Lokasi harus diizinkan untuk absensi pada sesi ini.');
    const position = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
    const maxAccuracy = session.attendance_policy.max_accuracy_meters;
    if (maxAccuracy && position.coords.accuracy !== null && position.coords.accuracy > maxAccuracy) throw new Error(`Akurasi lokasi ${Math.round(position.coords.accuracy)} m melewati batas ${maxAccuracy} m.`);
    return { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy_meters: position.coords.accuracy ?? undefined };
  }

  async function recordAttendance() {
    if (!intent || !token || !deviceId) return;
    setBusy(true); setError('');
    const eventUuid = uuid();
    try {
      const location = await captureLocation(intent.session);
      const payload = {
        event_uuid: eventUuid, shift_session_id: intent.session.id, event_type: intent.eventType, device_id: deviceId,
        device_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Jakarta', captured_offline: false, ...(location ? { location } : {}),
      };
      if (!online) {
        await enqueueEvent(payload, 'pending');
        Alert.alert('Disimpan di perangkat', 'Event akan dikirim dengan UUID yang sama saat koneksi kembali. Absensi offline dapat memerlukan verifikasi HR.');
      } else {
        try {
          const result = await request<{ data: { status: string; reason_code?: string } }>(baseUrl, 'attendance/events', token, { method: 'POST', body: payload });
          if (result.data.status === 'REJECTED') Alert.alert('Absensi ditolak server', result.data.reason_code ?? 'Periksa jadwal dan waktu absensi.');
        } catch (failure) {
          if (!(failure instanceof ApiError) || failure.status !== 0) throw failure;
          await enqueueEvent(payload, 'uncertain');
          Alert.alert('Status belum pasti', 'Kami mengecek tanda terima server. Event tidak akan dikirim ulang dengan waktu server baru.');
        }
      }
      setIntent(null);
      if (online) await loadData();
      setOutbox(await outboxRows());
    } catch (failure) {
      Alert.alert('Absensi belum dicatat', failure instanceof Error ? failure.message : 'Coba kembali.');
    } finally { setBusy(false); }
  }

  async function chooseProof() {
    const result = await DocumentPicker.getDocumentAsync({ type: ['image/jpeg', 'image/png', 'application/pdf'], copyToCacheDirectory: true, multiple: false });
    if (!result.canceled && result.assets[0]) setProof({ uri: result.assets[0].uri, name: result.assets[0].name, mimeType: result.assets[0].mimeType ?? undefined });
  }

  function formData(values: Record<string, string>, attachment: Proof | null): FormData {
    const form = new FormData();
    Object.entries(values).forEach(([key, value]) => { if (value !== '') form.append(key, value); });
    if (attachment) form.append('evidence', { uri: attachment.uri, name: attachment.name, type: attachment.mimeType ?? 'application/octet-stream' } as unknown as Blob);
    return form;
  }

  async function submitRequest() {
    if (!token) return;
    setBusy(true); setError('');
    try {
      if (requestKind === 'leave') {
        if (!workDate || reason.trim().length < 3) throw new Error('Pilih tanggal dan tulis alasan minimal 3 karakter.');
        await request(baseUrl, 'leaves', token, { method: 'POST', body: { work_date: workDate, type: leaveType, reason: reason.trim() } });
      } else {
        if (!sessionId || reason.trim().length < 3) throw new Error('Pilih sesi dan tulis alasan minimal 3 karakter.');
        const selected = openSessions.find((item) => item.session.id === sessionId)?.session;
        const proofRequired = requestKind === 'late' ? selected?.attendance_policy?.late_permission_proof_required : selected?.attendance_policy?.correction_proof_required;
        if (proofRequired && !proof) throw new Error('Kebijakan sesi ini mewajibkan bukti lampiran.');
        if (requestKind === 'late') {
          if (!allowedUntil && !minutes) throw new Error('Isi jam target hadir atau durasi izin.');
          await request(baseUrl, 'late-permissions', token, { method: 'POST', body: formData({ shift_session_id: String(sessionId), allowed_until: allowedUntil, allowed_minutes: minutes, reason: reason.trim() }, proof) });
        } else {
          if (!requestedIn && !requestedOut) throw new Error('Isi minimal waktu check-in atau check-out yang diminta.');
          await request(baseUrl, 'attendance-corrections', token, { method: 'POST', body: formData({ shift_session_id: String(sessionId), requested_check_in_at: requestedIn, requested_check_out_at: requestedOut, reason: reason.trim() }, proof) });
        }
      }
      setWorkDate(''); setReason(''); setMinutes(''); setAllowedUntil(''); setRequestedIn(''); setRequestedOut(''); setProof(null);
      Alert.alert('Pengajuan dikirim', 'Status dapat dilihat di daftar pengajuan.');
      await loadData();
    } catch (failure) {
      Alert.alert('Pengajuan belum terkirim', failure instanceof Error ? failure.message : 'Periksa data lalu coba lagi.');
    } finally { setBusy(false); }
  }

  async function cancelRequest(path: string, label: string) {
    if (!token) return;
    try { await request(baseUrl, path, token, { method: 'POST' }); await loadData(); }
    catch (failure) { Alert.alert(`Tidak dapat membatalkan ${label.toLowerCase()}`, failure instanceof Error ? failure.message : 'Coba kembali.'); }
  }

  async function submitOutboxCorrection(row: OutboxRow) {
    if (!token) return;
    const payload = JSON.parse(row.payload) as { shift_session_id: number; event_type: 'CHECK_IN' | 'CHECK_OUT'; device_at?: string };
    const eventTime = payload.device_at ?? row.created_at;
    try {
      await request(baseUrl, 'attendance-corrections', token, { method: 'POST', body: {
        shift_session_id: payload.shift_session_id,
        ...(payload.event_type === 'CHECK_IN' ? { requested_check_in_at: eventTime } : { requested_check_out_at: eventTime }),
        reason: 'Event absensi tersimpan lokal dan tidak ditemukan pada tanda terima server setelah 120 detik.',
      } });
      await updateOutbox(row.event_uuid, 'correction_submitted', 'Koreksi sudah diajukan ke HR.');
      setOutbox(await outboxRows());
      Alert.alert('Koreksi diajukan', 'HR akan memeriksa waktu dari perangkat sebagai permintaan, bukan sebagai absensi yang sudah disahkan.');
      await loadData();
    } catch (failure) { Alert.alert('Koreksi belum terkirim', failure instanceof Error ? failure.message : 'Coba lagi.'); }
  }

  const todayAssignments = assignments.filter((item) => item.work_date.slice(0, 10) === todayKey() || item.sessions.some((session) => session.local_start_date === todayKey() || session.local_end_date === todayKey()));
  const renderAttendance = (assignment: Assignment, session: Session) => {
    const record = session.attendance;
    const verifiedMinutes = record?.verified_minutes ?? 0;
    const eventType = record?.check_in_at ? (!record.check_out_at && !record.provisional_out_at ? 'CHECK_OUT' : null) : 'CHECK_IN';
    const label = record?.provisional_out_at ? `Checkout otomatis ${clock(record.provisional_out_at)} · menunggu tinjauan` : record?.check_out_at ? `Keluar ${clock(record.check_out_at)}` : record?.check_in_at ? `Masuk ${clock(record.check_in_at)}` : 'Belum absen';
    return <View key={session.id} style={styles.sessionCard}>
      <View style={styles.sessionTop}><View style={styles.flex}><Text style={styles.sessionTitle}>Sesi {session.seq} · {shortClock(session.local_start)}–{shortClock(session.local_end)}</Text><Text style={styles.mutedCopy}>{label}{record?.needs_review ? ' · perlu verifikasi' : ''}</Text></View><Pill tone={record?.needs_review ? 'warning' : record?.check_out_at ? 'success' : record?.check_in_at ? 'blue' : 'neutral'}>{record?.needs_review ? 'Tinjauan' : record?.check_out_at ? 'Selesai' : record?.check_in_at ? 'Berjalan' : 'Terjadwal'}</Pill></View>
      {eventType && <Pressable accessibilityRole="button" onPress={() => setIntent({ assignment, session, eventType })} style={({ pressed }) => [styles.actionButton, pressed && styles.pressed]}><Text style={styles.actionButtonText}>{eventType === 'CHECK_IN' ? 'Check-in' : 'Check-out'}</Text><Text style={styles.actionHint}>{session.attendance_policy?.location_required ? 'Lokasi diperlukan · waktu server saat online' : 'Waktu server saat online'}</Text></Pressable>}
      {verifiedMinutes > 0 && <Text style={styles.verified}>Terverifikasi {Math.floor(verifiedMinutes / 60)}j {verifiedMinutes % 60}m</Text>}
    </View>;
  };

  function outboxCard(row: OutboxRow) {
    const title = row.status === 'pending' ? 'Menunggu sinkronisasi' : row.status === 'uncertain' ? 'Memeriksa tanda terima' : row.status === 'needs_correction' ? 'Perlu koreksi HR' : row.status === 'rejected' ? 'Ditolak server' : 'Koreksi diajukan';
    return <View style={styles.listCard} key={row.event_uuid}><View style={styles.flex}><Text style={styles.cardTitle}>{title}</Text><Text style={styles.cardCopy}>{row.message ?? localDate(row.created_at)}</Text></View>{row.status === 'needs_correction' && <Pressable style={styles.smallButton} onPress={() => submitOutboxCorrection(row)}><Text style={styles.smallButtonText}>Ajukan koreksi</Text></Pressable>}</View>;
  }

  function renderToday() {
    const activeAssignment = todayAssignments[0];
    const sessions = todayAssignments.flatMap((assignment) => assignment.sessions.map((session) => ({ assignment, session })));
    const activeSession = sessions.find(({ session }) => session.attendance?.check_in_at && !session.attendance.check_out_at && !session.attendance.provisional_out_at)
      ?? sessions.find(({ session }) => !session.attendance?.check_in_at && new Date(session.attendance_window?.check_in_opens_at ?? session.start_at) <= new Date() && new Date(session.end_at) > new Date());
    const activeIntent = activeSession ? { assignment: activeSession.assignment, session: activeSession.session, eventType: activeSession.session.attendance?.check_in_at ? 'CHECK_OUT' as const : 'CHECK_IN' as const } : null;
    const credited = sessions.reduce((sum, item) => sum + (item.session.attendance?.verified_minutes ?? 0), 0);
    return <ScrollView contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
      <View style={styles.headingRow}><View><Text style={styles.overline}>{localDate(new Date()).toLocaleUpperCase('id-ID')}</Text><Text style={styles.heading}>Halo, {profile?.employee?.name?.split(' ')[0] ?? profile?.name?.split(' ')[0] ?? 'Karyawan'}</Text></View><View style={styles.avatar}><Text style={styles.avatarText}>{(profile?.employee?.name ?? profile?.name ?? 'K').split(' ').slice(0, 2).map((part) => part[0]).join('').toUpperCase()}</Text></View></View>
      <View style={[styles.networkBanner, !online && styles.networkBannerOffline]}><View style={[styles.dot, !online && styles.dotOffline]} /><View style={styles.flex}><Text style={styles.networkTitle}>{online ? 'Koneksi server tersedia' : 'Mode offline'}</Text><Text style={styles.networkCopy}>{loading ? 'Memperbarui data…' : online ? 'Jadwal dan pengajuan dimuat dari sistem' : 'Jadwal terakhir tersimpan di perangkat'}</Text></View>{pendingOutbox.length > 0 && <Text style={styles.queueCount}>{pendingOutbox.length} antrean</Text>}</View>
      <View style={styles.heroCard}><View style={styles.heroTop}><Text style={styles.heroLabel}>SIKLUS KERJA HARI INI</Text><Pill tone={activeAssignment ? 'success' : 'neutral'}>{activeAssignment?.status === 'IN_PROGRESS' ? 'Berjalan' : activeAssignment ? 'Terbit' : 'Tanpa jadwal'}</Pill></View><Text style={styles.heroTitle}>{activeAssignment?.template_name ?? 'Belum ada jadwal'}</Text><Text style={styles.heroTime}>{activeAssignment ? `${activeAssignment.sessions.length} sesi · ${Math.floor(activeAssignment.scheduled_minutes / 60)} jam` : 'Jadwal dari HR tampil di sini'}</Text><Text style={styles.heroMeta}>{profile?.employee?.department ?? 'Absensi'}{activeSession?.session.attendance_policy?.location_required ? ' · validasi lokasi aktif' : ''}</Text>{activeIntent && <Pressable accessibilityRole="button" onPress={() => setIntent(activeIntent)} style={({ pressed }) => [styles.attendanceButton, pressed && styles.pressed]}><Text style={styles.attendanceButtonText}>{activeIntent.eventType === 'CHECK_IN' ? 'Check-in sesi' : 'Check-out sesi'}</Text><Text style={styles.attendanceHint}>Sesi {activeIntent.session.seq} · {shortClock(activeIntent.session.local_start)}–{shortClock(activeIntent.session.local_end)}</Text></Pressable>}</View>
      {balance && <View style={styles.balanceCard}><View><Text style={styles.cardEyebrow}>SISA CUTI {balance.year}</Text><Text style={styles.balanceValue}>{balance.regular.available}<Text style={styles.balanceUnit}> hari</Text></Text><Text style={styles.cardCopy}>Reguler · {balance.regular.used} terpakai · {balance.regular.reserved} dipesan</Text></View><View style={styles.balanceDivider} /><View><Text style={styles.cardEyebrow}>DAY OFF</Text><Text style={styles.balanceValue}>{balance.day_off.available}<Text style={styles.balanceUnit}> hari</Text></Text><Text style={styles.cardCopy}>{balance.day_off.used} terpakai · {balance.day_off.reserved} dipesan</Text></View></View>}
      <View style={styles.sectionHeading}><Text style={styles.sectionTitle}>Rangkaian sesi</Text><Text style={styles.sectionMeta}>{sessions.length} sesi</Text></View>
      {sessions.length ? sessions.map(({ assignment, session }) => renderAttendance(assignment, session)) : <View style={styles.emptyCard}><Text style={styles.cardTitle}>Belum ada jadwal hari ini</Text><Text style={styles.cardCopy}>Jadwal yang diterbitkan HR akan muncul setelah disinkronkan.</Text></View>}
      {pendingOutbox.length > 0 && <><View style={styles.sectionHeading}><Text style={styles.sectionTitle}>Antrean absensi</Text><Pressable onPress={() => syncOutbox()}><Text style={styles.linkText}>Sinkronkan</Text></Pressable></View>{pendingOutbox.map(outboxCard)}</>}
      <View style={styles.notice}><Text style={styles.noticeTitle}>Menit kerja terverifikasi</Text><Text style={styles.noticeValue}>{Math.floor(credited / 60)}j {credited % 60}m</Text><Text style={styles.cardCopy}>Kehadiran provisional dan event offline belum masuk total ini.</Text></View>
      {!!error && <Text style={styles.errorText}>{error}</Text>}
    </ScrollView>;
  }

  function renderSchedule() {
    const upcoming = [...assignments].sort((a, b) => a.work_date.localeCompare(b.work_date));
    return <ScrollView contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}><Text style={styles.overline}>JADWAL DARI HR</Text><Text style={styles.heading}>Jadwal kerja</Text><Text style={styles.intro}>Satu siklus kerja mengikuti tanggal awal, termasuk sesi malam dan split shift.</Text>{upcoming.length ? upcoming.map((assignment) => <View style={styles.assignmentCard} key={assignment.id}><View style={styles.assignmentHeading}><View style={styles.flex}><Text style={styles.dateText}>{localDate(assignment.work_date)}</Text><Text style={styles.cardTitle}>{assignment.template_name}</Text></View><Pill tone={assignment.status === 'COMPLETED' ? 'neutral' : assignment.status === 'IN_PROGRESS' ? 'blue' : 'success'}>{assignment.status === 'IN_PROGRESS' ? 'Berjalan' : assignment.status === 'COMPLETED' ? 'Selesai' : 'Terbit'}</Pill></View>{assignment.sessions.map((session) => renderAttendance(assignment, session))}</View>) : <View style={styles.emptyCard}><Text style={styles.cardTitle}>Jadwal belum tersedia</Text><Text style={styles.cardCopy}>Jadwal akan tampil setelah HR menerbitkannya.</Text></View>}{!!error && <Text style={styles.errorText}>{error}</Text>}</ScrollView>;
  }

  function renderRequestForm() {
    const sessions = requestKind === 'late' ? unfinishedSessions : openSessions;
    const chosen = sessions.find((item) => item.session.id === sessionId)?.session;
    const evidenceRequired = requestKind === 'late' ? chosen?.attendance_policy?.late_permission_proof_required : chosen?.attendance_policy?.correction_proof_required;
    return <View style={styles.formCard}>
      {requestKind === 'leave' ? <>
        <Text style={styles.cardTitle}>Ajukan satu hari kerja</Text>
        <TextField label="Tanggal kerja" value={workDate} onChangeText={setWorkDate} placeholder="YYYY-MM-DD" />
        <View style={styles.segmentRow}><Pressable onPress={() => setLeaveType('regular')} style={[styles.segment, leaveType === 'regular' && styles.segmentActive]}><Text style={[styles.segmentText, leaveType === 'regular' && styles.segmentTextActive]}>Cuti reguler</Text></Pressable><Pressable onPress={() => setLeaveType('day_off')} style={[styles.segment, leaveType === 'day_off' && styles.segmentActive]}><Text style={[styles.segmentText, leaveType === 'day_off' && styles.segmentTextActive]}>Day off</Text></Pressable></View>
      </> : <>
        <Text style={styles.cardTitle}>{requestKind === 'late' ? 'Izin terlambat' : 'Koreksi absensi'}</Text>
        <Text style={styles.fieldLabel}>Pilih sesi</Text>
        {sessions.slice(0, 20).map(({ assignment, session }) => <Pressable key={session.id} onPress={() => setSessionId(session.id)} style={[styles.choiceCard, sessionId === session.id && styles.choiceCardActive]}><Text style={styles.cardTitle}>{localDate(assignment.work_date)} · {assignment.template_name} · sesi {session.seq}</Text><Text style={styles.cardCopy}>{shortClock(session.local_start)}–{shortClock(session.local_end)}{session.attendance_policy?.location_required ? ' · GPS diwajibkan' : ''}</Text></Pressable>)}
        {requestKind === 'late' ? <><TextField label="Target hadir (ISO, opsional)" value={allowedUntil} onChangeText={setAllowedUntil} placeholder="2026-10-25T08:30:00+07:00" autoCapitalize="none" /><TextField label="Atau durasi izin (menit)" value={minutes} onChangeText={setMinutes} placeholder="30" keyboardType="numeric" /></> : <><TextField label="Waktu check-in yang diminta (ISO)" value={requestedIn} onChangeText={setRequestedIn} placeholder="2026-10-25T08:05:00+07:00" autoCapitalize="none" /><TextField label="Waktu check-out yang diminta (ISO)" value={requestedOut} onChangeText={setRequestedOut} placeholder="2026-10-25T17:00:00+07:00" autoCapitalize="none" /></>}
        <Pressable onPress={chooseProof} style={styles.attachButton}><Text style={styles.attachText}>{proof ? `Bukti: ${proof.name}` : 'Lampirkan JPG, PNG, atau PDF'}</Text></Pressable>{evidenceRequired && <Text style={styles.requiredHint}>Bukti wajib sesuai aturan absensi sesi ini.</Text>}
      </>}
      <TextField label="Alasan" value={reason} onChangeText={setReason} placeholder="Jelaskan keperluan atau perubahan" multiline />
      <Pressable disabled={busy} onPress={submitRequest} style={({ pressed }) => [styles.actionButton, pressed && styles.pressed, busy && styles.disabled]}><Text style={styles.actionButtonText}>{busy ? 'Mengirim…' : 'Kirim pengajuan'}</Text></Pressable>
    </View>;
  }

  function renderRequests() {
    return <ScrollView contentContainerStyle={styles.scrollContent} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}><Text style={styles.overline}>LAYANAN KARYAWAN</Text><Text style={styles.heading}>Pengajuan</Text>{balance && <View style={styles.balanceStrip}><Text style={styles.cardTitle}>Tersedia tahun {balance.year}</Text><Text style={styles.cardCopy}>Cuti reguler {balance.regular.available} hari · Day off {balance.day_off.available} hari{balance.opening_confirmed ? '' : ' · saldo belum dibuka HR'}</Text></View>}<View style={styles.segmentRow}>{(['leave', 'late', 'correction'] as const).map((item) => <Pressable key={item} onPress={() => { setRequestKind(item); setSessionId(null); setProof(null); }} style={[styles.segment, requestKind === item && styles.segmentActive]}><Text style={[styles.segmentText, requestKind === item && styles.segmentTextActive]}>{item === 'leave' ? 'Cuti' : item === 'late' ? 'Izin terlambat' : 'Koreksi'}</Text></Pressable>)}</View>{renderRequestForm()}
      <Text style={styles.sectionTitle}>Riwayat pengajuan</Text>{[...leaveRequests.map((item) => ({ ...item, group: 'Cuti' })), ...lateRequests.map((item) => ({ ...item, group: 'Izin terlambat' })), ...corrections.map((item) => ({ ...item, group: 'Koreksi' }))].sort((a, b) => b.work_date.localeCompare(a.work_date)).map((item) => <View key={`${item.group}-${item.id}`} style={styles.listCard}><View style={styles.flex}><Text style={styles.cardTitle}>{item.group} · {localDate(item.work_date)}</Text><Text style={styles.cardCopy}>{item.reason}{item.decision_reason ? ` · ${item.decision_reason}` : ''}</Text></View><Pill tone={item.status === 'APPROVED' ? 'success' : item.status === 'PENDING' ? 'warning' : 'neutral'}>{item.status === 'PENDING' ? 'Menunggu' : item.status === 'APPROVED' ? 'Disetujui' : item.status === 'REJECTED' ? 'Ditolak' : 'Dibatalkan'}</Pill>{item.status === 'PENDING' && <Pressable onPress={() => cancelRequest(item.group === 'Cuti' ? `leaves/${item.id}/cancel` : item.group === 'Izin terlambat' ? `late-permissions/${item.id}/cancel` : `attendance-corrections/${item.id}/cancel`, item.group)} style={styles.smallButton}><Text style={styles.smallButtonText}>Batalkan</Text></Pressable>}</View>)}
      {!leaveRequests.length && !lateRequests.length && !corrections.length && <Text style={styles.cardCopy}>Belum ada pengajuan tersimpan.</Text>}{!!error && <Text style={styles.errorText}>{error}</Text>}</ScrollView>;
  }

  function renderProfile() {
    const employee = profile?.employee;
    return <ScrollView contentContainerStyle={styles.scrollContent}><Text style={styles.overline}>AKUN KARYAWAN</Text><Text style={styles.heading}>Profil</Text><View style={styles.profileCard}><View style={styles.avatarLarge}><Text style={styles.avatarLargeText}>{(employee?.name ?? profile?.name ?? 'K').split(' ').slice(0, 2).map((part) => part[0]).join('').toUpperCase()}</Text></View><Text style={styles.profileName}>{employee?.name ?? profile?.name ?? 'Karyawan'}</Text><Text style={styles.cardCopy}>{employee?.code ?? profile?.email}</Text><Text style={styles.cardCopy}>{employee?.department ?? 'Departemen belum diatur'}{employee?.position ? ` · ${employee.position}` : ''}</Text></View><View style={styles.settingsCard}><Text style={styles.cardEyebrow}>SERVER</Text><Text style={styles.cardTitle}>{baseUrl}</Text><Text style={styles.cardCopy}>Perangkat terdaftar dengan ID tersimpan aman di perangkat ini.</Text><Text style={styles.cardEyebrow}>SINKRONISASI</Text><Text style={styles.cardCopy}>{pendingOutbox.length} event belum selesai · {online ? 'online' : 'offline'}</Text><Pressable onPress={() => { if (token) { syncOutbox(); loadData(); } }} style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Perbarui data</Text></Pressable><Pressable onPress={logout} style={styles.logoutButton}><Text style={styles.logoutText}>Keluar dari akun</Text></Pressable></View></ScrollView>;
  }

  if (!token) return <SafeAreaView style={styles.safeArea}><StatusBar barStyle="dark-content" backgroundColor={colors.canvas} /><ScrollView contentContainerStyle={styles.loginContent} keyboardShouldPersistTaps="handled"><View style={styles.loginMark}><Text style={styles.loginMarkText}>D</Text></View><Text style={styles.overline}>DERESORT · ABSENSI</Text><Text style={styles.loginHeading}>Masuk ke akun</Text><Text style={styles.intro}>Gunakan akun karyawan dari HR untuk melihat jadwal dan mencatat kehadiran.</Text><View style={styles.formCard}><TextField label="Alamat server" value={baseUrl} onChangeText={setBaseUrl} placeholder="http://192.168.1.10:8000" autoCapitalize="none" /><TextField label="Email" value={email} onChangeText={setEmail} placeholder="nama@deresort.id" keyboardType="email-address" autoCapitalize="none" /><TextField label="Password" value={password} onChangeText={setPassword} placeholder="Password akun" secureTextEntry autoCapitalize="none" />{!!error && <Text style={styles.errorText}>{error}</Text>}<Pressable disabled={loginBusy} onPress={login} style={({ pressed }) => [styles.actionButton, pressed && styles.pressed, loginBusy && styles.disabled]}><Text style={styles.actionButtonText}>{loginBusy ? 'Menghubungkan…' : 'Masuk'}</Text></Pressable><Text style={styles.cardCopy}>Alamat server dapat diisi IP lokal komputer saat menguji di ponsel. Login membutuhkan koneksi.</Text></View></ScrollView></SafeAreaView>;

  return <SafeAreaView style={styles.safeArea}><StatusBar barStyle="dark-content" backgroundColor={colors.canvas} /><View style={styles.app}>{tab === 'Hari ini' ? renderToday() : tab === 'Jadwal' ? renderSchedule() : tab === 'Pengajuan' ? renderRequests() : renderProfile()}<View style={styles.tabBar}>{(['Hari ini', 'Jadwal', 'Pengajuan', 'Profil'] as Tab[]).map((item) => <Pressable key={item} accessibilityRole="tab" accessibilityState={{ selected: tab === item }} onPress={() => setTab(item)} style={({ pressed }) => [styles.tab, pressed && styles.pressed]}><View style={[styles.tabIndicator, tab === item && styles.tabIndicatorActive]} /><Text style={[styles.tabText, tab === item && styles.tabTextActive]}>{item}</Text></Pressable>)}</View></View>
    <Modal visible={Boolean(intent)} transparent animationType="fade" onRequestClose={() => setIntent(null)}><View style={styles.modalBackdrop}><View style={styles.modalCard}><Text style={styles.modalEyebrow}>{intent?.assignment.template_name.toUpperCase()} · SESI {intent?.session.seq}</Text><Text style={styles.modalTitle}>Konfirmasi {intent?.eventType === 'CHECK_IN' ? 'check-in' : 'check-out'}</Text><Text style={styles.modalCopy}>{intent ? `${localDate(intent.assignment.work_date)} · ${shortClock(intent.session.local_start)}–${shortClock(intent.session.local_end)}` : ''}. Server memeriksa jadwal, lokasi, dan waktu saat event diterima.</Text>{!online && <View style={styles.offlineWarning}><Text style={styles.offlineWarningText}>Event tersimpan dengan waktu perangkat dan dapat memerlukan verifikasi HR.</Text></View>}<View style={styles.modalActions}><Pressable onPress={() => setIntent(null)} style={styles.cancelButton}><Text style={styles.cancelButtonText}>Batal</Text></Pressable><Pressable disabled={busy} onPress={recordAttendance} style={[styles.confirmButton, busy && styles.disabled]}><Text style={styles.confirmButtonText}>{busy ? 'Mencatat…' : 'Ya, catat'}</Text></Pressable></View></View></View></Modal>
  </SafeAreaView>;
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: colors.canvas }, app: { flex: 1 }, flex: { flex: 1, minWidth: 0 }, scrollContent: { paddingHorizontal: 20, paddingTop: 22, paddingBottom: 116 }, loginContent: { flexGrow: 1, justifyContent: 'center', padding: 24 }, headingRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }, overline: { color: colors.muted, fontSize: 10, fontWeight: '800', letterSpacing: 1.1, marginBottom: 6 }, heading: { color: colors.ink, fontSize: 31, fontWeight: '700', letterSpacing: -0.8 }, loginHeading: { color: colors.ink, fontSize: 33, fontWeight: '700', letterSpacing: -1, marginBottom: 6 }, intro: { color: colors.muted, fontSize: 12, lineHeight: 19, marginBottom: 20 }, avatar: { width: 44, height: 44, borderRadius: 14, alignItems: 'center', justifyContent: 'center', backgroundColor: '#D2EEE8' }, avatarText: { color: '#175449', fontWeight: '800' },
  loginMark: { width: 48, height: 48, borderRadius: 16, alignItems: 'center', justifyContent: 'center', marginBottom: 22, backgroundColor: colors.primary }, loginMarkText: { color: '#fff', fontSize: 22, fontWeight: '800' }, networkBanner: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 13, marginBottom: 14, borderRadius: 12, backgroundColor: colors.successSoft }, networkBannerOffline: { backgroundColor: colors.warningSoft }, dot: { width: 9, height: 9, borderRadius: 5, backgroundColor: '#3D955D' }, dotOffline: { backgroundColor: '#B46931' }, networkTitle: { color: colors.ink, fontSize: 12, fontWeight: '700' }, networkCopy: { color: colors.muted, fontSize: 10, marginTop: 2 }, queueCount: { color: colors.warning, fontSize: 10, fontWeight: '800' },
  heroCard: { padding: 19, borderRadius: 18, backgroundColor: colors.primary }, heroTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }, heroLabel: { color: '#B7CBBE', fontSize: 9, fontWeight: '800', letterSpacing: 1 }, heroTitle: { color: '#FFFFFF', fontSize: 19, fontWeight: '700', marginTop: 20 }, heroTime: { color: colors.accent, fontSize: 23, fontWeight: '700', marginTop: 5 }, heroMeta: { color: '#B7CBBE', fontSize: 11, marginTop: 8 }, attendanceButton: { minHeight: 58, justifyContent: 'center', alignItems: 'center', marginTop: 20, borderRadius: 12, backgroundColor: colors.accent }, attendanceButtonText: { color: colors.primary, fontSize: 14, fontWeight: '800' }, attendanceHint: { color: '#40531C', fontSize: 10, marginTop: 3 }, pressed: { opacity: .76 }, disabled: { opacity: .55 }, sectionHeading: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 23, marginBottom: 10 }, sectionTitle: { color: colors.ink, fontSize: 16, fontWeight: '800', marginTop: 22, marginBottom: 10 }, sectionMeta: { color: colors.muted, fontSize: 10, fontWeight: '700' },
  sessionCard: { padding: 14, marginTop: 9, borderWidth: 1, borderColor: colors.line, borderRadius: 14, backgroundColor: colors.surface }, sessionTop: { flexDirection: 'row', alignItems: 'center', gap: 8 }, sessionTitle: { color: colors.ink, fontSize: 12, fontWeight: '800' }, mutedCopy: { color: colors.muted, fontSize: 10, lineHeight: 15, marginTop: 4 }, verified: { color: '#27643C', fontSize: 10, fontWeight: '700', marginTop: 10 }, pill: { paddingHorizontal: 8, paddingVertical: 5, borderRadius: 6, backgroundColor: colors.successSoft }, pillWarning: { backgroundColor: colors.warningSoft }, pillNeutral: { backgroundColor: '#EDF0ED' }, pillBlue: { backgroundColor: colors.blueSoft }, pillText: { color: '#22653C', fontSize: 9, fontWeight: '800' }, pillWarningText: { color: colors.warning }, pillNeutralText: { color: colors.muted }, pillBlueText: { color: '#2F5D8C' },
  actionButton: { minHeight: 46, justifyContent: 'center', alignItems: 'center', marginTop: 12, borderRadius: 10, backgroundColor: colors.accent }, actionButtonText: { color: colors.primary, fontSize: 12, fontWeight: '800' }, actionHint: { color: '#40531C', fontSize: 9, marginTop: 2 }, balanceCard: { flexDirection: 'row', alignItems: 'center', gap: 14, padding: 16, marginTop: 14, borderRadius: 14, backgroundColor: '#E9F0E8' }, balanceStrip: { padding: 14, marginTop: 14, borderRadius: 12, backgroundColor: colors.successSoft }, balanceDivider: { width: 1, alignSelf: 'stretch', backgroundColor: '#CBD8CC' }, cardEyebrow: { color: colors.muted, fontSize: 9, fontWeight: '800', letterSpacing: .8, marginTop: 8 }, balanceValue: { color: colors.primary, fontSize: 22, fontWeight: '800', marginTop: 4 }, balanceUnit: { fontSize: 11 }, cardTitle: { color: colors.ink, fontSize: 12, fontWeight: '800' }, cardCopy: { color: colors.muted, fontSize: 10, lineHeight: 15, marginTop: 4 }, notice: { padding: 16, marginTop: 14, borderRadius: 14, backgroundColor: colors.blueSoft }, noticeTitle: { color: '#2F5D8C', fontSize: 10, fontWeight: '800' }, noticeValue: { color: colors.ink, fontSize: 24, fontWeight: '800', marginTop: 4 }, emptyCard: { padding: 16, marginTop: 10, borderRadius: 14, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.surface },
  assignmentCard: { padding: 13, marginTop: 13, borderWidth: 1, borderColor: colors.line, borderRadius: 15, backgroundColor: colors.surface }, assignmentHeading: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 2, paddingBottom: 4 }, dateText: { color: colors.muted, fontSize: 10, fontWeight: '700', marginBottom: 4 }, listCard: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 13, marginTop: 9, borderWidth: 1, borderColor: colors.line, borderRadius: 12, backgroundColor: colors.surface },
  formCard: { padding: 16, marginTop: 14, borderWidth: 1, borderColor: colors.line, borderRadius: 15, backgroundColor: colors.surface }, field: { gap: 6, marginTop: 12 }, fieldLabel: { color: colors.muted, fontSize: 10, fontWeight: '800' }, input: { minHeight: 46, paddingHorizontal: 12, borderWidth: 1, borderColor: '#C8D1CA', borderRadius: 9, color: colors.ink, fontSize: 12, backgroundColor: '#fff' }, multiline: { minHeight: 84, paddingTop: 10, textAlignVertical: 'top' }, segmentRow: { flexDirection: 'row', gap: 7, marginTop: 12 }, segment: { minHeight: 42, flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 8, borderWidth: 1, borderColor: colors.line, borderRadius: 9, backgroundColor: '#fff' }, segmentActive: { borderColor: colors.primary, backgroundColor: colors.primary }, segmentText: { color: colors.muted, fontSize: 10, fontWeight: '700' }, segmentTextActive: { color: '#fff' }, choiceCard: { padding: 11, marginTop: 7, borderWidth: 1, borderColor: colors.line, borderRadius: 10, backgroundColor: '#fff' }, choiceCardActive: { borderColor: colors.primary, backgroundColor: colors.successSoft }, attachButton: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 12, marginTop: 12, borderWidth: 1, borderStyle: 'dashed', borderColor: '#AAB8AD', borderRadius: 9 }, attachText: { color: colors.primary, fontSize: 10, fontWeight: '700' }, requiredHint: { color: colors.warning, fontSize: 10, marginTop: 6 }, linkText: { color: colors.primary, fontSize: 10, fontWeight: '800' }, smallButton: { minHeight: 40, justifyContent: 'center', alignItems: 'center', paddingHorizontal: 10, borderRadius: 8, backgroundColor: colors.successSoft }, smallButtonText: { color: colors.primary, fontSize: 9, fontWeight: '800' },
  profileCard: { alignItems: 'center', padding: 22, marginTop: 16, borderWidth: 1, borderColor: colors.line, borderRadius: 16, backgroundColor: colors.surface }, avatarLarge: { width: 66, height: 66, borderRadius: 22, alignItems: 'center', justifyContent: 'center', backgroundColor: '#D2EEE8' }, avatarLargeText: { color: '#175449', fontSize: 20, fontWeight: '800' }, profileName: { color: colors.ink, fontSize: 19, fontWeight: '800', marginTop: 12 }, settingsCard: { padding: 16, marginTop: 13, borderWidth: 1, borderColor: colors.line, borderRadius: 15, backgroundColor: colors.surface }, secondaryButton: { minHeight: 44, justifyContent: 'center', alignItems: 'center', marginTop: 12, borderWidth: 1, borderColor: colors.line, borderRadius: 9 }, secondaryButtonText: { color: colors.ink, fontSize: 11, fontWeight: '800' }, logoutButton: { minHeight: 44, justifyContent: 'center', alignItems: 'center', marginTop: 8, borderRadius: 9, backgroundColor: colors.warningSoft }, logoutText: { color: colors.warning, fontSize: 11, fontWeight: '800' }, errorText: { color: '#A51F18', fontSize: 11, lineHeight: 16, marginTop: 10 },
  tabBar: { position: 'absolute', left: 12, right: 12, bottom: 6, minHeight: 64, flexDirection: 'row', padding: 5, borderWidth: 1, borderColor: colors.line, borderRadius: 16, backgroundColor: '#FFFFFF' }, tab: { flex: 1, minHeight: 52, alignItems: 'center', justifyContent: 'center', gap: 6, borderRadius: 12 }, tabIndicator: { width: 18, height: 3, borderRadius: 2, backgroundColor: 'transparent' }, tabIndicatorActive: { backgroundColor: colors.primary }, tabText: { color: colors.muted, fontSize: 9, fontWeight: '700' }, tabTextActive: { color: colors.primary }, modalBackdrop: { flex: 1, justifyContent: 'center', padding: 20, backgroundColor: 'rgba(10,25,17,.55)' }, modalCard: { padding: 21, borderRadius: 18, backgroundColor: '#fff' }, modalEyebrow: { color: colors.muted, fontSize: 9, fontWeight: '800', letterSpacing: .8 }, modalTitle: { color: colors.ink, fontSize: 21, fontWeight: '800', marginTop: 8 }, modalCopy: { color: colors.muted, fontSize: 12, lineHeight: 18, marginTop: 8 }, offlineWarning: { padding: 11, marginTop: 12, borderRadius: 9, backgroundColor: colors.warningSoft }, offlineWarningText: { color: '#70441F', fontSize: 10, lineHeight: 15 }, modalActions: { flexDirection: 'row', gap: 9, marginTop: 18 }, cancelButton: { minHeight: 48, flex: 1, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: colors.line, borderRadius: 10 }, cancelButtonText: { color: colors.ink, fontWeight: '800', fontSize: 11 }, confirmButton: { minHeight: 48, flex: 1.3, alignItems: 'center', justifyContent: 'center', borderRadius: 10, backgroundColor: colors.primary }, confirmButtonText: { color: '#fff', fontWeight: '800', fontSize: 11 },
});
