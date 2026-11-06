// สารบัญหน้า "เกี่ยวกับ" แบบเดียวกับสารบัญหน้ากฎ: กลุ่มพับได้ + เลขหมวด + ไฮไลต์ตามตำแหน่งที่อ่าน
function aboutToc() {
  const groups = [
    { label: 'แก๊ง', items: [['gang', 'แก๊ง GANG'], ['outfit-gang', 'ชุดที่ลงได้ของแก๊ง']] },
    { label: 'ครอบครัว', items: [['family', 'ครอบครัว Family'], ['outfit-family', 'ชุดที่ลงได้ของครอบครัว']] },
    { label: 'สมาชิกและค่าบริการ', items: [['members', 'การเพิ่ม - ออกสมาชิก'], ['fees', 'ค่าบริการ']] },
  ];

  return {
    groups,
    closed: {},
    current: groups[0].items[0][0],

    init() {
      const ids = groups.flatMap((g) => g.items.map((i) => i[0]));
      const spy = () => {
        // หมวดที่อยู่ใกล้ขอบบนของหน้าจอที่สุด (หักความสูงเมนู)
        const line = 140;
        let cur = ids[0];
        for (const id of ids) {
          const el = document.getElementById(id);
          if (el && el.getBoundingClientRect().top - line <= 0) cur = id;
        }
        this.current = cur;
      };
      window.addEventListener('scroll', spy, { passive: true });
      spy();
    },

    toggle(g) {
      this.closed = { ...this.closed, [g.label]: !this.closed[g.label] };
    },
    isOpen(g) {
      return !this.closed[g.label];
    },
    hasCurrent(g) {
      return g.items.some((i) => i[0] === this.current);
    },
    pad(n) {
      return String(n).padStart(2, '0');
    },
  };
}
