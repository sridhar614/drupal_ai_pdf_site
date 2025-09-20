<?php

namespace Drupal\sdc_pdf_gen\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\sdc_pdf_gen\Service\AiSiteGenerator;

class AiNewPageForm extends FormBase {
  public function getFormId(): string { return 'sdc_ai_gen_new_page_form'; }

  public function __construct(protected AiSiteGenerator $gen) {}

  public static function create(ContainerInterface $c): self {
    return new self($c->get('sdc_pdf_gen.generator'));
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['title'] = ['#type'=>'textfield','#title'=>$this->t('Title (optional)')];
    $form['brief'] = ['#type'=>'textarea','#title'=>$this->t('Email / Notes / Brief'),'#required'=>TRUE,'#rows'=>10];
  
    $form['kb_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('KB only (no LLM)'),
      '#default_value' => TRUE,
    ];
    $form['use_kb_rag'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Use Knowledge Base (summarize via RetrieveAndGenerate)'),
        '#default_value' => TRUE,
    ];
  
    $form['use_kb'] = ['#type'=>'checkbox','#title'=>$this->t('Use Knowledge Base'), '#default_value'=>TRUE];
    $form['use_web'] = ['#type'=>'checkbox','#title'=>$this->t('Use allowlisted web fallback'), '#default_value'=>FALSE];
    $form['actions']['submit'] = ['#type'=>'submit','#value'=>$this->t('Generate page')];
    return $form;
  }
  
  public function submitForm(array &$form, FormStateInterface $state): void {
    $title  = trim((string) $state->getValue('title'));
    $brief  = (string) $state->getValue('brief');
    $useRag = (bool) $state->getValue('use_kb_rag'); // add a checkbox in buildForm
  
    if ($useRag) {
    //   $node = $this->gen->createFromBriefKbRag($title ?: NULL, $brief);
    $node = $this->gen->createFromBriefKbOnly($title ?: NULL, $brief);

    } else {
      // fallback to your KB-only or LLM path
      $node = $this->gen->createFromBriefKbOnly($title ?: NULL, $brief);

    }
  
    $this->messenger()->addStatus($this->t('Draft created: @link', [
      '@link' => $node->toLink($node->label())->toString(),
    ]));
  }  
}
