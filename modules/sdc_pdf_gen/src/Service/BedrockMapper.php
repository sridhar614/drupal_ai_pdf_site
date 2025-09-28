<?php

namespace Drupal\sdc_pdf_gen\Service;

use Aws\BedrockAgentRuntime\BedrockAgentRuntimeClient;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Bedrock helpers for Hackathon renderer:
 * - kbRetrieve(): vector-only retrieval from KB
 * - planRichSections(): Gen-AI composition (highlights, cards, quotes) grounded in passages
 * - summarizeHighlightsToBlurbs(): small grounded blurbs (fallback path)
 */
class BedrockMapper {

  protected ?BedrockAgentRuntimeClient $ragClient = NULL;
  protected ?BedrockRuntimeClient $rtClient = NULL;
  protected ?string $ragRegion = NULL;
  protected ?string $rtRegion  = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  // ---------------------------------------------------------------------------
  // KB RETRIEVE (no LLM)
  // ---------------------------------------------------------------------------

  /**
   * Retrieve passages from a Bedrock Knowledge Base.
   *
   * @param string $kbId   Knowledge base ID.
   * @param string $query  Query string.
   * @param int    $topK   Number of results to return.
   * @return array[] Each: ['text'=>string,'score'=>float|null,'source'=>string|null]
   */
  public function kbRetrieve(string $kbId, string $query, int $topK = 50): array {
    $region = (string) ($this->configFactory->get('sdc_pdf_gen.settings')->get('aws_region_kb') ?: 'us-east-2');

    try {
      $client = $this->rag($region);
      $res = $client->retrieve([
        'knowledgeBaseId' => $kbId,
        'retrievalQuery' => ['text' => $query],
        'retrievalConfiguration' => [
          'vectorSearchConfiguration' => [
            'numberOfResults' => max(1, min(50, $topK)),
          ],
        ],
      ]);

      $out = [];
      foreach ((array) $res->get('retrievalResults') as $row) {
        $text  = (string) ($row['content']['text'] ?? '');
        $score = isset($row['score']) ? (float) $row['score'] : null;

        // Pull a friendly source URL if present in the metadata bundle.
        $src = '';
        $attrs = (array) ($row['location']['s3Location']['metadata'] ?? []);
        foreach ($attrs as $a) {
          if (!empty($a['key']) && !empty($a['value'])) {
            $k = strtolower((string) $a['key']);
            if ($k === 'source' || $k === 'url' || $k === 'document_url') {
              $src = (string) $a['value']; break;
            }
          }
        }
        $out[] = ['text' => $text, 'score' => $score, 'source' => $src ?: null];
      }
      return $out;
    } catch (\Throwable $e) {
      $this->logger->error('KB retrieve failed: @m', ['@m' => $e->getMessage()]);
      return [];
    }
  }

  // ---------------------------------------------------------------------------
  // Gen-AI planner (grounded): highlights, cards, quotes
  // ---------------------------------------------------------------------------

  /**
   * Ask the LLM to plan highlights, cards, and quotes from KB passages.
   * Strictly grounded: do not add facts/links not present in passages.
   * Returns normalized array or [] on failure.
   */
  public function planRichSections(string $brief, array $passages, int $maxItems = 6): array {
    if (!$passages) return [];

    $cfg    = $this->configFactory->get('sdc_pdf_gen.settings');
    $region = (string) ($cfg->get('aws_region_runtime') ?: 'us-east-1');
    $modelId = (string) (
      $cfg->get('bedrock_runtime_model_id') ?:
      $cfg->get('bedrock_model_id') ?:
      'anthropic.claude-3-5-sonnet-20240620-v1:0'
    );

    // Trim + pack passages
    $ctx = [];
    foreach (array_slice($passages, 0, 14) as $i => $h) {
      $ctx[] = [
        'idx'    => $i + 1,
        'domain' => parse_url((string)($h['source'] ?? ''), PHP_URL_HOST),
        'text'   => mb_substr((string)($h['text'] ?? ''), 0, 2200),
      ];
    }

    $schema = [
      '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
      'type'       => 'object',
      'required'   => ['highlights', 'cards', 'quotes'],
      'properties' => [
        'intro'      => ['type' => 'string'],
        'highlights' => [
          'type' => 'array',
          'items'=> ['type'=>'string', 'minLength'=>40, 'maxLength'=>260],
          'maxItems' => $maxItems
        ],
        'cards'      => [
          'type' => 'array',
          'maxItems' => 4,
          'items'=> [
            'type' => 'object',
            'required' => ['title','blurb','color'],
            'properties' => [
              'title' => ['type'=>'string', 'minLength'=>10, 'maxLength'=>90],
              'blurb' => ['type'=>'string', 'minLength'=>40, 'maxLength'=>240],
              'color' => ['type'=>'string', 'enum'=>['blue','green','orange','purple','gray']]
            ],
            'additionalProperties' => false
          ]
        ],
        'quotes'     => [
          'type' => 'array',
          'maxItems' => 4,
          'items'=> [
            'type' => 'object',
            'required' => ['text'],
            'properties' => [
              'text'   => ['type'=>'string', 'minLength'=>40, 'maxLength'=>240],
              'domain' => ['type'=>'string']
            ],
            'additionalProperties' => false
          ]
        ],
      ],
      'additionalProperties' => false
    ];

    $system = <<<SYS
You are a careful content editor. Use ONLY the provided passages. Do NOT invent facts, numbers, program names, or links.
Output MUST be valid JSON that conforms exactly to the provided schema. No markdown, no prose outside JSON.
Keep items concise and non-duplicative. Quotes must be verbatim subsequences (light trimming is fine).
Cards: summarize in 1–2 sentences, neutral tone, no URLs or footnotes.
SYS;

    $user = json_encode([
      'brief'     => $brief,
      'schema'    => $schema,
      'passages'  => $ctx,
      'direction' => [
        'order' => ['highlights','cards','quotes'],
        'style' => ['neutral' => true, 'no_links' => true, 'no_new_facts' => true],
      ],
    ], JSON_UNESCAPED_SLASHES);

    try {
      $client = $this->runtime($region);
      $payload = [
        'anthropic_version' => 'bedrock-2023-05-31',
        'max_tokens'        => 1800,
        'temperature'       => 0.2,
        'system'            => $system,
        'messages'          => [[ 'role'=>'user', 'content'=>$user ]],
      ];

      $res   = $client->invokeModel([
        'modelId'     => $modelId,
        'contentType' => 'application/json',
        'accept'      => 'application/json',
        'body'        => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE),
      ]);
      $outer = json_decode($res['body']->getContents(), true);
      $raw   = (string) ($outer['content'][0]['text'] ?? '');
      $json  = json_decode(trim($raw), true);
      if (!is_array($json)) return [];

      // Normalize + clamp
      $json['intro'] = is_string($json['intro'] ?? '') ? trim($json['intro']) : '';
      $json['highlights'] = array_slice(array_values(array_filter($json['highlights'] ?? [], 'is_string')), 0, $maxItems);
      $json['cards'] = array_slice(array_values(array_filter($json['cards'] ?? [], fn($r)=>is_array($r) && !empty($r['title']) && !empty($r['blurb']) && !empty($r['color']))), 0, 4);
      $json['quotes'] = array_slice(array_values(array_filter($json['quotes'] ?? [], fn($r)=>is_array($r) && !empty($r['text']))), 0, 4);

      return $json;
    } catch (\Throwable $e) {
      $this->logger->warning('planRichSections failed: @m', ['@m'=>$e->getMessage()]);
      return [];
    }
  }

  // ---------------------------------------------------------------------------
  // Lightweight blurbing (fallback path)
  // ---------------------------------------------------------------------------

  /**
   * Given highlight sentences, produce short blurbs (grounded). Returns [] on failure.
   */
  public function summarizeHighlightsToBlurbs(string $brief, array $highlights, int $sentences = 2): array {
    if (!$highlights) return [];

    $cfg    = $this->configFactory->get('sdc_pdf_gen.settings');
    $region = (string) ($cfg->get('aws_region_runtime') ?: 'us-east-1');
    $modelId = (string) (
      $cfg->get('bedrock_runtime_model_id') ?:
      $cfg->get('bedrock_model_id') ?:
      'anthropic.claude-3-5-sonnet-20240620-v1:0'
    );

    $rows = [];
    foreach ($highlights as $i=>$t) {
      $rows[] = 'H'.($i+1).': '.$this->clip($t, 800);
    }
    $ctx = implode("\n\n", $rows);

    $system = "You are a careful content editor. Only use the provided text. Do not invent facts or links.";
    $user = <<<PROMPT
Brief:
{$brief}

Highlight sentences (ground truth):
{$ctx}

Task:
For each highlight H1..Hn, produce a concise {$sentences}-sentence blurb that stays faithful to the highlight (no new facts).
Return a JSON array of strings in order (no keys, no prose).
PROMPT;

    try {
      $client = $this->runtime($region);
      $payload = [
        'anthropic_version' => 'bedrock-2023-05-31',
        'max_tokens'        => 600,
        'temperature'       => 0.2,
        'system'            => $system,
        'messages'          => [[ 'role' => 'user', 'content' => $user ]],
      ];

      $res = $client->invokeModel([
        'modelId'     => $modelId,
        'contentType' => 'application/json',
        'accept'      => 'application/json',
        'body'        => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE),
      ]);

      $outer = json_decode($res['body']->getContents(), true);
      $text  = (string) ($outer['content'][0]['text'] ?? '');

      $parsed = json_decode(trim($text), true);
      if (!is_array($parsed)) return [];
      $out = [];
      foreach ($parsed as $s) {
        if (!is_string($s)) continue;
        $s = trim($s);
        if ($s !== '') $out[] = $s;
      }
      return $out;
    } catch (\Throwable $e) {
      $this->logger->warning('summarizeHighlightsToBlurbs failed: @m', ['@m' => $e->getMessage()]);
      return [];
    }
  }

  // ---------------------------------------------------------------------------
  // Clients / shared
  // ---------------------------------------------------------------------------

  protected function rag(string $region): BedrockAgentRuntimeClient {
    if ($this->ragClient && $this->ragRegion === $region) return $this->ragClient;
    $args = $this->clientArgs($region);
    $this->ragClient = new BedrockAgentRuntimeClient($args);
    $this->ragRegion = $region;
    return $this->ragClient;
  }

  protected function runtime(string $region): BedrockRuntimeClient {
    if ($this->rtClient && $this->rtRegion === $region) return $this->rtClient;
    $args = $this->clientArgs($region);
    $this->rtClient = new BedrockRuntimeClient($args);
    $this->rtRegion = $region;
    return $this->rtClient;
  }

  protected function clientArgs(string $region): array {
    $cfg  = $this->configFactory->get('sdc_pdf_gen.settings');
    $args = [
      'region'  => $region,
      'version' => 'latest',
      'http'    => ['connect_timeout' => 3.0, 'timeout' => 60.0],
      'retries' => 3,
    ];
    $key    = (string) ($cfg->get('aws_access_key_id')     ?: getenv('AWS_ACCESS_KEY_ID')     ?: '');
    $secret = (string) ($cfg->get('aws_secret_access_key') ?: getenv('AWS_SECRET_ACCESS_KEY') ?: '');
    $token  = (string) ($cfg->get('aws_session_token')     ?: getenv('AWS_SESSION_TOKEN')     ?: '');
    if ($key !== '' && $secret !== '') {
      $creds = ['key'=>$key,'secret'=>$secret]; if ($token!=='') $creds['token']=$token;
      $args['credentials'] = $creds;
    }
    return $args;
  }

  // ---------------------------------------------------------------------------
  // Small helpers
  // ---------------------------------------------------------------------------

  protected function clip(string $txt, int $max): string {
    $txt = preg_replace('/\s{2,}/u',' ', trim($txt));
    if (mb_strlen($txt) <= $max) return $txt;
    $cut = mb_substr($txt, 0, $max);
    if (preg_match('/^(.{120,})\.\s/um', $cut, $m)) return $m[1].'.';
    return rtrim($cut, " \t\n\r\0\x0B,;:") . '…';
  }
}
