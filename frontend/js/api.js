// ตัวกลางเรียก backend: ส่ง cookie เสมอ, แนบ CSRF token ของ Sanctum, จัดการ 401
const API = (() => {
  const base = () => window.APP_CONFIG.API_URL;

  const xsrf = () => {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
  };

  async function csrf() {
    await fetch(`${base()}/sanctum/csrf-cookie`, { credentials: 'include' });
  }

  async function request(method, path, body) {
    const opts = {
      method,
      credentials: 'include',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    };
    if (method !== 'GET') {
      if (!xsrf()) await csrf();
      opts.headers['X-XSRF-TOKEN'] = xsrf();
    }
    if (body instanceof FormData) {
      opts.body = body; // multipart: ให้เบราว์เซอร์ตั้ง boundary เอง
    } else if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    let res;
    try {
      res = await fetch(`${base()}${path}`, opts);
    } catch (e) {
      // เชื่อมต่อไม่ได้เลย: backend ไม่ทำงาน, ที่อยู่ API ผิด หรือถูก CORS บล็อก
      const err = new Error('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
      err.network = true;
      throw err;
    }
    const data = res.status === 204 ? null : await res.json().catch(() => null);

    if (res.status === 401) {
      window.dispatchEvent(new CustomEvent('auth:unauthenticated'));
    }
    // เวรหมดระหว่างใช้งาน (เช่น ออกเวรจากอีกแท็บ): กลับหน้าแรกเพื่อเข้าเวรใหม่
    if (res.status === 403 && data && data.code === 'off_duty' && !/^\/(index\.html)?$/.test(location.pathname)) {
      location.href = '/index.html?duty=required';
    }
    if (!res.ok) {
      const err = new Error((data && data.message) || `HTTP ${res.status}`);
      err.status = res.status;
      err.errors = data && data.errors;
      throw err;
    }
    return data;
  }

  // ข้อความ error สำหรับแสดงผู้ใช้: แยกกรณีเชื่อมต่อไม่ได้ ออกจากกรณีเซิร์ฟเวอร์ตอบผิดพลาด
  function describe(e, fallback) {
    if (e && e.network) {
      return `เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ (${base()}) — ตรวจสอบว่า backend เปิดอยู่ และเปิดหน้านี้ผ่าน ${location.origin} ที่ได้รับอนุญาต`;
    }
    return fallback;
  }

  return {
    describe,
    get: (p) => request('GET', p),
    post: (p, b) => request('POST', p, b ?? {}),
    postForm: (p, fd) => request('POST', p, fd),
    put: (p, b) => request('PUT', p, b ?? {}),
    patch: (p, b) => request('PATCH', p, b ?? {}),
    del: (p) => request('DELETE', p),
    loginUrl: () => `${base()}/auth/discord/redirect`,
  };
})();
