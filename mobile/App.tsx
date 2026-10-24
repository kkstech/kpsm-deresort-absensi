import { useState } from 'react';
import {
  Alert,
  Modal,
  Platform,
  Pressable,
  SafeAreaView,
  ScrollView,
  StatusBar,
  StyleSheet,
  Switch,
  Text as NativeText,
  type TextProps,
  View,
} from 'react-native';

type Tab = 'Hari ini' | 'Jadwal' | 'Pengajuan' | 'Profil';
type AttendanceState = 'ready' | 'checked-in' | 'queued';

const colors = {
  ink: '#142019', muted: '#657168', line: '#DDE4DE', canvas: '#F2F5F1', surface: '#FFFFFF',
  primary: '#173D2B', accent: '#D9F25F', successSoft: '#E6F1E9', warning: '#A65718', warningSoft: '#FFF1DC',
};

const Text = ({ style, ...props }: TextProps) => {
  const weight = String(StyleSheet.flatten(style)?.fontWeight ?? '400');
  const androidWeight = weight === 'bold' ? '700' : weight === '900' ? '800' : ['500', '600', '700', '800'].includes(weight) ? weight : '400';
  const family = Platform.OS === 'android' ? `plus-jakarta-${androidWeight}` : 'Plus Jakarta Sans';
  return <NativeText {...props} style={[{ fontFamily: family }, style]} />;
};

function StatusPill({ children, tone = 'success' }: { children: React.ReactNode; tone?: 'success' | 'warning' | 'neutral' }) {
  return <View style={[styles.pill, tone === 'warning' && styles.pillWarning, tone === 'neutral' && styles.pillNeutral]}><Text style={[styles.pillText, tone === 'warning' && styles.pillWarningText]}>{children}</Text></View>;
}

function Today({ online, state, onAttendance }: { online: boolean; state: AttendanceState; onAttendance: () => void }) {
  const actionLabel = state === 'ready' ? 'Check-in sesi 2' : state === 'checked-in' ? 'Check-out sesi 2' : 'Tersimpan di perangkat';
  return <ScrollView contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
    <View style={styles.headingRow}>
      <View><Text style={styles.overline}>SABTU, 24 OKTOBER</Text><Text style={styles.heading}>Halo, Nadia</Text></View>
      <View style={styles.avatar}><Text style={styles.avatarText}>NA</Text></View>
    </View>
    <View style={[styles.networkBanner, !online && styles.networkBannerOffline]}>
      <View style={[styles.dot, !online && styles.dotOffline]} />
      <View style={styles.flex}><Text style={styles.networkTitle}>{online ? 'Terhubung ke server lokal' : 'Mode offline'}</Text><Text style={styles.networkCopy}>{online ? 'Jadwal diperbarui 2 menit lalu' : 'Absensi akan dikirim saat tersambung'}</Text></View>
      {!online && <Text style={styles.queueCount}>1 antrean</Text>}
    </View>
    <View style={styles.heroCard}>
      <View style={styles.heroTop}><Text style={styles.heroLabel}>SESI BERIKUTNYA</Text><StatusPill tone={state === 'queued' ? 'warning' : 'success'}>{state === 'queued' ? 'Belum sinkron' : 'Siap absen'}</StatusPill></View>
      <Text style={styles.heroTitle}>Shift Khusus · Sesi 2</Text>
      <Text style={styles.heroTime}>12.00 – 15.00</Text>
      <View style={styles.locationRow}><Text style={styles.locationText}>Front Office · Tanpa validasi GPS</Text></View>
      <Pressable accessibilityRole="button" disabled={state === 'queued'} onPress={onAttendance} style={({ pressed }) => [styles.attendanceButton, pressed && styles.pressed, state === 'queued' && styles.disabled]}>
        <Text style={styles.attendanceButtonText}>{actionLabel}</Text>
        <Text style={styles.attendanceHint}>{state === 'ready' ? 'Dibuka 11.45–12.30 WIB' : state === 'checked-in' ? 'Masuk tercatat pukul 11.56' : 'Menunggu koneksi'}</Text>
      </Pressable>
    </View>
    <View style={styles.sectionHeading}><Text style={styles.sectionTitle}>Timeline hari ini</Text><Text style={styles.sectionMeta}>2 dari 3 sesi</Text></View>
    <View style={styles.timelineCard}>
      <View style={styles.timelineItem}><View style={styles.timelineMarkerDone}><Text style={styles.check}>✓</Text></View><View style={styles.flex}><Text style={styles.timelineTitle}>Sesi 1 · 06.00–09.00</Text><Text style={styles.timelineCopy}>05.54 masuk · 09.02 keluar</Text></View><StatusPill>Selesai</StatusPill></View>
      <View style={styles.timelineLine} />
      <View style={styles.timelineItem}><View style={styles.timelineMarkerActive} /><View style={styles.flex}><Text style={styles.timelineTitle}>Sesi 2 · 12.00–15.00</Text><Text style={styles.timelineCopy}>{state === 'ready' ? 'Check-in tersedia sekarang' : 'Check-in 11.56 · sedang berlangsung'}</Text></View><StatusPill tone="warning">Aktif</StatusPill></View>
      <View style={styles.timelineLineMuted} />
      <View style={styles.timelineItem}><View style={styles.timelineMarker} /><View style={styles.flex}><Text style={styles.timelineTitle}>Sesi 3 · 18.00–21.00</Text><Text style={styles.timelineCopy}>Check-in dibuka pukul 17.45</Text></View><StatusPill tone="neutral">Nanti</StatusPill></View>
    </View>
    <View style={styles.notice}><Text style={styles.noticeTitle}>Jam valid hari ini</Text><Text style={styles.noticeValue}>3j 08m</Text><Text style={styles.noticeCopy}>Hanya sesi yang sudah lengkap dan tervalidasi.</Text></View>
  </ScrollView>;
}

function Schedule() {
  return <ScrollView contentContainerStyle={styles.scrollContent}><Text style={styles.overline}>JADWAL KERJA</Text><Text style={styles.heading}>7 hari ke depan</Text>{[
    ['Sab, 24 Okt', 'Shift Khusus', '3 sesi · 06.00–21.00', 'Hari ini'],
    ['Min, 25 Okt', 'Libur', 'Tidak ada sesi', 'Libur'],
    ['Sen, 26 Okt', 'Reguler', '08.00–17.00', 'Terbit'],
    ['Sel, 27 Okt', 'Malam', '22.00–06.00 (+1)', 'Terbit'],
  ].map(([date, shift, time, status]) => <View style={styles.listCard} key={date}><Text style={styles.listDate}>{date}</Text><View style={styles.flex}><Text style={styles.timelineTitle}>{shift}</Text><Text style={styles.timelineCopy}>{time}</Text></View><StatusPill tone={status === 'Libur' ? 'neutral' : 'success'}>{status}</StatusPill></View>)}</ScrollView>;
}

function Requests() {
  return <ScrollView contentContainerStyle={styles.scrollContent}><Text style={styles.overline}>LAYANAN MANDIRI</Text><Text style={styles.heading}>Pengajuan</Text><View style={styles.quickGrid}><Pressable style={styles.quickAction}><Text style={styles.quickTitle}>Izin terlambat</Text><Text style={styles.quickCopy}>Ajukan target hadir atau durasi izin.</Text></Pressable><Pressable style={styles.quickAction}><Text style={styles.quickTitle}>Koreksi absensi</Text><Text style={styles.quickCopy}>Laporkan waktu masuk atau keluar.</Text></Pressable></View><Text style={styles.sectionTitle}>Status terbaru</Text><View style={styles.listCard}><View style={styles.flex}><Text style={styles.timelineTitle}>Izin terlambat</Text><Text style={styles.timelineCopy}>21 Okt · target hadir 08.30</Text></View><StatusPill>Disetujui</StatusPill></View><View style={styles.listCard}><View style={styles.flex}><Text style={styles.timelineTitle}>Koreksi checkout</Text><Text style={styles.timelineCopy}>18 Okt · checkout 17.06</Text></View><StatusPill tone="warning">Ditinjau</StatusPill></View><View style={styles.consentCard}><Text style={styles.consentLabel}>PERINTAH LEMBUR</Text><Text style={styles.consentTitle}>Senin, 26 Oktober · 18.00–20.00</Text><Text style={styles.consentCopy}>Perintah sudah disetujui Admin dan menunggu persetujuan digital Anda. Persetujuan membutuhkan koneksi server.</Text><Pressable style={styles.secondaryButton}><Text style={styles.secondaryButtonText}>Lihat detail perintah</Text></Pressable></View></ScrollView>;
}

function Profile({ online, setOnline }: { online: boolean; setOnline: (value: boolean) => void }) {
  return <ScrollView contentContainerStyle={styles.scrollContent}><Text style={styles.overline}>AKUN KARYAWAN</Text><Text style={styles.heading}>Profil</Text><View style={styles.profileCard}><View style={styles.avatarLarge}><Text style={styles.avatarLargeText}>NA</Text></View><Text style={styles.profileName}>Nadia Anjani</Text><Text style={styles.timelineCopy}>EMP-017 · Front Office</Text></View><View style={styles.settingsCard}><View style={styles.settingRow}><View style={styles.flex}><Text style={styles.timelineTitle}>Simulasi koneksi</Text><Text style={styles.timelineCopy}>Matikan untuk menguji antrean offline.</Text></View><Switch value={online} onValueChange={setOnline} trackColor={{ false: '#B46B36', true: '#7BA68B' }} thumbColor="#FFFFFF" accessibilityLabel="Simulasi koneksi server" /></View><View style={styles.settingRow}><View style={styles.flex}><Text style={styles.timelineTitle}>Perangkat ini</Text><Text style={styles.timelineCopy}>Demo frontend · belum terdaftar</Text></View></View></View></ScrollView>;
}

export default function App() {
  const [tab, setTab] = useState<Tab>('Hari ini');
  const [online, setOnline] = useState(true);
  const [attendanceState, setAttendanceState] = useState<AttendanceState>('ready');
  const [confirmVisible, setConfirmVisible] = useState(false);

  const confirmAttendance = () => {
    setConfirmVisible(false);
    if (!online) {
      setAttendanceState('queued');
      Alert.alert('Tersimpan di perangkat', 'Event akan disinkronkan dengan UUID yang sama setelah koneksi kembali.');
      return;
    }
    setAttendanceState(attendanceState === 'ready' ? 'checked-in' : 'ready');
    Alert.alert('Absensi tercatat', attendanceState === 'ready' ? 'Check-in sesi 2 berhasil dicatat.' : 'Check-out sesi 2 berhasil dicatat.');
  };

  return <SafeAreaView style={styles.safeArea}>
    <StatusBar barStyle="dark-content" backgroundColor={colors.canvas} />
    <View style={styles.app}>
      {tab === 'Hari ini' && <Today online={online} state={attendanceState} onAttendance={() => setConfirmVisible(true)} />}
      {tab === 'Jadwal' && <Schedule />}{tab === 'Pengajuan' && <Requests />}{tab === 'Profil' && <Profile online={online} setOnline={setOnline} />}
      <View style={styles.tabBar}>{(['Hari ini', 'Jadwal', 'Pengajuan', 'Profil'] as Tab[]).map((item) => <Pressable key={item} accessibilityRole="tab" accessibilityState={{ selected: tab === item }} onPress={() => setTab(item)} style={({ pressed }) => [styles.tab, pressed && styles.pressed]}><View style={[styles.tabIndicator, tab === item && styles.tabIndicatorActive]} /><Text style={[styles.tabText, tab === item && styles.tabTextActive]}>{item}</Text></Pressable>)}</View>
    </View>
    <Modal visible={confirmVisible} transparent animationType="fade" onRequestClose={() => setConfirmVisible(false)}>
      <View style={styles.modalBackdrop}><View style={styles.modalCard}><Text style={styles.modalTitle}>Konfirmasi {attendanceState === 'ready' ? 'check-in' : 'check-out'}</Text><Text style={styles.modalCopy}>Shift Khusus · Sesi 2. Waktu efektif akan ditentukan server saat online.</Text>{!online && <View style={styles.offlineWarning}><Text style={styles.offlineWarningText}>Anda sedang offline. Timestamp perangkat hanya menjadi metadata dan event dapat masuk review.</Text></View>}<View style={styles.modalActions}><Pressable onPress={() => setConfirmVisible(false)} style={styles.cancelButton}><Text style={styles.cancelButtonText}>Batal</Text></Pressable><Pressable onPress={confirmAttendance} style={styles.confirmButton}><Text style={styles.confirmButtonText}>Ya, catat</Text></Pressable></View></View></View>
    </Modal>
  </SafeAreaView>;
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: colors.canvas }, app: { flex: 1 }, flex: { flex: 1 }, scrollContent: { paddingHorizontal: 20, paddingTop: 22, paddingBottom: 112 },
  headingRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 22 }, overline: { color: colors.muted, fontSize: 11, fontWeight: '800', letterSpacing: 1.1, marginBottom: 5 }, heading: { color: colors.ink, fontSize: 34, fontWeight: '600', letterSpacing: -1.2 },
  avatar: { width: 44, height: 44, borderRadius: 13, alignItems: 'center', justifyContent: 'center', backgroundColor: '#D2EEE8' }, avatarText: { color: '#175449', fontWeight: '800' },
  networkBanner: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 13, marginBottom: 14, borderRadius: 12, backgroundColor: colors.successSoft }, networkBannerOffline: { backgroundColor: colors.warningSoft }, dot: { width: 9, height: 9, borderRadius: 5, backgroundColor: '#3D955D' }, dotOffline: { backgroundColor: '#B46931' }, networkTitle: { color: colors.ink, fontSize: 12, fontWeight: '700' }, networkCopy: { color: colors.muted, fontSize: 10, marginTop: 2 }, queueCount: { color: colors.warning, fontSize: 10, fontWeight: '800' },
  heroCard: { padding: 20, borderRadius: 18, backgroundColor: colors.primary }, heroTop: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }, heroLabel: { color: '#B7CBBE', fontSize: 10, fontWeight: '800', letterSpacing: 1 }, heroTitle: { color: '#FFFFFF', fontSize: 17, fontWeight: '700', marginTop: 24 }, heroTime: { color: colors.accent, fontSize: 34, fontWeight: '700', letterSpacing: -1, marginTop: 4, fontVariant: ['tabular-nums'] }, locationRow: { marginTop: 8 }, locationText: { color: '#B7CBBE', fontSize: 11 }, attendanceButton: { minHeight: 64, justifyContent: 'center', alignItems: 'center', marginTop: 24, borderRadius: 13, backgroundColor: colors.accent }, attendanceButtonText: { color: colors.primary, fontSize: 16, fontWeight: '800' }, attendanceHint: { color: '#40531C', fontSize: 10, marginTop: 3 }, pressed: { opacity: .74 }, disabled: { opacity: .66 },
  sectionHeading: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 26, marginBottom: 12 }, sectionTitle: { color: colors.ink, fontSize: 17, fontWeight: '800', marginTop: 24, marginBottom: 12 }, sectionMeta: { color: colors.muted, fontSize: 11, fontWeight: '700' },
  timelineCard: { paddingHorizontal: 16, borderWidth: 1, borderColor: colors.line, borderRadius: 15, backgroundColor: colors.surface }, timelineItem: { minHeight: 80, flexDirection: 'row', alignItems: 'center', gap: 12 }, timelineMarkerDone: { width: 23, height: 23, borderRadius: 12, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.primary }, check: { color: '#FFFFFF', fontSize: 12, fontWeight: '900' }, timelineMarkerActive: { width: 14, height: 14, marginHorizontal: 4, borderRadius: 7, backgroundColor: colors.accent, borderWidth: 3, borderColor: colors.primary }, timelineMarker: { width: 12, height: 12, marginHorizontal: 5, borderRadius: 6, borderWidth: 2, borderColor: '#A9B4AC' }, timelineLine: { position: 'absolute', left: 27, top: 52, height: 56, width: 2, backgroundColor: colors.primary }, timelineLineMuted: { position: 'absolute', left: 27, top: 132, height: 56, width: 2, backgroundColor: '#D8DEDA' }, timelineTitle: { color: colors.ink, fontSize: 13, fontWeight: '700' }, timelineCopy: { color: colors.muted, fontSize: 11, lineHeight: 16, marginTop: 4 },
  pill: { paddingHorizontal: 8, paddingVertical: 5, borderRadius: 6, backgroundColor: colors.successSoft }, pillWarning: { backgroundColor: colors.warningSoft }, pillNeutral: { backgroundColor: '#EDF0ED' }, pillText: { color: '#22653C', fontSize: 9, fontWeight: '800' }, pillWarningText: { color: colors.warning },
  notice: { marginTop: 14, padding: 17, borderRadius: 14, backgroundColor: '#E8F1FB' }, noticeTitle: { color: '#2F5D8C', fontSize: 11, fontWeight: '800' }, noticeValue: { color: colors.ink, fontSize: 25, fontWeight: '700', marginTop: 5, fontVariant: ['tabular-nums'] }, noticeCopy: { color: colors.muted, fontSize: 10, marginTop: 4 },
  listCard: { minHeight: 76, flexDirection: 'row', alignItems: 'center', gap: 14, paddingHorizontal: 16, marginTop: 10, borderWidth: 1, borderColor: colors.line, borderRadius: 13, backgroundColor: colors.surface }, listDate: { width: 72, color: colors.ink, fontSize: 11, fontWeight: '800' }, quickGrid: { flexDirection: 'row', gap: 10, marginTop: 24 }, quickAction: { flex: 1, minHeight: 128, padding: 16, borderRadius: 14, backgroundColor: colors.primary }, quickTitle: { color: '#FFFFFF', fontSize: 14, fontWeight: '800' }, quickCopy: { color: '#BCD0C2', fontSize: 11, lineHeight: 16, marginTop: 8 }, consentCard: { marginTop: 22, padding: 18, borderRadius: 15, backgroundColor: colors.warningSoft }, consentLabel: { color: colors.warning, fontSize: 10, fontWeight: '900', letterSpacing: 1 }, consentTitle: { color: colors.ink, fontSize: 16, fontWeight: '800', marginTop: 10 }, consentCopy: { color: '#66584B', fontSize: 11, lineHeight: 17, marginTop: 7 }, secondaryButton: { minHeight: 46, alignItems: 'center', justifyContent: 'center', marginTop: 16, borderWidth: 1, borderColor: '#C98B5B', borderRadius: 10 }, secondaryButtonText: { color: '#754018', fontSize: 12, fontWeight: '800' },
  profileCard: { alignItems: 'center', padding: 24, marginTop: 22, borderWidth: 1, borderColor: colors.line, borderRadius: 16, backgroundColor: colors.surface }, avatarLarge: { width: 72, height: 72, borderRadius: 24, alignItems: 'center', justifyContent: 'center', backgroundColor: '#D2EEE8' }, avatarLargeText: { color: '#175449', fontSize: 22, fontWeight: '800' }, profileName: { color: colors.ink, fontSize: 20, fontWeight: '800', marginTop: 13 }, settingsCard: { marginTop: 14, paddingHorizontal: 16, borderWidth: 1, borderColor: colors.line, borderRadius: 15, backgroundColor: colors.surface }, settingRow: { minHeight: 76, flexDirection: 'row', alignItems: 'center', gap: 12, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.line },
  tabBar: { position: 'absolute', left: 12, right: 12, bottom: 10, minHeight: 68, flexDirection: 'row', padding: 6, borderWidth: 1, borderColor: colors.line, borderRadius: 18, backgroundColor: '#FFFFFF' }, tab: { flex: 1, minHeight: 54, alignItems: 'center', justifyContent: 'center', gap: 7, borderRadius: 13 }, tabIndicator: { width: 20, height: 3, borderRadius: 2, backgroundColor: 'transparent' }, tabIndicatorActive: { backgroundColor: colors.primary }, tabText: { color: colors.muted, fontSize: 10, fontWeight: '700' }, tabTextActive: { color: colors.primary },
  modalBackdrop: { flex: 1, justifyContent: 'flex-end', padding: 14, backgroundColor: 'rgba(10, 25, 17, .58)' }, modalCard: { padding: 22, paddingBottom: 28, borderRadius: 20, backgroundColor: '#FFFFFF' }, modalTitle: { color: colors.ink, fontSize: 22, fontWeight: '800' }, modalCopy: { color: colors.muted, fontSize: 13, lineHeight: 19, marginTop: 8 }, offlineWarning: { padding: 12, marginTop: 14, borderRadius: 10, backgroundColor: colors.warningSoft }, offlineWarningText: { color: '#70441F', fontSize: 11, lineHeight: 16 }, modalActions: { flexDirection: 'row', gap: 10, marginTop: 20 }, cancelButton: { flex: 1, minHeight: 50, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: colors.line, borderRadius: 11 }, cancelButtonText: { color: colors.ink, fontWeight: '800' }, confirmButton: { flex: 1.35, minHeight: 50, alignItems: 'center', justifyContent: 'center', borderRadius: 11, backgroundColor: colors.primary }, confirmButtonText: { color: '#FFFFFF', fontWeight: '800' },
});
