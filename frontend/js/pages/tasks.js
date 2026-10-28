function tasksPage() {
  const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
  const SEV = {
    high: { label: 'เร่งด่วน', cls: 'badge badge-sanction' },
    medium: { label: 'ควรทำ', cls: 'badge badge-blacklist' },
    low: { label: 'ทบทวน', cls: 'badge badge-muted' },
  };

  return {
    ready: false,
    items: [],
    loading: true,
    error: '',
    filter: '',
    filters: [
      { value: '', label: 'ทั้งหมด' },
      { value: 'high', label: 'เร่งด่วน' },
      { value: 'medium', label: 'ควรทำ' },
      { value: 'low', label: 'ทบทวน' },
    ],

    async init() {
      await authState().require('member');
      this.ready = true;
      await this.load();
    },

    async load() {
      this.loading = true;
      this.error = '';
      try {
        this.items = (await API.get('/api/council/alerts')).data;
      } catch (e) {
        this.items = [];
        this.error = e.status === 403 ? 'คุณไม่มีสิทธิ์ดูงานค้าง' : API.describe(e, 'โหลดงานค้างไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
      } finally {
        this.loading = false;
      }
    },

    get shown() {
      return this.filter ? this.items.filter((a) => a.severity === this.filter) : this.items;
    },

    countOf(value) {
      return value ? this.items.filter((a) => a.severity === value).length : this.items.length;
    },

    sev(s) {
      return SEV[s] || SEV.low;
    },

    when(iso) {
      const d = new Date(iso);
      if (!iso || isNaN(d)) return '-';
      return `${d.getDate()} ${MONTHS[d.getMonth()]} ${String(d.getFullYear() + 543).slice(-2)}`;
    },
  };
}
