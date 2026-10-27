import { useEffect, useMemo, useRef, useState } from 'react';
import {
  Alert, Animated, Easing, Image, KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, StatusBar, StyleSheet,
  Switch, Text as NativeText, TextInput, type TextProps, View,
} from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import * as DocumentPicker from 'expo-document-picker';
import * as Location from 'expo-location';
import * as Network from 'expo-network';
import * as SplashScreen from 'expo-splash-screen';
import { Ionicons } from '@expo/vector-icons';

SplashScreen.preventAutoHideAsync().catch(() => {});
import {
  ApiError, authStore, enqueueEvent, normalizeBaseUrl, outboxRows, readCache, request, saveCache, updateOutbox, uuid,
  type OutboxRow, type Colleague, type ShiftExchangeItem, type ShiftExchangeSchedule, type SwapPreviewResult
} from './src/api';

type Tab = 'Hari ini' | 'Jadwal' | 'Pengajuan' | 'Profil';
const tabItems: Array<{
  key: Tab;
  label: string;
  activeIcon: keyof typeof Ionicons.glyphMap;
  inactiveIcon: keyof typeof Ionicons.glyphMap;
}> = [
  { key: 'Hari ini', label: 'Hari ini', activeIcon: 'time', inactiveIcon: 'time-outline' },
  { key: 'Jadwal', label: 'Jadwal', activeIcon: 'calendar', inactiveIcon: 'calendar-outline' },
  { key: 'Pengajuan', label: 'Pengajuan', activeIcon: 'document-text', inactiveIcon: 'document-text-outline' },
  { key: 'Profil', label: 'Profil', activeIcon: 'person', inactiveIcon: 'person-outline' },
];

type Session = {
  id: number; seq: number; start_at: string; end_at: string; local_start: string; local_end: string; local_start_date?: string; local_end_date?: string;
  attendance_window?: { check_in_opens_at: string; check_in_closes_at: string; max_elapsed_minutes: number | null };
  attendance_policy?: { location_required: boolean; max_accuracy_meters: number | null; late_permission_proof_required: boolean; correction_proof_required: boolean };
  attendance?: { check_in_at?: string; check_out_at?: string; provisional_out_at?: string; verified_minutes: number; status: string; needs_review: boolean } | null;
};
type Assignment = { id: number; employee_id?: number; employee_name?: string; work_date: string; template_name: string; status: string; revision?: number; scheduled_minutes: number; sessions: Session[] };
type Profile = {
  id: number;
  name: string;
  email: string;
  phone?: string | null;
  role: string;
  avatar_path?: string | null;
  avatar_url?: string | null;
  employee: {
    id: number;
    code: string;
    name: string;
    department?: string | { id?: number; name?: string } | null;
    position?: string | { id?: number; name?: string } | null;
    email?: string | null;
    phone?: string | null;
    avatar_path?: string | null;
    avatar_url?: string | null;
  } | null;
};
type LeaveBalance = { year: number; opening_confirmed: boolean; regular: { entitled: number; used: number; reserved: number; available: number }; day_off: { entitled: number; used: number; reserved: number; available: number } };
type WorkRequest = {
  id: number;
  work_date: string;
  end_date?: string | null;
  days_count?: number;
  shift: string;
  type?: string;
  reason: string;
  status: string;
  decision_reason?: string;
  shift_session_id: number;
};
type Proof = { uri: string; name: string; mimeType?: string };
type ActionIntent = { assignment: Assignment; session: Session; eventType: 'CHECK_IN' | 'CHECK_OUT' };
type RequestKind = 'leave' | 'late' | 'correction' | 'swap';

const colors = { ink: '#142019', muted: '#657168', line: '#DDE4DE', canvas: '#F2F5F1', surface: '#FFFFFF', primary: '#173D2B', accent: '#D9F25F', successSoft: '#E6F1E9', warning: '#A65718', warningSoft: '#FFF1DC', blueSoft: '#E8F1FB', dangerSoft: '#FEECEC', danger: '#A51F18' };
const Text = ({ style, ...props }: TextProps) => {
  const weight = String(StyleSheet.flatten(style)?.fontWeight ?? '400');
  const androidWeight = weight === 'bold' ? '700' : weight === '900' ? '800' : ['500', '600', '700', '800'].includes(weight) ? weight : '400';
  return <NativeText {...props} style={[{ fontFamily: Platform.OS === 'android' ? `plus-jakarta-${androidWeight}` : 'Plus Jakarta Sans' }, style]} />;
};
const localDate = (value?: string | Date | null) => {
  if (!value) return '—';
  return new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).format(typeof value === 'string' ? new Date(`${value.slice(0, 10)}T12:00:00+07:00`) : value);
};
const todayKey = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
const clock = (value?: string | null) => value ? new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '—';
const shortClock = (value: string) => value.replace('.', ':').replace(' (+1)', ' (+1)');
const nameOrString = (val: unknown, fallback = '—'): string => {
  if (!val) return fallback;
  if (typeof val === 'string') return val;
  if (typeof val === 'object' && val !== null && 'name' in val) {
    return String((val as { name: unknown }).name ?? fallback);
  }
  return fallback;
};
const dateRange = () => {
  const date = todayKey().split('-').map(Number);
  const year = date[0] ?? 2026; const month = date[1] ?? 1; const day = date[2] ?? 1;
  const from = new Date(Date.UTC(year, month - 1, day - 1, 5));
  const to = new Date(Date.UTC(year, month - 1, day + 30, 5));
  const key = (d: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(d);
  return { from: key(from), to: key(to) };
};

function humanizeReason(code?: string | null): string {
  switch (code) {
    case 'OUTSIDE_WINDOW':
      return 'Waktu absensi di luar jendela sesi (belum dibuka atau sesi telah berakhir). Periksa jadwal shift Anda atau ajukan koreksi ke HR.';
    case 'ALREADY_CHECKED_IN':
      return 'Anda sudah melakukan check-in pada sesi ini.';
    case 'ALREADY_CHECKED_OUT':
      return 'Anda sudah melakukan check-out pada sesi ini.';
    case 'NOT_CHECKED_IN':
      return 'Belum dapat check-out karena Anda belum check-in pada sesi ini.';
    case 'SCHEDULE_CHANGED':
      return 'Jadwal sesi telah diubah atau diperbarui oleh HR.';
    case 'SCHEDULE_CANCELLED':
      return 'Jadwal shift ini telah dibatalkan.';
    case 'SCHEDULE_NOT_PUBLISHED':
      return 'Jadwal shift masih berstatus draf dan belum diterbitkan HR.';
    case 'PERIOD_LOCKED':
      return 'Periode absensi telah dikunci oleh HR.';
    case 'AUTO_CHECKOUT_DUE':
      return 'Batas waktu kerja sesi telah terlampaui (auto-checkout).';
    default:
      return code ? `Penolakan server (${code}). Periksa jadwal dan waktu absensi Anda.` : 'Periksa jadwal dan waktu absensi Anda.';
  }
}
let syncingOutbox = false;

function Pill({ children, tone = 'success' }: { children: React.ReactNode; tone?: 'success' | 'warning' | 'neutral' | 'blue' | 'danger' }) {
  return (
    <View style={[
      styles.pill,
      tone === 'warning' && styles.pillWarning,
      tone === 'neutral' && styles.pillNeutral,
      tone === 'blue' && styles.pillBlue,
      tone === 'danger' && styles.pillDanger,
    ]}>
      <Text style={[
        styles.pillText,
        tone === 'warning' && styles.pillWarningText,
        tone === 'neutral' && styles.pillNeutralText,
        tone === 'blue' && styles.pillBlueText,
        tone === 'danger' && styles.pillDangerText,
      ]}>{children}</Text>
    </View>
  );
}

function TextField({ label, value, onChangeText, placeholder, multiline = false, keyboardType, secureTextEntry = false, autoCapitalize = 'sentences' }: {
  label: string; value: string; onChangeText: (value: string) => void; placeholder?: string; multiline?: boolean;
  keyboardType?: 'default' | 'email-address' | 'numeric' | 'numbers-and-punctuation' | 'phone-pad'; secureTextEntry?: boolean; autoCapitalize?: 'none' | 'sentences' | 'words' | 'characters';
}) {
  const [hidePassword, setHidePassword] = useState(secureTextEntry);

  return (
    <View style={styles.field}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <View style={styles.inputWrapper}>
        <TextInput
          accessibilityLabel={label}
          value={value}
          onChangeText={onChangeText}
          placeholder={placeholder}
          placeholderTextColor="#87928A"
          multiline={multiline}
          keyboardType={keyboardType}
          secureTextEntry={secureTextEntry ? hidePassword : false}
          autoCapitalize={autoCapitalize}
          style={[styles.input, secureTextEntry && styles.inputWithIcon, multiline && styles.multiline]}
        />
        {secureTextEntry && (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={hidePassword ? 'Tampilkan password' : 'Sembunyikan password'}
            onPress={() => setHidePassword(!hidePassword)}
            style={({ pressed }) => [styles.inputEyeIcon, pressed && { opacity: 0.7 }]}
            hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}
          >
            <Ionicons
              name={hidePassword ? 'eye-outline' : 'eye-off-outline'}
              size={22}
              color={colors.ink}
            />
          </Pressable>
        )}
      </View>
    </View>
  );
}

function Main() {
  const [baseUrl, setBaseUrl] = useState(process.env.EXPO_PUBLIC_API_URL || 'http://192.168.106.188:8000');
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
  const tabFade = useRef(new Animated.Value(1)).current;
  const tabSlide = useRef(new Animated.Value(0)).current;

  useEffect(() => {
    tabFade.setValue(0);
    tabSlide.setValue(12);
    Animated.parallel([
      Animated.timing(tabFade, {
        toValue: 1,
        duration: 220,
        easing: Easing.out(Easing.cubic),
        useNativeDriver: true,
      }),
      Animated.timing(tabSlide, {
        toValue: 0,
        duration: 220,
        easing: Easing.out(Easing.cubic),
        useNativeDriver: true,
      }),
    ]).start();
  }, [tab]);
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [loginBusy, setLoginBusy] = useState(false);
  const [intent, setIntent] = useState<ActionIntent | null>(null);
  const [busy, setBusy] = useState(false);

  // Leave / Late / Correction Form state
  const [requestKind, setRequestKind] = useState<RequestKind>('leave');
  const [workDate, setWorkDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [leaveType, setLeaveType] = useState<'regular' | 'day_off'>('regular');
  const [reason, setReason] = useState('');
  const [minutes, setMinutes] = useState('');
  const [allowedUntil, setAllowedUntil] = useState('');
  const [sessionId, setSessionId] = useState<number | null>(null);
  const [requestedIn, setRequestedIn] = useState('');
  const [requestedOut, setRequestedOut] = useState('');
  const [proof, setProof] = useState<Proof | null>(null);

  // Shift Exchange & Admin states
  const [shiftExchanges, setShiftExchanges] = useState<ShiftExchangeItem[]>([]);
  const [adminShiftExchanges, setAdminShiftExchanges] = useState<ShiftExchangeItem[]>([]);
  const [colleagues, setColleagues] = useState<Colleague[]>([]);
  const [allEmployees, setAllEmployees] = useState<Colleague[]>([]);
  const [adminSchedules, setAdminSchedules] = useState<Assignment[]>([]);
  const [adminScheduleEmpFilter, setAdminScheduleEmpFilter] = useState<number | null>(null);
  const [pengajuanView, setPengajuanView] = useState<'mine' | 'admin'>('mine');
  const [approvalFilter, setApprovalFilter] = useState<'ALL' | 'PENDING' | 'APPROVED' | 'REJECTED'>('PENDING');

  // Employee shift exchange form
  const [swapSourceAssId, setSwapSourceAssId] = useState<number | null>(null);
  const [swapTargetEmpId, setSwapTargetEmpId] = useState<number | null>(null);
  const [swapMode, setSwapMode] = useState<'direct' | 'delegation'>('direct');
  const [swapTargetAssId, setSwapTargetAssId] = useState<number | null>(null);
  const [swapVacancyAction, setSwapVacancyAction] = useState<'roster_off' | 'regular' | 'day_off'>('roster_off');
  const [targetEmpSchedules, setTargetEmpSchedules] = useState<ShiftExchangeSchedule[]>([]);
  const [loadingTargetSchedules, setLoadingTargetSchedules] = useState(false);

  // Admin Direct Swap Modal state
  const [directSwapOpen, setDirectSwapOpen] = useState(false);
  const [directSourceEmpId, setDirectSourceEmpId] = useState<number | null>(null);
  const [directSourceSchedules, setDirectSourceSchedules] = useState<ShiftExchangeSchedule[]>([]);
  const [directSourceAssId, setDirectSourceAssId] = useState<number | null>(null);
  const [directTargetEmpId, setDirectTargetEmpId] = useState<number | null>(null);
  const [directTargetSchedules, setDirectTargetSchedules] = useState<ShiftExchangeSchedule[]>([]);
  const [directTargetAssId, setDirectTargetAssId] = useState<number | null>(null);
  const [directSwapMode, setDirectSwapMode] = useState<'direct' | 'delegation'>('direct');
  const [directVacancyAction, setDirectVacancyAction] = useState<'roster_off' | 'regular' | 'day_off'>('roster_off');
  const [directCrossUnitConfirm, setDirectCrossUnitConfirm] = useState(false);
  const [directReason, setDirectReason] = useState('');
  const [directPreview, setDirectPreview] = useState<SwapPreviewResult | null>(null);
  const [directPreviewBusy, setDirectPreviewBusy] = useState(false);
  const [directCommitBusy, setDirectCommitBusy] = useState(false);
  const [directError, setDirectError] = useState('');

  // Decision Modal state (for HRD to reject or review approval)
  const [decisionModalOpen, setDecisionModalOpen] = useState(false);
  const [decisionItem, setDecisionItem] = useState<ShiftExchangeItem | null>(null);
  const [decisionType, setDecisionType] = useState<'APPROVED' | 'REJECTED'>('APPROVED');
  const [decisionReason, setDecisionReason] = useState('');
  const [decisionVacancyAction, setDecisionVacancyAction] = useState<'roster_off' | 'regular' | 'day_off'>('roster_off');
  const [decisionCrossUnitConfirm, setDecisionCrossUnitConfirm] = useState(false);
  const [decisionBusy, setDecisionBusy] = useState(false);

  // Profile edit & avatar states
  const [editProfileOpen, setEditProfileOpen] = useState(false);
  const [editName, setEditName] = useState('');
  const [editPhone, setEditPhone] = useState('');
  const [editEmail, setEditEmail] = useState('');
  const [editCurrentPassword, setEditCurrentPassword] = useState('');
  const [editNewPassword, setEditNewPassword] = useState('');
  const [editConfirmPassword, setEditConfirmPassword] = useState('');
  const [editProfileBusy, setEditProfileBusy] = useState(false);
  const [editProfileError, setEditProfileError] = useState('');
  const [avatarUploading, setAvatarUploading] = useState(false);

  const isAdminOrSupervisor = ['super_admin', 'admin', 'supervisor'].includes(profile?.role ?? '');
  const hasEmployee = Boolean(profile?.employee);

  const unfinishedSessions = useMemo(() => assignments.flatMap((assignment) => assignment.sessions
    .filter((session) => !session.attendance?.check_out_at && !session.attendance?.provisional_out_at)
    .map((session) => ({ assignment, session }))), [assignments]);
  const openSessions = useMemo(() => assignments.flatMap((assignment) => assignment.sessions.map((session) => ({ assignment, session }))), [assignments]);
  const pendingOutbox = outbox.filter((row) => ['pending', 'uncertain', 'needs_correction', 'rejected'].includes(row.status));

  const myUpcomingAssignments = useMemo(() => {
    return assignments.filter((item) => {
      if (['CANCELLED'].includes(item.status)) return false;
      const first = item.sessions[0];
      return first && new Date(first.start_at) > new Date();
    }).sort((a, b) => a.work_date.localeCompare(b.work_date));
  }, [assignments]);

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
      } finally {
        await SplashScreen.hideAsync().catch(() => {});
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

    const tasks: Array<{ key: string; promise: Promise<any> }> = [
      { key: 'profile', promise: request<{ user: Profile }>(url, 'me', authToken) },
      { key: 'assignments', promise: request<{ data: Assignment[] }>(url, `attendance/me?from=${range.from}&to=${range.to}`, authToken) },
      { key: 'balance', promise: request<{ data: LeaveBalance | null }>(url, 'me/leave-balance', authToken) },
      { key: 'leaveRequests', promise: request<{ data: WorkRequest[] }>(url, 'leaves', authToken) },
      { key: 'lateRequests', promise: request<{ data: WorkRequest[] }>(url, 'late-permissions', authToken) },
      { key: 'corrections', promise: request<{ data: WorkRequest[] }>(url, 'attendance-corrections', authToken) },
      { key: 'shiftExchanges', promise: request<{ data: ShiftExchangeItem[] }>(url, 'shift-exchanges', authToken) },
      { key: 'colleagues', promise: request<{ data: Colleague[] }>(url, 'shift-exchanges/colleagues', authToken) },
      { key: 'adminShiftExchanges', promise: request<{ data: ShiftExchangeItem[] }>(url, 'shift-exchanges?scope=admin', authToken) },
      { key: 'adminSchedules', promise: request<{ data: Assignment[] }>(url, `admin/schedule?from=${range.from}&to=${range.to}`, authToken) },
      { key: 'allEmployees', promise: request<{ data: Colleague[] }>(url, 'admin/employees', authToken) },
    ];

    const results = await Promise.allSettled(tasks.map((t) => t.promise));
    for (let index = 0; index < results.length; index += 1) {
      const result = results[index];
      const task = tasks[index];
      if (!result || !task) continue;
      try {
        if (result.status === 'fulfilled') {
          const value = result.value;
          const normalized = task.key === 'profile' ? (value as { user: Profile }).user : (value as { data: unknown }).data;
          await saveCache(task.key, normalized);
          applyCached(task.key, normalized);
        } else {
          const cached = await readCache<unknown>(task.key);
          if (cached) applyCached(task.key, cached);
          if (!error && result.reason instanceof Error && !(result.reason instanceof ApiError && result.reason.status === 403)) {
            setError(result.reason.message);
          }
        }
      } catch { /* Cache failure must not hide server data. */ }
    }
    setLoading(false);
    setOutbox(await outboxRows());
  }

  function applyCached(key: string, value: unknown) {
    if (key === 'profile') {
      const p = value as any;
      if (p?.employee) {
        if (typeof p.employee.department === 'object' && p.employee.department !== null) {
          p.employee.department = p.employee.department.name;
        }
        if (typeof p.employee.position === 'object' && p.employee.position !== null) {
          p.employee.position = p.employee.position.name;
        }
      }
      setProfile(p as Profile);
      if (!p?.employee && ['super_admin', 'admin', 'supervisor'].includes(p?.role)) {
        setPengajuanView('admin');
      }
    }
    if (key === 'assignments') setAssignments(value as Assignment[]);
    if (key === 'balance') setBalance(value as LeaveBalance);
    if (key === 'leaveRequests') setLeaveRequests(value as WorkRequest[]);
    if (key === 'lateRequests') setLateRequests(value as WorkRequest[]);
    if (key === 'corrections') setCorrections(value as WorkRequest[]);
    if (key === 'shiftExchanges') setShiftExchanges(value as ShiftExchangeItem[]);
    if (key === 'colleagues') {
      const list = Array.isArray(value) ? value : [];
      setColleagues(list.map((e: any) => ({
        ...e,
        department: typeof e.department === 'object' && e.department !== null ? e.department.name : (e.department || ''),
        position: typeof e.position === 'object' && e.position !== null ? e.position.name : (e.position || ''),
      })));
    }
    if (key === 'adminShiftExchanges') setAdminShiftExchanges(value as ShiftExchangeItem[]);
    if (key === 'adminSchedules') setAdminSchedules(value as Assignment[]);
    if (key === 'allEmployees') {
      const list = Array.isArray(value) ? value : [];
      setAllEmployees(list.map((e: any) => ({
        ...e,
        department: typeof e.department === 'object' && e.department !== null ? e.department.name : (e.department || ''),
        position: typeof e.position === 'object' && e.position !== null ? e.position.name : (e.position || ''),
      })));
    }
  }

  async function login() {
    let url = normalizeBaseUrl(baseUrl);
    if (!/^https?:\/\//i.test(url)) { setError('Alamat server harus dimulai dengan http:// atau https://.'); return; }
    if (url.includes(':8081')) {
      setError('Port 8081 adalah server Expo (Metro), bukan backend Laravel. Gunakan port 8000.');
      return;
    }
    if (url.includes(':5173')) {
      setError('Port 5173 adalah server frontend Vite, bukan backend Laravel. Gunakan port 8000.');
      return;
    }
    const cleanUsername = username.trim();
    if (!cleanUsername) {
      setError('Masukkan username atau kode karyawan.');
      return;
    }
    if (!password) {
      setError('Masukkan password akun.');
      return;
    }
    setLoginBusy(true);
    setError('');
    try {
      const id = deviceId || await authStore.device();
      setDeviceId(id);
      const result = await request<{ token?: string; user?: Profile }>(url, 'auth/login', null, {
        method: 'POST',
        body: {
          username: cleanUsername,
          password,
          device_uuid: id,
          device_name: Platform.OS === 'ios' ? 'iPhone' : Platform.OS === 'android' ? 'Android' : 'Perangkat mobile',
        },
      });
      const receivedToken = typeof result?.token === 'string' && result.token.trim().length > 0
        ? result.token.trim()
        : (typeof (result as any)?.data?.token === 'string' ? (result as any).data.token.trim() : null);

      if (!receivedToken) {
        throw new ApiError(0, 'INVALID_RESPONSE', 'Respon server tidak valid atau bukan backend Laravel. Pastikan alamat server mengarah ke port 8000.');
      }
      await Promise.all([authStore.saveToken(receivedToken), authStore.saveUrl(url)]);
      setBaseUrl(url);
      setPassword('');
      setToken(receivedToken);
      if (result.user) setProfile(result.user);
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : 'Login gagal.');
    } finally { setLoginBusy(false); }
  }

  async function logout() {
    try { if (token && online) await request(baseUrl, 'auth/logout', token, { method: 'POST' }); } catch {}
    await authStore.clearToken();
    setToken(null); setProfile(null); setAssignments([]); setBalance(null);
    setShiftExchanges([]); setAdminShiftExchanges([]); setAdminSchedules([]);
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
          if (result.data.status === 'REJECTED') Alert.alert('Absensi ditolak server', humanizeReason(result.data.reason_code));
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

  async function selectColleague(empId: number) {
    setSwapTargetEmpId(empId);
    setSwapTargetAssId(null);
    setLoadingTargetSchedules(true);
    try {
      if (token) {
        const res = await request<{ data: ShiftExchangeSchedule[] }>(baseUrl, `shift-exchanges/employee-schedules?employee_id=${empId}`, token);
        setTargetEmpSchedules(res.data ?? []);
      }
    } catch {
      setTargetEmpSchedules([]);
    } finally {
      setLoadingTargetSchedules(false);
    }
  }

  async function submitShiftExchange() {
    if (!token) return;
    setBusy(true); setError('');
    try {
      if (!swapSourceAssId) throw new Error('Pilih jadwal shift yang ingin Anda tukar.');
      if (!swapTargetEmpId) throw new Error('Pilih rekan pengganti.');
      if (swapMode === 'direct' && !swapTargetAssId) throw new Error('Pilih jadwal rekan pengganti untuk ditukar balik.');
      if (reason.trim().length < 3) throw new Error('Tulis alasan pengajuan tukar shift minimal 3 karakter.');

      await request(baseUrl, 'shift-exchanges', token, {
        method: 'POST',
        body: {
          shift_assignment_id: swapSourceAssId,
          target_employee_id: swapTargetEmpId,
          swap_mode: swapMode,
          target_assignment_id: swapMode === 'direct' ? swapTargetAssId : undefined,
          vacancy_action: swapMode === 'delegation' ? swapVacancyAction : undefined,
          reason: reason.trim(),
        },
      });

      setSwapSourceAssId(null);
      setSwapTargetEmpId(null);
      setSwapTargetAssId(null);
      setTargetEmpSchedules([]);
      setReason('');
      Alert.alert('Pengajuan terkirim', 'Pengajuan tukar shift berhasil dikirim dan menunggu persetujuan HRD.');
      await loadData();
    } catch (failure) {
      Alert.alert('Pengajuan belum terkirim', failure instanceof Error ? failure.message : 'Periksa data lalu coba lagi.');
    } finally { setBusy(false); }
  }

  async function submitRequest() {
    if (!token) return;
    if (requestKind === 'swap') {
      await submitShiftExchange();
      return;
    }

    setBusy(true); setError('');
    try {
      if (requestKind === 'leave') {
        const cleanStart = workDate.trim();
        const cleanEnd = endDate.trim();
        if (!cleanStart || reason.trim().length < 3) throw new Error('Pilih tanggal mulai dan tulis alasan minimal 3 karakter.');
        if (cleanEnd && cleanEnd < cleanStart) throw new Error('Tanggal akhir cuti tidak boleh mendahului tanggal mulai.');
        await request(baseUrl, 'leaves', token, {
          method: 'POST',
          body: {
            work_date: cleanStart,
            end_date: cleanEnd || undefined,
            type: leaveType,
            reason: reason.trim(),
          },
        });
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
      setWorkDate(''); setEndDate(''); setReason(''); setMinutes(''); setAllowedUntil(''); setRequestedIn(''); setRequestedOut(''); setProof(null);
      Alert.alert('Pengajuan dikirim', 'Status dapat dilihat di daftar pengajuan.');
      await loadData();
    } catch (failure) {
      Alert.alert('Pengajuan belum terkirim', failure instanceof Error ? failure.message : 'Periksa data lalu coba lagi.');
    } finally { setBusy(false); }
  }

  async function cancelRequest(path: string, label: string) {
    if (!token) return;
    try {
      await request(baseUrl, path, token, { method: 'POST' });
      await loadData();
      Alert.alert('Pengajuan dibatalkan', `${label} berhasil dibatalkan.`);
    } catch (failure) {
      Alert.alert(`Tidak dapat membatalkan ${label.toLowerCase()}`, failure instanceof Error ? failure.message : 'Coba kembali.');
    }
  }

  async function handleDecideShiftExchange(item: ShiftExchangeItem, decision: 'APPROVED' | 'REJECTED', note?: string, vacancyAction?: string, crossUnit = false) {
    if (!token) return;
    setDecisionBusy(true);
    try {
      await request(baseUrl, `shift-exchanges/${item.id}/decide`, token, {
        method: 'POST',
        body: {
          decision,
          reason: note?.trim() || (decision === 'APPROVED' ? 'Disetujui oleh HRD' : 'Ditolak oleh HRD'),
          vacancy_action: vacancyAction || item.vacancy_action || 'roster_off',
          confirm_cross_unit: crossUnit,
        },
      });
      setDecisionModalOpen(false);
      setDecisionItem(null);
      Alert.alert(
        decision === 'APPROVED' ? 'Tukar shift disetujui' : 'Pengajuan ditolak',
        decision === 'APPROVED' ? 'Jadwal shift karyawan telah berhasil ditukar dan dicatat di sistem.' : 'Pengajuan telah ditandai ditolak.'
      );
      await loadData();
    } catch (failure) {
      Alert.alert('Tidak dapat memproses pengajuan', failure instanceof Error ? failure.message : 'Terjadi kendala.');
    } finally {
      setDecisionBusy(false);
    }
  }

  function promptExchangeDecision(item: ShiftExchangeItem, decision: 'APPROVED' | 'REJECTED') {
    setDecisionItem(item);
    setDecisionType(decision);
    setDecisionReason(decision === 'APPROVED' ? 'Disetujui atas persetujuan bersama' : 'Operasional shift membutuhkan staf di pos ini.');
    setDecisionVacancyAction(item.vacancy_action || 'roster_off');
    setDecisionCrossUnitConfirm(false);
    setDecisionModalOpen(true);
  }

  // Direct Swap Functions for Admin / HRD
  async function openDirectSwap(preselectedAssignment?: Assignment, preselectedEmpId?: number) {
    setDirectPreview(null);
    setDirectError('');
    setDirectReason('');
    setDirectCrossUnitConfirm(false);
    setDirectSwapMode('direct');
    setDirectVacancyAction('roster_off');

    const sourceEmpId = preselectedEmpId ?? preselectedAssignment?.employee_id ?? profile?.employee?.id ?? null;
    setDirectSourceEmpId(sourceEmpId);

    if (preselectedAssignment) {
      setDirectSourceAssId(preselectedAssignment.id);
      setDirectSourceSchedules([{
        id: preselectedAssignment.id,
        work_date: preselectedAssignment.work_date,
        template_name: preselectedAssignment.template_name,
        status: preselectedAssignment.status,
        revision: preselectedAssignment.revision ?? 1,
        scheduled_minutes: preselectedAssignment.scheduled_minutes,
        sessions: preselectedAssignment.sessions.map((s) => ({ id: s.id, seq: s.seq, local_start: s.local_start, local_end: s.local_end })),
      }]);
    } else if (sourceEmpId) {
      setDirectSourceAssId(null);
      await selectDirectSourceEmp(sourceEmpId);
    } else {
      setDirectSourceAssId(null);
      setDirectSourceSchedules([]);
    }

    setDirectTargetEmpId(null);
    setDirectTargetAssId(null);
    setDirectTargetSchedules([]);
    setDirectSwapOpen(true);
  }

  async function selectDirectSourceEmp(empId: number) {
    setDirectSourceEmpId(empId);
    setDirectSourceAssId(null);
    setDirectPreview(null);
    try {
      if (token) {
        const res = await request<{ data: ShiftExchangeSchedule[] }>(baseUrl, `shift-exchanges/employee-schedules?employee_id=${empId}`, token);
        setDirectSourceSchedules(res.data ?? []);
      }
    } catch {
      setDirectSourceSchedules([]);
    }
  }

  async function selectDirectTargetEmp(empId: number) {
    setDirectTargetEmpId(empId);
    setDirectTargetAssId(null);
    setDirectPreview(null);
    try {
      if (token) {
        const res = await request<{ data: ShiftExchangeSchedule[] }>(baseUrl, `shift-exchanges/employee-schedules?employee_id=${empId}`, token);
        setDirectTargetSchedules(res.data ?? []);
      }
    } catch {
      setDirectTargetSchedules([]);
    }
  }

  async function previewDirectSwap() {
    if (!token || !directSourceAssId || !directTargetEmpId) {
      setDirectError('Pilih jadwal asal dan karyawan pengganti.');
      return;
    }
    const sourceAss = directSourceSchedules.find((s) => s.id === directSourceAssId);
    if (!sourceAss) {
      setDirectError('Jadwal asal tidak ditemukan.');
      return;
    }
    if (directSwapMode === 'direct' && !directTargetAssId) {
      setDirectError('Pilih jadwal rekan pengganti untuk ditukar balik.');
      return;
    }

    const moves: Array<{ assignment_id: number; expected_revision: number; to_employee_id: number }> = [
      { assignment_id: sourceAss.id, expected_revision: sourceAss.revision, to_employee_id: directTargetEmpId }
    ];

    if (directSwapMode === 'direct' && directTargetAssId && directSourceEmpId) {
      const targetAss = directTargetSchedules.find((s) => s.id === directTargetAssId);
      if (!targetAss) {
        setDirectError('Pilih jadwal rekan pengganti untuk ditukar balik.');
        return;
      }
      moves.push({ assignment_id: targetAss.id, expected_revision: targetAss.revision, to_employee_id: directSourceEmpId });
    }

    const vacancy_decisions = directSwapMode === 'delegation' && directSourceEmpId ? [
      { employee_id: directSourceEmpId, work_date: sourceAss.work_date, action: directVacancyAction, reason: directReason.trim() || 'Pelimpahan shift' }
    ] : [];

    setDirectPreviewBusy(true);
    setDirectError('');
    try {
      const res = await request<SwapPreviewResult>(baseUrl, 'admin/schedule/swap/preview', token, {
        method: 'POST',
        body: {
          moves,
          vacancy_decisions,
          confirm_cross_unit: directCrossUnitConfirm,
        },
      });
      setDirectPreview(res);
    } catch (failure) {
      setDirectError(failure instanceof Error ? failure.message : 'Pratinjau pertukaran gagal.');
    } finally {
      setDirectPreviewBusy(false);
    }
  }

  async function commitDirectSwap() {
    if (!token || !directPreview || !directPreview.can_commit || !directPreview.preview_token) return;
    if (directReason.trim().length < 3) {
      setDirectError('Tuliskan alasan pertukaran shift minimal 3 karakter.');
      return;
    }

    const sourceAss = directSourceSchedules.find((s) => s.id === directSourceAssId);
    if (!sourceAss || !directTargetEmpId) return;

    const moves: Array<{ assignment_id: number; expected_revision: number; to_employee_id: number }> = [
      { assignment_id: sourceAss.id, expected_revision: sourceAss.revision, to_employee_id: directTargetEmpId }
    ];

    if (directSwapMode === 'direct' && directTargetAssId && directSourceEmpId) {
      const targetAss = directTargetSchedules.find((s) => s.id === directTargetAssId);
      if (targetAss) {
        moves.push({ assignment_id: targetAss.id, expected_revision: targetAss.revision, to_employee_id: directSourceEmpId });
      }
    }

    const vacancy_decisions = directSwapMode === 'delegation' && directSourceEmpId ? [
      { employee_id: directSourceEmpId, work_date: sourceAss.work_date, action: directVacancyAction, reason: directReason.trim() }
    ] : [];

    setDirectCommitBusy(true);
    setDirectError('');
    try {
      await request(baseUrl, 'admin/schedule/swap', token, {
        method: 'POST',
        body: {
          moves,
          vacancy_decisions,
          confirm_cross_unit: directCrossUnitConfirm,
          preview_token: directPreview.preview_token,
          reason: directReason.trim(),
        },
      });
      setDirectSwapOpen(false);
      Alert.alert('Tukar shift berhasil', 'Pertukaran jadwal shift telah berhasil diterapkan ke sistem.');
      await loadData();
    } catch (failure) {
      setDirectError(failure instanceof Error ? failure.message : 'Gagal menerapkan pertukaran shift.');
    } finally {
      setDirectCommitBusy(false);
    }
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
      Alert.alert('Koreksi diajukan', 'HR akan memeriksa waktu dari perangkat sebagai permintaan.');
      await loadData();
    } catch (failure) { Alert.alert('Koreksi belum terkirim', failure instanceof Error ? failure.message : 'Coba lagi.'); }
  }

  function openEditProfile() {
    setEditName(profile?.employee?.name ?? profile?.name ?? '');
    setEditPhone(profile?.employee?.phone ?? profile?.phone ?? '');
    setEditEmail(profile?.employee?.email ?? profile?.email ?? '');
    setEditCurrentPassword('');
    setEditNewPassword('');
    setEditConfirmPassword('');
    setEditProfileError('');
    setEditProfileOpen(true);
  }

  async function handleSaveProfile() {
    if (!editName.trim()) {
      setEditProfileError('Nama lengkap tidak boleh kosong.');
      return;
    }
    if (!editEmail.trim() || !editEmail.includes('@')) {
      setEditProfileError('Email pribadi tidak valid.');
      return;
    }
    if (editNewPassword) {
      if (!editCurrentPassword) {
        setEditProfileError('Masukkan kata sandi saat ini untuk mengubah kata sandi.');
        return;
      }
      if (editNewPassword.length < 8) {
        setEditProfileError('Kata sandi baru minimal 8 karakter.');
        return;
      }
      if (editNewPassword !== editConfirmPassword) {
        setEditProfileError('Konfirmasi kata sandi baru tidak cocok.');
        return;
      }
    }

    try {
      setEditProfileBusy(true);
      setEditProfileError('');
      const body: Record<string, string> = {
        name: editName.trim(),
        email: editEmail.trim(),
        phone: editPhone.trim(),
      };
      if (editNewPassword) {
        body.current_password = editCurrentPassword;
        body.password = editNewPassword;
        body.password_confirmation = editConfirmPassword;
      }
      const res = await request<{ message: string; user: Profile }>(baseUrl, 'me', token, {
        method: 'PATCH',
        body,
      });
      setProfile(res.user);
      await saveCache('profile', res.user);
      setEditProfileOpen(false);
      Alert.alert('Sukses', res.message || 'Profil berhasil diperbarui.');
    } catch (err) {
      setEditProfileError(err instanceof Error ? err.message : 'Gagal memperbarui profil.');
    } finally {
      setEditProfileBusy(false);
    }
  }

  async function handleChooseAvatar() {
    try {
      const result = await DocumentPicker.getDocumentAsync({
        type: ['image/*'],
        copyToCacheDirectory: true,
        multiple: false,
      });
      if (result.canceled || !result.assets || !result.assets[0]) return;
      const asset = result.assets[0];

      setAvatarUploading(true);

      let fileName = asset.name || 'avatar.jpg';
      let mimeType = asset.mimeType || 'image/jpeg';

      if (!mimeType || mimeType === 'application/octet-stream') {
        const lower = fileName.toLowerCase();
        if (lower.endsWith('.png')) mimeType = 'image/png';
        else if (lower.endsWith('.webp')) mimeType = 'image/webp';
        else mimeType = 'image/jpeg';
      }

      if (!/\.(jpe?g|png|webp)$/i.test(fileName)) {
        const ext = mimeType === 'image/png' ? '.png' : (mimeType === 'image/webp' ? '.webp' : '.jpg');
        fileName = `${fileName.replace(/\.[^/.]+$/, '')}${ext}`;
      }

      let fileUri = asset.uri;
      if (Platform.OS === 'ios' && fileUri.startsWith('file://')) {
        fileUri = fileUri.replace('file://', '');
      }

      const form = new FormData();
      form.append('avatar', {
        uri: fileUri,
        name: fileName,
        type: mimeType,
      } as any);

      const res = await request<{ message: string; user: Profile }>(baseUrl, 'me/avatar', token, {
        method: 'POST',
        body: form,
      });

      if (res?.user) {
        setProfile(res.user);
        await saveCache('profile', res.user);
      }
      Alert.alert('Sukses', res.message || 'Foto profil berhasil diperbarui.');
    } catch (err) {
      Alert.alert('Gagal Mengunggah Foto', err instanceof Error ? err.message : 'Terjadi kesalahan.');
    } finally {
      setAvatarUploading(false);
    }
  }

  async function handleDeleteAvatar() {
    Alert.alert('Hapus Foto Profil?', 'Foto profil akan dikembalikan ke inisial nama.', [
      { text: 'Batal', style: 'cancel' },
      {
        text: 'Hapus',
        style: 'destructive',
        onPress: async () => {
          try {
            setAvatarUploading(true);
            const res = await request<{ message: string; user: Profile }>(baseUrl, 'me/avatar', token, {
              method: 'DELETE',
            });
            setProfile(res.user);
            await saveCache('profile', res.user);
            Alert.alert('Sukses', res.message || 'Foto profil berhasil dihapus.');
          } catch (err) {
            Alert.alert('Gagal', err instanceof Error ? err.message : 'Terjadi kesalahan.');
          } finally {
            setAvatarUploading(false);
          }
        },
      },
    ]);
  }

  const todayAssignments = assignments.filter((item) => item.work_date.slice(0, 10) === todayKey() || item.sessions.some((session) => session.local_start_date === todayKey() || session.local_end_date === todayKey()));

  const renderAttendance = (assignment: Assignment, session: Session) => {
    const record = session.attendance;
    const verifiedMinutes = record?.verified_minutes ?? 0;
    const now = new Date();
    const opensAt = session.attendance_window?.check_in_opens_at ? new Date(session.attendance_window.check_in_opens_at) : new Date(session.start_at);
    const closesAt = new Date(session.end_at);

    const hasCheckedIn = Boolean(record?.check_in_at);
    const hasCheckedOut = Boolean(record?.check_out_at || record?.provisional_out_at);

    let eventType: 'CHECK_IN' | 'CHECK_OUT' | null = null;
    let timingNotice: string | null = null;
    let allowAction = true;

    if (!hasCheckedIn) {
      eventType = 'CHECK_IN';
      if (now < opensAt) {
        allowAction = false;
        timingNotice = `Check-in dibuka pk. ${clock(opensAt.toISOString())}`;
      } else if (now >= closesAt) {
        allowAction = false;
        timingNotice = `Sesi berakhir pk. ${shortClock(session.local_end)}`;
      }
    } else if (!hasCheckedOut) {
      eventType = 'CHECK_OUT';
    }

    const label = record?.provisional_out_at ? `Checkout otomatis ${clock(record.provisional_out_at)} · menunggu tinjauan` : record?.check_out_at ? `Keluar ${clock(record.check_out_at)}` : record?.check_in_at ? `Masuk ${clock(record.check_in_at)}` : 'Belum absen';
    const pillTone = record?.needs_review ? 'warning' : record?.check_out_at ? 'success' : record?.check_in_at ? 'blue' : (now >= closesAt && !hasCheckedIn ? 'warning' : 'neutral');
    const pillLabel = record?.needs_review ? 'Tinjauan' : record?.check_out_at ? 'Selesai' : record?.check_in_at ? 'Berjalan' : (now >= closesAt && !hasCheckedIn ? 'Terlewat' : 'Terjadwal');

    return (
      <View key={session.id} style={styles.sessionCard}>
        <View style={styles.sessionTop}>
          <View style={styles.flex}>
            <Text style={styles.sessionTitle}>Sesi {session.seq} · {shortClock(session.local_start)}–{shortClock(session.local_end)}</Text>
            <Text style={styles.mutedCopy}>{label}{record?.needs_review ? ' · perlu verifikasi' : ''}</Text>
          </View>
          <Pill tone={pillTone}>{pillLabel}</Pill>
        </View>
        {eventType && (
          allowAction ? (
            <Pressable accessibilityRole="button" onPress={() => setIntent({ assignment, session, eventType })} style={({ pressed }) => [styles.actionButton, pressed && styles.pressed]}>
              <Text style={styles.actionButtonText}>{eventType === 'CHECK_IN' ? 'Check-in' : 'Check-out'}</Text>
              <Text style={styles.actionHint}>{session.attendance_policy?.location_required ? 'Lokasi diperlukan · waktu server' : 'Waktu server saat online'}</Text>
            </Pressable>
          ) : (
            <View style={styles.sessionDisabledWrap}>
              <Text style={styles.sessionDisabledText}>{timingNotice}</Text>
              {now >= closesAt && !hasCheckedIn && (
                <Pressable onPress={() => { setRequestKind('correction'); setSessionId(session.id); setTab('Pengajuan'); }} style={styles.smallButton}>
                  <Text style={styles.smallButtonText}>Ajukan koreksi</Text>
                </Pressable>
              )}
            </View>
          )
        )}
        {verifiedMinutes > 0 && <Text style={styles.verified}>Terverifikasi {Math.floor(verifiedMinutes / 60)}j {verifiedMinutes % 60}m</Text>}
      </View>
    );
  };

  function outboxCard(row: OutboxRow) {
    const title = row.status === 'pending' ? 'Menunggu sinkronisasi' : row.status === 'uncertain' ? 'Memeriksa tanda terima' : row.status === 'needs_correction' ? 'Perlu koreksi HR' : row.status === 'rejected' ? 'Ditolak server' : 'Koreksi diajukan';
    return (
      <View style={styles.listCard} key={row.event_uuid}>
        <View style={styles.flex}>
          <Text style={styles.cardTitle}>{title}</Text>
          <Text style={styles.cardCopy}>{row.message ?? localDate(row.created_at)}</Text>
        </View>
        {row.status === 'needs_correction' && (
          <Pressable style={styles.smallButton} onPress={() => submitOutboxCorrection(row)}>
            <Text style={styles.smallButtonText}>Ajukan koreksi</Text>
          </Pressable>
        )}
      </View>
    );
  }

  function renderToday() {
    const pendingHrdCount = adminShiftExchanges.filter((item) => item.status === 'PENDING').length;

    // Pure Admin / HRD view (account without dedicated employee schedule)
    if (!hasEmployee && isAdminOrSupervisor) {
      return (
        <ScrollView contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
          <View style={styles.headingRow}>
            <View>
              <Text style={styles.overline}>{localDate(new Date()).toLocaleUpperCase('id-ID')}</Text>
              <Text style={styles.heading}>Halo, {profile?.name ?? 'Admin / HRD'}</Text>
            </View>
            <View style={styles.avatar}><Text style={styles.avatarText}>HR</Text></View>
          </View>

          <View style={[styles.networkBanner, !online && styles.networkBannerOffline]}>
            <View style={[styles.dot, !online && styles.dotOffline]} />
            <View style={styles.flex}>
              <Text style={styles.networkTitle}>{online ? 'Sistem Aktif & Terhubung' : 'Mode Offline'}</Text>
              <Text style={styles.networkCopy}>Panel Pengaturan & Persetujuan Admin / HRD</Text>
            </View>
          </View>

          <View style={styles.heroCard}>
            <View style={styles.heroTop}>
              <Text style={styles.heroLabel}>PANEL ADMIN / HRD</Text>
              <Pill tone="blue">{profile?.role.replace('_', ' ').toUpperCase()}</Pill>
            </View>
            <Text style={styles.heroTitle}>Manajemen Absensi & Tukar Shift</Text>
            <Text style={styles.heroMeta}>Kelola roster, pengajuan tukar jadwal, dan persetujuan shift seluruh staf.</Text>

            <Pressable accessibilityRole="button" onPress={() => openDirectSwap()} style={({ pressed }) => [styles.attendanceButton, pressed && styles.pressed]}>
              <Text style={styles.attendanceButtonText}>Atur Tukar Shift Langsung</Text>
              <Text style={styles.attendanceHint}>Tukar jadwal atau limpahkan shift karyawan</Text>
            </Pressable>
          </View>

          <View style={styles.statRow}>
            <Pressable onPress={() => { setTab('Pengajuan'); setPengajuanView('admin'); }} style={styles.statCard}>
              <Text style={styles.statLabel}>Pengajuan Tukar Shift</Text>
              <Text style={[styles.statValue, pendingHrdCount > 0 && { color: colors.warning }]}>{pendingHrdCount}</Text>
              <Text style={styles.statSub}>Menunggu persetujuan</Text>
            </Pressable>
            <Pressable onPress={() => { setTab('Jadwal'); }} style={styles.statCard}>
              <Text style={styles.statLabel}>Karyawan Terdaftar</Text>
              <Text style={styles.statValue}>{allEmployees.length}</Text>
              <Text style={styles.statSub}>Lihat seluruh roster</Text>
            </Pressable>
          </View>

          <View style={styles.sectionHeading}>
            <Text style={styles.sectionTitle}>Aksi Cepat Admin</Text>
          </View>
          <Pressable style={styles.menuRow} onPress={() => { setTab('Pengajuan'); setPengajuanView('admin'); }}>
            <Ionicons name="swap-horizontal" size={20} color={colors.primary} />
            <View style={styles.flex}>
              <Text style={styles.cardTitle}>Persetujuan Tukar Shift ({pendingHrdCount})</Text>
              <Text style={styles.cardCopy}>Tinjau, setujui atau tolak pengajuan tukar shift dari staf</Text>
            </View>
            <Ionicons name="chevron-forward" size={18} color={colors.muted} />
          </Pressable>

          <Pressable style={styles.menuRow} onPress={() => openDirectSwap()}>
            <Ionicons name="options-outline" size={20} color={colors.primary} />
            <View style={styles.flex}>
              <Text style={styles.cardTitle}>Pengaturan Tukar Shift Langsung</Text>
              <Text style={styles.cardCopy}>Eksekusi pertukaran shift antar karyawan secara manual</Text>
            </View>
            <Ionicons name="chevron-forward" size={18} color={colors.muted} />
          </Pressable>

          <Pressable style={styles.menuRow} onPress={() => setTab('Jadwal')}>
            <Ionicons name="calendar-outline" size={20} color={colors.primary} />
            <View style={styles.flex}>
              <Text style={styles.cardTitle}>Roster & Jadwal Seluruh Karyawan</Text>
              <Text style={styles.cardCopy}>Pantau jadwal per departemen dan eksekusi tukar shift</Text>
            </View>
            <Ionicons name="chevron-forward" size={18} color={colors.muted} />
          </Pressable>
        </ScrollView>
      );
    }

    const activeAssignment = todayAssignments[0];
    const sessions = todayAssignments.flatMap((assignment) => assignment.sessions.map((session) => ({ assignment, session })));
    const activeSession = sessions.find(({ session }) => session.attendance?.check_in_at && !session.attendance.check_out_at && !session.attendance.provisional_out_at)
      ?? sessions.find(({ session }) => !session.attendance?.check_in_at && new Date(session.attendance_window?.check_in_opens_at ?? session.start_at) <= new Date() && new Date(session.end_at) > new Date());
    const activeIntent = activeSession ? { assignment: activeSession.assignment, session: activeSession.session, eventType: activeSession.session.attendance?.check_in_at ? 'CHECK_OUT' as const : 'CHECK_IN' as const } : null;
    const credited = sessions.reduce((sum, item) => sum + (item.session.attendance?.verified_minutes ?? 0), 0);

    return (
      <ScrollView contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
        <View style={styles.headingRow}>
          <View>
            <Text style={styles.overline}>{localDate(new Date()).toLocaleUpperCase('id-ID')}</Text>
            <Text style={styles.heading}>Halo, {profile?.employee?.name?.split(' ')[0] ?? profile?.name?.split(' ')[0] ?? 'Karyawan'}</Text>
          </View>
          <View style={styles.avatar}>
            <Text style={styles.avatarText}>{(profile?.employee?.name ?? profile?.name ?? 'K').split(' ').slice(0, 2).map((part) => part[0]).join('').toUpperCase()}</Text>
          </View>
        </View>

        <View style={[styles.networkBanner, !online && styles.networkBannerOffline]}>
          <View style={[styles.dot, !online && styles.dotOffline]} />
          <View style={styles.flex}>
            <Text style={styles.networkTitle}>{online ? 'Koneksi server tersedia' : 'Mode offline'}</Text>
            <Text style={styles.networkCopy}>{loading ? 'Memperbarui data…' : online ? 'Jadwal dan pengajuan dimuat dari sistem' : 'Jadwal terakhir tersimpan di perangkat'}</Text>
          </View>
          {pendingOutbox.length > 0 && <Text style={styles.queueCount}>{pendingOutbox.length} antrean</Text>}
        </View>

        {isAdminOrSupervisor && pendingHrdCount > 0 && (
          <Pressable onPress={() => { setTab('Pengajuan'); setPengajuanView('admin'); }} style={styles.adminAlertCard}>
            <Ionicons name="notifications-outline" size={20} color={colors.warning} />
            <View style={styles.flex}>
              <Text style={styles.adminAlertTitle}>Persetujuan Tukar Shift ({pendingHrdCount})</Text>
              <Text style={styles.cardCopy}>Ada {pendingHrdCount} pengajuan tukar shift menunggu persetujuan HRD.</Text>
            </View>
            <Text style={styles.linkText}>Periksa</Text>
          </Pressable>
        )}

        <View style={styles.heroCard}>
          <View style={styles.heroTop}>
            <Text style={styles.heroLabel}>SIKLUS KERJA HARI INI</Text>
            <Pill tone={activeAssignment ? 'success' : 'neutral'}>{activeAssignment?.status === 'IN_PROGRESS' ? 'Berjalan' : activeAssignment ? 'Terbit' : 'Tanpa jadwal'}</Pill>
          </View>
          <Text style={styles.heroTitle}>{activeAssignment?.template_name ?? 'Belum ada jadwal'}</Text>
          <Text style={styles.heroTime}>{activeAssignment ? `${activeAssignment.sessions.length} sesi · ${Math.floor(activeAssignment.scheduled_minutes / 60)} jam` : 'Jadwal dari HR tampil di sini'}</Text>
          <Text style={styles.heroMeta}>{nameOrString(profile?.employee?.department, 'Absensi')}{activeSession?.session.attendance_policy?.location_required ? ' · validasi lokasi aktif' : ''}</Text>
          {activeIntent && (
            <Pressable accessibilityRole="button" onPress={() => setIntent(activeIntent)} style={({ pressed }) => [styles.attendanceButton, pressed && styles.pressed]}>
              <Text style={styles.attendanceButtonText}>{activeIntent.eventType === 'CHECK_IN' ? 'Check-in sesi' : 'Check-out sesi'}</Text>
              <Text style={styles.attendanceHint}>Sesi {activeIntent.session.seq} · {shortClock(activeIntent.session.local_start)}–{shortClock(activeIntent.session.local_end)}</Text>
            </Pressable>
          )}
        </View>

        {balance && (
          <View style={styles.balanceCard}>
            <View>
              <Text style={styles.cardEyebrow}>SISA CUTI {balance.year}</Text>
              <Text style={styles.balanceValue}>{balance.regular.available}<Text style={styles.balanceUnit}> hari</Text></Text>
              <Text style={styles.cardCopy}>Reguler · {balance.regular.used} terpakai</Text>
            </View>
            <View style={styles.balanceDivider} />
            <View>
              <Text style={styles.cardEyebrow}>DAY OFF</Text>
              <Text style={styles.balanceValue}>{balance.day_off.available}<Text style={styles.balanceUnit}> hari</Text></Text>
              <Text style={styles.cardCopy}>{balance.day_off.used} terpakai</Text>
            </View>
          </View>
        )}

        <View style={styles.sectionHeading}>
          <Text style={styles.sectionTitle}>Rangkaian sesi</Text>
          <Text style={styles.sectionMeta}>{sessions.length} sesi</Text>
        </View>
        {sessions.length ? sessions.map(({ assignment, session }) => renderAttendance(assignment, session)) : (
          <View style={styles.emptyCard}><Text style={styles.cardTitle}>Belum ada jadwal hari ini</Text><Text style={styles.cardCopy}>Jadwal yang diterbitkan HR akan muncul setelah disinkronkan.</Text></View>
        )}

        {pendingOutbox.length > 0 && (
          <>
            <View style={styles.sectionHeading}><Text style={styles.sectionTitle}>Antrean absensi</Text><Pressable onPress={() => syncOutbox()}><Text style={styles.linkText}>Sinkronkan</Text></Pressable></View>
            {pendingOutbox.map(outboxCard)}
          </>
        )}

        <View style={styles.notice}>
          <Text style={styles.noticeTitle}>Menit kerja terverifikasi</Text>
          <Text style={styles.noticeValue}>{Math.floor(credited / 60)}j {credited % 60}m</Text>
          <Text style={styles.cardCopy}>Kehadiran provisional dan event offline belum masuk total ini.</Text>
        </View>
        {!!error && <Text style={styles.errorText}>{error}</Text>}
      </ScrollView>
    );
  }

  function renderSchedule() {
    const displayedAssignments = (isAdminOrSupervisor && !hasEmployee)
      ? (adminScheduleEmpFilter ? adminSchedules.filter((a) => a.employee_id === adminScheduleEmpFilter) : adminSchedules)
      : assignments;

    const upcoming = [...displayedAssignments].sort((a, b) => a.work_date.localeCompare(b.work_date));

    return (
      <ScrollView contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
        <View style={styles.headingRow}>
          <View>
            <Text style={styles.overline}>JADWAL & ROSTER</Text>
            <Text style={styles.heading}>Jadwal kerja</Text>
          </View>
          {isAdminOrSupervisor && (
            <Pressable onPress={() => openDirectSwap()} style={styles.iconButton} accessibilityLabel="Atur Tukar Shift">
              <Ionicons name="swap-horizontal" size={20} color={colors.primary} />
            </Pressable>
          )}
        </View>

        {isAdminOrSupervisor && (
          <View style={styles.adminBar}>
            <Text style={styles.fieldLabel}>Filter Karyawan Roster:</Text>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.empFilterScroll}>
              <Pressable onPress={() => setAdminScheduleEmpFilter(null)} style={[styles.filterChip, adminScheduleEmpFilter === null && styles.filterChipActive]}>
                <Text style={[styles.filterChipText, adminScheduleEmpFilter === null && styles.filterChipTextActive]}>Semua Karyawan ({allEmployees.length})</Text>
              </Pressable>
              {allEmployees.map((emp) => (
                <Pressable key={emp.id} onPress={() => setAdminScheduleEmpFilter(emp.id)} style={[styles.filterChip, adminScheduleEmpFilter === emp.id && styles.filterChipActive]}>
                  <Text style={[styles.filterChipText, adminScheduleEmpFilter === emp.id && styles.filterChipTextActive]}>{emp.name}</Text>
                </Pressable>
              ))}
            </ScrollView>
          </View>
        )}

        <Text style={styles.intro}>Satu siklus kerja mengikuti tanggal awal, termasuk shift malam dan split shift.</Text>

        {upcoming.length ? upcoming.map((assignment) => (
          <View style={styles.assignmentCard} key={assignment.id}>
            <View style={styles.assignmentHeading}>
              <View style={styles.flex}>
                <Text style={styles.dateText}>{localDate(assignment.work_date)}</Text>
                <Text style={styles.cardTitle}>{assignment.template_name}</Text>
                {assignment.employee_name && <Text style={styles.employeeTag}>Karyawan: {assignment.employee_name}</Text>}
              </View>
              <Pill tone={assignment.status === 'COMPLETED' ? 'neutral' : assignment.status === 'IN_PROGRESS' ? 'blue' : 'success'}>
                {assignment.status === 'IN_PROGRESS' ? 'Berjalan' : assignment.status === 'COMPLETED' ? 'Selesai' : 'Terbit'}
              </Pill>
            </View>

            {isAdminOrSupervisor && assignment.status !== 'CANCELLED' && (
              <View style={styles.scheduleActionRow}>
                <Pressable onPress={() => openDirectSwap(assignment, assignment.employee_id)} style={styles.swapQuickBtn}>
                  <Ionicons name="swap-horizontal" size={14} color={colors.primary} />
                  <Text style={styles.swapQuickText}>Tukar Shift</Text>
                </Pressable>
              </View>
            )}

            {assignment.sessions.map((session) => renderAttendance(assignment, session))}
          </View>
        )) : (
          <View style={styles.emptyCard}>
            <Text style={styles.cardTitle}>Jadwal belum tersedia</Text>
            <Text style={styles.cardCopy}>Jadwal akan tampil setelah HR menerbitkannya.</Text>
          </View>
        )}
        {!!error && <Text style={styles.errorText}>{error}</Text>}
      </ScrollView>
    );
  }

  function renderSwapForm() {
    return (
      <View style={styles.formCard}>
        <Text style={styles.cardTitle}>Pengajuan Tukar Shift</Text>
        <Text style={styles.cardCopy}>Pilih jadwal Anda dan rekan pengganti untuk ditukar.</Text>

        {/* Source Assignment Selector */}
        <Text style={styles.fieldLabel}>1. Pilih Jadwal Anda yang Ingin Ditukar</Text>
        {myUpcomingAssignments.length === 0 ? (
          <Text style={styles.fieldHint}>Anda belum memiliki jadwal aktif mendatang untuk ditukar.</Text>
        ) : (
          <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.horizontalSelector}>
            {myUpcomingAssignments.map((ass) => {
              const selected = swapSourceAssId === ass.id;
              return (
                <Pressable key={ass.id} onPress={() => setSwapSourceAssId(ass.id)} style={[styles.choiceChip, selected && styles.choiceChipActive]}>
                  <Text style={[styles.choiceChipTitle, selected && styles.choiceChipTitleActive]}>{localDate(ass.work_date)}</Text>
                  <Text style={[styles.choiceChipSub, selected && styles.choiceChipSubActive]}>{ass.template_name}</Text>
                </Pressable>
              );
            })}
          </ScrollView>
        )}

        {/* Target Colleague Selector */}
        <Text style={[styles.fieldLabel, { marginTop: 14 }]}>2. Pilih Rekan Kerja Pengganti</Text>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.horizontalSelector}>
          {colleagues.map((col) => {
            const selected = swapTargetEmpId === col.id;
            return (
              <Pressable key={col.id} onPress={() => selectColleague(col.id)} style={[styles.choiceChip, selected && styles.choiceChipActive]}>
                <Text style={[styles.choiceChipTitle, selected && styles.choiceChipTitleActive]}>{col.name}</Text>
                <Text style={[styles.choiceChipSub, selected && styles.choiceChipSubActive]}>{nameOrString(col.department, col.code)}</Text>
              </Pressable>
            );
          })}
        </ScrollView>

        {/* Swap Mode Toggle */}
        <Text style={[styles.fieldLabel, { marginTop: 14 }]}>3. Pilihan Jenis Pertukaran</Text>
        <View style={styles.segmentRow}>
          <Pressable onPress={() => setSwapMode('direct')} style={[styles.segment, swapMode === 'direct' && styles.segmentActive]}>
            <Text style={[styles.segmentText, swapMode === 'direct' && styles.segmentTextActive]}>Tukar Langsung</Text>
          </Pressable>
          <Pressable onPress={() => setSwapMode('delegation')} style={[styles.segment, swapMode === 'delegation' && styles.segmentActive]}>
            <Text style={[styles.segmentText, swapMode === 'delegation' && styles.segmentTextActive]}>Pelimpahan Shift</Text>
          </Pressable>
        </View>

        {/* If direct: pick target colleague's schedule */}
        {swapMode === 'direct' ? (
          <>
            <Text style={[styles.fieldLabel, { marginTop: 14 }]}>Pilih Jadwal Rekan untuk Ditukar Balik</Text>
            {loadingTargetSchedules ? (
              <Text style={styles.fieldHint}>Memuat jadwal rekan…</Text>
            ) : !swapTargetEmpId ? (
              <Text style={styles.fieldHint}>Pilih rekan kerja terlebih dahulu.</Text>
            ) : targetEmpSchedules.length === 0 ? (
              <Text style={styles.fieldHint}>Rekan ini belum memiliki jadwal aktif lain di periode mendatang. Anda dapat memilih Pelimpahan Shift.</Text>
            ) : (
              <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.horizontalSelector}>
                {targetEmpSchedules.map((item) => {
                  const selected = swapTargetAssId === item.id;
                  return (
                    <Pressable key={item.id} onPress={() => setSwapTargetAssId(item.id)} style={[styles.choiceChip, selected && styles.choiceChipActive]}>
                      <Text style={[styles.choiceChipTitle, selected && styles.choiceChipTitleActive]}>{localDate(item.work_date)}</Text>
                      <Text style={[styles.choiceChipSub, selected && styles.choiceChipSubActive]}>{item.template_name}</Text>
                    </Pressable>
                  );
                })}
              </ScrollView>
            )}
          </>
        ) : (
          <>
            <Text style={[styles.fieldLabel, { marginTop: 14 }]}>Status Anda Saat Melepas Shift (Tanpa Tukar Balik)</Text>
            <View style={styles.segmentRow}>
              {(['roster_off', 'regular', 'day_off'] as const).map((opt) => (
                <Pressable key={opt} onPress={() => setSwapVacancyAction(opt)} style={[styles.segment, swapVacancyAction === opt && styles.segmentActive]}>
                  <Text style={[styles.segmentText, swapVacancyAction === opt && styles.segmentTextActive]}>
                    {opt === 'roster_off' ? 'Roster Off' : opt === 'regular' ? 'Cuti' : 'Day Off'}
                  </Text>
                </Pressable>
              ))}
            </View>
          </>
        )}

        <TextField label="Alasan Pertukaran Shift" value={reason} onChangeText={setReason} placeholder="Jelaskan keperluan pertukaran shift" multiline />

        <Pressable disabled={busy} onPress={submitRequest} style={({ pressed }) => [styles.actionButton, pressed && styles.pressed, busy && styles.disabled]}>
          <Text style={styles.actionButtonText}>{busy ? 'Mengirim…' : 'Kirim Pengajuan Tukar Shift'}</Text>
        </Pressable>
      </View>
    );
  }

  function renderRequestForm() {
    if (requestKind === 'swap') {
      return renderSwapForm();
    }

    const sessions = requestKind === 'late' ? unfinishedSessions : openSessions;
    const chosen = sessions.find((item) => item.session.id === sessionId)?.session;
    const evidenceRequired = requestKind === 'late' ? chosen?.attendance_policy?.late_permission_proof_required : chosen?.attendance_policy?.correction_proof_required;

    return (
      <View style={styles.formCard}>
        {requestKind === 'leave' ? (
          <>
            <Text style={styles.cardTitle}>Pengajuan cuti</Text>
            <TextField label="Tanggal mulai cuti" value={workDate} onChangeText={setWorkDate} placeholder="YYYY-MM-DD" />
            <TextField
              label="Tanggal akhir cuti (opsional, jika > 1 hari)"
              value={endDate}
              onChangeText={setEndDate}
              placeholder="YYYY-MM-DD (kosongkan jika hanya 1 hari)"
            />
            {!!workDate && !!endDate && endDate >= workDate && (
              <Text style={styles.leaveDurationText}>
                Durasi cuti: {Math.max(1, Math.round((new Date(`${endDate.slice(0, 10)}T00:00:00`).getTime() - new Date(`${workDate.slice(0, 10)}T00:00:00`).getTime()) / 86400000) + 1)} hari kerja
              </Text>
            )}
            <View style={styles.segmentRow}>
              <Pressable onPress={() => setLeaveType('regular')} style={[styles.segment, leaveType === 'regular' && styles.segmentActive]}>
                <Text style={[styles.segmentText, leaveType === 'regular' && styles.segmentTextActive]}>Cuti reguler</Text>
              </Pressable>
              <Pressable onPress={() => setLeaveType('day_off')} style={[styles.segment, leaveType === 'day_off' && styles.segmentActive]}>
                <Text style={[styles.segmentText, leaveType === 'day_off' && styles.segmentTextActive]}>Day off</Text>
              </Pressable>
            </View>
          </>
        ) : (
          <>
            <Text style={styles.cardTitle}>{requestKind === 'late' ? 'Izin terlambat' : 'Koreksi absensi'}</Text>
            <Text style={styles.fieldLabel}>Pilih sesi</Text>
            {sessions.slice(0, 20).map(({ assignment, session }) => (
              <Pressable key={session.id} onPress={() => setSessionId(session.id)} style={[styles.choiceCard, sessionId === session.id && styles.choiceCardActive]}>
                <Text style={styles.cardTitle}>{localDate(assignment.work_date)} · {assignment.template_name} · sesi {session.seq}</Text>
                <Text style={styles.cardCopy}>{shortClock(session.local_start)}–{shortClock(session.local_end)}{session.attendance_policy?.location_required ? ' · GPS diwajibkan' : ''}</Text>
              </Pressable>
            ))}
            {requestKind === 'late' ? (
              <>
                <TextField label="Target hadir (ISO, opsional)" value={allowedUntil} onChangeText={setAllowedUntil} placeholder="2026-10-25T08:30:00+07:00" autoCapitalize="none" />
                <TextField label="Atau durasi izin (menit)" value={minutes} onChangeText={setMinutes} placeholder="30" keyboardType="numeric" />
              </>
            ) : (
              <>
                <TextField label="Waktu check-in yang diminta (ISO)" value={requestedIn} onChangeText={setRequestedIn} placeholder="2026-10-25T08:05:00+07:00" autoCapitalize="none" />
                <TextField label="Waktu check-out yang diminta (ISO)" value={requestedOut} onChangeText={setRequestedOut} placeholder="2026-10-25T17:00:00+07:00" autoCapitalize="none" />
              </>
            )}
            <Pressable onPress={chooseProof} style={styles.attachButton}>
              <Text style={styles.attachText}>{proof ? `Bukti: ${proof.name}` : 'Lampirkan JPG, PNG, atau PDF'}</Text>
            </Pressable>
            {evidenceRequired && <Text style={styles.requiredHint}>Bukti wajib sesuai aturan absensi sesi ini.</Text>}
          </>
        )}
        <TextField label="Alasan" value={reason} onChangeText={setReason} placeholder="Jelaskan keperluan atau perubahan" multiline />
        <Pressable disabled={busy} onPress={submitRequest} style={({ pressed }) => [styles.actionButton, pressed && styles.pressed, busy && styles.disabled]}>
          <Text style={styles.actionButtonText}>{busy ? 'Mengirim…' : 'Kirim pengajuan'}</Text>
        </Pressable>
      </View>
    );
  }

  function renderAdminExchangeApproval() {
    const pendingCount = adminShiftExchanges.filter((i) => i.status === 'PENDING').length;
    const filtered = adminShiftExchanges.filter((item) => {
      if (approvalFilter === 'ALL') return true;
      return item.status === approvalFilter;
    });

    return (
      <View style={styles.adminApprovalSection}>
        <View style={styles.adminBarHeader}>
          <View>
            <Text style={styles.sectionTitle}>Persetujuan Tukar Shift HRD</Text>
            <Text style={styles.cardCopy}>Tinjau pengajuan dari staf dan terapkan ke sistem jadwal.</Text>
          </View>
          <Pressable onPress={() => openDirectSwap()} style={styles.directSwapActionBtn}>
            <Ionicons name="add" size={16} color="#fff" />
            <Text style={styles.directSwapActionText}>Atur Langsung</Text>
          </Pressable>
        </View>

        {/* Filter chips */}
        <View style={styles.segmentRow}>
          {(['PENDING', 'APPROVED', 'REJECTED', 'ALL'] as const).map((filter) => (
            <Pressable key={filter} onPress={() => setApprovalFilter(filter)} style={[styles.segment, approvalFilter === filter && styles.segmentActive]}>
              <Text style={[styles.segmentText, approvalFilter === filter && styles.segmentTextActive]}>
                {filter === 'PENDING' ? `Menunggu (${pendingCount})` : filter === 'APPROVED' ? 'Disetujui' : filter === 'REJECTED' ? 'Ditolak' : 'Semua'}
              </Text>
            </Pressable>
          ))}
        </View>

        {filtered.length === 0 ? (
          <View style={styles.emptyCard}>
            <Text style={styles.cardTitle}>Tidak ada pengajuan tukar shift</Text>
            <Text style={styles.cardCopy}>Belum ada data pengajuan dengan status yang dipilih.</Text>
          </View>
        ) : (
          filtered.map((item) => (
            <View key={item.id} style={styles.exchangeCard}>
              <View style={styles.exchangeHeader}>
                <View style={styles.flex}>
                  <Text style={styles.exchangeDate}>{localDate(item.assignment?.work_date ?? item.created_at)}</Text>
                  <Text style={styles.exchangeShift}>{item.assignment?.template_name ?? 'Shift'}</Text>
                </View>
                <Pill tone={item.status === 'APPROVED' ? 'success' : item.status === 'PENDING' ? 'warning' : item.status === 'REJECTED' ? 'danger' : 'neutral'}>
                  {item.status === 'PENDING' ? 'Menunggu HR' : item.status === 'APPROVED' ? 'Disetujui' : item.status === 'REJECTED' ? 'Ditolak' : 'Dibatalkan'}
                </Pill>
              </View>

              <View style={styles.exchangeRoute}>
                <View style={styles.flex}>
                  <Text style={styles.fieldLabel}>Pemohon:</Text>
                  <Text style={styles.empName}>{item.employee?.name}</Text>
                  <Text style={styles.cardCopy}>{nameOrString(item.employee?.department)} · {nameOrString(item.employee?.position)}</Text>
                </View>
                <Ionicons name="arrow-forward" size={18} color={colors.primary} style={{ marginHorizontal: 8 }} />
                <View style={styles.flex}>
                  <Text style={styles.fieldLabel}>Rekan Pengganti:</Text>
                  <Text style={styles.empName}>{item.target_employee?.name}</Text>
                  <Text style={styles.cardCopy}>{nameOrString(item.target_employee?.department)} · {nameOrString(item.target_employee?.position)}</Text>
                </View>
              </View>

              <View style={styles.exchangeMetaBox}>
                <Text style={styles.metaLabel}>Mode: <Text style={styles.metaValue}>{item.swap_mode === 'direct' ? 'Tukar Jadwal Langsung' : 'Pelimpahan Shift'}</Text></Text>
                {item.swap_mode === 'direct' && item.target_assignment && (
                  <Text style={styles.metaLabel}>Jadwal pengganti: <Text style={styles.metaValue}>{localDate(item.target_assignment.work_date)} ({item.target_assignment.template_name})</Text></Text>
                )}
                {item.swap_mode === 'delegation' && item.vacancy_action && (
                  <Text style={styles.metaLabel}>Status roster pemohon: <Text style={styles.metaValue}>{item.vacancy_action === 'roster_off' ? 'Roster Off' : item.vacancy_action === 'regular' ? 'Potong Cuti' : 'Potong Day Off'}</Text></Text>
                )}
                <Text style={styles.metaLabel}>Alasan pemohon: <Text style={styles.metaValue}>"{item.reason}"</Text></Text>
                {item.decision_reason && (
                  <Text style={styles.metaDecision}>Catatan HRD: {item.decision_reason} {item.decided_by_name ? `(${item.decided_by_name})` : ''}</Text>
                )}
              </View>

              {item.status === 'PENDING' && (
                <View style={styles.exchangeBtnRow}>
                  <Pressable onPress={() => promptExchangeDecision(item, 'REJECTED')} style={styles.rejectBtn}>
                    <Text style={styles.rejectBtnText}>Tolak</Text>
                  </Pressable>
                  <Pressable onPress={() => promptExchangeDecision(item, 'APPROVED')} style={styles.approveBtn}>
                    <Text style={styles.approveBtnText}>Setujui & Terapkan</Text>
                  </Pressable>
                </View>
              )}
            </View>
          ))
        )}
      </View>
    );
  }

  function renderRequests() {
    const pendingHrdCount = adminShiftExchanges.filter((i) => i.status === 'PENDING').length;

    return (
      <ScrollView contentContainerStyle={styles.scrollContent} keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
        <View style={styles.headingRow}>
          <View>
            <Text style={styles.overline}>LAYANAN & PERSETUJUAN</Text>
            <Text style={styles.heading}>Pengajuan</Text>
          </View>
          {isAdminOrSupervisor && (
            <Pressable onPress={() => openDirectSwap()} style={styles.iconButton} accessibilityLabel="Pengaturan Tukar Shift">
              <Ionicons name="options-outline" size={20} color={colors.primary} />
            </Pressable>
          )}
        </View>

        {/* View Switcher for Admin / HRD */}
        {isAdminOrSupervisor && (
          <View style={styles.viewSwitcher}>
            {hasEmployee && (
              <Pressable onPress={() => setPengajuanView('mine')} style={[styles.switcherBtn, pengajuanView === 'mine' && styles.switcherBtnActive]}>
                <Text style={[styles.switcherBtnText, pengajuanView === 'mine' && styles.switcherBtnTextActive]}>Pengajuan Saya</Text>
              </Pressable>
            )}
            <Pressable onPress={() => setPengajuanView('admin')} style={[styles.switcherBtn, pengajuanView === 'admin' && styles.switcherBtnActive]}>
              <Text style={[styles.switcherBtnText, pengajuanView === 'admin' && styles.switcherBtnTextActive]}>
                Persetujuan HRD {pendingHrdCount > 0 ? `(${pendingHrdCount})` : ''}
              </Text>
            </Pressable>
          </View>
        )}

        {pengajuanView === 'admin' && isAdminOrSupervisor ? (
          renderAdminExchangeApproval()
        ) : (
          <>
            {balance && (
              <View style={styles.balanceStrip}>
                <Text style={styles.cardTitle}>Tersedia tahun {balance.year}</Text>
                <Text style={styles.cardCopy}>Cuti reguler {balance.regular.available} hari · Day off {balance.day_off.available} hari{balance.opening_confirmed ? '' : ' · saldo belum dibuka HR'}</Text>
              </View>
            )}

            <View style={styles.segmentRow}>
              {(['leave', 'late', 'correction', 'swap'] as const).map((item) => (
                <Pressable key={item} onPress={() => { setRequestKind(item); setSessionId(null); setProof(null); }} style={[styles.segment, requestKind === item && styles.segmentActive]}>
                  <Text style={[styles.segmentText, requestKind === item && styles.segmentTextActive]}>
                    {item === 'leave' ? 'Cuti' : item === 'late' ? 'Izin' : item === 'correction' ? 'Koreksi' : 'Tukar Shift'}
                  </Text>
                </Pressable>
              ))}
            </View>

            {renderRequestForm()}

            <Text style={styles.sectionTitle}>Riwayat pengajuan</Text>
            {[
              ...leaveRequests.map((item) => ({ ...item, group: 'Cuti', sortDate: item.work_date })),
              ...lateRequests.map((item) => ({ ...item, group: 'Izin terlambat', sortDate: item.work_date })),
              ...corrections.map((item) => ({ ...item, group: 'Koreksi', sortDate: item.work_date })),
              ...shiftExchanges.map((item) => ({
                id: item.id,
                work_date: item.assignment?.work_date ?? item.created_at.slice(0, 10),
                end_date: null,
                days_count: 1,
                shift: item.assignment?.template_name ?? 'Shift',
                reason: `${item.swap_mode === 'direct' ? 'Tukar langsung' : 'Pelimpahan'} dengan ${item.target_employee?.name}: ${item.reason}`,
                status: item.status,
                decision_reason: item.decision_reason ?? undefined,
                shift_session_id: 0,
                group: 'Tukar shift',
                sortDate: item.assignment?.work_date ?? item.created_at.slice(0, 10),
              })),
            ].sort((a, b) => (b.sortDate || '').localeCompare(a.sortDate || '')).map((item) => (
              <View key={`${item.group}-${item.id}`} style={styles.listCard}>
                <View style={styles.flex}>
                  <Text style={styles.cardTitle}>
                    {item.group} · {item.end_date && item.end_date !== item.work_date
                      ? `${localDate(item.work_date)} – ${localDate(item.end_date)} (${item.days_count ?? 1} hari)`
                      : localDate(item.work_date)}
                  </Text>
                  <Text style={styles.cardCopy}>{item.reason}{item.decision_reason ? ` · ${item.decision_reason}` : ''}</Text>
                </View>
                <Pill tone={item.status === 'APPROVED' ? 'success' : item.status === 'PENDING' ? 'warning' : item.status === 'REJECTED' ? 'danger' : 'neutral'}>
                  {item.status === 'PENDING' ? 'Menunggu' : item.status === 'APPROVED' ? 'Disetujui' : item.status === 'REJECTED' ? 'Ditolak' : 'Dibatalkan'}
                </Pill>
                {item.status === 'PENDING' && (
                  <Pressable
                    onPress={() => cancelRequest(
                      item.group === 'Cuti' ? `leaves/${item.id}/cancel` :
                      item.group === 'Izin terlambat' ? `late-permissions/${item.id}/cancel` :
                      item.group === 'Koreksi' ? `attendance-corrections/${item.id}/cancel` :
                      `shift-exchanges/${item.id}/cancel`,
                      item.group
                    )}
                    style={styles.smallButton}
                  >
                    <Text style={styles.smallButtonText}>Batalkan</Text>
                  </Pressable>
                )}
              </View>
            ))}

            {!leaveRequests.length && !lateRequests.length && !corrections.length && !shiftExchanges.length && (
              <Text style={styles.cardCopy}>Belum ada pengajuan tersimpan.</Text>
            )}
          </>
        )}
        {!!error && <Text style={styles.errorText}>{error}</Text>}
      </ScrollView>
    );
  }

  function renderProfile() {
    const employee = profile?.employee;
    const avatarPath = profile?.avatar_path ?? employee?.avatar_path;
    const avatarUri = avatarPath
      ? (avatarPath.startsWith('http') ? avatarPath : `${normalizeBaseUrl(baseUrl)}${avatarPath.startsWith('/') ? '' : '/'}${avatarPath}`)
      : null;

    return (
      <ScrollView contentContainerStyle={styles.scrollContent}>
        <View style={styles.headingRow}>
          <View>
            <Text style={styles.overline}>AKUN & PENGATURAN</Text>
            <Text style={styles.heading}>Profil</Text>
          </View>
          <Pill tone="blue">{profile?.role.replace('_', ' ').toUpperCase()}</Pill>
        </View>

        <View style={styles.profileCard}>
          <View style={styles.avatarWrapper}>
            {avatarUri ? (
              <Image source={{ uri: avatarUri }} style={styles.avatarLargeImage} />
            ) : (
              <View style={styles.avatarLarge}>
                <Text style={styles.avatarLargeText}>{(employee?.name ?? profile?.name ?? 'K').split(' ').slice(0, 2).map((part) => part[0]).join('').toUpperCase()}</Text>
              </View>
            )}
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Ubah foto profil"
              disabled={avatarUploading}
              onPress={handleChooseAvatar}
              style={({ pressed }) => [styles.avatarBadgeBtn, pressed && styles.pressed, avatarUploading && styles.disabled]}
            >
              <Ionicons name="camera" size={14} color="#fff" />
            </Pressable>
          </View>

          <View style={styles.avatarActionsRow}>
            <Pressable disabled={avatarUploading} onPress={handleChooseAvatar} style={styles.avatarActionBtn}>
              <Ionicons name="image-outline" size={13} color={colors.primary} />
              <Text style={styles.avatarActionBtnText}>{avatarUploading ? 'Mengunggah…' : avatarUri ? 'Ganti Foto' : 'Unggah Foto'}</Text>
            </Pressable>
            {Boolean(avatarUri) && (
              <Pressable disabled={avatarUploading} onPress={handleDeleteAvatar} style={styles.avatarDeleteBtn}>
                <Text style={styles.avatarDeleteBtnText}>Hapus Foto</Text>
              </Pressable>
            )}
          </View>

          <Text style={styles.profileName}>{employee?.name ?? profile?.name ?? 'Karyawan'}</Text>
          <Text style={styles.cardCopy}>{employee?.code ?? (isAdminOrSupervisor ? 'Akun Pengelola' : 'Karyawan')}</Text>
          <Text style={styles.cardCopy}>{nameOrString(employee?.department, isAdminOrSupervisor ? 'Departemen Manajemen' : 'Departemen belum diatur')}{employee?.position ? ` · ${nameOrString(employee.position)}` : ''}</Text>

          <View style={styles.profileMetaBox}>
            <View style={styles.profileMetaRow}>
              <Ionicons name="mail-outline" size={15} color={colors.muted} />
              <Text style={styles.profileMetaLabel}>Email Pribadi</Text>
              <Text style={styles.profileMetaValue}>{employee?.email ?? profile?.email ?? '-'}</Text>
            </View>
            <View style={styles.profileMetaRow}>
              <Ionicons name="call-outline" size={15} color={colors.muted} />
              <Text style={styles.profileMetaLabel}>No. Telepon</Text>
              <Text style={styles.profileMetaValue}>{employee?.phone ?? profile?.phone ?? 'Belum diatur'}</Text>
            </View>
          </View>

          <Pressable onPress={openEditProfile} style={[styles.primaryActionButton, { marginTop: 16, width: '100%' }]}>
            <Ionicons name="create-outline" size={16} color="#fff" />
            <Text style={styles.primaryActionButtonText}>Perbarui Data Pribadi</Text>
          </Pressable>
        </View>

        {isAdminOrSupervisor && (
          <View style={styles.settingsCard}>
            <Text style={styles.cardEyebrow}>HAK AKSES KHUSUS HRD</Text>
            <Text style={styles.cardTitle}>Pengaturan & Persetujuan Tukar Shift</Text>
            <Text style={styles.cardCopy}>Anda memiliki akses untuk mengatur perpindahan jadwal seluruh karyawan resort dan menyetujui pengajuan.</Text>
            <Pressable onPress={() => openDirectSwap()} style={styles.primaryActionButton}>
              <Ionicons name="swap-horizontal" size={16} color="#fff" />
              <Text style={styles.primaryActionButtonText}>Atur Tukar Shift Karyawan</Text>
            </Pressable>
          </View>
        )}

        <View style={styles.settingsCard}>
          <Text style={styles.cardEyebrow}>SERVER</Text>
          <Text style={styles.cardTitle}>{baseUrl}</Text>
          <Text style={styles.cardCopy}>Perangkat terdaftar dengan ID tersimpan aman.</Text>
          <Text style={styles.cardEyebrow}>SINKRONISASI</Text>
          <Text style={styles.cardCopy}>{pendingOutbox.length} event belum selesai · {online ? 'online' : 'offline'}</Text>
          <Pressable onPress={() => { if (token) { syncOutbox(); loadData(); } }} style={styles.secondaryButton}>
            <Text style={styles.secondaryButtonText}>Perbarui data</Text>
          </Pressable>
          <Pressable onPress={logout} style={styles.logoutButton}>
            <Text style={styles.logoutText}>Keluar dari akun</Text>
          </Pressable>
        </View>
      </ScrollView>
    );
  }

  if (!token) {
    return (
      <SafeAreaView style={styles.safeArea}>
        <StatusBar barStyle="dark-content" backgroundColor={colors.canvas} />
        <KeyboardAvoidingView
          style={styles.flex}
          behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        >
          <ScrollView
            contentContainerStyle={styles.loginContent}
            keyboardShouldPersistTaps="handled"
            keyboardDismissMode="on-drag"
            showsVerticalScrollIndicator={false}
            automaticallyAdjustKeyboardInsets={true}
            bounces={true}
          >
            <View style={styles.loginMark}><Text style={styles.loginMarkText}>D</Text></View>
            <Text style={styles.overline}>DERESORT · ABSENSI & SHIFT</Text>
            <Text style={styles.loginHeading}>Masuk ke akun</Text>
            <Text style={styles.intro}>Gunakan username (kode karyawan) dan password akun untuk mengakses jadwal dan absensi.</Text>
            <View style={styles.formCard}>
              <TextField label="Alamat server" value={baseUrl} onChangeText={setBaseUrl} placeholder="https://domain-anda.com" autoCapitalize="none" />
              <TextField label="Username (Kode Karyawan)" value={username} onChangeText={setUsername} placeholder="contoh: FO-001" autoCapitalize="characters" />
              <TextField label="Password" value={password} onChangeText={setPassword} placeholder="Password akun" secureTextEntry autoCapitalize="none" />
              {!!error && <Text style={styles.errorText}>{error}</Text>}
              <Pressable disabled={loginBusy} onPress={login} style={({ pressed }) => [styles.actionButton, pressed && styles.pressed, loginBusy && styles.disabled]}>
                <Text style={styles.actionButtonText}>{loginBusy ? 'Menghubungkan…' : 'Masuk'}</Text>
              </Pressable>
              <Text style={styles.cardCopy}>Masuk dengan kode karyawan / username beserta password akun.</Text>
            </View>
          </ScrollView>
        </KeyboardAvoidingView>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.safeArea}>
      <StatusBar barStyle="dark-content" backgroundColor={colors.canvas} />
      <View style={styles.app}>
        <Animated.View style={[styles.flex, { opacity: tabFade, transform: [{ translateY: tabSlide }] }]}>
          {tab === 'Hari ini' ? renderToday() : tab === 'Jadwal' ? renderSchedule() : tab === 'Pengajuan' ? renderRequests() : renderProfile()}
        </Animated.View>
        <View style={styles.tabBar}>
          {tabItems.map(({ key, label, activeIcon, inactiveIcon }) => {
            const isSelected = tab === key;
            return (
              <Pressable
                key={key}
                accessibilityRole="tab"
                accessibilityState={{ selected: isSelected }}
                onPress={() => setTab(key)}
                style={({ pressed }) => [styles.tab, pressed && { opacity: 0.7, transform: [{ scale: 0.92 }] }]}
              >
                <Ionicons name={isSelected ? activeIcon : inactiveIcon} size={20} color={isSelected ? colors.primary : colors.muted} />
                <Text style={[styles.tabText, isSelected && styles.tabTextActive]}>{label}</Text>
                <View style={[styles.tabIndicator, isSelected && styles.tabIndicatorActive]} />
              </Pressable>
            );
          })}
        </View>
      </View>

      {/* Modal Attendance Action */}
      <Modal visible={Boolean(intent)} transparent animationType="fade" onRequestClose={() => setIntent(null)}>
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.modalEyebrow}>{intent?.assignment.template_name.toUpperCase()} · SESI {intent?.session.seq}</Text>
            <Text style={styles.modalTitle}>Konfirmasi {intent?.eventType === 'CHECK_IN' ? 'check-in' : 'check-out'}</Text>
            <Text style={styles.modalCopy}>{intent ? `${localDate(intent.assignment.work_date)} · ${shortClock(intent.session.local_start)}–${shortClock(intent.session.local_end)}` : ''}. Server memeriksa jadwal, lokasi, dan waktu saat event diterima.</Text>
            {!online && (
              <View style={styles.offlineWarning}>
                <Text style={styles.offlineWarningText}>Event tersimpan dengan waktu perangkat dan dapat memerlukan verifikasi HR.</Text>
              </View>
            )}
            <View style={styles.modalActions}>
              <Pressable onPress={() => setIntent(null)} style={styles.cancelButton}><Text style={styles.cancelButtonText}>Batal</Text></Pressable>
              <Pressable disabled={busy} onPress={recordAttendance} style={[styles.confirmButton, busy && styles.disabled]}><Text style={styles.confirmButtonText}>{busy ? 'Mencatat…' : 'Ya, catat'}</Text></Pressable>
            </View>
          </View>
        </View>
      </Modal>

      {/* Modal Decision (HRD Approve / Reject Shift Exchange) */}
      <Modal visible={decisionModalOpen} transparent animationType="fade" onRequestClose={() => setDecisionModalOpen(false)}>
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.modalEyebrow}>KEPUTUSAN HRD</Text>
            <Text style={styles.modalTitle}>{decisionType === 'APPROVED' ? 'Setujui Tukar Shift' : 'Tolak Tukar Shift'}</Text>
            <Text style={styles.modalCopy}>
              {decisionItem?.employee?.name} ⇄ {decisionItem?.target_employee?.name}
              {'\n'}Jadwal: {decisionItem?.assignment?.work_date} ({decisionItem?.assignment?.template_name})
            </Text>

            {decisionType === 'APPROVED' && decisionItem?.swap_mode === 'delegation' && (
              <View style={{ marginTop: 12 }}>
                <Text style={styles.fieldLabel}>Tindak Lanjut Roster Pemohon:</Text>
                <View style={styles.segmentRow}>
                  {(['roster_off', 'regular', 'day_off'] as const).map((opt) => (
                    <Pressable key={opt} onPress={() => setDecisionVacancyAction(opt)} style={[styles.segment, decisionVacancyAction === opt && styles.segmentActive]}>
                      <Text style={[styles.segmentText, decisionVacancyAction === opt && styles.segmentTextActive]}>
                        {opt === 'roster_off' ? 'Roster Off' : opt === 'regular' ? 'Cuti' : 'Day Off'}
                      </Text>
                    </Pressable>
                  ))}
                </View>
              </View>
            )}

            {decisionType === 'APPROVED' && (
              <View style={styles.switchRow}>
                <Switch value={decisionCrossUnitConfirm} onValueChange={setDecisionCrossUnitConfirm} trackColor={{ false: '#C8D1CA', true: colors.primary }} />
                <Text style={styles.switchLabel}>Konfirmasi jika perpindahan lintas unit/departemen</Text>
              </View>
            )}

            <TextField
              label={decisionType === 'APPROVED' ? 'Catatan Persetujuan (Opsional)' : 'Alasan Penolakan (Wajib)'}
              value={decisionReason}
              onChangeText={setDecisionReason}
              placeholder={decisionType === 'APPROVED' ? 'Contoh: Disetujui' : 'Contoh: Posisi tidak dapat ditinggalkan'}
              multiline
            />

            <View style={styles.modalActions}>
              <Pressable onPress={() => setDecisionModalOpen(false)} style={styles.cancelButton}>
                <Text style={styles.cancelButtonText}>Batal</Text>
              </Pressable>
              <Pressable
                disabled={decisionBusy}
                onPress={() => decisionItem && handleDecideShiftExchange(decisionItem, decisionType, decisionReason, decisionVacancyAction, decisionCrossUnitConfirm)}
                style={[styles.confirmButton, decisionType === 'REJECTED' && { backgroundColor: colors.danger }, decisionBusy && styles.disabled]}
              >
                <Text style={styles.confirmButtonText}>{decisionBusy ? 'Memproses…' : decisionType === 'APPROVED' ? 'Terapkan Tukar' : 'Konfirmasi Tolak'}</Text>
              </Pressable>
            </View>
          </View>
        </View>
      </Modal>

      {/* Modal Direct Swap (Pengaturan Tukar Shift Langsung oleh Admin / HRD) */}
      <Modal visible={directSwapOpen} transparent animationType="slide" onRequestClose={() => setDirectSwapOpen(false)}>
        <View style={styles.modalBackdrop}>
          <View style={[styles.modalCard, styles.modalCardLarge]}>
            <View style={styles.modalHeaderRow}>
              <View>
                <Text style={styles.modalEyebrow}>ADMIN / HRD</Text>
                <Text style={styles.modalTitle}>Pengaturan Tukar Shift</Text>
              </View>
              <Pressable onPress={() => setDirectSwapOpen(false)} style={styles.closeBtn}>
                <Ionicons name="close" size={22} color={colors.ink} />
              </Pressable>
            </View>

            <ScrollView showsVerticalScrollIndicator={false} style={styles.modalScroll}>
              {/* Step 1: Source Employee & Schedule */}
              <Text style={styles.fieldLabel}>1. Karyawan & Jadwal Asal</Text>
              <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.horizontalSelector}>
                {allEmployees.map((emp) => {
                  const selected = directSourceEmpId === emp.id;
                  return (
                    <Pressable key={emp.id} onPress={() => selectDirectSourceEmp(emp.id)} style={[styles.choiceChip, selected && styles.choiceChipActive]}>
                      <Text style={[styles.choiceChipTitle, selected && styles.choiceChipTitleActive]}>{emp.name}</Text>
                      <Text style={[styles.choiceChipSub, selected && styles.choiceChipSubActive]}>{nameOrString(emp.department)}</Text>
                    </Pressable>
                  );
                })}
              </ScrollView>

              {directSourceEmpId && (
                directSourceSchedules.length === 0 ? (
                  <Text style={styles.fieldHint}>Karyawan ini tidak memiliki jadwal aktif mendatang.</Text>
                ) : (
                  <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.horizontalSelector}>
                    {directSourceSchedules.map((ass) => {
                      const selected = directSourceAssId === ass.id;
                      return (
                        <Pressable key={ass.id} onPress={() => { setDirectSourceAssId(ass.id); setDirectPreview(null); }} style={[styles.choiceChip, selected && styles.choiceChipActive]}>
                          <Text style={[styles.choiceChipTitle, selected && styles.choiceChipTitleActive]}>{localDate(ass.work_date)}</Text>
                          <Text style={[styles.choiceChipSub, selected && styles.choiceChipSubActive]}>{ass.template_name}</Text>
                        </Pressable>
                      );
                    })}
                  </ScrollView>
                )
              )}

              {/* Step 2: Target Replacement Employee */}
              <Text style={[styles.fieldLabel, { marginTop: 14 }]}>2. Karyawan Pengganti</Text>
              <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.horizontalSelector}>
                {allEmployees.filter((e) => e.id !== directSourceEmpId).map((emp) => {
                  const selected = directTargetEmpId === emp.id;
                  return (
                    <Pressable key={emp.id} onPress={() => selectDirectTargetEmp(emp.id)} style={[styles.choiceChip, selected && styles.choiceChipActive]}>
                      <Text style={[styles.choiceChipTitle, selected && styles.choiceChipTitleActive]}>{emp.name}</Text>
                      <Text style={[styles.choiceChipSub, selected && styles.choiceChipSubActive]}>{nameOrString(emp.department)}</Text>
                    </Pressable>
                  );
                })}
              </ScrollView>

              {/* Step 3: Swap Mode */}
              <Text style={[styles.fieldLabel, { marginTop: 14 }]}>3. Mode Pertukaran</Text>
              <View style={styles.segmentRow}>
                <Pressable onPress={() => { setDirectSwapMode('direct'); setDirectPreview(null); }} style={[styles.segment, directSwapMode === 'direct' && styles.segmentActive]}>
                  <Text style={[styles.segmentText, directSwapMode === 'direct' && styles.segmentTextActive]}>Tukar Langsung</Text>
                </Pressable>
                <Pressable onPress={() => { setDirectSwapMode('delegation'); setDirectPreview(null); }} style={[styles.segment, directSwapMode === 'delegation' && styles.segmentActive]}>
                  <Text style={[styles.segmentText, directSwapMode === 'delegation' && styles.segmentTextActive]}>Pelimpahan Shift</Text>
                </Pressable>
              </View>

              {directSwapMode === 'direct' ? (
                <>
                  <Text style={[styles.fieldLabel, { marginTop: 14 }]}>Pilih Jadwal Karyawan Pengganti untuk Ditukar Balik</Text>
                  {!directTargetEmpId ? (
                    <Text style={styles.fieldHint}>Pilih karyawan pengganti di atas.</Text>
                  ) : directTargetSchedules.length === 0 ? (
                    <Text style={styles.fieldHint}>Karyawan pengganti tidak memiliki jadwal aktif lain untuk ditukar balik. Gunakan mode Pelimpahan Shift.</Text>
                  ) : (
                    <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.horizontalSelector}>
                      {directTargetSchedules.map((ass) => {
                        const selected = directTargetAssId === ass.id;
                        return (
                          <Pressable key={ass.id} onPress={() => { setDirectTargetAssId(ass.id); setDirectPreview(null); }} style={[styles.choiceChip, selected && styles.choiceChipActive]}>
                            <Text style={[styles.choiceChipTitle, selected && styles.choiceChipTitleActive]}>{localDate(ass.work_date)}</Text>
                            <Text style={[styles.choiceChipSub, selected && styles.choiceChipSubActive]}>{ass.template_name}</Text>
                          </Pressable>
                        );
                      })}
                    </ScrollView>
                  )}
                </>
              ) : (
                <>
                  <Text style={[styles.fieldLabel, { marginTop: 14 }]}>Tindak Lanjut Roster Karyawan Asal</Text>
                  <View style={styles.segmentRow}>
                    {(['roster_off', 'regular', 'day_off'] as const).map((opt) => (
                      <Pressable key={opt} onPress={() => { setDirectVacancyAction(opt); setDirectPreview(null); }} style={[styles.segment, directVacancyAction === opt && styles.segmentActive]}>
                        <Text style={[styles.segmentText, directVacancyAction === opt && styles.segmentTextActive]}>
                          {opt === 'roster_off' ? 'Roster Off' : opt === 'regular' ? 'Cuti Reguler' : 'Day Off'}
                        </Text>
                      </Pressable>
                    ))}
                  </View>
                </>
              )}

              <View style={styles.switchRow}>
                <Switch value={directCrossUnitConfirm} onValueChange={(v) => { setDirectCrossUnitConfirm(v); setDirectPreview(null); }} trackColor={{ false: '#C8D1CA', true: colors.primary }} />
                <Text style={styles.switchLabel}>Konfirmasi jika ada perpindahan lintas departemen</Text>
              </View>

              <TextField label="Alasan Pertukaran" value={directReason} onChangeText={setDirectReason} placeholder="Contoh: Penyesuaian operasional shift" multiline />

              {/* Validation & Preview section */}
              {directPreview && (
                <View style={styles.previewBox}>
                  <Text style={styles.previewTitle}>Hasil Validasi Roster:</Text>
                  {directPreview.moves.map((m, i) => (
                    <Text key={i} style={styles.previewMove}>✓ {m.work_date} ({m.shift}): {m.from_employee} → {m.to_employee}</Text>
                  ))}
                  {directPreview.vacancies.map((v, i) => (
                    <Text key={i} style={styles.previewVacancy}>• Roster kosong: {v.name} ({v.work_date})</Text>
                  ))}
                  {directPreview.blockers.map((b, i) => (
                    <Text key={i} style={styles.previewBlocker}>⚠️ {b.message}</Text>
                  ))}
                  {directPreview.cross_unit_confirmation_required && !directCrossUnitConfirm && (
                    <Text style={styles.previewBlocker}>⚠️ Wajib centang konfirmasi lintas departemen.</Text>
                  )}
                </View>
              )}

              {!!directError && <Text style={styles.errorText}>{directError}</Text>}

              <View style={styles.modalActions}>
                <Pressable disabled={directPreviewBusy} onPress={previewDirectSwap} style={styles.previewButton}>
                  <Text style={styles.previewButtonText}>{directPreviewBusy ? 'Memeriksa…' : 'Pratinjau & Validasi'}</Text>
                </Pressable>
                <Pressable
                  disabled={!directPreview?.can_commit || directCommitBusy}
                  onPress={commitDirectSwap}
                  style={[styles.confirmButton, (!directPreview?.can_commit || directCommitBusy) && styles.disabled]}
                >
                  <Text style={styles.confirmButtonText}>{directCommitBusy ? 'Menerapkan…' : 'Terapkan Pertukaran'}</Text>
                </Pressable>
              </View>
            </ScrollView>
          </View>
        </View>
      </Modal>

      {/* Modal Edit Data Mandiri */}
      <Modal visible={editProfileOpen} transparent animationType="slide" onRequestClose={() => setEditProfileOpen(false)}>
        <View style={styles.modalBackdrop}>
          <View style={[styles.modalCard, styles.modalCardLarge]}>
            <View style={styles.modalHeaderRow}>
              <View>
                <Text style={styles.modalEyebrow}>DATA MANDIRI</Text>
                <Text style={styles.modalTitle}>Perbarui Data Pribadi</Text>
              </View>
              <Pressable onPress={() => setEditProfileOpen(false)} style={styles.closeBtn}>
                <Ionicons name="close" size={22} color={colors.ink} />
              </Pressable>
            </View>

            <ScrollView contentContainerStyle={{ paddingBottom: 16 }} keyboardShouldPersistTaps="handled">
              <TextField label="Nama Lengkap" value={editName} onChangeText={setEditName} placeholder="Nama lengkap karyawan" />
              <TextField label="Nomor Telepon / WhatsApp" value={editPhone} onChangeText={setEditPhone} placeholder="contoh: 081234567890" keyboardType="phone-pad" />
              <TextField label="Email Pribadi" value={editEmail} onChangeText={setEditEmail} placeholder="contoh: nama@gmail.com" keyboardType="email-address" autoCapitalize="none" />

              <View style={{ marginTop: 14, paddingTop: 14, borderTopWidth: 1, borderTopColor: colors.line }}>
                <Text style={{ fontSize: 13, fontWeight: '800', color: colors.ink, marginBottom: 4 }}>Ganti Kata Sandi (Opsional)</Text>
                <Text style={styles.fieldHint}>Kosongkan jika tidak ingin mengubah kata sandi login.</Text>

                <TextField label="Kata Sandi Saat Ini" value={editCurrentPassword} onChangeText={setEditCurrentPassword} placeholder="Masukkan kata sandi lama" secureTextEntry autoCapitalize="none" />
                <TextField label="Kata Sandi Baru" value={editNewPassword} onChangeText={setEditNewPassword} placeholder="Minimal 8 karakter" secureTextEntry autoCapitalize="none" />
                <TextField label="Konfirmasi Kata Sandi Baru" value={editConfirmPassword} onChangeText={setEditConfirmPassword} placeholder="Ulangi kata sandi baru" secureTextEntry autoCapitalize="none" />
              </View>

              {!!editProfileError && <Text style={styles.errorText}>{editProfileError}</Text>}

              <View style={[styles.modalActions, { marginTop: 18 }]}>
                <Pressable onPress={() => setEditProfileOpen(false)} style={styles.cancelButton}>
                  <Text style={styles.cancelButtonText}>Batal</Text>
                </Pressable>
                <Pressable disabled={editProfileBusy} onPress={handleSaveProfile} style={[styles.confirmButton, editProfileBusy && styles.disabled]}>
                  <Text style={styles.confirmButtonText}>{editProfileBusy ? 'Menyimpan…' : 'Simpan Perubahan'}</Text>
                </Pressable>
              </View>
            </ScrollView>
          </View>
        </View>
      </Modal>
    </SafeAreaView>
  );
}

export default function App() {
  return (
    <SafeAreaProvider>
      <Main />
    </SafeAreaProvider>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: colors.canvas },
  app: { flex: 1 },
  flex: { flex: 1, minWidth: 0 },
  scrollContent: { paddingHorizontal: 20, paddingTop: 22, paddingBottom: 118 },
  loginContent: { flexGrow: 1, paddingHorizontal: 24, paddingTop: 36, paddingBottom: 140 },
  headingRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 },
  overline: { color: colors.muted, fontSize: 11, fontWeight: '800', letterSpacing: 1.1, marginBottom: 6 },
  heading: { color: colors.ink, fontSize: 32, fontWeight: '700', letterSpacing: -0.8 },
  loginHeading: { color: colors.ink, fontSize: 34, fontWeight: '700', letterSpacing: -1, marginBottom: 6 },
  intro: { color: colors.muted, fontSize: 13, lineHeight: 20, marginBottom: 20 },
  avatar: { width: 44, height: 44, borderRadius: 14, alignItems: 'center', justifyContent: 'center', backgroundColor: '#D2EEE8' },
  avatarText: { color: '#175449', fontSize: 15, fontWeight: '800' },
  iconButton: { width: 44, height: 44, borderRadius: 14, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: colors.line, backgroundColor: colors.surface },
  loginMark: { width: 48, height: 48, borderRadius: 16, alignItems: 'center', justifyContent: 'center', marginBottom: 22, backgroundColor: colors.primary },
  loginMarkText: { color: '#fff', fontSize: 23, fontWeight: '800' },
  networkBanner: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 13, marginBottom: 14, borderRadius: 12, backgroundColor: colors.successSoft },
  networkBannerOffline: { backgroundColor: colors.warningSoft },
  dot: { width: 9, height: 9, borderRadius: 5, backgroundColor: '#3D955D' },
  dotOffline: { backgroundColor: '#B46931' },
  networkTitle: { color: colors.ink, fontSize: 13, fontWeight: '700' },
  networkCopy: { color: colors.muted, fontSize: 11, marginTop: 2 },
  queueCount: { color: colors.warning, fontSize: 11, fontWeight: '800' },
  heroCard: { padding: 19, borderRadius: 18, backgroundColor: colors.primary },
  heroTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  heroLabel: { color: '#B7CBBE', fontSize: 10, fontWeight: '800', letterSpacing: 1 },
  heroTitle: { color: '#FFFFFF', fontSize: 20, fontWeight: '700', marginTop: 20 },
  heroTime: { color: colors.accent, fontSize: 24, fontWeight: '700', marginTop: 5 },
  heroMeta: { color: '#B7CBBE', fontSize: 12, marginTop: 8 },
  attendanceButton: { minHeight: 58, justifyContent: 'center', alignItems: 'center', marginTop: 20, borderRadius: 12, backgroundColor: colors.accent },
  attendanceButtonText: { color: colors.primary, fontSize: 15, fontWeight: '800' },
  attendanceHint: { color: '#40531C', fontSize: 11, marginTop: 3 },
  pressed: { opacity: 0.82, transform: [{ scale: 0.97 }] },
  disabled: { opacity: .55 },
  sectionHeading: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 23, marginBottom: 10 },
  sectionTitle: { color: colors.ink, fontSize: 17, fontWeight: '800', marginTop: 12, marginBottom: 6 },
  sectionMeta: { color: colors.muted, fontSize: 11, fontWeight: '700' },
  sessionCard: { padding: 14, marginTop: 9, borderWidth: 1, borderColor: colors.line, borderRadius: 14, backgroundColor: colors.surface },
  sessionDisabledWrap: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', padding: 11, marginTop: 10, borderRadius: 9, backgroundColor: '#F0F3F1' },
  sessionDisabledText: { color: colors.muted, fontSize: 11, fontWeight: '700' },
  sessionTop: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  sessionTitle: { color: colors.ink, fontSize: 13, fontWeight: '800' },
  mutedCopy: { color: colors.muted, fontSize: 11, lineHeight: 16, marginTop: 4 },
  verified: { color: '#27643C', fontSize: 11, fontWeight: '700', marginTop: 10 },
  pill: { paddingHorizontal: 8, paddingVertical: 5, borderRadius: 6, backgroundColor: colors.successSoft },
  pillWarning: { backgroundColor: colors.warningSoft },
  pillNeutral: { backgroundColor: '#EDF0ED' },
  pillBlue: { backgroundColor: colors.blueSoft },
  pillDanger: { backgroundColor: colors.dangerSoft },
  pillText: { color: '#22653C', fontSize: 10, fontWeight: '800' },
  pillWarningText: { color: colors.warning },
  pillNeutralText: { color: colors.muted },
  pillBlueText: { color: '#2F5D8C' },
  pillDangerText: { color: colors.danger },
  actionButton: { minHeight: 46, justifyContent: 'center', alignItems: 'center', marginTop: 12, borderRadius: 10, backgroundColor: colors.accent },
  actionButtonText: { color: colors.primary, fontSize: 13, fontWeight: '800' },
  actionHint: { color: '#40531C', fontSize: 10, marginTop: 2 },
  balanceCard: { flexDirection: 'row', alignItems: 'center', gap: 14, padding: 16, marginTop: 14, borderRadius: 14, backgroundColor: '#E9F0E8' },
  balanceStrip: { padding: 14, marginTop: 14, borderRadius: 12, backgroundColor: colors.successSoft },
  balanceDivider: { width: 1, alignSelf: 'stretch', backgroundColor: '#CBD8CC' },
  cardEyebrow: { color: colors.muted, fontSize: 10, fontWeight: '800', letterSpacing: .8, marginTop: 8 },
  balanceValue: { color: colors.primary, fontSize: 23, fontWeight: '800', marginTop: 4 },
  balanceUnit: { fontSize: 12 },
  cardTitle: { color: colors.ink, fontSize: 13, fontWeight: '800' },
  cardCopy: { color: colors.muted, fontSize: 11, lineHeight: 16, marginTop: 4 },
  notice: { padding: 16, marginTop: 14, borderRadius: 14, backgroundColor: colors.blueSoft },
  noticeTitle: { color: '#2F5D8C', fontSize: 11, fontWeight: '800' },
  noticeValue: { color: colors.ink, fontSize: 25, fontWeight: '800', marginTop: 4 },
  emptyCard: { padding: 16, marginTop: 10, borderRadius: 14, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.surface },
  assignmentCard: { padding: 13, marginTop: 13, borderWidth: 1, borderColor: colors.line, borderRadius: 15, backgroundColor: colors.surface },
  assignmentHeading: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 2, paddingBottom: 4 },
  dateText: { color: colors.muted, fontSize: 11, fontWeight: '700', marginBottom: 4 },
  employeeTag: { color: colors.primary, fontSize: 11, fontWeight: '800', marginTop: 2 },
  scheduleActionRow: { flexDirection: 'row', justifyContent: 'flex-end', marginTop: 4, marginBottom: 8 },
  swapQuickBtn: { flexDirection: 'row', alignItems: 'center', gap: 4, paddingHorizontal: 10, paddingVertical: 6, borderRadius: 8, backgroundColor: colors.successSoft },
  swapQuickText: { color: colors.primary, fontSize: 11, fontWeight: '800' },
  listCard: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 13, marginTop: 9, borderWidth: 1, borderColor: colors.line, borderRadius: 12, backgroundColor: colors.surface },
  formCard: { padding: 16, marginTop: 14, borderWidth: 1, borderColor: colors.line, borderRadius: 15, backgroundColor: colors.surface },
  field: { gap: 6, marginTop: 12 },
  fieldLabel: { color: colors.muted, fontSize: 11, fontWeight: '800' },
  fieldHint: { color: colors.muted, fontSize: 11, fontStyle: 'italic', marginTop: 4 },
  inputWrapper: { position: 'relative', justifyContent: 'center' },
  input: { minHeight: 48, paddingHorizontal: 12, borderWidth: 1, borderColor: '#C8D1CA', borderRadius: 9, color: colors.ink, fontSize: 13, backgroundColor: '#fff' },
  inputWithIcon: { paddingRight: 48 },
  inputEyeIcon: { position: 'absolute', right: 0, top: 0, bottom: 0, width: 48, justifyContent: 'center', alignItems: 'center', zIndex: 10 },
  multiline: { minHeight: 84, paddingTop: 10, textAlignVertical: 'top' },
  segmentRow: { flexDirection: 'row', gap: 7, marginTop: 12 },
  segment: { minHeight: 42, flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 8, borderWidth: 1, borderColor: colors.line, borderRadius: 9, backgroundColor: '#fff' },
  segmentActive: { borderColor: colors.primary, backgroundColor: colors.primary },
  segmentText: { color: colors.muted, fontSize: 11, fontWeight: '700' },
  segmentTextActive: { color: '#fff' },
  choiceCard: { padding: 11, marginTop: 7, borderWidth: 1, borderColor: colors.line, borderRadius: 10, backgroundColor: '#fff' },
  choiceCardActive: { borderColor: colors.primary, backgroundColor: colors.successSoft },
  horizontalSelector: { flexDirection: 'row', marginTop: 8 },
  choiceChip: { paddingHorizontal: 14, paddingVertical: 10, marginRight: 8, borderWidth: 1, borderColor: colors.line, borderRadius: 12, backgroundColor: '#fff' },
  choiceChipActive: { borderColor: colors.primary, backgroundColor: colors.primary },
  choiceChipTitle: { color: colors.ink, fontSize: 12, fontWeight: '800' },
  choiceChipTitleActive: { color: '#fff' },
  choiceChipSub: { color: colors.muted, fontSize: 10, marginTop: 2 },
  choiceChipSubActive: { color: '#DCE7E1' },
  attachButton: { minHeight: 44, justifyContent: 'center', paddingHorizontal: 12, marginTop: 12, borderWidth: 1, borderStyle: 'dashed', borderColor: '#AAB8AD', borderRadius: 9 },
  attachText: { color: colors.primary, fontSize: 11, fontWeight: '700' },
  requiredHint: { color: colors.warning, fontSize: 11, marginTop: 6 },
  leaveDurationText: { color: colors.primary, fontSize: 12, fontWeight: '700', marginTop: 8 },
  linkText: { color: colors.primary, fontSize: 11, fontWeight: '800' },
  smallButton: { minHeight: 40, justifyContent: 'center', alignItems: 'center', paddingHorizontal: 10, borderRadius: 8, backgroundColor: colors.successSoft },
  smallButtonText: { color: colors.primary, fontSize: 10, fontWeight: '800' },
  profileCard: { alignItems: 'center', padding: 22, marginTop: 16, borderWidth: 1, borderColor: colors.line, borderRadius: 16, backgroundColor: colors.surface },
  avatarWrapper: { position: 'relative', width: 76, height: 76, alignItems: 'center', justifyContent: 'center' },
  avatarLargeImage: { width: 76, height: 76, borderRadius: 26, borderWidth: 2, borderColor: colors.primary, backgroundColor: '#D2EEE8' },
  avatarBadgeBtn: { position: 'absolute', bottom: -2, right: -2, width: 28, height: 28, borderRadius: 14, backgroundColor: colors.primary, alignItems: 'center', justifyContent: 'center', borderWidth: 2, borderColor: '#fff' },
  avatarActionsRow: { flexDirection: 'row', gap: 8, marginTop: 10 },
  avatarActionBtn: { flexDirection: 'row', alignItems: 'center', gap: 4, paddingHorizontal: 10, paddingVertical: 6, borderRadius: 8, backgroundColor: '#EDF2EE' },
  avatarActionBtnText: { color: colors.ink, fontSize: 11, fontWeight: '700' },
  avatarDeleteBtn: { paddingHorizontal: 8, paddingVertical: 6, borderRadius: 8, backgroundColor: colors.dangerSoft },
  avatarDeleteBtnText: { color: colors.danger, fontSize: 11, fontWeight: '700' },
  avatarLarge: { width: 76, height: 76, borderRadius: 26, alignItems: 'center', justifyContent: 'center', backgroundColor: '#D2EEE8' },
  avatarLargeText: { color: '#175449', fontSize: 24, fontWeight: '800' },
  profileName: { color: colors.ink, fontSize: 20, fontWeight: '800', marginTop: 12 },
  profileMetaBox: { marginTop: 14, paddingTop: 12, borderTopWidth: 1, borderTopColor: colors.line, width: '100%', gap: 8 },
  profileMetaRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  profileMetaLabel: { fontSize: 12, color: colors.muted, fontWeight: '600', width: 92 },
  profileMetaValue: { fontSize: 13, color: colors.ink, fontWeight: '600', flex: 1 },
  settingsCard: { padding: 16, marginTop: 13, borderWidth: 1, borderColor: colors.line, borderRadius: 15, backgroundColor: colors.surface },
  primaryActionButton: { minHeight: 46, flexDirection: 'row', gap: 6, justifyContent: 'center', alignItems: 'center', marginTop: 12, borderRadius: 10, backgroundColor: colors.primary },
  primaryActionButtonText: { color: '#fff', fontSize: 12, fontWeight: '800' },
  secondaryButton: { minHeight: 44, justifyContent: 'center', alignItems: 'center', marginTop: 12, borderWidth: 1, borderColor: colors.line, borderRadius: 9 },
  secondaryButtonText: { color: colors.ink, fontSize: 12, fontWeight: '800' },
  logoutButton: { minHeight: 44, justifyContent: 'center', alignItems: 'center', marginTop: 8, borderRadius: 9, backgroundColor: colors.warningSoft },
  logoutText: { color: colors.warning, fontSize: 12, fontWeight: '800' },
  errorText: { color: '#A51F18', fontSize: 12, lineHeight: 17, marginTop: 10 },
  statRow: { flexDirection: 'row', gap: 12, marginTop: 14 },
  statCard: { flex: 1, padding: 16, borderRadius: 14, borderWidth: 1, borderColor: colors.line, backgroundColor: colors.surface },
  statLabel: { color: colors.muted, fontSize: 11, fontWeight: '800' },
  statValue: { color: colors.ink, fontSize: 28, fontWeight: '800', marginTop: 6 },
  statSub: { color: colors.muted, fontSize: 10, marginTop: 2 },
  menuRow: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14, marginTop: 9, borderWidth: 1, borderColor: colors.line, borderRadius: 12, backgroundColor: colors.surface },
  adminAlertCard: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 14, marginBottom: 14, borderWidth: 1, borderColor: '#F2D7B3', borderRadius: 14, backgroundColor: colors.warningSoft },
  adminAlertTitle: { color: colors.warning, fontSize: 13, fontWeight: '800' },
  adminBar: { marginTop: 4, marginBottom: 12 },
  empFilterScroll: { flexDirection: 'row', marginTop: 8 },
  filterChip: { paddingHorizontal: 12, paddingVertical: 8, marginRight: 8, borderWidth: 1, borderColor: colors.line, borderRadius: 10, backgroundColor: colors.surface },
  filterChipActive: { borderColor: colors.primary, backgroundColor: colors.primary },
  filterChipText: { color: colors.muted, fontSize: 11, fontWeight: '700' },
  filterChipTextActive: { color: '#fff' },
  viewSwitcher: { flexDirection: 'row', gap: 8, marginTop: 6, marginBottom: 14 },
  switcherBtn: { flex: 1, minHeight: 44, justifyContent: 'center', alignItems: 'center', borderWidth: 1, borderColor: colors.line, borderRadius: 12, backgroundColor: colors.surface },
  switcherBtnActive: { borderColor: colors.primary, backgroundColor: colors.primary },
  switcherBtnText: { color: colors.muted, fontSize: 12, fontWeight: '800' },
  switcherBtnTextActive: { color: '#fff' },
  adminApprovalSection: { marginTop: 4 },
  adminBarHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 },
  directSwapActionBtn: { flexDirection: 'row', alignItems: 'center', gap: 4, paddingHorizontal: 12, paddingVertical: 8, borderRadius: 10, backgroundColor: colors.primary },
  directSwapActionText: { color: '#fff', fontSize: 11, fontWeight: '800' },
  exchangeCard: { padding: 14, marginTop: 12, borderWidth: 1, borderColor: colors.line, borderRadius: 14, backgroundColor: colors.surface },
  exchangeHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: colors.line, paddingBottom: 8 },
  exchangeDate: { color: colors.muted, fontSize: 11, fontWeight: '700' },
  exchangeShift: { color: colors.ink, fontSize: 13, fontWeight: '800', marginTop: 2 },
  exchangeRoute: { flexDirection: 'row', alignItems: 'center', paddingVertical: 10 },
  empName: { color: colors.ink, fontSize: 13, fontWeight: '800', marginTop: 2 },
  exchangeMetaBox: { padding: 10, borderRadius: 9, backgroundColor: colors.canvas, gap: 4, marginTop: 4 },
  metaLabel: { color: colors.muted, fontSize: 11, fontWeight: '700' },
  metaValue: { color: colors.ink, fontWeight: '800' },
  metaDecision: { color: colors.primary, fontSize: 11, fontWeight: '800', marginTop: 2 },
  exchangeBtnRow: { flexDirection: 'row', gap: 8, marginTop: 12 },
  approveBtn: { flex: 1.5, minHeight: 42, justifyContent: 'center', alignItems: 'center', borderRadius: 9, backgroundColor: colors.primary },
  approveBtnText: { color: '#fff', fontSize: 12, fontWeight: '800' },
  rejectBtn: { flex: 1, minHeight: 42, justifyContent: 'center', alignItems: 'center', borderRadius: 9, backgroundColor: colors.dangerSoft },
  rejectBtnText: { color: colors.danger, fontSize: 12, fontWeight: '800' },
  tabBar: {
    position: 'absolute', left: 12, right: 12, bottom: 8, minHeight: 68,
    flexDirection: 'row', paddingHorizontal: 6, paddingVertical: 6,
    borderWidth: 1, borderColor: colors.line, borderRadius: 18,
    backgroundColor: '#FFFFFF', shadowColor: '#000',
    shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.08, shadowRadius: 10, elevation: 4,
  },
  tab: { flex: 1, minHeight: 54, alignItems: 'center', justifyContent: 'center', gap: 3, borderRadius: 12 },
  tabIndicator: { width: 16, height: 2.5, borderRadius: 2, backgroundColor: 'transparent', marginTop: 1 },
  tabIndicatorActive: { backgroundColor: colors.primary },
  tabText: { color: colors.muted, fontSize: 10, fontWeight: '700' },
  tabTextActive: { color: colors.primary, fontWeight: '800' },
  modalBackdrop: { flex: 1, justifyContent: 'center', padding: 20, backgroundColor: 'rgba(10,25,17,.55)' },
  modalCard: { padding: 21, borderRadius: 18, backgroundColor: '#fff', maxHeight: '90%' },
  modalCardLarge: { maxHeight: '92%', paddingVertical: 20 },
  modalHeaderRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 12 },
  modalScroll: { flexGrow: 0 },
  closeBtn: { padding: 4 },
  modalEyebrow: { color: colors.muted, fontSize: 10, fontWeight: '800', letterSpacing: .8 },
  modalTitle: { color: colors.ink, fontSize: 20, fontWeight: '800', marginTop: 4 },
  modalCopy: { color: colors.muted, fontSize: 12, lineHeight: 18, marginTop: 6 },
  offlineWarning: { padding: 11, marginTop: 12, borderRadius: 9, backgroundColor: colors.warningSoft },
  offlineWarningText: { color: '#70441F', fontSize: 11, lineHeight: 16 },
  switchRow: { flexDirection: 'row', alignItems: 'center', gap: 10, marginTop: 14, paddingVertical: 4 },
  switchLabel: { flex: 1, color: colors.ink, fontSize: 11, fontWeight: '700' },
  previewBox: { padding: 12, marginTop: 14, borderRadius: 12, borderWidth: 1, borderColor: colors.line, backgroundColor: '#F8FAF8' },
  previewTitle: { color: colors.ink, fontSize: 12, fontWeight: '800', marginBottom: 6 },
  previewMove: { color: '#1B5E20', fontSize: 11, fontWeight: '700', marginVertical: 2 },
  previewVacancy: { color: colors.warning, fontSize: 11, marginVertical: 2 },
  previewBlocker: { color: colors.danger, fontSize: 11, fontWeight: '700', marginVertical: 2 },
  modalActions: { flexDirection: 'row', gap: 9, marginTop: 18 },
  cancelButton: { minHeight: 48, flex: 1, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: colors.line, borderRadius: 10 },
  cancelButtonText: { color: colors.ink, fontWeight: '800', fontSize: 12 },
  confirmButton: { minHeight: 48, flex: 1.3, alignItems: 'center', justifyContent: 'center', borderRadius: 10, backgroundColor: colors.primary },
  confirmButtonText: { color: '#fff', fontWeight: '800', fontSize: 12 },
  previewButton: { minHeight: 48, flex: 1, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: colors.line, borderRadius: 10, backgroundColor: colors.surface },
  previewButtonText: { color: colors.ink, fontWeight: '800', fontSize: 12 },
});
