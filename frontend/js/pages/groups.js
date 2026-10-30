function groupsPage() {
  return {
    types: [
      { value: '', label: 'ทั้งหมด' },
      { value: 'gang', label: 'แก๊ง' },
      { value: 'family', label: 'ครอบครัว' },
    ],
    q: '',
    type: '',
    status: '',
    page: 1,

    groups: [],
    meta: null,
    loading: true,
    error: '',

    detail: null,
    detailLoading: false,

    init() {
      this.load();
      this.$watch('q', () => this.reload());
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
      if (this.type) params.set('type', this.type);
      if (this.status) params.set('status', this.status);

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

    fmtDate(iso) {
      return iso
        ? new Date(iso).toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' })
        : '-';
    },
  };
}
