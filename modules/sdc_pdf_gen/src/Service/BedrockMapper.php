<?php

namespace Drupal\sdc_pdf_gen\Service;

use Aws\BedrockAgentRuntime\BedrockAgentRuntimeClient;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Bedrock integration helpers:
 * - kbRetrieve(): retrieve passages from a Bedrock Knowledge Base (no LLM)
 * - planFromBriefToHtml(): invoke LLM to produce a clean HTML fragment
 * - kbRetrieveAndGenerate(): (optional) RAG to clean HTML fragment
 *
 * All HTML returning methods return a body fragment only
 * (no <html>/<head>/<body> wrappers; no scripts/styles).
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
  // KB RETRIEVE (no LLM): used by AiSiteGenerator::createFromBriefKbOnly()
  // ---------------------------------------------------------------------------
  /**
   * Retrieve passages from a Bedrock Knowledge Base.
   *
   * @param string $kbId   Knowledge base ID.
   * @param string $query  Query string.
   * @param int    $topK   Number of results to return.
   * @return array[] Each: ['text'=>string,'score'=>float|null,'source'=>string|null]
   */
  public function kbRetrieve(string $kbId, string $query, int $topK = 12): array {
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
        $text = (string) ($row['content']['text'] ?? '');
        $score = isset($row['score']) ? (float) $row['score'] : null;

        // try to find a usable source URL (s3 URIs are not helpful in UI)
        $src = '';
        $attrs = (array) ($row['location']['s3Location']['metadata'] ?? []);
        foreach ($attrs as $a) {
          if (!empty($a['key']) && !empty($a['value'])) {
            $k = strtolower((string) $a['key']);
            if ($k === 'source' || $k === 'url' || $k === 'document_url') {
              $src = (string) $a['value'];
              break;
            }
          }
        }
        $out[] = ['text' => $text, 'score' => $score, 'source' => $src ?: null];
      }
      return $out;
    }
    catch (\Throwable $e) {
      $this->logger->error('KB retrieve failed: @m', ['@m' => $e->getMessage()]);
      return [];
    }
  }

  // ---------------------------------------------------------------------------
  // OPTIONAL: KB RETRIEVE+GENERATE (RAG) → returns BODY HTML fragment
  // ---------------------------------------------------------------------------
  /**
   * Retrieve-and-generate using Bedrock KB.
   * Requires:
   *   bedrock_kb_id
   *   bedrock_kb_model_arn  ← a KB-supported FOUNDATION MODEL ARN (on-demand),
   *   aws_region_kb
   * Optional:
   *   bedrock_inference_profile_arn ← if using an inference profile (e.g., Claude)
   *
   * @return string HTML fragment or <p>More context required.</p>
   */
  public function kbRetrieveAndGenerate(string $query, int $topK = 12): string {
    $cfg    = $this->configFactory->get('sdc_pdf_gen.settings');
    $kbId   = (string) $cfg->get('bedrock_kb_id');
    $region = (string) ($cfg->get('aws_region_kb') ?: 'us-east-2');

    // IMPORTANT: This must be a KB-supported foundation model ARN (on-demand),
    // e.g. Titan Text Lite or Meta Llama 3.1 8B Instruct in us-east-2:
    //  arn:aws:bedrock:us-east-2::foundation-model/amazon.titan-text-lite-v1
    //  arn:aws:bedrock:us-east-2::foundation-model/meta.llama3-1-8b-instruct-v1:0
    $kbModelArn   = (string) ($cfg->get('bedrock_kb_model_arn') ?: '');
    $profileArn   = (string) ($cfg->get('bedrock_inference_profile_arn') ?: '');

    if ($kbId === '' || $kbModelArn === '') {
      $this->logger->error('RAG config error: kbId or kbModelArn missing.');
      return '<p>More context required.</p>';
    }

    $prompt = <<<PROMPT
You will receive retrieved snippets from web pages. Use ONLY BODY content.
IGNORE navigation, headers, footers, menus, breadcrumbs, login blocks, social links, and legal disclaimers.

Return ONLY a valid HTML fragment (no <html>/<head>/<body>), and only these tags:
<h1>, <h2>, <p>, <ul>, <li>, <blockquote>, <table>.

No scripts or styles. No raw URLs inside paragraphs.
At the end, include:
<h3>Sources</h3><ol>…</ol> with links to the cited pages.

If snippets are insufficient, return exactly: <p>More context required.</p>
PROMPT;

    try {
      $client = $this->rag($region);

      $ragCfg = [
        'type' => 'KNOWLEDGE_BASE',
        'knowledgeBaseConfiguration' => [
          'knowledgeBaseId' => $kbId,
          // Foundation model ARN (on-demand) for RAG must go here:
          'modelArn'        => $kbModelArn,
        ],
        'retrievalConfiguration' => [
          'vectorSearchConfiguration' => [
            'numberOfResults' => max(1, min(30, $topK)),
          ],
        ],
        'generationConfiguration' => [
          'promptTemplate' => [
            'textPromptTemplate' => $prompt,
          ],
          'inferenceConfig' => [
            'textInferenceConfig' => [
              'maxTokens'   => 1800,
              'temperature' => 0.2,
            ],
          ],
        ],
      ];

      // Optional: if you have an inference profile (e.g., Claude profile ARN)
      if ($profileArn !== '') {
        $ragCfg['inferenceProfileArn'] = $profileArn;
      }

      $res = $client->retrieveAndGenerate([
        'input' => ['text' => $query],
        'retrieveAndGenerateConfiguration' => $ragCfg,
      ]);

      $html = trim((string) ($res->get('output')['text'] ?? ''));
      $html = $this->ensureHtmlFragment($html);

      return $html !== '' ? $html : '<p>More context required.</p>';
    }
    catch (\Throwable $e) {
      $this->logger->error('RAG failed: @m', ['@m' => $e->getMessage()]);
      return '<p>More context required.</p>';
    }
  }

  // ---------------------------------------------------------------------------
  // LLM: PLAN/BRIEF → CLEAN HTML (body fragment)
  // ---------------------------------------------------------------------------
  /**
   * Convert a brief (and optional context) into a small HTML fragment via LLM.
   * Assumes an Anthropic model ID in config (e.g., anthropic.claude-3-5-sonnet-20240620-v1:0).
   */
  public function planFromBriefToHtml(string $brief, string $context = ''): string {
    $cfg    = $this->configFactory->get('sdc_pdf_gen.settings');
    $region = (string) ($cfg->get('aws_region_runtime') ?: 'us-east-1');
    $modelId = (string) (
      $cfg->get('bedrock_runtime_model_id') ?:
      $cfg->get('bedrock_model_id') ?:
      'anthropic.claude-3-5-sonnet-20240620-v1:0'
    );

    $systemPrompt = <<<PROMPT
You are a content designer. Output ONLY valid, self-contained HTML (no markdown, no explanations).
- Start directly with HTML tags (<h1>, <p>, <ul>, <li>, <blockquote>, <table>).
- No <html>/<head>/<body> wrappers; return a body fragment.
- No scripts or styles. Use short paragraphs, clear headings, and lists.
- End with a clear CTA paragraph with a link placeholder.
PROMPT;

    try {
      $client = $this->runtime($region);

      // Anthropic message format payload
      $payload = [
        'anthropic_version' => 'bedrock-2023-05-31',
        'max_tokens'        => 2500,
        'temperature'       => 0.3,
        'system'            => $systemPrompt,
        'messages'          => [[
          'role'    => 'user',
          'content' => "Brief:\n{$brief}\n\nOptional context:\n{$context}",
        ]],
      ];

      $res = $client->invokeModel([
        'modelId'     => $modelId,
        'contentType' => 'application/json',
        'accept'      => 'application/json',
        'body'        => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE),
      ]);

      $outer = json_decode($res['body']->getContents(), true);
      $text  = (string) ($outer['content'][0]['text'] ?? '');
      // occasionally models prepend “Here is…”
      $text = preg_replace('/^\s*Here[’\'`s is]+.*?:\s*/i', '', $text);
      $text = ltrim($text);

      $html = $this->ensureHtmlFragment($text);
      return $html !== '' ? $html : '<p>Generation failed.</p>';
    }
    catch (\Throwable $e) {
      $this->logger->error('Bedrock brief->HTML failed: @m', ['@m' => $e->getMessage()]);
      return '<p>Generation failed.</p>';
    }
  }

  // ---------------------------------------------------------------------------
  // CLIENTS / CREDS
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

  /** Shared client args (uses explicit creds from config/env if present; else default chain). */
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
      $creds = ['key' => $key, 'secret' => $secret];
      if ($token !== '') $creds['token'] = $token;
      $args['credentials'] = $creds;
    }

    return $args;
  }

  // ---------------------------------------------------------------------------
  // HTML SAFETY
  // ---------------------------------------------------------------------------
  /**
   * Ensure the model output is a safe body fragment (no wrappers, no scripts/styles).
   */
  protected function ensureHtmlFragment(string $html): string {
    if ($html === '') return '';
    // Remove accidental wrappers
    $html = preg_replace('#</?(?:html|head|body)[^>]*>#i', '', $html);
    // Remove scripts/styles outright
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
    $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
    $html = trim($html);
    // Require at least one allowed tag to avoid plain text noise
    if (!preg_match('/<\s*(h1|h2|p|ul|ol|blockquote|table)\b/i', $html)) {
      return '';
    }
    return $html;
  }
}
