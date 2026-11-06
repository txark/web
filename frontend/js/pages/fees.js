// ค่าบริการสภา (fees.html): อ่านหมวด "อัตราค่าบริการ" จากกฎที่เผยแพร่
function feesPage() {
  return {
    groups: [],
    currency: 'WVDL',
    loading: true,
    error: '',

    async init() {
      try {
        const res = await API.get('/api/public/rules');
        const sec = res.data.find((s) => s.key === 'fees');
        const groups = [];
        for (const r of sec ? sec.rules : []) {
          if (r.kind === 'heading') groups.push({ title: r.title, rows: [] });
          else if (r.kind === 'price') {
            if (!groups.length) groups.push({ title: '', rows: [] });
            groups[groups.length - 1].rows.push({ title: r.title, amount: String(r.body).replace(/\s*WVDL\s*$/, ''), note: r.note });
          }
        }
        this.groups = groups;
      } catch (e) {
        this.error = API.describe(e, 'โหลดค่าบริการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
      } finally {
        this.loading = false;
      }
    },
  };
}
