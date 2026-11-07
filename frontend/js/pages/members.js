// สมาชิกสภา (members.html): ชื่อ IC และตำแหน่ง จาก /api/public/members
function membersPage() {
  return {
    ranks: [],
    loading: true,
    error: '',
    pad: (n) => String(n).padStart(2, '0'),
    get total() {
      return this.ranks.reduce((n, r) => n + r.members.length, 0);
    },

    async init() {
      try {
        const res = await API.get('/api/public/members');
        this.ranks = res.data;
      } catch (e) {
        this.error = API.describe(e, 'โหลดรายชื่อสมาชิกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
      } finally {
        this.loading = false;
      }
    },
  };
}
