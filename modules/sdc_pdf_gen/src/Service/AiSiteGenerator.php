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
  
    // Build an initial, tolerant query and fetch plenty of passages.
    $query = $this->makeKbQuery($brief);
    $hits  = $this->mapper->kbRetrieve($kbId, $query, 24); // higher topK for resilience
    $hits  = $this->filterHitsByRentalTerms($hits);
  
    // Extract salient terms from the brief (used for alignment and a tight retry).
    $terms = $this->extractQueryTerms($brief);
  
    // Phase A: keep hits that actually contain our terms (loosely).
    $hitsAligned = $this->filterHitsByTerms($hits, $terms);
  
    // Phase B: if no aligned hits, retry KB with only salient terms (tight).
    if (!$hitsAligned) {
      $tight = implode(' ', $terms);
      if ($tight !== '') {
        $this->logger->notice('KB tight retry for "@q" → "@t"', ['@q' => $query, '@t' => $tight]);
        $retry = $this->mapper->kbRetrieve($kbId, $tight, 24);
        $hitsAligned = $this->filterHitsByTerms($retry, $terms);
        if (!$hitsAligned && $retry) {
          // keep something rather than nothing
          $hitsAligned = $retry;
        }
      }
    }
  
    // Optional: bias to Fannie Mae domains if there’s a mix (keep if in allowlist)
    $allow = ['singlefamily.fanniemae.com','www.fanniemae.com','fanniemae.com'];
    $domainBiased = array_values(array_filter($hitsAligned, function ($h) use ($allow) {
      $dom = $this->domainFromUrl((string)($h['source'] ?? ''));
      return in_array($dom, $allow, true);
    }));
    if ($domainBiased) {
      $hitsAligned = $domainBiased;
    }
  
    // Try a “dry sanitize” to see if our standard sanitizer wipes everything.
    $probe = [];
    foreach ($hitsAligned as $h) {
      $probe[] = $this->sanitizeKbText((string)($h['text'] ?? ''), /*relaxed*/ false);
    }
    $allEmpty = !array_filter($probe, fn($t) => $t !== '');
  
    // If standard sanitize kills everything, render with RELAXED sanitize.
    if ($allEmpty) {
      $this->logger->warning('KB sanitize wiped results; retrying with relaxed sanitizer.');
      // Temporarily map texts to a preserved copy for rendering path.
      $hitsForRelaxed = $hitsAligned; // render method will call sanitize again (with relaxed flag)
      $html = $this->renderKbHitsToHtml($brief, $hitsForRelaxed, /*relaxed*/ true);
    } else {
      $html = $this->renderKbHitsToHtml($brief, $hitsAligned, /*relaxed*/ false);
    }
  
    // Auto-title from top highlight (after sanitize; prefer structured)
    $sanitized = [];
    foreach ($hitsAligned as $h) {
      $t = $this->sanitizeKbText((string)($h['text'] ?? ''), /*relaxed*/ true);
      if ($t !== '') $sanitized[] = ['text' => $t, 'score' => $h['score'] ?? null, 'source' => $h['source'] ?? ''];
    }
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
  
    $this->logger->notice('KB-only: @n hits for query "@q"', ['@n' => count($hitsAligned), '@q' => $query]);
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
 // Replace your makeKbQuery() with this:
private function makeKbQuery(string $brief): string {
  $s = mb_strtolower($brief);

  // Add rental-income & eligibility vocabulary
  $needles = [
    // core brand/contexts
    'fannie mae','selling guide','desktop underwriter','du',
    // rental income topics
    'rental income','schedule e','form 1007','form 1025',
    'lease','security deposit','bank statements',
    'principal residence','housing expense','pitia',
    'b3-3.1-08','b3-3','b3','b2-2-04','b2-3-02','b2-3-03',
    'non-occupant borrower','guarantor','factory-built housing','leasehold estate',
    // keep rural set (still useful for other briefs)
    'duty to serve','rural','manufactured housing','mh advantage',
    'homeready','llpa','native american','ami','colonias',
    'lower mississippi delta','middle appalachia','persistent poverty',
  ];

  $terms = array_values(array_filter($needles, fn($t) => str_contains($s, $t)));

  // If user typed generic text (e.g., “rental income support”), seed with strong defaults
  if (!$terms) {
    $terms = ['rental income','b3-3.1-08','form 1007','form 1025','schedule e','selling guide'];
  }

  // Use a succinct “topic” prompt; KB retrieval tends to like short exact phrases
  return 'Topic: ' . implode(', ', array_unique($terms));
}


  /** Extract 3–10 salient lowercase terms from the user brief. */
private function extractQueryTerms(string $brief): array {
  $b = mb_strtolower($brief);
  // keep letters, numbers, spaces
  $b = preg_replace('/[^\\p{L}\\p{N}\\s]/u', ' ', $b);
  $parts = preg_split('/\\s+/u', $b, -1, PREG_SPLIT_NO_EMPTY);

  $stop = [
    'the','a','an','and','or','of','in','to','for','with','on','at','by','from','as','is','are','was','were',
    'this','that','these','those','it','its','be','can','may','any','more','most','about','how','what',
    'fannie','mae','single','family','guide','selling','policy','policies'
  ];

  $freq = [];
  foreach ($parts as $w) {
    if (mb_strlen($w) < 3) continue;
    if (in_array($w, $stop, true)) continue;
    $freq[$w] = ($freq[$w] ?? 0) + 1;
  }

  arsort($freq);
  $terms = array_keys($freq);
  // keep between 3 and 10 terms
  return array_slice($terms, 0, max(3, min(10, count($terms))));
}

/** Keep only hits that mention at least one of the query terms (unless no terms). */
private function filterHitsByTerms(array $hits, array $terms): array {
  if (!$terms) return $hits;
  $out = [];
  foreach ($hits as $h) {
    $txt = mb_strtolower((string)($h['text'] ?? ''));
    foreach ($terms as $t) {
      if ($t !== '' && str_contains($txt, $t)) { $out[] = $h; break; }
    }
  }
  // if filtering removed everything, return originals (don’t over-filter)
  return $out ?: $hits;
}


  /**
   * Render KB results into a clean HTML fragment with hero, highlights,
   * highlight cards (with anchors), grouped excerpts, and sources.
   */
  /**
 * Turn KB snippets into a clean HTML fragment with:
 * - hero (optional generated image)
 * - key highlights (list)
 * - highlight cards (CTAs jump to detail sections; optional images)
 * - excerpts grouped by source (2 per source)
 * - numbered sources list
 *
 * @param string $brief  Original user brief
 * @param array  $hits   KB hits: each ['text'=>string,'score'=>float,'source'=>url]
 * @return string        HTML fragment (no <html>/<body>)
 */
private function renderKbHitsToHtml(string $brief, array $hits): string {
  $safeBrief = nl2br(htmlspecialchars($brief, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

  // (Optional) restrict to configured allowlisted domains
  $allow = \Drupal::config('sdc_pdf_gen.settings')->get('kb_allowed_domains');
  if (is_array($allow) && $allow) {
    $hits = array_values(array_filter($hits, function ($h) use ($allow) {
      $dom = $this->domainFromUrl((string) ($h['source'] ?? ''));
      return in_array($dom, $allow, true);
    }));
  }

  // 1) sanitize text, drop empties
  foreach ($hits as &$h) {
    $h['text'] = $this->sanitizeKbText((string) ($h['text'] ?? ''));
  }
  unset($h);
  $hits = array_values(array_filter($hits, fn($h) => $h['text'] !== ''));

  // If nothing usable, return minimal fragment
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

  // 2) sort by adjusted score (recency-penalized), then de-duplicate
  $this->adjustScoresByRecency($hits);
  usort($hits, fn($a,$b) => ($b['_adj'] ?? ($b['score'] ?? 0)) <=> ($a['_adj'] ?? ($a['score'] ?? 0)));
  $seen = []; $clean = [];
  foreach ($hits as $h) {
    $norm = md5(preg_replace('/\s+/u', ' ', mb_strtolower($h['text'])));
    if (isset($seen[$norm])) continue;
    $seen[$norm] = true;
    $clean[] = $h;
  }

  // 3) group by source domain, cap 2 excerpts per source
  $bySource = [];
  foreach ($clean as $h) {
    $dom = $this->domainFromUrl((string) ($h['source'] ?? '')) ?: 'Source';
    $bySource[$dom] = $bySource[$dom] ?? [];
    $bySource[$dom][] = $h;
  }
  foreach ($bySource as &$arr) {
    usort($arr, fn($a,$b) => ($b['_adj'] ?? ($b['score'] ?? 0)) <=> ($a['_adj'] ?? ($a['score'] ?? 0)));
    $arr = array_slice($arr, 0, 2);
  }
  unset($arr);

  // 4) unique sources list
  $sources = [];
  foreach ($clean as $h) {
    $u = trim((string) ($h['source'] ?? ''));
    if ($u !== '' && !in_array($u, $sources, true)) $sources[] = $u;
  }
  $srcIndex = array_flip($sources);

  // 5) by-source excerpts
  $bySourceHtml = '';
  foreach ($bySource as $dom => $arr) {
    $bySourceHtml .= '<section class="kb-card-by-source"><h3>'
      . htmlspecialchars($dom, ENT_QUOTES, 'UTF-8') . '</h3>';
    foreach ($arr as $h) {
      $raw = $h['text'];
      $trimmed = $this->trimToSentence($raw, 420);
      $txt = htmlspecialchars($trimmed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $u   = trim((string) ($h['source'] ?? ''));
      $n   = isset($srcIndex[$u]) ? ($srcIndex[$u] + 1) : null;
      $cite = $n ? '<sup>[' . $n . ']</sup>' : '';
      $bySourceHtml .= '<blockquote class="kb-quote">' . $txt . '</blockquote><p class="kb-cite">' . $cite . '</p>';
    }
    $bySourceHtml .= '</section>';
  }

  // 6) highlights → list + card grid + detail sections
  $highlights = $this->extractHighlights(array_map(fn($h) => $h['text'], $clean), 6);

  $hlListHtml = '';
  if ($highlights) {
    $hlListHtml = '<h2>Key highlights</h2><ul>';
    foreach ($highlights as $h) {
      $hlListHtml .= '<li>' . htmlspecialchars($h['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
    }
    $hlListHtml .= '</ul>';
  }

  // --------- Optional generated images (hero + card thumbs) ----------
  $cfg = \Drupal::config('sdc_pdf_gen.settings');
  $imgEnabled = (bool) ($cfg->get('enable_generated_images') ?? false);
  $images = ['hero' => null, 'cards' => []];

  if ($imgEnabled && property_exists($this, 'imageGen') && $this->imageGen) {
    // Hero prompt (safe, generic—no logos/text)
    $heroPrompt = "Illustration of rural main street small businesses, diverse owners, sunny, optimistic, editorial style, no logos, no text";
    $heroAlt    = "Rural small businesses on a main street";
    try {
      if ($img = $this->imageGen->generateOne($heroPrompt, $heroAlt, 1600, 900)) {
        $images['hero'] = $img;
      }
    } catch (\Throwable $e) {
      $this->logger->warning('Hero image generation skipped: @m', ['@m' => $e->getMessage()]);
    }

    // Card prompts from top highlights (up to 4)
    $topsForImages = array_slice($highlights ?? [], 0, 4);
    foreach ($topsForImages as $h) {
      $p = "Flat illustration representing: " . $h['title'] .
           ". Focus on concepts, no logos, no text overlays, consistent palette, editorial.";
      $alt = $h['title'];
      try {
        if ($img = $this->imageGen->generateOne($p, $alt, 800, 450)) {
          $images['cards'][] = $img;
        } else {
          $images['cards'][] = null;
        }
      } catch (\Throwable $e) {
        $this->logger->warning('Card image generation skipped: @m', ['@m' => $e->getMessage()]);
        $images['cards'][] = null;
      }
    }
  }

  // Card grid (top 5 highlights)
  $cardsHtml = '';
  $sectionsHtml = '';
  if ($highlights) {
    $tops = array_slice($highlights, 0, 5);

    $cardsHtml .= '<div class="kb-card-grid">';
    foreach ($tops as $i => $h) {
      $thumb = $images['cards'][$i] ?? null;
      $imgTag = '';
      if ($thumb && !empty($thumb['uri'])) {
        $src = file_create_url($thumb['uri']);
        $alt = htmlspecialchars($thumb['alt'] ?? $h['title'], ENT_QUOTES);
        $imgTag = '<div class="kb-card__image"><img loading="lazy" src="'.$src.'" alt="'.$alt.'"></div>';
      }

      $cardsHtml .= '<article class="kb-card">'
        . $imgTag
        . '<h3 class="kb-card__title">' . htmlspecialchars($h['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
        . '<p class="kb-card__desc">Learn more about this highlight pulled from the knowledge base.</p>'
        . '<a class="kb-card__cta" href="#' . htmlspecialchars($h['slug'], ENT_QUOTES, 'UTF-8') . '">Read more →</a>'
        . '</article>';
    }
    $cardsHtml .= '</div>';

    // Detail sections (targets for CTAs)
    foreach ($tops as $h) {
      $sectionsHtml .= '<section id="' . htmlspecialchars($h['slug'], ENT_QUOTES, 'UTF-8') . '" class="kb-section">'
        . '<h2>' . htmlspecialchars($h['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>'
        . '<p>' . htmlspecialchars($h['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        . '</section>';
    }
  }

  // 7) sources list
  $srcHtml = '';
  if ($sources) {
    $srcHtml .= '<div class="kb-sources"><h3>Sources</h3><ol>';
    foreach ($sources as $i => $u) {
      $esc = htmlspecialchars($u, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $srcHtml .= '<li><a href="' . $esc . '" target="_blank" rel="noopener noreferrer">' . $esc . '</a></li>';
    }
    $srcHtml .= '</ol></div>';
  }

  // 8) build hero (with optional image)
  $heroImg = '';
  if (!empty($images['hero']['uri'])) {
    $src = file_create_url($images['hero']['uri']);
    $alt = htmlspecialchars($images['hero']['alt'] ?? 'Hero image', ENT_QUOTES);
    $heroImg = '<div class="kb-hero__image"><img src="'.$src.'" alt="'.$alt.'"></div>';
  }

  // 9) minimal body-scoped CSS
  $css = <<<CSS
<style>
/* --- KB base --- */
.kb-hero{margin:0 0 1.5rem;display:grid;gap:1rem;align-items:center}
.kb-hero__image img{width:100%;height:auto;border-radius:12px;display:block}
.kb-hero h1{margin:0 0 .25rem;font-size:1.75rem;line-height:1.2}
.kb-hero p{margin:.25rem 0 0;color:#444}
.kb-section{margin:1.5rem 0}
.kb-section h2{margin:0 0 .5rem;font-size:1.25rem;line-height:1.3}
.kb-quote{margin:.5rem 0;padding:.75rem 1rem;border-left:4px solid #e6e6e6;background:#fafafa;border-radius:6px}
.kb-cite{margin:.25rem 0 0;color:#666;font-size:.95rem}
.kb-card-by-source{margin:1rem 0 1.25rem}
.kb-cards-by-source{margin:1rem 0 1.5rem}

/* --- Highlight cards --- */
.kb-card-grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));margin:0 0 1.5rem}
.kb-card{border:1px solid #e6e6e6;border-radius:12px;padding:1rem;background:#fff;box-shadow:0 1px 0 rgba(0,0,0,.03)}
.kb-card__image{margin:-1rem -1rem .75rem -1rem}
.kb-card__image img{width:100%;height:auto;display:block;border-radius:12px 12px 0 0}
.kb-card__title{margin:.25rem 0 .5rem;font-size:1.05rem}
.kb-card__desc{margin:0 0 .75rem;color:#555}
.kb-card__cta{text-decoration:none;font-weight:600}

/* --- Sources --- */
.kb-sources ol{padding-left:1.25rem}
.kb-sources li{margin:.35rem 0;word-break:break-word}
</style>
CSS;

  // 10) assemble final HTML fragment
  $hitCount = count($clean);
  return <<<HTML
{$css}
<!-- KB_ONLY=true; HITS={$hitCount} -->
<header class="kb-hero">
  <div>
    <h1>Knowledge Base Summary</h1>
    <p>{$safeBrief}</p>
  </div>
  {$heroImg}
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
  // Tweak your sanitizeKbText() thresholds & rules:
private function sanitizeKbText(string $txt, bool $relaxed = false): string {
  if ($txt === '') return '';
  $txt = preg_replace('/!\[[^\]]*]\([^)]+\)/u', ' ', $txt);
  $txt = preg_replace('/^#{1,6}\s+/mu', ' ', $txt);

  // Keep kill list minimal for guide content
  $killRe = [
    '/\bSkip to main content\b/i',
    '/\bToggle navigation\b/i',
    '/\b(Site|Platform)\s+Nav\b/i',
    '/\bHomepage\b/i',
    '/\bLogin\b/i',
    '/\bFannie Mae logo\b/i',
  ];
  foreach ($killRe as $rx) { $txt = preg_replace($rx, ' ', $txt); }

  $lines = preg_split('/\R/u', $txt);
  $keep = [];
  foreach ($lines as $line) {
    $line = trim(preg_replace('/\s{2,}/u', ' ', $line));
    if ($line === '') continue;

    // Allow shorter lines if they contain guide tokens (B3-3.1-08, Form 1007, etc.)
    $hasGuideToken = (bool) preg_match('/\bB[0-9]-[0-9.]+|Form\s+1007|Form\s+1025|B3-3\.1-08|Schedule\s+E/i', $line);
    $minLen = $hasGuideToken ? 28 : ($relaxed ? 30 : 60);
    if (mb_strlen($line) < $minLen) continue;

    // Permit lines with '|' if they also contain guide tokens (prevents title-bars but keeps spec lines)
    if (substr_count($line, '|') >= 1 && !$hasGuideToken) continue;

    // Nav soup
    $manyBullets = (substr_count($line, '*') >= 6) || preg_match('/\x{2022}/u', $line);
    $hasMenuWords = preg_match('/\b(Home|Single-?Family|Pricing|Execution|Servicing|Learning Center|Apps|Technology|News|Events|About Us|Careers|Investor Relations|Data and Insights)\b/i', $line);
    if (!$relaxed && $manyBullets && $hasMenuWords) continue;

    $keep[] = $line;
  }

  $txt = trim(implode(' ', $keep));
  $txt = preg_replace('/\s{2,}/u', ' ', $txt);
  return (mb_strlen($txt) < ($relaxed ? 60 : 80)) ? '' : $txt;
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

  // Drop this near other helpers
private function filterHitsByRentalTerms(array $hits): array {
  if (!$hits) return $hits;

  $terms = [
    'rental income','schedule e','form 1007','form 1025','lease','bank statements',
    'security deposit','pitia','principal residence','housing expense',
    'b3-3.1-08','b2-2-04','b2-3-02','b2-3-03'
  ];
  $terms = array_map('mb_strtolower', $terms);

  $out = [];
  foreach ($hits as $h) {
    $txt = mb_strtolower((string)($h['text'] ?? ''));
    if ($txt === '') continue;
    $matched = 0;
    foreach ($terms as $t) {
      if (str_contains($txt, $t)) $matched++;
    }
    if ($matched > 0) {
      // Light score boost so these float to the top
      $h['score'] = (float)($h['score'] ?? 0) + min(0.2 * $matched, 1.0);
      $out[] = $h;
    }
  }
  // Fall back to original if everything was filtered out
  return $out ?: $hits;
}

}
