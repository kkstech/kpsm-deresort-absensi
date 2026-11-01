<?php

namespace App\Actions;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLevel;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BulkImportEmployees
{
    public const HEADER_SYNONYMS = [
        'code' => ['kode_karyawan', 'kode', 'code', 'nik', 'no_induk'],
        'name' => ['nama_lengkap', 'nama', 'name', 'employee_name'],
        'department' => ['departemen', 'department', 'divisi', 'unit'],
        'position' => ['jabatan', 'position', 'posisi', 'role_title'],
        'started_on' => ['tanggal_mulai_kerja', 'mulai_kerja', 'started_on', 'start_date', 'tanggal_masuk', 'join_date'],
        'employment_type' => ['status_kerja', 'jenis_karyawan', 'employment_type', 'type', 'status_kepegawaian'],
        'ends_on' => ['tanggal_selesai_kontrak', 'selesai_kontrak', 'ends_on', 'end_date', 'akhir_kontrak'],
        'email' => ['email', 'email_pribadi', 'email_login'],
        'phone' => ['no_telepon', 'telepon', 'phone', 'no_hp', 'whatsapp', 'wa'],
        'base_salary' => ['gaji_pokok', 'gaji', 'base_salary', 'basic_salary'],
        'religion' => ['agama', 'religion'],
        'place_of_birth' => ['tempat_lahir', 'kota_lahir', 'place_of_birth'],
        'date_of_birth' => ['tanggal_lahir', 'tgl_lahir', 'date_of_birth', 'birth_date'],
        'gender' => ['jenis_kelamin', 'gender', 'kelamin'],
        'marital_status' => ['status_pernikahan', 'status_kawin', 'marital_status'],
        'permanent_address' => ['alamat_asal', 'alamat_tetap', 'permanent_address'],
        'current_address' => ['alamat_domisili', 'alamat_sekarang', 'current_address'],
        'level' => ['level', 'level_karyawan', 'golongan', 'grade'],
        'work_location' => ['lokasi_kerja', 'lokasi', 'location', 'work_location'],
        'status' => ['status', 'status_aktif'],
        'make_account' => ['buat_akun', 'make_account', 'bikin_akun', 'buatkan_akun'],
        'password' => ['password_awal', 'password', 'pass'],
    ];

    public static function templateCsv(): string
    {
        $headers = [
            'kode_karyawan',
            'nama_lengkap',
            'departemen',
            'jabatan',
            'tanggal_mulai_kerja',
            'status_kerja',
            'tanggal_selesai_kontrak',
            'email',
            'no_telepon',
            'gaji_pokok',
            'agama',
            'tempat_lahir',
            'tanggal_lahir',
            'jenis_kelamin',
            'status_pernikahan',
            'alamat_asal',
            'alamat_domisili',
            'level',
            'lokasi_kerja',
            'status',
            'buat_akun',
            'password_awal',
        ];

        $sampleRow1 = [
            'FO-001',
            'Nadia Anjani',
            'Front Office',
            'Receptionist',
            '2026-01-01',
            'tetap',
            '',
            'nadia@deresort.test',
            '081234567801',
            '',
            'Islam',
            '',
            '',
            '',
            '',
            '',
            '',
            'Staff',
            'Resort Utama',
            'aktif',
            'ya',
            'password123',
        ];

        $sampleRow2 = [
            'EN-001',
            'Dimas Saputra',
            'Engineering',
            'Technician',
            '2026-01-15',
            'kontrak',
            '2026-12-31',
            'dimas@deresort.test',
            '081234567802',
            '',
            'Islam',
            '',
            '',
            '',
            '',
            '',
            '',
            'Staff',
            '',
            'aktif',
            'ya',
            'password123',
        ];

        $output = fopen('php://temp', 'r+');
        // Prepend UTF-8 BOM so Excel opens it with proper encoding
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers, ',', '"', '\\');
        fputcsv($output, $sampleRow1, ',', '"', '\\');
        fputcsv($output, $sampleRow2, ',', '"', '\\');
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv ?: '';
    }

    public function parseCsv(string $content): array
    {
        // Strip UTF-8 BOM
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        if (empty($lines)) {
            return [];
        }

        // Auto-detect delimiter from first line
        $firstLine = $lines[0];
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $headerRow = str_getcsv(array_shift($lines), $delimiter);
        if (! $headerRow) {
            return [];
        }

        $headerMap = [];
        foreach ($headerRow as $idx => $rawHeader) {
            $normalized = Str::lower(trim(str_replace([' ', '-', '.'], '_', (string) $rawHeader)));
            foreach (self::HEADER_SYNONYMS as $key => $synonyms) {
                if (in_array($normalized, $synonyms, true)) {
                    $headerMap[$idx] = $key;
                    break;
                }
            }
        }

        $parsed = [];
        $lineNum = 1; // 1 is header
        foreach ($lines as $line) {
            $lineNum++;
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $cols = str_getcsv($trimmed, $delimiter);
            $row = ['_line' => $lineNum];
            foreach ($headerMap as $idx => $key) {
                $val = trim((string) ($cols[$idx] ?? ''));
                $row[$key] = $val !== '' ? $val : null;
            }

            $parsed[] = $row;
        }

        return $parsed;
    }

    public function preview(string $content): array
    {
        $rawRows = $this->parseCsv($content);
        if (empty($rawRows)) {
            return [
                'total_rows' => 0,
                'valid_count' => 0,
                'error_count' => 0,
                'rows' => [],
                'errors' => ['File CSV kosong atau tidak memiliki baris data.'],
            ];
        }

        $existingCodes = Employee::query()->pluck('code')->all();
        $existingCodesLower = array_map('strtolower', $existingCodes);
        $existingEmails = User::query()->pluck('email')->all();
        $existingEmailsLower = array_map('strtolower', array_filter($existingEmails));

        $seenCodesInFile = [];
        $validatedRows = [];
        $allErrors = [];

        foreach ($rawRows as $row) {
            $line = $row['_line'];
            $errors = [];

            // 1. Code
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') {
                $errors[] = 'Kode karyawan wajib diisi.';
            } elseif (! preg_match('/^[A-Za-z0-9._-]+$/', $code)) {
                $errors[] = 'Format kode karyawan tidak valid (hanya huruf, angka, titik, strip).';
            } elseif (in_array(strtolower($code), $existingCodesLower, true)) {
                $errors[] = "Kode karyawan '{$code}' sudah terdaftar di database.";
            } elseif (in_array(strtolower($code), $seenCodesInFile, true)) {
                $errors[] = "Kode karyawan '{$code}' duplikat dalam file CSV ini.";
            } else {
                $seenCodesInFile[] = strtolower($code);
            }

            // 2. Name
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = 'Nama lengkap wajib diisi.';
            } elseif (mb_strlen($name) > 120) {
                $errors[] = 'Nama lengkap maksimal 120 karakter.';
            }

            // 3. Department
            $department = trim((string) ($row['department'] ?? ''));
            if ($department === '') {
                $errors[] = 'Departemen wajib diisi.';
            }

            // 4. Position
            $position = trim((string) ($row['position'] ?? ''));
            if ($position === '') {
                $errors[] = 'Jabatan wajib diisi.';
            }

            // 5. Started on
            $startedOn = trim((string) ($row['started_on'] ?? ''));
            $startedOnDate = null;
            if ($startedOn === '') {
                $errors[] = 'Tanggal mulai kerja wajib diisi (YYYY-MM-DD).';
            } else {
                try {
                    $startedOnDate = CarbonImmutable::parse($startedOn);
                    $startedOn = $startedOnDate->format('Y-m-d');
                } catch (\Throwable) {
                    $errors[] = "Format tanggal mulai kerja '{$startedOn}' tidak valid (gunakan YYYY-MM-DD).";
                }
            }

            // 6. Employment type
            $rawType = Str::lower(trim((string) ($row['employment_type'] ?? 'tetap')));
            $type = in_array($rawType, ['kontrak', 'contract'], true) ? 'contract' : 'permanent';

            // 7. Ends on (if contract)
            $endsOn = trim((string) ($row['ends_on'] ?? ''));
            if ($type === 'contract') {
                if ($endsOn === '') {
                    $errors[] = 'Tanggal selesai kontrak wajib diisi untuk karyawan kontrak.';
                } else {
                    try {
                        $endsOnDate = CarbonImmutable::parse($endsOn);
                        $endsOn = $endsOnDate->format('Y-m-d');
                        if ($startedOnDate && $endsOnDate->lt($startedOnDate)) {
                            $errors[] = 'Tanggal selesai kontrak tidak boleh sebelum tanggal mulai kerja.';
                        }
                    } catch (\Throwable) {
                        $errors[] = "Format tanggal selesai kontrak '{$endsOn}' tidak valid (gunakan YYYY-MM-DD).";
                    }
                }
            } else {
                $endsOn = null;
            }

            // 8. Email
            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '') {
                $lowerEmail = Str::lower($email);
                if (! filter_var($lowerEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Format email '{$email}' tidak valid.";
                } else {
                    $email = $lowerEmail;
                }
            } else {
                $email = null;
            }

            // 9. Phone
            $phone = trim((string) ($row['phone'] ?? '')) ?: null;

            // 10. HR profile fields
            $salaryRaw = trim((string) ($row['base_salary'] ?? ''));
            $baseSalary = null;
            if ($salaryRaw !== '') {
                if (! ctype_digit($salaryRaw) || (float) $salaryRaw > 999999999999) {
                    $errors[] = 'Gaji pokok harus berupa angka rupiah tanpa pemisah ribuan.';
                } else {
                    $baseSalary = (int) $salaryRaw;
                }
            }

            $religionRaw = Str::lower(trim((string) ($row['religion'] ?? '')));
            $religionRaw = str_replace(['_', '-'], ' ', $religionRaw);
            $religion = match ($religionRaw) {
                '', 'islam' => $religionRaw === '' ? null : 'islam',
                'protestant', 'protestan', 'kristen protestan' => 'protestant',
                'catholic', 'katolik', 'kristen katolik' => 'catholic',
                'hindu' => 'hindu',
                'buddhist', 'buddha', 'budha' => 'buddhist',
                'confucian', 'konghucu', 'kong hu cu' => 'confucian',
                'other', 'lainnya' => 'other',
                default => null,
            };
            if ($religionRaw !== '' && $religion === null) {
                $errors[] = "Agama '{$row['religion']}' tidak dikenal.";
            }

            $placeOfBirth = trim((string) ($row['place_of_birth'] ?? '')) ?: null;
            if ($placeOfBirth && mb_strlen($placeOfBirth) > 120) {
                $errors[] = 'Tempat lahir maksimal 120 karakter.';
            }

            $birthDate = trim((string) ($row['date_of_birth'] ?? ''));
            if ($birthDate !== '') {
                try {
                    $parsedBirthDate = CarbonImmutable::createFromFormat('!Y-m-d', $birthDate);
                    if (! $parsedBirthDate || $parsedBirthDate->format('Y-m-d') !== $birthDate) {
                        throw new \InvalidArgumentException;
                    }
                    if ($parsedBirthDate->gt(CarbonImmutable::now('Asia/Jakarta')->startOfDay())) {
                        $errors[] = 'Tanggal lahir tidak boleh di masa depan.';
                    }
                    $birthDate = $parsedBirthDate->format('Y-m-d');
                } catch (\Throwable) {
                    $errors[] = "Format tanggal lahir '{$birthDate}' tidak valid (gunakan YYYY-MM-DD).";
                    $birthDate = null;
                }
            } else {
                $birthDate = null;
            }

            $genderRaw = Str::lower(trim((string) ($row['gender'] ?? '')));
            $genderRaw = str_replace(['_', '-'], ' ', $genderRaw);
            $gender = match ($genderRaw) {
                '' => null,
                'male', 'l', 'laki laki' => 'male',
                'female', 'p', 'perempuan' => 'female',
                default => null,
            };
            if ($genderRaw !== '' && $gender === null) {
                $errors[] = "Jenis kelamin '{$row['gender']}' tidak dikenal.";
            }

            $maritalRaw = Str::lower(trim((string) ($row['marital_status'] ?? '')));
            $maritalRaw = str_replace(['_', '-'], ' ', $maritalRaw);
            $maritalStatus = match ($maritalRaw) {
                '' => null,
                'single', 'unmarried', 'belum menikah' => 'single',
                'married', 'menikah' => 'married',
                'divorced', 'cerai hidup' => 'divorced',
                'widowed', 'cerai mati' => 'widowed',
                default => null,
            };
            if ($maritalRaw !== '' && $maritalStatus === null) {
                $errors[] = "Status pernikahan '{$row['marital_status']}' tidak dikenal.";
            }

            $permanentAddress = trim((string) ($row['permanent_address'] ?? '')) ?: null;
            $currentAddress = trim((string) ($row['current_address'] ?? '')) ?: null;
            if ($permanentAddress && mb_strlen($permanentAddress) > 2000) {
                $errors[] = 'Alamat asal maksimal 2.000 karakter.';
            }
            if ($currentAddress && mb_strlen($currentAddress) > 2000) {
                $errors[] = 'Alamat domisili maksimal 2.000 karakter.';
            }

            // 11. Status
            $rawStatus = Str::lower(trim((string) ($row['status'] ?? 'aktif')));
            $status = in_array($rawStatus, ['nonaktif', 'inactive', 'tidak'], true) ? 'inactive' : 'active';

            // 12. Make account
            $rawMake = Str::lower(trim((string) ($row['make_account'] ?? '')));
            $makeAccount = in_array($rawMake, ['ya', '1', 'true', 'yes'], true) || (! empty($email) && $rawMake === '');

            // 13. Password
            $password = trim((string) ($row['password'] ?? '')) ?: 'password123';
            if ($makeAccount && mb_strlen($password) < 1) {
                $errors[] = 'Password akun tidak boleh kosong.';
            }

            $isValid = empty($errors);
            if (! $isValid) {
                foreach ($errors as $err) {
                    $allErrors[] = "Baris {$line}: {$err}";
                }
            }

            $validatedRows[] = [
                '_line' => $line,
                '_valid' => $isValid,
                '_errors' => $errors,
                'code' => $code,
                'name' => $name,
                'department' => $department,
                'position' => $position,
                'started_on' => $startedOn,
                'employment_type' => $type,
                'ends_on' => $endsOn,
                'email' => $email,
                'phone' => $phone,
                'base_salary' => $baseSalary,
                'religion' => $religion,
                'place_of_birth' => $placeOfBirth,
                'date_of_birth' => $birthDate,
                'gender' => $gender,
                'marital_status' => $maritalStatus,
                'permanent_address' => $permanentAddress,
                'current_address' => $currentAddress,
                'level' => trim((string) ($row['level'] ?? '')) ?: null,
                'work_location' => trim((string) ($row['work_location'] ?? '')) ?: null,
                'status' => $status,
                'make_account' => $makeAccount,
                'password' => $password,
            ];
        }

        $validCount = count(array_filter($validatedRows, fn ($r) => $r['_valid']));
        $errorCount = count($validatedRows) - $validCount;

        return [
            'total_rows' => count($validatedRows),
            'valid_count' => $validCount,
            'error_count' => $errorCount,
            'rows' => $validatedRows,
            'errors' => $allErrors,
        ];
    }

    public function import(array $rows, ?User $actor = null): array
    {
        $validRows = array_filter($rows, fn ($r) => ! empty($r['_valid']) || ! isset($r['_valid']));

        return DB::transaction(function () use ($validRows, $actor) {
            $createdEmployees = [];
            $deptCache = [];
            $posCache = [];
            $levelCache = [];
            $locationCache = [];

            foreach ($validRows as $row) {
                // 1. Department
                $deptName = trim((string) $row['department']);
                $deptKey = strtolower($deptName);
                if (! isset($deptCache[$deptKey])) {
                    $deptCache[$deptKey] = Department::query()->firstOrCreate(['name' => $deptName]);
                }
                $department = $deptCache[$deptKey];

                // 2. Position
                $posName = trim((string) $row['position']);
                $posKey = strtolower($posName);
                if (! isset($posCache[$posKey])) {
                    $posCache[$posKey] = Position::query()->firstOrCreate(['name' => $posName]);
                }
                $position = $posCache[$posKey];

                // 3. Level (optional)
                $levelId = null;
                if (! empty($row['level'])) {
                    $lvlRaw = trim((string) $row['level']);
                    $lvlKey = strtolower($lvlRaw);
                    if (! isset($levelCache[$lvlKey])) {
                        $levelCache[$lvlKey] = EmployeeLevel::query()
                            ->where('code', $lvlRaw)
                            ->orWhere('name', $lvlRaw)
                            ->first();
                    }
                    $levelId = $levelCache[$lvlKey]?->id;
                }

                // 4. Work location (optional)
                $locationId = null;
                if (! empty($row['work_location'])) {
                    $locRaw = trim((string) $row['work_location']);
                    $locKey = strtolower($locRaw);
                    if (! isset($locationCache[$locKey])) {
                        $locationCache[$locKey] = WorkLocation::query()
                            ->where('name', $locRaw)
                            ->first();
                    }
                    $locationId = $locationCache[$locKey]?->id;
                }

                // 5. User Account (optional)
                $userId = null;
                $email = ! empty($row['email']) ? Str::lower(trim((string) $row['email'])) : null;
                $phone = ! empty($row['phone']) ? trim((string) $row['phone']) : null;
                $makeAccount = ! empty($row['make_account']);

                if ($makeAccount && $email) {
                    $existingUser = User::query()->where('email', $email)->first();
                    if ($existingUser) {
                        $userId = $existingUser->id;
                        if (! empty($row['password'])) {
                            $existingUser->update(['password' => $row['password']]);
                        }
                    } else {
                        $newUser = User::query()->create([
                            'name' => $row['name'],
                            'email' => $email,
                            'phone' => $phone,
                            'password' => ! empty($row['password']) ? $row['password'] : 'password123',
                            'role' => 'employee',
                        ]);
                        $userId = $newUser->id;
                    }
                }

                // 6. Employee
                $employee = Employee::query()->create([
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'department_id' => $department->id,
                    'position_id' => $position->id,
                    'level_id' => $levelId,
                    'work_location_id' => $locationId,
                    'started_on' => $row['started_on'],
                    'status' => $row['status'] ?? 'active',
                    'email' => $email,
                    'phone' => $phone,
                    'user_id' => $userId,
                    'base_salary' => $row['base_salary'] ?? null,
                    'religion' => $row['religion'] ?? null,
                    'place_of_birth' => $row['place_of_birth'] ?? null,
                    'date_of_birth' => $row['date_of_birth'] ?? null,
                    'gender' => $row['gender'] ?? null,
                    'marital_status' => $row['marital_status'] ?? null,
                    'permanent_address' => $row['permanent_address'] ?? null,
                    'current_address' => $row['current_address'] ?? null,
                ]);

                // 7. Initial Department Placement
                $employee->placements()->create([
                    'department_id' => $department->id,
                    'effective_from' => $employee->started_on,
                ]);

                // 8. Initial Employment Term
                $employee->employmentTerms()->create([
                    'type' => $row['employment_type'] ?? 'permanent',
                    'starts_on' => $employee->started_on,
                    'ends_on' => $row['ends_on'] ?? null,
                ]);

                // 9. Level Assignment (if assigned)
                if ($levelId) {
                    $employee->levelAssignments()->create([
                        'level_id' => $levelId,
                        'effective_from' => $employee->started_on,
                        'reason' => 'Import massal awal',
                    ]);
                }

                $createdEmployees[] = $employee;
            }

            $subject = $actor ?? ($createdEmployees[0] ?? auth()->user());
            if ($subject) {
                Audit::log('employees.bulk_imported', $subject, null, [
                    'imported_count' => count($createdEmployees),
                    'actor_id' => $actor?->id,
                ]);
            }

            return [
                'imported_count' => count($createdEmployees),
                'employees' => $createdEmployees,
            ];
        });
    }
}
