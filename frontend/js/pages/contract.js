// หน้า "หนังสือสัญญาสตอรี่ตามลิงก์" (contract.html?t=...): อ่านอย่างเดียว ไม่ต้องล็อกอิน
function contractPage() {
  const DOC_W = 1240; // ความกว้างของหนังสือสัญญา (พิกเซล) ย่อให้พอดีจอแคบ

  return {
    ...StoryFmt,
    cur: null,
    loading: true,
    error: '',
    scale: 1,
    boxW: DOC_W,
    boxH: 1000,

    embed: new URLSearchParams(location.search).get('embed') === '1', // ฝังในป๊อปอัป: ไม่มีขอบ/ท้ายหน้า
    mode: 'link', // link = เปิดจากลิงก์แชร์ (สาธารณะ) · member = เปิดจากระบบ (?id=) ต้องล็อกอินสภา

    async init() {
      const q = new URLSearchParams(location.search);
      const id = q.get('id');
      const token = q.get('t') || '';
      try {
        if (id) {
          this.mode = 'member';
          this.cur = (await API.get(`/api/council/stories/${encodeURIComponent(id)}`)).data;
        } else {
          this.cur = (await API.get(`/api/public/stories/${encodeURIComponent(token)}`)).data;
        }
        this.$nextTick(() => this.fit());
        setTimeout(() => this.fit(), 400); // รอฟอนต์/รูปโลโก้โหลดแล้ววัดความสูงอีกครั้ง
      } catch (e) {
        this.error = this.mode === 'member' && (e.status === 401 || e.status === 403)
          ? 'ต้องเข้าสู่ระบบสภาและเข้าเวรอยู่จึงจะดูหนังสือสัญญาจากระบบได้'
          : e.status === 404
            ? 'ไม่พบหนังสือสัญญานี้ ลิงก์อาจไม่ถูกต้องหรือถูกยกเลิกแล้ว'
            : 'โหลดหนังสือสัญญาไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
      } finally {
        this.loading = false;
      }
    },

    /** ย่อเอกสารให้พอดีความกว้างหน้าจอ (ไม่ขยายเกินขนาดจริง) */
    fit() {
      const doc = document.getElementById('story-contract');
      this.scale = Math.min(1, (document.documentElement.clientWidth - 24) / DOC_W);
      const h = doc ? doc.offsetHeight : 1000;
      this.boxW = Math.round(DOC_W * this.scale);
      this.boxH = Math.round(h * this.scale);
    },
  };
}
