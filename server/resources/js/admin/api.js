const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export class ApiError extends Error {
    constructor(status, body) {
        super(body?.message ?? 'Gagal terhubung ke server.');
        this.status = status;
        this.code = body?.code;
        this.details = body?.details ?? {};
    }
}

export async function api(path, { method = 'GET', body, query } = {}) {
    const url = new URL(`/admin/api/${path}`, location.origin);
    Object.entries(query ?? {}).forEach(([k, v]) => {
        if (Array.isArray(v)) {
            v.forEach((value) => url.searchParams.append(`${k}[]`, value));
        } else if (v !== '' && v != null) {
            url.searchParams.set(k, v);
        }
    });
    const isForm = body instanceof FormData;

    let response;
    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', ...(isForm ? {} : { 'Content-Type': 'application/json' }), 'X-CSRF-TOKEN': csrf },
            body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
        });
    } catch {
        throw new ApiError(0, { message: 'Server tidak bisa dihubungi. Cek koneksi lalu coba lagi.' });
    }

    if (response.status === 401 || response.status === 419) {
        const body = response.headers.get('content-type')?.includes('json') ? await response.json().catch(() => null) : null;
        // APP_UPDATED_RELOGIN: server baru di-deploy; pesan tampil di halaman login lewat flash session.
        location.assign('/login');
        throw new ApiError(response.status, body?.code === 'APP_UPDATED_RELOGIN' ? body : { message: 'Sesi habis. Silakan login lagi.' });
    }

    const data = response.headers.get('content-type')?.includes('json') ? await response.json() : null;
    if (!response.ok) throw new ApiError(response.status, data);
    return data;
}

const cache = new Map();
/** Daftar referensi (departemen, jabatan, lokasi, template) dipakai banyak layar. */
export async function lookup(name) {
    if (!cache.has(name)) cache.set(name, api(name).then((r) => r.data).catch((e) => { cache.delete(name); throw e; }));
    return cache.get(name);
}
export const invalidate = (name) => cache.delete(name);

export const role = document.body.dataset.role;
export const isAdmin = role === 'admin' || role === 'super_admin';
export const isSuperAdmin = role === 'super_admin';
