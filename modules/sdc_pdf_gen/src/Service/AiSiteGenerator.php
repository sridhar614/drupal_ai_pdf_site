<?php

namespace Drupal\sdc_pdf_gen\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;
use Psr\Log\LoggerInterface;

/**
 * Hackathon: KB→LLM (plan) → HTML body renderer (no LB/Paragraphs).
 * - Retrieves passages from Bedrock KB
 * - Lets LLM plan highlights/cards/quotes (grounded; no links/new facts)
 * - Renders clean HTML + inline CSS into Basic page body
 */
class AiSiteGenerator {

  public function __construct(
    protected LoggerInterface $logger,
    protected EntityTypeManagerInterface $etm,
    protected FileSystemInterface $fs,
    protected BedrockMapper $mapper
  ) {}

  /**
   * Create a Basic Page from a brief using KB retrieval and LLM planning.
   */
  public function createFromBriefKbOnly(?string $title, string $brief): Node {
    $cfg  = \Drupal::config('sdc_pdf_gen.settings');
    $kbId = (string) ($cfg->get('bedrock_kb_id') ?? '');
    if ($kbId === '') {
      return $this->saveHtmlNode(
        $title ?: 'AI Draft (KB only)',
        '<p><strong>KB not configured.</strong> Set <code>bedrock_kb_id</code> and <code>aws_region_kb</code>.</p>'
      );
    }

    // Retrieve with a few focused variants to improve recall.
    $variants = $this->buildQueryVariants($brief);
    $hits = $this->retrieveMergeDedup($kbId, $variants, 80);
    if (!$hits) {
      return $this->saveHtmlNode($title ?: 'AI Draft (KB only)', '<p><em>No matching passages found.</em></p>');
    }

    // Score & sort (prefer fresher content).
    $this->adjustScoresByRecency($hits);
    usort($hits, fn($a,$b) => ($b['_adj'] ?? ($b['score'] ?? 0)) <=> ($a['_adj'] ?? ($a['score'] ?? 0)));

    // Build rich HTML body (LLM plan with deterministic fallback).
    $bodyHtml = $this->renderRichBody($brief, $hits);

    $node = Node::create([
      'type'   => 'page',
      'title'  => $title ?: $this->autoTitle($brief, $hits),
      'body'   => ['value' => $bodyHtml, 'format' => 'full_html'],
      'status' => 0,
    ]);
    $node->save();

    return $node;
  }

  // ---------------------------------------------------------------------------
  // Rich renderer (hero + optional intro + highlights + card grid + quotes)
  // ---------------------------------------------------------------------------

  private function renderRichBody(string $brief, array $hits): string {
    $safeBrief = nl2br(htmlspecialchars($brief, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    // Prepare cleaned, deduped passages.
    $seen = []; $clean = [];
    foreach ($hits as $h) {
      $txt = $this->sanitizeKbText((string)($h['text'] ?? ''), true);
      if ($txt === '') continue;
      $src = (string)($h['source'] ?? '');
      $key = md5(mb_strtolower(preg_replace('/\s+/u',' ', $txt)).'|'.$src);
      if (isset($seen[$key])) continue;
      $seen[$key] = 1;
      $h['text'] = $txt;
      $clean[] = $h;
    }
    if (!$clean) return '<p><em>No usable passages.</em></p>';

    // 1) Try Gen-AI section planner first (grounded in KB).
    $planned = $this->mapper->planRichSections($brief, $clean, 6);

    // If model returned something usable, render from it; else fallback path.
    if ($planned && (!empty($planned['highlights']) || !empty($planned['cards']) || !empty($planned['quotes']) || !empty($planned['intro']))) {

      $css = <<<CSS
<style>
.ai-kb { --gap:1rem; --muted:#555; --border:#e6e6e6; --bg:#fafafa; }
.ai-kb h1,.ai-kb h2,.ai-kb h3 { line-height:1.25; }
.ai-kb .hero{margin:0 0 1.5rem}
.ai-kb .hero h1{margin:0 0 .25rem;font-size:1.75rem}
.ai-kb .hero p{color:#444;margin:.25rem 0 0}
.ai-kb .highlights{margin:1rem 0}
.ai-kb .highlights ul{padding-left:1.25rem}
.ai-kb .cards{display:grid;gap:var(--gap);grid-template-columns:repeat(auto-fit,minmax(260px,1fr));margin:1rem 0 1.25rem}
.ai-kb .card{border:1px solid var(--border);border-radius:12px;padding:1rem;background:#fff;box-shadow:0 1px 0 rgba(0,0,0,.03)}
.ai-kb .card h3{margin:.25rem 0 .5rem;font-size:1.1rem}
.ai-kb .card p{margin:0 0 .75rem;color:var(--muted)}
.ai-kb .card a{text-decoration:none;font-weight:600}
.ai-kb blockquote{margin:.5rem 0;padding:.75rem 1rem;border-left:4px solid var(--border);background:var(--bg);border-radius:6px}
.ai-kb .bydom{margin:1.25rem 0}
.ai-kb .cite{margin:.25rem 0 0;color:#666;font-size:.95rem}
</style>
CSS;

      $hero = '<header class="hero"><p>'.$safeBrief.'</p></header>';

      // Optional intro/body from model (short paragraph).
      $introHtml = '';
      if (!empty($planned['intro'])) {
        $introHtml = '<p>'.htmlspecialchars($planned['intro'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</p>';
      }

      // Highlights
      $hl = '';
      if (!empty($planned['highlights'])) {
        $hl .= '<div class="highlights"><h2>Key highlights</h2><ul>';
        foreach ($planned['highlights'] as $t) {
          $hl .= '<li>'.htmlspecialchars($t, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</li>';
        }
        $hl .= '</ul></div>';
      }

      // Cards (already summarized by LLM)
      $cardsHtml = '';
      if (!empty($planned['cards'])) {
        $cardsHtml .= '<div class="cards">';
        foreach ($planned['cards'] as $i => $c) {
          $title = htmlspecialchars($c['title'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
          $blurb = htmlspecialchars($c['blurb'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
          // anchor slug for "Read more" – not strictly needed but nice
          $slug  = trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($c['title'])), '-') ?: 'card-'.$i;
          $cardsHtml .= '<article class="card">'
            . '<h3>'.$title.'</h3>'
            . '<p>'.$blurb.'</p>'
            . '<a href="#'.$slug.'">Read more →</a>'
            . '</article>';
        }
        $cardsHtml .= '</div>';
      }

      // Quotes (flat list)
      $quotesHtml = '';
      if (!empty($planned['quotes'])) {
        $quotesHtml .= '<section class="bydom"><h3>Quotes</h3>';
        foreach ($planned['quotes'] as $q) {
          $qt = htmlspecialchars($q['text'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
          $dm = !empty($q['domain']) ? ' <span class="cite">— '.htmlspecialchars($q['domain'], ENT_QUOTES).'</span>' : '';
          $quotesHtml .= '<blockquote>'.$qt.$dm.'</blockquote>';
        }
        $quotesHtml .= '</section>';
      }

      $hitCount = count($clean);
      return <<<HTML
{$css}
<div class="ai-kb">
  <!-- KB_ONLY=true; LLM_PLANNED=true; HITS={$hitCount} -->
  {$hero}
  {$introHtml}
  {$hl}
  {$cardsHtml}
  {$quotesHtml}
</div>
HTML;
    }

    // 2) Fallback: deterministic renderer (still nice for demos)
    // Extract highlights locally.
    $highlights = $this->extractHighlights(array_map(fn($h)=>$h['text'],$clean), 6);
    $highlightTexts = array_map(fn($h)=>$h['text'], $highlights);

    // Light blurbing via LLM (still grounded) with deterministic fallback.
    $blurbs = $this->mapper->summarizeHighlightsToBlurbs($brief, $highlightTexts, 2);
    if (!$blurbs) {
      $blurbs = array_map(fn($t)=>$this->trimToSentence($t, 220), $highlightTexts);
    }

    // Group quotes by domain (top 2 per domain).
    $bySource = [];
    foreach ($clean as $h) {
      $dom = $this->domainFromUrl((string)($h['source'] ?? '')) ?: 'Source';
      $bySource[$dom] = $bySource[$dom] ?? [];
      $bySource[$dom][] = $h;
    }
    foreach ($bySource as &$arr) {
      usort($arr, fn($a,$b)=>($b['_adj']??($b['score']??0))<=>($a['_adj']??($a['score']??0)));
      $arr = array_slice($arr, 0, 2);
    }
    unset($arr);

    $css = <<<CSS
<style>
.ai-kb { --gap:1rem; --muted:#555; --border:#e6e6e6; --bg:#fafafa; }
.ai-kb h1,.ai-kb h2,.ai-kb h3 { line-height:1.25; }
.ai-kb .hero{margin:0 0 1.5rem}
.ai-kb .hero h1{margin:0 0 .25rem;font-size:1.75rem}
.ai-kb .hero p{color:#444;margin:.25rem 0 0}
.ai-kb .highlights{margin:1rem 0}
.ai-kb .highlights ul{padding-left:1.25rem}
.ai-kb .cards{display:grid;gap:var(--gap);grid-template-columns:repeat(auto-fit,minmax(260px,1fr));margin:1rem 0 1.25rem}
.ai-kb .card{border:1px solid var(--border);border-radius:12px;padding:1rem;background:#fff;box-shadow:0 1px 0 rgba(0,0,0,.03)}
.ai-kb .card h3{margin:.25rem 0 .5rem;font-size:1.1rem}
.ai-kb .card p{margin:0 0 .75rem;color:var(--muted)}
.ai-kb .card a{text-decoration:none;font-weight:600}
.ai-kb .section{margin:1.25rem 0}
.ai-kb blockquote{margin:.5rem 0;padding:.75rem 1rem;border-left:4px solid var(--border);background:#fafafa;border-radius:6px}
.ai-kb .bydom{margin:1.25rem 0}
.ai-kb .cite{margin:.25rem 0 0;color:#666;font-size:.95rem}
</style>
CSS;

    // Highlights list
    $hlList = '';
    if ($highlights) {
      $hlList .= '<div class="highlights"><h2>Key highlights</h2><ul>';
      foreach ($highlights as $h) {
        $hlList .= '<li>'.htmlspecialchars($h['text'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</li>';
      }
      $hlList .= '</ul></div>';
    }

    // Cards (top 4)
    $cardsHtml = '';
    if ($highlights) {
      $tops = array_slice($highlights, 0, 4);
      $cardsHtml .= '<div class="cards">';
      foreach ($tops as $i => $h) {
        $blurb = htmlspecialchars($blurbs[$i] ?? $h['text'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $cardsHtml .= '<article class="card">'
          . '<h3>'.htmlspecialchars($h['title'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</h3>'
          . '<p>'.$blurb.'</p>'
          . '<a href="#'.htmlspecialchars($h['slug'], ENT_QUOTES, 'UTF-8').'">Read more →</a>'
          . '</article>';
      }
      $cardsHtml .= '</div>';
    }

    // Quotes by domain
    $quotesHtml = '';
    if ($bySource) {
      $quotesHtml .= '<section class="bydom"><h3>Quotes</h3>';
      foreach ($bySource as $dom => $arr) {
        foreach ($arr as $h) {
          $q = htmlspecialchars($this->trimToSentence((string)$h['text'], 420), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
          $quotesHtml .= '<blockquote>'.$q.' <span class="cite">— '.htmlspecialchars($dom, ENT_QUOTES).'</span></blockquote>';
        }
      }
      $quotesHtml .= '</section>';
    }

    $hitCount = count($clean);
    return <<<HTML
{$css}
<div class="ai-kb">
  <!-- KB_ONLY=true; LLM_PLANNED=false; HITS={$hitCount} -->
  <header class="hero"><p>{$safeBrief}</p></header>
  {$hlList}
  {$cardsHtml}
  {$quotesHtml}
</div>
HTML;
  }

  // ---------------------------------------------------------------------------
  // Retrieval helpers + small text utils
  // ---------------------------------------------------------------------------

  private function buildQueryVariants(string $brief): array {
    $base = $this->makeKbQuery($brief);
    preg_match_all('/"([^"]{4,120})"/u', $brief, $m);
    $quoted = array_slice(array_filter(array_map('trim', $m[1])), 0, 3);
    $qQuoted = $quoted ? implode(' ', array_map(fn($s)=>'"'.$s.'"', $quoted)) : '';
    $raw = mb_substr(trim($brief), 0, 480);
    $variants = array_values(array_unique(array_filter([$base, $qQuoted, $raw])));
    return $variants ?: [$base];
  }

  private function retrieveMergeDedup(string $kbId, array $variants, int $topK): array {
    $all = [];
    foreach ($variants as $q) {
      $res = $this->mapper->kbRetrieve($kbId, $q, $topK);
      if ($res) $all = array_merge($all, $res);
    }
    if (!$all) return [];
    $seen=[]; $hits=[];
    foreach ($all as $row) {
      $txt = $this->sanitizeKbText((string)($row['text'] ?? ''), true);
      if ($txt === '') continue;
      $src = (string)($row['source'] ?? '');
      $key = md5(mb_strtolower(preg_replace('/\s+/u',' ', $txt)).'|'.$src);
      if (isset($seen[$key])) continue;
      $seen[$key]=1; $row['text']=$txt; $hits[]=$row;
    }
    return $hits;
  }

  private function autoTitle(string $brief, array $hits): string {
    foreach ($hits as $h) {
      $t = $this->firstSentence((string)$h['text']);
      if (mb_strlen($t) >= 24) return mb_substr($t, 0, 90);
    }
    return mb_substr(trim($brief), 0, 90);
  }

  private function makeKbQuery(string $brief): string {
    $b = mb_strtolower($brief);
    $b = preg_replace('/[^\p{L}\p{N}\s]/u',' ', $b);
    $parts = preg_split('/\s+/u', $b, -1, PREG_SPLIT_NO_EMPTY);
    $stop = ['the','a','an','and','or','of','in','to','for','with','on','at','by','from','as','is','are','was','were','this','that','these','those','it','its','be','can','may','any','more','most','about','how','what'];
    $freq=[]; foreach($parts as $w){ if(mb_strlen($w)>=3 && !in_array($w,$stop,true)) $freq[$w]=($freq[$w]??0)+1; }
    arsort($freq);
    $terms = array_slice(array_keys($freq), 0, 10);
    $q = $terms ? implode(' ', $terms) : trim($brief);
    return mb_substr($q, 0, 240);
  }

  private function sanitizeKbText(string $txt, bool $relaxed=false): string {
    if ($txt==='') return '';
    $txt = preg_replace('/!\[[^\]]*]\([^)]+\)/u',' ',$txt);
    $txt = preg_replace('/^#{1,6}\s+/mu',' ',$txt);
    foreach (['/\bSkip to main content\b/i','/\bToggle navigation\b/i','/\b(Site|Platform)\s+Nav\b/i','/\bHomepage\b/i','/\bLogin\b/i','/\bFannie Mae logo\b/i'] as $rx) {
      $txt = preg_replace($rx,' ',$txt);
    }
    $lines = preg_split('/\R/u',$txt); $keep=[];
    foreach ($lines as $line){
      $line = trim(preg_replace('/\s{2,}/u',' ',$line));
      if ($line==='') continue;
      $hasGuideToken = (bool) preg_match('/\bB[0-9]-[0-9.]+|Form\s+1007|Form\s+1025|B3-3\.1-08|Schedule\s+E/i',$line);
      $minLen = $hasGuideToken ? 20 : ($relaxed ? 24 : 50);
      if (mb_strlen($line) < $minLen) continue;
      if (substr_count($line,'|')>=1 && !$hasGuideToken) continue;
      $keep[]=$line;
    }
    $txt = trim(implode(' ',$keep));
    $txt = preg_replace('/\s{2,}/u',' ',$txt);
    return (mb_strlen($txt) < ($relaxed?60:80)) ? '' : $txt;
  }

  private function extractHighlights(array $texts, int $max=6): array {
    $slugify = fn(string $s)=> trim(preg_replace('/[^\p{L}\p{N}]+/u','-', mb_strtolower($s)),'-') ?: 'section';
    $makeTitle = function(string $s): string {
      $clean = trim($s);
      if (mb_strlen($clean) > 90) {
        $cut = mb_substr($clean, 0, 90);
        if (preg_match('/^(.{50,90}?[.:;])\s/u', $cut, $m)) $clean = $m[1]; else $clean = rtrim($cut," \t\n\r\0\x0B,;:").'…';
      }
      return ucfirst($clean);
    };
    $scored=[];
    foreach ($texts as $t){
      $sents = preg_split('/(?<=[.?!])\s+/u', (string)$t);
      foreach ($sents as $s){
        $s=trim($s); if($s==='') continue;
        $len=mb_strlen($s); if($len<50||$len>260) continue;
        if (str_contains($s,'|')) continue;
        $score = min(3,(int)floor($len/120));
        $scored[]=['text'=>$s,'score'=>$score];
      }
    }
    usort($scored, fn($a,$b)=>$b['score']<=>$a['score']);
    $out=[]; $seen=[];
    foreach ($scored as $row){
      $key = md5(mb_strtolower(preg_replace('/\s+/u',' ',$row['text'])));
      if (isset($seen[$key])) continue; $seen[$key]=1;
      $title=$makeTitle($row['text']); $slug=$slugify($title);
      $out[]=['title'=>$title,'text'=>$row['text'],'slug'=>$slug,'score'=>$row['score']];
      if (count($out) >= $max) break;
    }
    return $out;
  }

  private function adjustScoresByRecency(array &$hits): void {
    foreach ($hits as &$h){
      $t=(string)($h['text']??''); $base=(float)($h['score']??0); $pen=0.0;
      if (preg_match('/\b(20\d{2})\b/',$t,$m)){
        $age=max(0,(int)date('Y')-(int)$m[1]); $pen=min(0.25*$age,2.0);
      }
      $h['_adj']=$base-$pen;
    }
    unset($h);
  }

  private function trimToSentence(string $txt, int $max): string {
    if (mb_strlen($txt)<= $max) return $txt;
    $cut = mb_substr($txt,0,$max);
    if (preg_match('/^(.{80,})\.\s/u',$cut,$m)) return $m[1].'.';
    return rtrim($cut," \t\n\r\0\x0B,;:").'…';
  }

  private function firstSentence(string $text): string {
    $text = trim(preg_replace('/\s+/u',' ',$text));
    if ($text==='') return '';
    if (preg_match('/^(.{20,200}?\.)\s/u',$text,$m)) return $m[1];
    return mb_substr($text,0,200);
  }

  private function domainFromUrl(string $url): string {
    $host = parse_url($url, PHP_URL_HOST);
    return $host ?: ($url ? 'Source' : 'Knowledge Base');
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
}
