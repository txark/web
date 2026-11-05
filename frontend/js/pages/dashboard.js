// แดชบอร์ดสรุปภาพรวม (รองประธานขึ้นไป)
function dashboardPage() {
  const TZ = 'Asia/Bangkok';
  const pad = (n) => String(n).padStart(2, '0');

  return {
    ready: false,
    loading: false,
    error: '',
    d: null,
    updated: '',

    async init() {
      const auth = authState();
      await auth.require('vice_president');
      this.ready = true;
      await this.load();
    },

    async load() {
      this.loading = true;
      this.error = '';
      try {
        this.d = (await API.get('/api/council/dashboard')).data;
        this.updated = this.time(this.d.generated_at);
      } catch (e) {
        this.error = API.describe(e, 'โหลดแดชบอร์ดไม่สำเร็จ');
      } finally {
        this.loading = false;
      }
    },

    money(n) {
      return Number(n || 0).toLocaleString('en-US');
    },

    pct(n, total) {
      return total ? Math.round((n / total) * 100) : 0;
    },

    hm(seconds) {
      const s = Math.max(0, Math.round(seconds || 0));
      const h = Math.floor(s / 3600);
      const m = Math.floor((s % 3600) / 60);
      if (h === 0 && m === 0) return s > 0 ? '< 1 นาที' : '0';
      return h > 0 ? `${h} ชม. ${pad(m)} นาที` : `${m} นาที`;
    },

    time(iso) {
      return iso ? new Intl.DateTimeFormat('th-TH', { timeZone: TZ, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(iso)) : '—';
    },

    stamp(iso) {
      return iso ? new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { timeZone: TZ, day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(iso)).replace(',', '') : '—';
    },
  };
}
