<?php

namespace Drupal\sdc_pdf_gen\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\sdc_pdf_gen\Service\BedrockMapper;
use Drupal\sdc_pdf_gen\Service\SdcPageBuilder;

class PdfUploadForm extends FormBase {

  public function getFormId(): string {
    return 'sdc_pdf_gen_upload_form';
  }

  public function __construct(
    protected BedrockMapper $mapper,
    protected SdcPageBuilder $builder,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('sdc_pdf_gen.bedrock_mapper'),
      $container->get('sdc_pdf_gen.sdc_page_builder'),
    );
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['pdf'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Upload PDF'),
        '#upload_location' => 'public://sdc_pdf_uploads',
        '#required' => TRUE,
        '#upload_validators' => [
          'FileExtension' => ['pdf'],
        ],
    ];
      

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate SDC Draft Page'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fids = $form_state->getValue('pdf');
    $file = File::load(reset($fids));
    $file->setPermanent();
    $file->save();

    // Send the PDF file to Bedrock and get JSON back.
    $sdcLayout = $this->mapper->mapPdfToSdc($file->getFileUri());

    // Create draft node from JSON blocks.
    $node = $this->builder->createPageFromLayout($sdcLayout, [
      'title' => $file->getFilename(),
    ]);

    $this->messenger()->addStatus($this->t('Draft created: @title', ['@title' => $node->label()]));
  }
}
