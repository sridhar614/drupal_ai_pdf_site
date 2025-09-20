<?php

namespace Drupal\sdc_pdf_gen\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;
use Psr\Log\LoggerInterface;

/**
 * Generates Drupal "Basic page" content from Bedrock KB only (no LLM),
 * with a presentable HTML body (hero, highlights list, highlight cards,
 * grouped excerpts, and sources). Also keeps an LLM path for later.
 */
class AiSiteGenerator {

  public function __construct(
    protected LoggerInterface $logger,
    protected EntityTypeManagerInterface $etm,
    protected FileSystemInterface $fs,
    protected BedrockMapper $mapper
  ) {}

  /**
   * KB-only builder (no invokeModel). Renders cleaned excerpts + citations.
   */
  public function createFromBriefKbOnly(?string $title, string $brief): Node {
    $kbId = \Drupal::config('sdc_pdf_gen.settings')->get('bedrock_kb_id');
    if (!$kbId) {
      $html = '<p><strong>KB not configured.</strong> Set <code>bedrock_kb_id</code> and <code>aws_region_kb</code>.</p>';
      return $this->saveHtmlNode($title ?: 'AI Draft (KB only)', $html);
    }

    $query = $this->makeKbQuery($brief);
    $hits  = $this->mapper->kbRetrieve($kbId, $query, 12); // retrieve only

    // Prepare sanitized passages for auto-title selection.
    $sanitized = [];
    foreach ($hits as $h) {
      $t = $this->sanitizeKbText((string) ($h['text'] ?? ''));
      if ($t !== '') {
        $sanitized[] = ['text' => $t, 'score' => $h['score'] ?? null, 'source' => $h['source'] ?? ''];
      }
    }

    // Title fallback from top highlight after sanitation (deterministic, no LLM)
    $autoTitle = null;
    if ($sanitized) {
      $hl = $this->extractHighlights(array_map(fn($r) => $r['text'], $sanitized), 1);
      if ($hl) {
        $first = $hl[0];
        $autoTitle = isset($first['title']) && is_string($first['title']) && $first['title'] !== ''
          ? $first['title']
          : mb_substr((string)($first['text'] ?? ''), 0, 120);
      }
    }

    $html = $this->renderKbHitsToHtml($brief, $hits);
    $this->logger->notice('KB-only: @n hits for query "@q"', ['@n' => count($hits), '@q' => $query]);

    return $this->saveHtmlNode($title ?: ($autoTitle ?: 'AI Draft (KB only)'), $html);
  }

  /**
   * LLM path (optional): keep for when you do want generation.
   */
  public function createFromBrief(?string $title, string $brief, bool $useKb = false, bool $useWeb = false): Node {
    $context = $this->collectContext($brief, $useKb, $useWeb);
    $html = $this->mapper->planFromBriefToHtml($brief, $context);
    return $this->saveHtmlNode($title ?: 'AI Draft', $html);
  }

  private function saveHtmlNode(string $title, string $html): Node {
    $node = Node::create([
      'type' => 'page',
      'title' => $title,
      'body' => ['value' => $html, 'format' => 'full_html'],
      'status' => 0,
    ]);
    $node->save();
    return $node;
  }

  /** Build a focused KB query from the brief for better recall. */
  private function makeKbQuery(string $brief): string {
    $brief = mb_strtolower($brief);
    $needles = [
      'fannie mae','duty to serve','rural','manufactured housing',
      'homeready','llpa','native american','ami','desktop underwriter',
      'colonias','lower mississippi delta','middle appalachia',
    ];
    $terms = array_values(array_filter($needles, fn($t) => str_contains($brief, $t)));
    if (!$terms) $terms = ['fannie mae','duty to serve','rural'];
    return 'Topic: ' . implode(', ', $terms);
  }

  /**
   * Render KB results into a clean HTML fragment with hero, highlights,
   * highlight cards (with anchors), grouped excerpts, and sources.
   */
  private function renderKbHitsToHtml(string $brief, array $hits): string {
    $safeBrief = nl2br(htmlspecialchars($brief, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    // (Optional) restrict to allowlisted domains
    $allow = \Drupal::config('sdc_pdf_gen.settings')->get('kb_allowed_domains');
    if (is_array($allow) && $allow) {
      $hits = array_values(array_filter($hits, function ($h) use ($allow) {
        $dom = $this->domainFromUrl((string) ($h['source'] ?? ''));
        return in_array($dom, $allow, true);
      }));
    }

    // Sanitize text, drop empties
    foreach ($hits as &$h) {
      $h['text'] = $this->sanitizeKbText((string) ($h['text'] ?? ''));
    }
    unset($h);
    $hits = array_values(array_filter($hits, fn($h) => $h['text'] !== ''));

    if (!$hits) {
      return <<<HTML
<style>
.kb-hero{margin:0 0 1.5rem}.kb-hero h1{margin:0 0 .25rem;font-size:1.75rem;line-height:1.2}.kb-hero p{margin:.25rem 0 0;color:#444}
</style>
<header class="kb-hero">
  <h1>Knowledge Base Summary</h1>
  <p>{$safeBrief}</p>
</header>
<p><em>No matching passages found.</em></p>
HTML;
    }

    // Sort by adjusted score, de-duplicate
    $this->adjustScoresByRecency($hits);
    usort($hits, fn($a, $b) => ($b['_adj'] ?? ($b['score'] ?? 0)) <=> ($a['_adj'] ?? ($a['score'] ?? 0)));
    $seen = []; $clean = [];
    foreach ($hits as $h) {
      $norm = md5(preg_replace('/\s+/u', ' ', mb_strtolower($h['text'])));
      if (isset($seen[$norm])) continue;
      $seen[$norm] = true;
      $clean[] = $h;
    }

    // Group by domain, max 2 per source
    $bySource = [];
    foreach ($clean as $h) {
      $dom = $this->domainFromUrl((string) ($h['source'] ?? '')) ?: 'Source';
      $bySource[$dom] = $bySource[$dom] ?? [];
      $bySource[$dom][] = $h;
    }
    foreach ($bySource as &$arr) {
      usort($arr, fn($a, $b) => ($b['_adj'] ?? ($b['score'] ?? 0)) <=> ($a['_adj'] ?? ($a['score'] ?? 0)));
      $arr = array_slice($arr, 0, 2);
    }
    unset($arr);

    // Global numbered sources
    $sources = [];
    foreach ($clean as $h) {
      $u = trim((string) ($h['source'] ?? ''));
      if ($u !== '' && !in_array($u, $sources, true)) $sources[] = $u;
    }
    $srcIndex = array_flip($sources);

    // Excerpts by source
    $bySourceHtml = '';
    foreach ($bySource as $dom => $arr) {
      $bySourceHtml .= '<section class="kb-card-by-source"><h3>'
        . htmlspecialchars($dom, ENT_QUOTES, 'UTF-8') . '</h3>';
      foreach ($arr as $h) {
        $raw = (string) $h['text'];
        $trimmed = $this->trimToSentence($raw, 420);
        $txt = htmlspecialchars($trimmed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $u   = trim((string) ($h['source'] ?? ''));
        $n   = isset($srcIndex[$u]) ? ($srcIndex[$u] + 1) : null;
        $cite = $n ? '<sup>[' . $n . ']</sup>' : '';
        $bySourceHtml .= '<blockquote class="kb-quote">' . $txt . '</blockquote><p class="kb-cite">' . $cite . '</p>';
      }
      $bySourceHtml .= '</section>';
    }

    // Highlights → list + cards + sections
    $highlights = $this->extractHighlights(array_map(fn($h) => $h['text'], $clean), 6);

    $hlListHtml = '';
    if ($highlights) {
      $hlListHtml = '<h2>Key highlights</h2><ul>';
      foreach ($highlights as $h) {
        $hlListHtml .= '<li>' . htmlspecialchars((string)($h['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
      }
      $hlListHtml .= '</ul>';
    }

    $cardsHtml = '';
    $sectionsHtml = '';
    if ($highlights) {
      $tops = array_slice($highlights, 0, 5);

      // Card grid
      $cardsHtml .= '<div class="kb-card-grid">';
      foreach ($tops as $h) {
        $title = (string) ($h['title'] ?? 'Highlight');
        $slug  = (string) ($h['slug'] ?? 'section');
        $cardsHtml .= '<article class="kb-card">'
          . '<h3 class="kb-card__title">' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
          . '<p class="kb-card__desc">Learn more about this highlight pulled from the knowledge base.</p>'
          . '<a class="kb-card__cta" href="#' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">Read more →</a>'
          . '</article>';
      }
      $cardsHtml .= '</div>';

      // Detail sections (anchors)
      foreach ($tops as $h) {
        $title = (string) ($h['title'] ?? 'Detail');
        $slug  = (string) ($h['slug'] ?? 'section');
        $text  = (string) ($h['text'] ?? '');
        $sectionsHtml .= '<section id="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '" class="kb-section">'
          . '<h2>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>'
          . '<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
          . '</section>';
      }
    }

    // Sources list
    $srcHtml = '';
    if ($sources) {
      $srcHtml .= '<div class="kb-sources"><h3>Sources</h3><ol>';
      foreach ($sources as $i => $u) {
        $esc = htmlspecialchars($u, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $srcHtml .= '<li><a href="' . $esc . '" target="_blank" rel="noopener noreferrer">' . $esc . '</a></li>';
      }
      $srcHtml .= '</ol></div>';
    }

    $hitCount = count($clean);

    // Inline, body-scoped CSS only
    $css = <<<CSS
<style>
/* --- KB base --- */
.kb-hero{margin:0 0 1.5rem}
.kb-hero h1{margin:0 0 .25rem;font-size:1.75rem;line-height:1.2}
.kb-hero p{margin:.25rem 0 0;color:#444}
.kb-section{margin:1.5rem 0}
.kb-section h2{margin:0 0 .5rem;font-size:1.25rem;line-height:1.3}
.kb-quote{margin:.5rem 0;padding:.75rem 1rem;border-left:4px solid #e6e6e6;background:#fafafa}
.kb-cite{margin:.25rem 0 0;color:#666;font-size:.95rem}
.kb-card-by-source{margin:1rem 0 1.25rem}
.kb-cards-by-source{margin:1rem 0 1.5rem}

/* --- Highlight cards --- */
.kb-card-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));margin:0 0 1.5rem}
.kb-card{border:1px solid #e6e6e6;border-radius:12px;padding:1rem;background:#fff;box-shadow:0 1px 0 rgba(0,0,0,.03)}
.kb-card__title{margin:.25rem 0 .5rem;font-size:1.05rem}
.kb-card__desc{margin:0 0 .75rem;color:#555}
.kb-card__cta{text-decoration:none;font-weight:600}

/* --- Sources --- */
.kb-sources ol{padding-left:1.25rem}
.kb-sources li{margin:.35rem 0;word-break:break-word}
</style>
CSS;

    return <<<HTML
{$css}
<!-- KB_ONLY=true; HITS={$hitCount} -->
<header class="kb-hero">
  <h1>Knowledge Base Summary</h1>
  <p>{$safeBrief}</p>
</header>

{$hlListHtml}

{$cardsHtml}

<div class="kb-cards-by-source">{$bySourceHtml}</div>

{$sectionsHtml}

{$srcHtml}
HTML;
  }

  /** Optional context for LLM path (kept minimal). */
  private function collectContext(string $brief, bool $useKb, bool $useWeb): string {
    return '';
  }

  // ----------------------- Helpers -----------------------

  /** Strip boilerplate/disclaimers/nav and return substantive text only. */
  private function sanitizeKbText(string $txt): string {
    if ($txt === '') return '';

    // Remove markdown images and headings
    $txt = preg_replace('/!\[[^\]]*]\([^)]+\)/u', ' ', $txt);
    $txt = preg_replace('/^#{1,6}\s+/mu', ' ', $txt);

    // Kill boilerplate / disclaimers
    $killRe = [
      '/\bSkip to main content\b/i',
      '/\bToggle navigation\b/i',
      '/\b(Site|Platform)\s+Nav\b/i',
      '/\bHomepage\b/i',
      '/\bLogin\b/i',
      '/\bFannie Mae logo\b/i',
      '/\bThis summary is intended for reference only\b/i',
      '/\bSelling Guide will govern\b/i',
      '/\bTrademarks of Fannie Mae\b/i',
      '/^Knowledge Base$/im',
      '/©\s*\d{4}\s*Fannie Mae/i',
      '/\bBack to top\b/i',
      '/\bFooter\b/i',
      '/\bAll rights reserved\b/i',
      '/\bLast updated\b/i',
      '/\bContact Us\b/i',
      '/\bAppraiser Update\b/i',
      '/\bSpecial Edition\b/i',
    ];
    foreach ($killRe as $rx) { $txt = preg_replace($rx, ' ', $txt); }

    // Split + filter out nav/title-ish lines
    $lines = preg_split('/\R/u', $txt);
    $keep = [];
    foreach ($lines as $line) {
      $line = trim(preg_replace('/\s{2,}/u', ' ', $line));
      if ($line === '') continue;

      if (mb_strlen($line) < 60) continue;                     // too short
      if (substr_count($line, '|') >= 1) continue;             // title bars
      $manyBullets = (substr_count($line, '*') >= 4) || preg_match('/\x{2022}/u', $line);
      $hasMenuWords = preg_match('/\b(Home|Single-?Family|Pricing|Execution|Servicing|Learning Center|Apps|Technology|News|Events|About Us|Careers|Investor Relations|Data and Insights)\b/i', $line);
      if ($manyBullets && $hasMenuWords) continue;

      $allCaps = preg_match_all('/\b[A-Z]{3,}\b/u', $line);
      $words   = max(1, preg_match_all('/\b\w+\b/u', $line));
      if ($words > 0 && ($allCaps / $words) > 0.3) continue;

      $keep[] = $line;
    }

    $txt = trim(implode(' ', $keep));
    $txt = preg_replace('/\s{2,}/u', ' ', $txt);

    return (mb_strlen($txt) < 80) ? '' : $txt;
  }

  /**
   * Extract readable highlights and return structured items:
   * ['title'=>string,'text'=>string,'slug'=>string,'score'=>int]
   */
  private function extractHighlights(array $texts, int $max = 6): array {
    $kw = [
      'duty to serve','rural','homeready','llpa','manufactured housing',
      'native american','appraisal','valuation','ami','desktop underwriter',
      'colonias','lower mississippi delta','middle appalachia',
    ];

    $slugify = function (string $s): string {
      $s = mb_strtolower($s);
      $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
      $s = trim($s, '-');
      return $s ?: 'section';
    };

    $makeTitle = function (string $s): string {
      $clean = trim($s);
      if (mb_strlen($clean) > 90) {
        $cut = mb_substr($clean, 0, 90);
        if (preg_match('/^(.{50,90}?[.:;])\s/u', $cut, $m)) {
          $clean = $m[1];
        } else {
          $clean = rtrim($cut, " \t\n\r\0\x0B,;:") . '…';
        }
      }
      return ucfirst($clean);
    };

    $scored = [];
    foreach ($texts as $t) {
      $sentences = preg_split('/(?<=[.?!])\s+/u', (string) $t);
      foreach ($sentences as $s) {
        $s = trim($s);
        if ($s === '') continue;

        $len = mb_strlen($s);
        if ($len < 50 || $len > 260) continue;
        if (str_contains($s, '|')) continue;
        if (preg_match('/\b(Toggle|Homepage|Login|Site Nav|Platform Nav|Footer|Contact Us)\b/i', $s)) continue;
        if (preg_match('/(?:\*|\x{2022}).*(?:\*|\x{2022})/u', $s)) continue;
        if (preg_match_all('/\b[A-Z]{3,}\b/u', $s) >= 3) continue;

        $score = 0;
        $ls = mb_strtolower($s);
        foreach ($kw as $k) { if (str_contains($ls, $k)) $score++; }
        $score += min(3, (int) floor($len / 120));

        $scored[] = ['text' => $s, 'score' => $score];
      }
    }

    usort($scored, fn($a,$b) => $b['score'] <=> $a['score']);

    $out = []; $seen = [];
    foreach ($scored as $row) {
      $key = md5(mb_strtolower(preg_replace('/\s+/u',' ', $row['text'])));
      if (isset($seen[$key])) continue;
      $seen[$key] = 1;

      $title = $makeTitle($row['text']);
      $slug  = $slugify($title);

      $out[] = [
        'title' => $title,
        'text'  => $row['text'],
        'slug'  => $slug,
        'score' => (int) $row['score'],
      ];
      if (count($out) >= $max) break;
    }
    return $out;
  }

  /** Prefer recent passages by subtracting a small penalty for older years. */
  private function adjustScoresByRecency(array &$hits): void {
    foreach ($hits as &$h) {
      $t = (string) ($h['text'] ?? '');
      $base = (float) ($h['score'] ?? 0);
      $pen = 0.0;
      if (preg_match('/\b(20\d{2})\b/', $t, $m)) {
        $age = max(0, (int) date('Y') - (int) $m[1]);
        $pen = min(0.25 * $age, 2.0);
      }
      $h['_adj'] = $base - $pen;
    }
    unset($h);
  }

  /** Trim text to a clean sentence end near the max length. */
  private function trimToSentence(string $txt, int $max): string {
    if (mb_strlen($txt) <= $max) return $txt;
    $cut = mb_substr($txt, 0, $max);
    if (preg_match('/^(.{80,})\.\s/u', $cut, $m)) return $m[1] . '.';
    return rtrim($cut, " \t\n\r\0\x0B,;:") . '…';
  }

  /** Get the domain from a URL (or a friendly label). */
  private function domainFromUrl(string $url): string {
    $host = parse_url($url, PHP_URL_HOST);
    return $host ?: ($url ? 'Source' : 'Knowledge Base');
  }

  /** First sentence helper (kept for optional uses). */
  private function firstSentence(string $text): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') return '';
    if (preg_match('/^(.{20,200}?\.)\s/u', $text, $m)) return $m[1];
    return mb_substr($text, 0, 200);
  }
}
