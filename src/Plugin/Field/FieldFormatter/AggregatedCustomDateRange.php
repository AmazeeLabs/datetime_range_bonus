<?php

/**
 * @file
 *  Contains \Drupal\datetime_range_bonus\Plugin\Field\FieldFormatter\AggregatedCustomDateRange
 */

namespace Drupal\datetime_range_bonus\Plugin\Field\FieldFormatter;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\datetime_range\Plugin\Field\FieldFormatter\DateRangeCustomFormatter;

/**
 * Plugin implementation of the 'Aggregated Custom' formatter for 'daterange'
 * fields.
 *
 * This formatter renders the data range as plain text, with a fully
 * configurable date format using the PHP date syntax and separator.
 * Additionally, the displayed dates can be configured to be aggregated for the
 * case when they have the same time, day, or month.
 *
 * @FieldFormatter(
 *   id = "daterange_bonus_aggregated_custom",
 *   label = @Translation("Aggregated custom"),
 *   field_types = {
 *     "daterange"
 *   }
 * )
 */
class AggregatedCustomDateRange extends DateRangeCustomFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'different_time' => ['date_format_start' => 'fallback', 'date_format_end' => 'fallback'],
      'different_date' => ['date_format_start' => 'fallback', 'date_format_end' => 'fallback'],
      'different_month' => ['date_format_start' => 'fallback', 'date_format_end' => 'fallback'],
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $separator = $this->getSetting('separator');

    foreach ($items as $delta => $item) {
      if (!empty($item->start_date)) {
        // Make the end date the same as the start date, in case the start date
        // is empty.
        if (empty($item->end_date)) {
          $item->end_date = clone $item->start_date;
        }
        /** @var \Drupal\Core\Datetime\DrupalDateTime $start_date */
        $start_date = $item->start_date;
        $this->setTimeZone($start_date);
        /** @var \Drupal\Core\Datetime\DrupalDateTime $end_date */
        $end_date = $item->end_date;
        $this->setTimeZone($end_date);

        // If the dates have different parts, then we will need to see which
        // date format we have to use for the start and the end date.
        if ($start_date->format('U') !== $end_date->format('U')) {
          $date_format = $this->getCustomFormatForDateRange($start_date, $end_date);
          // To be able to benefit from the buildDate() method of the
          // DateTimeRangeTrait trait which calls the formatDate() method on the
          // current object, we have to do some tricks with the date_format
          // setting. Basically, the formatDate will check that setting and
          // format the date accordingly. So, what we do is to temporary alter
          // the setting with the different configuration we have for the date
          // range.
          $tmp_date_format = $this->getSetting('date_format');
          $this->setSetting('date_format', $date_format['start']->getPattern());
          $elements[$delta]['start_date'] = $this->buildDate($start_date);
          $elements[$delta]['separator'] = ['#plain_text' => $separator];
          $this->setSetting('date_format', $date_format['end']->getPattern());
          $elements[$delta]['end_date'] = $this->buildDate($end_date);
          $this->setSetting('date_format', $tmp_date_format);
          // Check if the final output for the end and start date are the same
          // and if yes just remove the separator and the end date.
          if ($elements[$delta]['start_date'] == $elements[$delta]['end_date']) {
            unset($elements[$delta]['separator'], $elements[$delta]['end_date']);
          }
        }
        else {
          // The default is just to use the date_format setting directly. But
          // here we also have a trick to do, because we replace in the settings
          // form the date_format textfield with a select box from the available
          // formats, which is the logical way to choose a format for a date. It
          // also has the advantage that we can have different formats per
          // language, since the date formats are translatable (but the field
          // display settings not, at least not at the moment).
          $tmp_date_format = $this->getSetting('date_format');
          $date_format = $this->dateFormatStorage->load($tmp_date_format);
          $this->setSetting('date_format', $date_format->getPattern());

          $elements[$delta] = $this->buildDate($start_date);

          $this->setSetting('date_format', $tmp_date_format);
        }
      }
    }

    return $elements;
  }

  /**
   * Returns an array with the formatter to be used for the start and the end
   * date of a date range.
   *
   * @param \Drupal\Core\Datetime\DrupalDateTime $start_date
   * @param \Drupal\Core\Datetime\DrupalDateTime $end_date
   */
  public function getCustomFormatForDateRange(DrupalDateTime $start_date, DrupalDateTime $end_date) {
    $format = array();
    $start_date_parts = explode('.', $start_date->format('Y.n.d.His'));
    $end_date_parts = explode('.', $end_date->format('Y.n.d.His'));
    // If the year of the dates is different, we will just use the default
    // formatter setting (the 'date_format' setting).
    if ($start_date_parts[0] != $end_date_parts[0]) {
      $format['start'] = $this->dateFormatStorage->load($this->getSetting('date_format'));
      $format['end'] = $this->dateFormatStorage->load($this->getSetting('date_format'));
    }
    // For the rest of the cases, we will take the additional settings we
    // defined in the 'Customisations for different time' section.
    elseif ($start_date_parts[1] != $end_date_parts[1]) {
      $different_month_settings = $this->getSetting('different_month');
      $format['start'] = $this->dateFormatStorage->load($different_month_settings['date_format_start']);
      $format['end'] = $this->dateFormatStorage->load($different_month_settings['date_format_end']);
    }
    elseif ($start_date_parts[2] != $end_date_parts[2]) {
      $different_date_settings = $this->getSetting('different_date');
      $format['start'] = $this->dateFormatStorage->load($different_date_settings['date_format_start']);
      $format['end'] = $this->dateFormatStorage->load($different_date_settings['date_format_end']);
    }
    elseif ($start_date_parts[3] != $end_date_parts[3]) {
      // For this case, we have to check if the start time or the end time were
      // actually input by the user. For example, if the start time is '000000'
      // then it is very very likely that this is the default value. The same
      // for the end date being '235959'. One important thing to remark here:
      // as mentioned, it is just very very likely, it is not 100% sure, but
      // in our case it is really very unlikely to have dates with times
      // starting at 000000 or ending at 235959 sharp.
      $different_time_settings = $this->getSetting('different_time');
      if ($start_date_parts[3] !== '000000') {
        $format['start'] = $this->dateFormatStorage->load($different_time_settings['date_format_start']);
      }
      else {
        $format['start'] = $this->dateFormatStorage->load($this->getSetting('date_format'));
      }

      if ($end_date_parts[3] !== '235959') {
        $format['end'] = $this->dateFormatStorage->load($different_time_settings['date_format_end']);
      }
      else {
        $format['end'] = $this->dateFormatStorage->load($this->getSetting('date_format'));
      }
    }
    else {
      // The default is to use the 'date_format' setting.
      $format['start'] = $this->dateFormatStorage->load($this->getSetting('date_format'));
      $format['end'] = $this->dateFormatStorage->load($this->getSetting('date_format'));
    }
    return $format;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);

    $date_formats = $this->dateFormatStorage->loadMultiple();
    $formatter_options = array();
    foreach ($date_formats as $date_format) {
      $formatter_options[$date_format->id()] = $date_format->label() . '(' .$this->dateFormatter->format(REQUEST_TIME, $date_format->id()) . ')';
    }

    $different_time_settings = $this->getSetting('different_time');
    $form['different_time'] = array(
      '#type' => 'details',
      '#title' => $this->t('Customisations for different time'),
    );
    $form['different_time']['date_format_start'] = array(
      '#type' => 'select',
      '#title' => $this->t('Date/time format for the start date'),
      '#options' => $formatter_options,
      '#default_value' => $different_time_settings['date_format_start'],
    );
    $form['different_time']['date_format_end'] = array(
      '#type' => 'select',
      '#title' => $this->t('Date/time format for the end date'),
      '#options' => $formatter_options,
      '#default_value' => $different_time_settings['date_format_end'],
    );

    $different_date_settings = $this->getSetting('different_date');
    $form['different_date'] = array(
      '#type' => 'details',
      '#title' => $this->t('Customisations for different date'),
    );
    $form['different_date']['date_format_start'] = array(
      '#type' => 'select',
      '#title' => $this->t('Date/time format for the start date'),
      '#options' => $formatter_options,
      '#default_value' => $different_date_settings['date_format_start'],
    );
    $form['different_date']['date_format_end'] = array(
      '#type' => 'select',
      '#title' => $this->t('Date/time format for the end date'),
      '#options' => $formatter_options,
      '#default_value' => $different_date_settings['date_format_end'],
    );

    $different_month_settings = $this->getSetting('different_month');
    $form['different_month'] = array(
      '#type' => 'details',
      '#title' => $this->t('Customisations for different month'),
    );
    $form['different_month']['date_format_start'] = array(
      '#type' => 'select',
      '#title' => $this->t('Date/time format for the start date'),
      '#options' => $formatter_options,
      '#default_value' => $different_month_settings['date_format_start'],
    );
    $form['different_month']['date_format_end'] = array(
      '#type' => 'select',
      '#title' => $this->t('Date/time format for the end date'),
      '#options' => $formatter_options,
      '#default_value' => $different_month_settings['date_format_end'],
    );

    // Make the date_format setting a select list from a predefined formatter.
    $form['date_format']['#type'] = 'select';
    $form['date_format']['#options'] = $formatter_options;
    $form['date_format']['#description'] = $this->t('This format will be used when the start and end dates are identical, there is no end date or the entire date is different.');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    // Temporary change the date_format setting with its corresponding date
    // format string.
    $tmp_date_format = $this->getSetting('date_format');
    $date_format = $this->dateFormatStorage->load($tmp_date_format);
    $this->setSetting('date_format', $date_format->getPattern());

    $summary = parent::settingsSummary();

    // @todo: add summary for all the other cases.

    // Set back the old date_format setting.
    $this->setSetting('date_format', $tmp_date_format);
    return $summary;
  }

}
