<?php

namespace Drupal\media_webdam\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class WebdamConfig.
 *
 * @package Drupal\media_webdam\Form
 */
class WebdamConfig extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webdam_config';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'media_webdam.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = \Drupal::config('media_webdam.settings');

    $form['username'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Webdam username'),
      '#default_value' => $config->get('username'),
    );
    $form['password'] = array(
      '#type' => 'password',
      '#title' => $this->t('Webdam password'),
      '#default_value' => $config->get('password'),
    );
    $form['client_id'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Webdam client ID'),
      '#default_value' => $config->get('client_id'),
    );
    $form['client_secret'] = array(
      '#type' => 'password',
      '#title' => $this->t('Webdam client secret'),
      '#default_value' => $config->get('secret'),
    );
    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('media_webdam.settings');

    $config->set('username', $form_state->getValue('username'))
      ->set('password', $form_state->getValue('password'))
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('secret', $form_state->getValue('client_secret'));
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
