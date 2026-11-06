<?php

namespace App\Services;

use App\Enums\RuleSection;
use App\Models\Rule;
use App\Models\RuleSectionMeta;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ดึงกฎหมวดสภาล่าสุดจากเว็บทางการ (https://official.wavevycity.com/rules) แล้วเทียบ/อัปเดตลงฐานข้อมูล
 *
 * เว็บทางการ render กฎเป็น HTML ฝั่งเซิร์ฟเวอร์: การ์ดแต่ละข้อคือ <article id="VISIT-001"> ภายใน <section id="cat-VISIT">
 * นำเข้าเฉพาะหมวดสภา (CC VISIT EVID SUMMON REG GANG BUY DEAL MATCH PROT MAX DUTY) ส่วน CC-004 (ค่าบริการ) อยู่ในหมวด "อัตราค่าบริการ"
 * ซึ่งไม่ถูกแก้ทับ (ระบบจัดกลุ่มรายการราคาเอง) — ตัวนำเข้ารายงานรายการค่าบริการจากเว็บทางการไว้ให้ตรวจเอง
 */
class OfficialRulesImporter
{
    public const URL = 'https://official.wavevycity.com/rules';

    private const NOTE_LABELS = [
        'Examples' => 'ตัวอย่างความผิด',
        'Conditions' => 'หมายเหตุ',
        'Exception' => 'ข้อยกเว้น',
    ];

    /** @return array{version: ?string, effective: ?string, sections: array<string, list<array<string, mixed>>>, fees: list<string>} */
    public function fetch(?string $url = null): array
    {
        $res = Http::timeout(60)->withHeaders(['User-Agent' => 'WavevyCouncilRulesSync/1.0'])->get($url ?: self::URL);
        if (! $res->successful()) {
            throw new RuntimeException('ดึงหน้ากฎไม่สำเร็จ (HTTP '.$res->status().')');
        }

        return $this->parse($res->body());
    }

    /** @return array{version: ?string, effective: ?string, sections: array<string, list<array<string, mixed>>>, fees: list<string>} */
    public function parse(string $html): array
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $text = preg_replace('/\s+/u', ' ', $doc->textContent ?? '');
        preg_match('/ฉบับ\s*([0-9.]+)\s*·\s*มีผลตั้งแต่\s*([^·]+?)\s*·/u', $text, $m);

        $sections = [];
        $fees = [];
        foreach ($xp->query('//article[@id]') as $article) {
            /** @var DOMElement $article */
            $code = $article->getAttribute('id');
            if (! preg_match('/^([A-Z]+)-\d+$/', $code, $cm)) {
                continue;
            }
            $key = strtolower($cm[1]);
            $section = RuleSection::tryFrom($key);
            if (! $section || $section === RuleSection::Fees) {
                continue;
            }

            if ($code === 'CC-004') {
                foreach ($xp->query('.//ol//li//div[contains(@class,"prose-wv")]', $article) as $li) {
                    $fees[] = $this->inline($li);
                }

                continue;
            }

            $sections[$key][] = $this->card($xp, $article, $code);
        }

        return ['version' => $m[1] ?? null, 'effective' => isset($m[2]) ? trim($m[2]) : null, 'sections' => $sections, 'fees' => $fees];
    }

    /**
     * เทียบกับฐานข้อมูลปัจจุบัน
     *
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @return array<string, array{official: int, current: int, added: list<string>, removed: list<string>, changed: list<string>}>
     */
    public function diff(array $sections): array
    {
        $out = [];
        foreach ($sections as $key => $cards) {
            $current = Rule::where('section', $key)->where('kind', 'rule')->get()->keyBy('code');
            $codes = array_column($cards, 'code');
            $changed = [];
            foreach ($cards as $c) {
                $row = $current->get($c['code']);
                if ($row && ($row->title !== $c['title'] || $row->body !== $c['body'] || $row->penalty !== $c['penalty'] || $row->note !== $c['note'])) {
                    $changed[] = $c['code'];
                }
            }
            $out[$key] = [
                'official' => count($cards),
                'current' => $current->count(),
                'added' => array_values(array_diff($codes, $current->keys()->filter()->all())),
                'removed' => array_values(array_diff($current->keys()->filter()->all(), $codes)),
                'changed' => $changed,
            ];
        }

        return $out;
    }

    /**
     * แทนที่กฎของหมวดสภาที่ดึงมาทั้งหมด (ลบของเดิมของหมวดนั้นแล้วใส่ตามเว็บทางการ)
     *
     * @param  array<string, list<array<string, mixed>>>  $sections
     */
    public function apply(array $sections): int
    {
        $total = 0;

        DB::transaction(function () use ($sections, &$total) {
            Rule::withoutEvents(function () use ($sections, &$total) {
                foreach ($sections as $key => $cards) {
                    Rule::where('section', $key)->delete();
                    foreach ($cards as $i => $c) {
                        Rule::create([
                            'section' => $key,
                            'code' => $c['code'],
                            'sort_order' => ($i + 1) * 10,
                            'kind' => 'rule',
                            'title' => $c['title'],
                            'body' => $c['body'],
                            'note_label' => $c['note'] ? $c['note_label'] : null,
                            'note' => $c['note'],
                            'penalty' => $c['penalty'],
                        ]);
                        $total++;
                    }
                    RuleSectionMeta::updateOrCreate(['section' => $key], ['source_checked_at' => today()->toDateString()]);
                }
            });
        });

        return $total;
    }

    /** ไฟล์ข้อมูลตั้งต้นของกฎหมวดสภา (ได้จาก php artisan rules:pull-official --save=...) */
    public const SNAPSHOT = 'database/data/council-rules.json';

    /** @param  array<string, mixed>  $data */
    public function save(array $data, string $path): void
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode([
            'version' => $data['version'],
            'effective' => $data['effective'],
            'source' => self::URL,
            'sections' => $data['sections'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."
");
    }

    /** @return array{version: ?string, effective: ?string, sections: array<string, list<array<string, mixed>>>}|null */
    public static function snapshot(): ?array
    {
        $file = base_path(self::SNAPSHOT);

        return is_file($file) ? json_decode(file_get_contents($file), true) : null;
    }

    // ───────────────────────── แปลง HTML → ข้อมูลกฎ ─────────────────────────

    /** @return array{code: string, title: ?string, body: ?string, penalty: ?string, note: ?string, note_label: string} */
    private function card(DOMXPath $xp, DOMElement $article, string $code): array
    {
        $title = trim($this->inline($xp->query('.//h4', $article)->item(0)));

        $body = [];
        $penalty = null;
        $notes = [];

        $root = $xp->query('.//div[contains(@class,"min-w-0") and contains(@class,"p-5")]', $article)->item(0);
        foreach ($root?->childNodes ?? [] as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $class = $child->getAttribute('class');

            if ($child->tagName === 'div' && str_contains($class, 'prose-wv')) {
                $body[] = $this->prose($child);
            } elseif ($child->tagName === 'ol') {
                foreach ($xp->query('.//li/div[contains(@class,"prose-wv")]', $child) as $li) {
                    $body[] = '• '.$this->inline($li);
                }
            } elseif ($child->tagName === 'div') {
                $label = trim($xp->query('.//*[contains(@class,"hud-label")]', $child)->item(0)?->textContent ?? '');
                if (str_starts_with($label, 'Penalty')) {
                    $penalty = $this->penalty($xp, $child);
                } else {
                    foreach (self::NOTE_LABELS as $en => $th) {
                        if (str_starts_with($label, $en)) {
                            $prose = $xp->query('.//div[contains(@class,"prose-wv")]', $child)->item(0);
                            $notes[] = [$th, $prose ? $this->prose($prose) : ''];
                        }
                    }
                }
            }
        }

        $note = null;
        $noteLabel = 'หมายเหตุ';
        if (count($notes) === 1) {
            [$noteLabel, $note] = $notes[0];
        } elseif (count($notes) > 1) {
            $note = implode("\n", array_map(fn ($n) => $n[0].': '.$n[1], $notes));
        }

        return [
            'code' => $code,
            'title' => $title !== '' ? $title : null,
            'body' => trim(implode("\n", array_filter($body, fn ($b) => $b !== ''))) ?: null,
            'penalty' => $penalty,
            'note' => $note !== '' ? $note : null,
            'note_label' => $noteLabel,
        ];
    }

    /** รายการบทลงโทษ: "หรือ/และ บำเพ็ญ ..." ต่อท้ายบรรทัดเดียวกัน ส่วนบรรทัดอื่น (ครั้งที่ 1, ร่วมกับ ...) ขึ้นบรรทัดใหม่ */
    private function penalty(DOMXPath $xp, DOMNode $box): ?string
    {
        $lines = [];
        foreach ($xp->query('.//ul/li', $box) as $li) {
            $connector = '';
            $parts = [];
            foreach ($li->childNodes as $n) {
                if (! $n instanceof DOMElement) {
                    continue;
                }
                $t = trim($this->inline($n));
                if ($t === '') {
                    continue;
                }
                if ($parts === [] && $connector === '' && str_contains($n->getAttribute('class'), 'text-mist-300')) {
                    $connector = $t;
                } else {
                    $parts[] = $t;
                }
            }
            $rest = implode(' ', $parts);
            if ($rest === '') {
                continue;
            }
            if ($lines && in_array($connector, ['หรือ', 'และ'], true) && str_starts_with($rest, 'บำเพ็ญ')) {
                $lines[count($lines) - 1] .= " {$connector}{$rest}";
            } else {
                $lines[] = trim($connector.' '.$rest);
            }
        }

        return $lines ? implode("\n", $lines) : null;
    }

    /** ย่อหน้า/รายการภายในกล่องข้อความ → ข้อความล้วน (รายการขึ้นต้น "• ") */
    private function prose(DOMNode $node): string
    {
        $out = [];
        foreach ($node->childNodes as $c) {
            if (! $c instanceof DOMElement) {
                $t = trim($c->textContent);
                if ($t !== '') {
                    $out[] = $t;
                }

                continue;
            }
            if (in_array($c->tagName, ['ul', 'ol'], true)) {
                $n = 0;
                foreach ($c->childNodes as $li) {
                    if ($li instanceof DOMElement && $li->tagName === 'li') {
                        $n++;
                        $out[] = ($c->tagName === 'ol' ? "{$n}. " : '• ').trim($this->inline($li));
                    }
                }
            } else {
                $t = trim($this->inline($c));
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }

        return trim(implode("\n", $out));
    }

    /** ข้อความในบรรทัด (br = ขึ้นบรรทัดใหม่) */
    private function inline(?DOMNode $node): string
    {
        if (! $node) {
            return '';
        }
        if ($node instanceof DOMElement && $node->tagName === 'br') {
            return "\n";
        }
        if (! $node->hasChildNodes()) {
            return $node->nodeType === XML_TEXT_NODE ? $node->textContent : '';
        }
        $s = '';
        foreach ($node->childNodes as $c) {
            // ย่อหน้า/กล่องซ้อนกันขึ้นบรรทัดใหม่ (เช่น ข้อความหลัก + หมายเหตุในรายการเดียวกัน)
            $block = $c instanceof DOMElement && in_array($c->tagName, ['p', 'div'], true);
            $s .= $block ? "\n".$this->inline($c)."\n" : $this->inline($c);
        }
        $s = preg_replace("/[ \t]+/u", ' ', $s) ?? $s;

        return trim(preg_replace("/\n\s*\n+/u", "\n", $s) ?? $s);
    }
}
