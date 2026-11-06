function groupsPage() {
  return {
    types: [
      { value: '', label: 'ทั้งหมด' },
      { value: 'gang', label: 'Gang' },
      { value: 'lady', label: 'Lady Gang' },
      { value: 'family', label: 'Family' },
    ],
    q: '',
    type: '',
    status: '',
    protect: false,
    counts: {},
    page: 1,

    groups: [],
    meta: null,
    loading: true,
    error: '',

    detail: null,
    detailLoading: false,

    init() {
      this.load();
      this.loadCounts();
      this.$watch('q', () => this.reload());
      this.$watch('protect', () => this.reload());
    },

    /** จำนวนในแต่ละปุ่มกรอง (ทั้งหมด / แก๊ง / แก๊งหญิง / ครอบครัว) */
    async loadCounts() {
      try {
        const total = async (kind) => (await API.get(`/api/public/groups?per_page=1${kind ? '&kind=' + kind : ''}`)).meta.total;
        const [all, gang, lady, family] = await Promise.all([total(''), total('gang'), total('lady'), total('family')]);
        this.counts = { '': all, gang, lady, family };
      } catch (e) {}
    },

    reload() {
      this.page = 1;
      this.load();
    },

    go(p) {
      this.page = p;
      this.load();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    async load() {
      this.loading = true;
      this.error = '';
      const params = new URLSearchParams({ page: this.page });
      if (this.q.trim()) params.set('q', this.q.trim());
      if (this.type) params.set('kind', this.type);
      if (this.status) params.set('status', this.status);
      if (this.protect) params.set('protect', '1');

      try {
        const res = await API.get(`/api/public/groups?${params}`);
        this.groups = res.data;
        this.meta = res.meta;
      } catch (e) {
        this.error = API.describe(e, 'โหลดทำเนียบไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        this.groups = [];
      } finally {
        this.loading = false;
      }
    },

    async open(g) {
      this.detail = null;
      this.detailLoading = true;
      try {
        this.detail = (await API.get(`/api/public/groups/${g.id}`)).data;
      } catch (e) {
        this.error = API.describe(e, 'โหลดรายละเอียดไม่สำเร็จ');
      } finally {
        this.detailLoading = false;
      }
    },

    closeDetail() {
      this.detail = null;
      this.detailLoading = false;
    },

    statusClass(status) {
      return {
        normal: 'badge badge-normal',
        blacklist: 'badge badge-blacklist',
        sanction: 'badge badge-sanction',
        inactive: 'badge badge-muted',
      }[status] || 'badge badge-muted';
    },

    /** ป้ายประเภทกลุ่ม (ภาษาอังกฤษ): Gang / Lady Gang / Family */
    typeTag(g) {
      return g.type === 'family' ? 'Family' : g.is_lady ? 'Lady Gang' : 'Gang';
    },

    storyType(t) {
      return { gang: 'Gang', family: 'Family', lady: 'Lady Gang' }[t] || t || '—';
    },

    fmtDate(iso) {
      return iso
        ? new Date(iso).toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' })
        : '-';
    },
  };
}
