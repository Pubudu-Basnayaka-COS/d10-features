<?php

namespace Drupal\features_ui\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\features\FeaturesAssignerInterface;
use Drupal\features\FeaturesGeneratorInterface;
use Drupal\features\FeaturesManagerInterface;
use Drupal\features\ConfigurationItem;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\config_update\ConfigRevertInterface;

/**
 * Defines the features settings form.
 */
class FeaturesEditForm extends FormBase {

  /**
   * The features manager.
   *
   * @var array
   */
  protected $featuresManager;

  /**
   * The package assigner.
   *
   * @var array
   */
  protected $assigner;

  /**
   * The package generator.
   *
   * @var array
   */
  protected $generator;

  /**
   * Current package being edited.
   *
   * @var \Drupal\features\Package
   */
  protected $package;

  /**
   * Current bundle machine name.
   *
   * NOTE: D8 cannot serialize objects within forms so you can't directly
   * store the entire Bundle object here.
   *
   * @var string
   */
  protected $bundle;

  /**
   * Previous bundle name for ajax processing.
   *
   * @var string
   */
  protected $oldBundle;

  /**
   * Config to be specifically excluded.
   *
   * @var array
   */
  protected $excluded;

  /**
   * Config to be specifically required.
   *
   * @var array
   */
  protected $required;

  /**
   * Config referenced by other packages.
   *
   * @var array
   */
  protected $conflicts;

  /**
   * Determine if conflicts are allowed to be added.
   *
   * @var bool
   */
  protected $allowConflicts;

  /**
   * Config missing from active site.
   *
   * @var array
   */
  protected $missing;

  /**
   * The config reverter.
   *
   * @var \Drupal\config_update\ConfigRevertInterface
   */
  protected $configRevert;

  /**
   * Constructs a FeaturesEditForm object.
   *
   * @param \Drupal\features\FeaturesManagerInterface $features_manager
   *   The features manager.
   * @param \Drupal\features\FeaturesAssignerInterface $assigner
   *   The feature assigner.
   * @param \Drupal\features\FeaturesGeneratorInterface $generator
   *   The features generator.
   * @param \Drupal\config_update\ConfigRevertInterface $config_revert
   *   The config revert.
   */
  public function __construct(FeaturesManagerInterface $features_manager, FeaturesAssignerInterface $assigner, FeaturesGeneratorInterface $generator, ConfigRevertInterface $config_revert) {
    $this->featuresManager = $features_manager;
    $this->assigner = $assigner;
    $this->generator = $generator;
    $this->configRevert = $config_revert;
    $this->excluded = [];
    $this->required = [];
    $this->conflicts = [];
    $this->missing = [];
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('features.manager'),
      $container->get('features_assigner'),
      $container->get('features_generator'),
      $container->get('features.config_update')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'features_edit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $featurename = '') {
    if ($this->getRequest()->hasSession()) {
      $session = $this->getRequest()->getSession();
    }

    $trigger = $form_state->getTriggeringElement();
    if (isset($trigger['#name']) && $trigger['#name'] == 'package') {
      // Save current bundle name for later ajax callback.
      $this->oldBundle = $this->bundle;
    }
    elseif (isset($trigger['#name']) && $trigger['#name'] == 'conflicts') {
      if (isset($session)) {
        $session->set('features_allow_conflicts', $form_state->getValue('conflicts'));
      }
    }
    if (!$form_state->isValueEmpty('package')) {
      $bundle_name = $form_state->getValue('package');
      $bundle = $this->assigner->getBundle($bundle_name);
    }
    else {
      $bundle = $this->assigner->loadBundle();
    }
    // Only store bundle name, not full object.
    $this->bundle = $bundle->getMachineName();

    $this->allowConflicts = FALSE;
    if (isset($session)) {
      $this->allowConflicts = $session->get('features_allow_conflicts', FALSE);
    }

    // Pass the $force argument as TRUE because we want to include any excluded
    // configuration items. These should show up as automatically assigned, but
    // not selected, thus allowing the admin to reselect if desired.
    // @see FeaturesManagerInterface::assignConfigPackage()
    $this->assigner->assignConfigPackages(TRUE);

    $packages = $this->featuresManager->getPackages();
    if (empty($packages[$featurename])) {
      $featurename = str_replace(['-', ' '], '_', $featurename);
      $this->package = $this->featuresManager->initPackage($featurename, NULL, '', 'module', $bundle);
    }
    else {
      $this->package = $packages[$featurename];
    }

    if (!empty($packages[$featurename]) && $this->package->getBundle() !== $this->bundle && $form_state->isValueEmpty('package')) {
      // Make sure the current bundle matches what is stored in the package.
      // But only do this if the Package value hasn't been manually changed.
      $bundle = $this->assigner->getBundle($this->package->getBundle());
      if (empty($bundle)) {
        // Create bundle if it doesn't exist yet.
        $bundle = $this->assigner->createBundleFromDefault($this->package->getBundle());
      }
      $this->bundle = $bundle->getMachineName();
      $this->assigner->reset();
      $this->assigner->assignConfigPackages(TRUE);
      $packages = $this->featuresManager->getPackages();
      $this->package = $packages[$featurename];
    }

    $form = [
      '#show_operations' => FALSE,
      '#prefix' => '<div id="features-edit-wrapper" class="features-edit-wrapper clearfix">',
      '#suffix' => '</div>',
    ];

    $form['info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('General Information'),
      '#tree' => FALSE,
      '#weight' => 2,
      '#prefix' => '<div id="features-export-info" class="features-export-info">',
      '#suffix' => '</div>',
    ];

    $form['info']['name'] = [
      '#title' => $this->t('Name'),
      '#description' => $this->t('Example: Image gallery') . ' (' . $this->t('Do not begin name with numbers.') . ')',
      '#type' => 'textfield',
      '#default_value' => $this->package->getName(),
    ];
    if (!$bundle->isDefault()) {
      $form['info']['name']['#description'] .= '<br/>' .
        $this->t('The namespace "@name_" will be prepended to the machine name', ['@name' => $bundle->getMachineName()]);
    }

    $form['info']['machine_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Machine-readable name'),
      '#description' => $this->t('Example: image_gallery') . ' ' . $this->t('May only contain lowercase letters, numbers and underscores.'),
      '#required' => TRUE,
      '#default_value' => $bundle->getShortName($this->package->getMachineName()),
      '#machine_name' => [
        'source' => ['info', 'name'],
        'exists' => [$this, 'featureExists'],
      ],
    ];
    if (!$bundle->isDefault()) {
      $form['info']['machine_name']['#description'] .= '<br/>' .
        $this->t('NOTE: Do NOT include the namespace prefix "@name_"; it will be added automatically.', ['@name' => $bundle->getMachineName()]);
    }

    $form['info']['description'] = [
      '#title' => $this->t('Description'),
      '#description' => $this->t('Provide a short description of what users should expect when they install your feature.'),
      '#type' => 'textarea',
      '#rows' => 3,
      '#default_value' => $this->package->getDescription(),
    ];

    $form['info']['package'] = [
      '#title' => $this->t('Bundle'),
      '#type' => 'select',
      '#options' => $this->assigner->getBundleOptions(),
      '#default_value' => $bundle->getMachineName(),
      '#ajax' => [
        'callback' => '::updateBundle',
        'wrapper' => 'features-export-info',
      ],
    ];

    $form['info']['version'] = [
      '#title' => $this->t('Version'),
      '#description' => $this->t('Examples: 8.x-1.0, 3.1.4'),
      '#type' => 'textfield',
      '#required' => FALSE,
      '#default_value' => $this->package->getVersion(),
      '#size' => 30,
    ];

    [$full_name, $path] = $this->featuresManager->getExportInfo($this->package, $bundle);
    $form['info']['directory'] = [
      '#title' => $this->t('Path'),
      '#description' => $this->t('Path to export package using Write action, relative to root directory.'),
      '#type' => 'textfield',
      '#required' => FALSE,
      '#default_value' => $path,
      '#size' => 30,
    ];

    $require_all = $this->package->getRequiredAll();
    $form['info']['require_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mark all config as required'),
      '#default_value' => $this->package->getRequiredAll(),
      '#description' => $this->t('Required config will be assigned to this feature regardless of other assignment plugins.'),
    ];

    $form['conflicts'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow conflicts'),
      '#default_value' => $this->allowConflicts,
      '#description' => $this->t('Allow configuration to be exported to more than one feature.'),
      '#weight' => 8,
      '#ajax' => [
        'callback' => '::updateForm',
        'wrapper' => 'features-edit-wrapper',
      ],
      '#wrapper_attributes' => [
        'class' => ['features-ui-conflicts'],
      ],
    ];

    $generation_info = [];
    if (\Drupal::currentUser()->hasPermission('export configuration')) {
      // Offer available generation methods.
      $generation_info = $this->generator->getGenerationMethods();
      // Sort generation methods by weight.
      uasort($generation_info, '\Drupal\Component\Utility\SortArray::sortByWeightElement');
    }

    $form['actions'] = ['#type' => 'actions', '#tree' => TRUE];
    foreach ($generation_info as $method_id => $method) {
      $form['actions'][$method_id] = [
        '#type' => 'submit',
        '#name' => $method_id,
        '#value' => $this->t('@name', ['@name' => $method['name']]),
        '#attributes' => [
          'title' => Html::escape($method['description']),
        ],
      ];
    }

    // Build the Component Listing panel on the right.
    $form['export'] = $this->buildComponentList($form_state);

    if (!empty($this->missing)) {
      if ($this->allowConflicts) {
        $form['actions']['#prefix'] = '<strong>' .
          $this->t('WARNING: Package contains configuration missing from site.') . '<br>' .
          $this->t('This configuration will be removed if you export it.') .
          '</strong>';
      }
      else {
        foreach ($generation_info as $method_id => $method) {
          unset($form['actions'][$method_id]);
        }
        $form['actions']['#prefix'] = '<strong>' .
          $this->t('Package contains configuration missing from site.') . '<br>' .
          $this->t('Import the feature to create the missing config before you can export it.') . '<br>' .
          $this->t('Or, enable the Allow Conflicts option above.') .
          '</strong>';
      }
      $form['actions']['import_missing'] = [
        '#type' => 'submit',
        '#name' => 'import_missing',
        '#value' => $this->t('Import Missing'),
        '#attributes' => [
          'title' => $this->t('Import only the missing configuration items.'),
        ],
      ];
    }

    $form['#attached'] = [
      'library' => [
        'features_ui/drupal.features_ui.admin',
      ],
      'drupalSettings' => [
        'features' => [
          'excluded' => $this->excluded,
          'required' => $this->required,
          'conflicts' => $this->conflicts,
          'autodetect' => TRUE,
        ],
      ],
    ];

    return $form;
  }

  /**
   * Provides an ajax callback for handling conflict checkbox.
   */
  public function updateForm($form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * Provides an ajax callback for handling switching the bundle selector.
   */
  public function updateBundle($form, FormStateInterface $form_state) {
    $old_bundle = $this->assigner->getBundle($this->oldBundle);
    $bundle_name = $form_state->getValue('package');
    $bundle = $this->assigner->getBundle($bundle_name);
    if (isset($bundle) && isset($old_bundle)) {
      $short_name = $old_bundle->getShortName($this->package->getMachineName());
      if ($bundle->isDefault()) {
        $short_name = $old_bundle->getFullName($short_name);
      }
      $this->package->setMachineName($bundle->getFullName($short_name));
      $form['info']['machine_name']['#value'] = $bundle->getShortName($this->package->getMachineName());
    }
    return $form['info'];
  }

  /**
   * Callback for machine_name exists()
   *
   * @param $value
   * @param $element
   * @param $form_state
   *
   * @return bool
   */
  public function featureExists($value, $element, $form_state) {
    $bundle = $this->assigner->getBundle($this->bundle);
    $value = $bundle->getFullName($value);
    $packages = $this->featuresManager->getPackages();
    // A package may conflict only if it's been exported.
    return (isset($packages[$value]) && ($packages[$value]->getState() !== FeaturesManagerInterface::STATUS_NO_EXPORT)) || \Drupal::moduleHandler()->moduleExists($value);
  }

  /**
   * Returns the render array elements for the Components selection on the Edit
   * form.
   */
  protected function buildComponentList(FormStateInterface $form_state) {
    $element = [
      '#type' => 'fieldset',
      '#title' => $this->t('Components'),
      '#description' => $this->t('Expand each component section and select which items should be included in this feature export.'),
      '#tree' => FALSE,
      '#prefix' => '<div id="features-export-wrapper" class="features-export-wrapper js-features-export-wrapper">',
      '#suffix' => '</div>',
      '#weight' => 1,
    ];

    // Filter field used in javascript, so javascript will unhide it.
    $element['features_filter_wrapper'] = [
      '#type' => 'fieldgroup',
      '#title' => $this->t('Filters'),
      '#title_display' => 'invisible',
      '#tree' => FALSE,
      '#prefix' => '<div id="features-filter" class="features-filter js-features-filter visually-hidden">',
      '#suffix' => '</div>',
      '#weight' => -10,
      '#attributes' => [
        'class' => ['features-filter__fieldset', 'container-inline'],
      ],
    ];
    $element['features_filter_wrapper']['features_filter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search'),
      '#hidden' => TRUE,
      '#default_value' => '',
      '#attributes' => ['class' => ['js-features-filter-input']],
      '#suffix' => "<span class='features-filter-clear js-features-filter-clear'>" . $this->t('Clear') . "</span>",
    ];
    $element['features_filter_wrapper']['checkall'] = [
      '#type' => 'checkbox',
      '#default_value' => FALSE,
      '#hidden' => TRUE,
      '#title' => $this->t('Select all'),
      '#attributes' => [
        'class' => [
          'features-checkall',
          'js-features-checkall',
          'features-filter',
          'js-features-filter',
        ],
      ],
      '#label_attributes' => [
        'title' => $this->t('Select all currently expanded configurations'),
      ],
    ];
    $element['features_filter_wrapper']['toggle-components'] = [
      '#type' => 'html_tag',
      '#tag' => 'a',
      '#value' => $this->t('Expand all'),
      '#attributes' => [
        'href' => '#',
        'title' => $this->t('Expand/collapse components in order to "Select all".'),
        'class' => [
          'features-toggle-components',
          'features-filter',
          'js-features-filter',
        ],
      ],
    ];
    $element['features_filter_wrapper']['hide-components'] = [
      '#type' => 'select',
      '#title' => $this->t('Hide'),
      '#options' => [
        'included' => $this->t('Normal'),
        'included+groups' => $this->t('Normal (and empty groups)'),
        'added' => $this->t('Added'),
        'detected' => $this->t('Auto detected'),
        'conflict' => $this->t('Conflict'),
      ],
      '#empty_option' => $this->t('- None -'),
      '#default_value' => '',
      '#attributes' => [
        'class' => [
          'features-hide-component',
          'features-filter',
          'js-features-filter',
        ],
        'title' => $this->t('Hide specific Features components'),
      ],
    ];
    $element['features_filter_wrapper']['features_legend'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Legend'),
      '#tree' => FALSE,
      '#prefix' => '<div id="features-legend">',
      '#suffix' => '</div>',
    ];
    $element['features_filter_wrapper']['features_legend']['legend'] = [
      '#markup' => implode('', [
        "<span class='features-legend-component features-legend-component--included'>" . $this->t('Normal') . '</span> ',
        "<span class='features-legend-component features-legend-component--added'>" . $this->t('Added') . '</span> ',
        "<span class='features-legend-component features-legend-component--detected'>" . $this->t('Auto detected') . '</span> ',
        "<span class='features-legend-component features-legend-component--conflict'>" . $this->t('Conflict') . '</span> ',
      ]),
    ];

    $sections = ['included', 'detected', 'added'];
    $config_types = $this->featuresManager->listConfigTypes();

    // Generate the export array for the current feature and user selections.
    $export = $this->getComponentList($form_state);

    foreach ($export['components'] as $component => $component_info) {

      $component_items_count = count($component_info['_features_options']['sources']);
      $label = new FormattableMarkup('@component (<span class="component-count js-component-count">@count</span>)',
        [
          '@component' => $config_types[$component],
          '@count' => $component_items_count,
        ]
      );

      $count = 0;
      foreach ($sections as $section) {
        $count += count($component_info['_features_options'][$section]);
      }
      $extra_class = ($count == 0) ? 'features-export-empty' : '';
      $component_name = str_replace('_', '-', Html::escape($component));

      if ($count + $component_items_count > 0) {
        $element[$component] = [
          '#markup' => '',
          '#tree' => TRUE,
        ];

        $element[$component]['sources'] = [
          '#type' => 'details',
          '#title' => $label,
          '#tree' => TRUE,
          '#open' => FALSE,
          '#attributes' => [
            'class' => [
              'features-export-component',
              'js-features-export-component',
            ],
          ],
          '#prefix' => "<div class='features-export-parent js-features-export-parent js-component--name-$component'>",
        ];
        $element[$component]['sources']['selected'] = [
          '#type' => 'checkboxes',
          '#id' => "edit-sources-$component_name",
          '#options' => $this->domDecodeOptions($component_info['_features_options']['sources']),
          '#default_value' => $this->domDecodeOptions($component_info['_features_selected']['sources'], FALSE),
          '#attributes' => ['class' => ['component-select']],
          '#prefix' => "<span class='components-select js-components-select'>",
          '#suffix' => '</span>',
        ];

        $element[$component]['before-list'] = [
          '#markup' => "<div class='component-list js-component-list features-export-list js-features-export-list $extra_class'>",
        ];

        foreach ($sections as $section) {
          $element[$component][$section] = [
            '#type' => 'checkboxes',
            '#options' => !empty($component_info['_features_options'][$section]) ?  $this->domDecodeOptions($component_info['_features_options'][$section]) : [],
            '#default_value' => !empty($component_info['_features_selected'][$section]) ? $this->domDecodeOptions($component_info['_features_selected'][$section], FALSE) : [],
            '#attributes' => [
              'class' => ['component-' . $section, 'js-component-' . $section],
            ],
            '#prefix' => "<span class='components-$section js-components-$section'>",
            '#suffix' => '</span>',
          ];
        }

        // Close both the before-list as well as the sources div.
        $element[$component]['after-list'] = [
          '#markup' => "</div></div>",
        ];
      }
    }

    $element['features_missing'] = [
      '#theme' => 'item_list',
      '#wrapper_attributes' => [
        'class' => [
          'features-missing-items',
        ],
      ],
      '#items' => $export['missing'],
      '#title' => $this->t('Configuration missing from active site:'),
      '#suffix' => '<div class="description">' . $this->t('Import the feature to create the missing config listed above.') . '</div>',
      '#access' => !empty($export['missing']),
    ];

    return $element;
  }

  /**
   * Returns the full feature export array based upon user selections in
   * form_state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Optional form_state information for user selections. Can be updated to
   *   reflect new selection status.
   *
   * @return \Drupal\features\Package
   *   New export array to be exported
   *   array['components'][$component_name] = $component_info
   *     $component_info['_features_options'][$section] is list of available options
   *     $component_info['_features_selected'][$section] is option state TRUE/FALSE
   *   $section = array('sources', included', 'detected', 'added')
   *     sources - options that are available to be added to the feature
   *     included - options that have been previously exported to the feature
   *     detected - options that have been auto-detected
   *     added - newly added options to the feature
   *
   *   NOTE: This routine gets a bit complex to handle all of the different
   *   possible user checkbox selections and de-selections.
   *   Cases to test:
   *   1a) uncheck Included item -> mark as Added but unchecked
   *   1b) re-check unchecked Added item -> return it to Included check item
   *   2a) check Sources item -> mark as Added and checked
   *   2b) uncheck Added item -> return it to Sources as unchecked
   *   3a) uncheck Included item that still exists as auto-detect -> mark as
   *       Detected but unchecked
   *   3b) re-check Detected item -> return it to Included and checked
   *   4a) check Sources item should also add any auto-detect items as Detected
   *       and checked
   *   4b) uncheck Sources item with auto-detect and auto-detect items should
   *       return to Sources and unchecked
   *   5a) uncheck a Detected item -> refreshing page should keep it as
   *       unchecked Detected
   *   6)  when nothing changes, refresh should not change any state
   *   7)  should never see an unchecked Included item
   */
  protected function getComponentList(FormStateInterface $form_state) {
    $config = $this->featuresManager->getConfigCollection();

    $package_name = $this->package->getMachineName();
    // Auto-detect dependencies for included config.
    $package_config = $this->package->getConfig();
    if (!empty($this->package->getConfigOrig())) {
      $package_config = array_unique(array_merge($package_config, $this->package->getConfigOrig()));
    }
    if (!empty($package_config)) {
      $this->featuresManager->assignConfigDependents($package_config, $package_name);
    }

    $packages = $this->featuresManager->getPackages();
    // Re-fetch the package in case config was updated with Dependents above.
    $this->package = $packages[$package_name];

    // Make a map of all config data.
    $components = [];
    $this->conflicts = [];
    foreach ($config as $item_name => $item) {
      if (($item->getPackage() != $package_name) &&
        !empty($packages[$item->getPackage()]) && ($packages[$item->getPackage()]->getStatus() != FeaturesManagerInterface::STATUS_NO_EXPORT)) {
        $this->conflicts[$item->getType()][$item->getShortName()] = $item->getLabel();
      }
      if ($this->allowConflicts
        || !isset($this->conflicts[$item->getType()][$item->getShortName()])
        || ($this->package->getConfigOrig() && in_array($item_name, $this->package->getConfigOrig()))) {
        $components[$item->getType()][$item->getShortName()] = $item->getLabel();
      }
    }

    // Make a map of the config data already exported to the Feature.
    $this->missing = [];
    $exported_features_info = [];
    foreach ($this->package->getConfigOrig() as $item_name) {
      // Make sure the extension provided item exists in the active
      // configuration storage.
      if (isset($config[$item_name])) {
        $item = $config[$item_name];
        // Remove any conflicts if those are not being allowed.
        // if ($this->allowConflicts || !isset($this->conflicts[$item['type']][$item['name_short']])) {
        $exported_features_info[$item->getType()][$item->getShortName()] = $item->getLabel();
        // }
      }
      else {
        $this->missing[] = $item_name;
      }
    }
    $exported_features_info['dependencies'] = $this->package->getDependencyInfo();

    // Make a map of any config specifically excluded and/or required.
    foreach (['excluded', 'required'] as $constraint) {
      $this->{$constraint} = [];
      $info = !empty($this->package->{'get' . $constraint}()) ? $this->package->{'get' . $constraint}() : [];
      // $info may be boolean.
      if (is_array($info)) {
        foreach ($info as $item_name) {
          if (!isset($config[$item_name])) {
            continue;
          }
          $item = $config[$item_name];
          $this->{$constraint}[$item->getType()][$item->getShortName()] = $item->getLabel();
        }
      }
    }

    // Make a map of the config data to be exported within the Feature.
    $new_features_info = [];
    foreach ($this->package->getConfig() as $item_name) {
      $item = $config[$item_name];
      $new_features_info[$item->getType()][$item->getShortName()] = $item->getLabel();
    }
    $new_features_info['dependencies'] = $this->package->getDependencies();

    // Assemble the combined component list.
    $config_new = [];
    $sections = ['sources', 'included', 'detected', 'added'];

    // Generate list of config to be exported.
    $config_count = [];
    foreach ($components as $component => $component_info) {
      // User-selected components take precedence.
      $config_new[$component] = [];
      $config_count[$component] = 0;
      // Add selected items from Sources checkboxes.
      if (!$form_state->isValueEmpty([$component, 'sources', 'selected'])) {
        // Don't use the array_merge function, otherwise configs like
        // "metatag.metatag_defaults.404" will have the key "404" be reindexed.
        $config_new[$component] = $config_new[$component] + $this->domDecodeOptions(array_filter($form_state->getValue([
          $component,
          'sources',
          'selected',
        ])));
        $config_count[$component]++;
      }
      // Add selected items from already Included, newly Added, auto-detected
      // checkboxes.
      foreach (['included', 'added', 'detected'] as $section) {
        if (!$form_state->isValueEmpty([$component, $section])) {
          $config_new[$component] = $config_new[$component] + $this->domDecodeOptions(array_filter($form_state->getValue([$component, $section])));
          $config_count[$component]++;
        }
      }
      // Only fallback to an existing feature's values if there are no export
      // options for the component.
      if ($component == 'dependencies') {
        if (($config_count[$component] == 0) && !empty($exported_features_info['dependencies'])) {
          $config_new[$component] = array_combine($exported_features_info['dependencies'], $exported_features_info['dependencies']);
        }
      }
      elseif (($config_count[$component] == 0) && !empty($exported_features_info[$component])) {
        $config_names = array_keys($exported_features_info[$component]);
        $config_new[$component] = array_combine($config_names, $config_names);
      }
    }

    // Generate new populated feature.
    $export['package'] = $this->package;
    $export['config_new'] = $config_new;

    // Now fill the $export with categorized sections of component options
    // based upon user selections and de-selections.
    foreach ($components as $component => $component_info) {
      $component_export = $component_info;
      foreach ($sections as $section) {
        $component_export['_features_options'][$section] = [];
        $component_export['_features_selected'][$section] = [];
      }
      if (!empty($component_info)) {
        $exported_components = !empty($exported_features_info[$component]) ? $exported_features_info[$component] : [];
        $new_components = !empty($new_features_info[$component]) ? $new_features_info[$component] : [];

        foreach ($component_info as $key => $label) {
          $config_name = $this->featuresManager->getFullName($component, $key);
          // If checkbox in Sources is checked, move it to Added section.
          if (!$form_state->isValueEmpty([$component, 'sources', 'selected', $key])) {
            $form_state->setValue([$component, 'sources', 'selected', $key], FALSE);
            $form_state->setValue([$component, 'added', $key], 1);
            $component_export['_features_options']['added'][$key] = $this->configLabel($component, $key, $label);
            $component_export['_features_selected']['added'][$key] = $key;
            // If this was previously excluded, we don't need to set it as
            // required because it was automatically assigned.
            if (isset($this->excluded[$component][$key])) {
              unset($this->excluded[$component][$key]);
            }
            else {
              $this->required[$component][$key] = $key;
            }
          }
          elseif (isset($new_components[$key]) || isset($config_new[$component][$key])) {
            // Option is in the New exported array.
            if (isset($exported_components[$key])) {
              // Option was already previously exported so it's part of the
              // Included checkboxes.
              $section = 'included';
              $default_value = $key;
              // If Included item was un-selected (removed from export
              // $config_new) but was re-detected in the $new_components
              // means it was an auto-detect that was previously part of the
              // export and is now de-selected in UI.
              if ($form_state->isSubmitted() &&
                  ($form_state->hasValue([$component, 'included', $key]) ||
                  ($form_state->isValueEmpty([$component, 'detected', $key]))) &&
                  empty($config_new[$component][$key])) {
                $section = 'detected';
                $default_value = FALSE;
              }
              // Unless it's unchecked in the form, then move it to Newly
              // disabled item.
              elseif ($form_state->isSubmitted() &&
                  $form_state->isValueEmpty([$component, 'added', $key]) &&
                  $form_state->isValueEmpty([$component, 'detected', $key]) &&
                  $form_state->isValueEmpty([$component, 'included', $key])) {
                $section = 'added';
                $default_value = FALSE;
              }
            }
            else {
              // Option was in New exported array, but NOT in already exported
              // so it's a user-selected or an auto-detect item.
              $section = 'detected';
              $default_value = NULL;
              // Check for item explicitly excluded.
              if (isset($this->excluded[$component][$key]) && !$form_state->isSubmitted()) {
                $default_value = FALSE;
              }
              else {
                $default_value = $key;
              }
              // If it's already checked in Added or Sources, leave it in Added
              // as checked.
              if ($form_state->isSubmitted() &&
                  (!$form_state->isValueEmpty([$component, 'added', $key]) ||
                   !$form_state->isValueEmpty([$component, 'sources', 'selected', $key]))) {
                $section = 'added';
                $default_value = $key;
              }
              // If it's already been unchecked, leave it unchecked.
              elseif ($form_state->isSubmitted() &&
                  $form_state->isValueEmpty([$component, 'sources', 'selected', $key]) &&
                  $form_state->isValueEmpty([$component, 'detected', $key]) &&
                  !$form_state->hasValue([$component, 'added', $key])) {
                $section = 'detected';
                $default_value = FALSE;
              }
            }
            $component_export['_features_options'][$section][$key] = $this->configLabel($component, $key, $label);
            $component_export['_features_selected'][$section][$key] = $default_value;
            // Save which dependencies are specifically excluded from
            // auto-detection.
            if (($section == 'detected') && ($default_value === FALSE)) {
              // If this was previously required, we don't need to set it as
              // excluded because it wasn't automatically assigned.
              if (!isset($this->required[$component][$key]) || ($this->package->getRequired() === TRUE)) {
                $this->excluded[$component][$key] = $key;
              }
              unset($this->required[$component][$key]);
              // Remove excluded item from export.
              if ($component == 'dependencies') {
                $export['package']->removeDependency($key);
              }
              else {
                $export['package']->removeConfig($config_name);
              }
            }
            else {
              unset($this->excluded[$component][$key]);
            }
            // Remove the 'input' and set the 'values' so Drupal stops looking
            // at 'input'.
            if ($form_state->isSubmitted()) {
              if (!$default_value) {
                $form_state->setValue([$component, $section, $key], FALSE);
              }
              else {
                $form_state->setValue([$component, $section, $key], 1);
              }
            }
          }
          elseif (!$form_state->isSubmitted() && isset($exported_components[$key])) {
            // Component is not part of new export, but was in original export.
            // Mark component as Added when creating initial form.
            $component_export['_features_options']['added'][$key] = $this->configLabel($component, $key, $label);
            $component_export['_features_selected']['added'][$key] = $key;
          }
          else {
            // Option was not part of the new export.
            $added = FALSE;
            foreach (['included', 'added'] as $section) {
              // Restore any user-selected checkboxes.
              if (!$form_state->isValueEmpty([$component, $section, $key])) {
                $component_export['_features_options'][$section][$key] = $this->configLabel($component, $key, $label);
                $component_export['_features_selected'][$section][$key] = $key;
                $added = TRUE;
              }
            }
            if (!$added) {
              // If not Included or Added, then put it back in the unchecked
              // Sources checkboxes.
              $component_export['_features_options']['sources'][$key] = $this->configLabel($component, $key, $label);
              $component_export['_features_selected']['sources'][$key] = FALSE;
            }
          }
        }
      }
      $export['components'][$component] = $component_export;
    }
    $export['features_exclude'] = $this->excluded;
    $export['features_require'] = $this->required;
    $export['conflicts'] = $this->conflicts;
    $export['missing'] = $this->missing;

    return $export;
  }

  /**
   * Returns a formatted and sanitized label for a config item.
   *
   * @param string $type
   *   The config type.
   * @param string $key
   *   The short machine name of the item.
   * @param string $label
   *   The human label for the item.
   */
  protected function configLabel($type, $key, $label) {
    $value = Html::escape($label);
    if ($key != $label) {
      $value .= '  <span class="config-name">(' . Html::escape($key) . ')</span>';
    }
    if (isset($this->conflicts[$type][$key])) {
      // Show what package the conflict is stored in.
      $config = $this->featuresManager->getConfigCollection();
      $config_name = $this->featuresManager->getFullName($type, $key);
      $package_name = isset($config[$config_name]) ? $config[$config_name]->getPackage() : '';
      // Get the full machine name instead of the short name.
      $packages = $this->featuresManager->getPackages();
      if (isset($packages[$package_name])) {
        $package_name = $packages[$package_name]->getMachineName();
      }
      $value .= '  <span class="config-name">[' . $this->t('in') . ' ' . Html::escape($package_name) . ']</span>';
    }
    return Xss::filterAdmin($value);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $bundle = $this->assigner->getBundle($this->bundle);
    $this->assigner->assignConfigPackages();

    $this->package->setName($form_state->getValue('name'));
    $this->package->setMachineName($form_state->getValue('machine_name'));
    $this->package->setDescription($form_state->getValue('description'));
    $this->package->setVersion($form_state->getValue('version'));
    $this->package->setDirectory($form_state->getValue('directory'));
    $this->package->setBundle($bundle->getMachineName());
    // Save it first just to create it in case it's a new package.
    $this->featuresManager->setPackage($this->package);

    $config = $this->updatePackageConfig($form_state);
    $this->featuresManager->assignConfigPackage($this->package->getMachineName(), $config, TRUE);
    $this->package->setExcluded($this->updateExcluded());
    if ($form_state->getValue('require_all')) {
      $this->package->setRequired(TRUE);
    }
    else {
      $required = $this->updateRequired();
      $this->package->setRequired($required);
    }
    // Now save it with the selected config data.
    $this->featuresManager->setPackage($this->package);

    $method_id = NULL;
    $trigger = $form_state->getTriggeringElement();
    $op = $form_state->getValue('op');
    if (!empty($trigger) && empty($op)) {
      $method_id = $trigger['#name'];
    }

    // Set default redirect, but allow generators to change it later.
    $form_state->setRedirect('features.edit', ['featurename' => $this->package->getMachineName()]);
    if ($method_id == 'import_missing') {
      $this->importMissing();
    }
    elseif (!empty($method_id)) {
      $packages = [$this->package->getMachineName()];
      $this->generator->generatePackages($method_id, $bundle, $packages);
      $this->generator->applyExportFormSubmit($method_id, $form, $form_state);
    }

    $this->assigner->setCurrent($bundle);
  }

  /**
   * Updates the config stored in the package from the current edit form.
   *
   * @return array
   *   Config array to be exported.
   */
  protected function updatePackageConfig(FormStateInterface $form_state) {
    $config = [];
    $components = $this->getComponentList($form_state);
    foreach ($components['config_new'] as $config_type => $items) {
      foreach ($items as $name) {
        $config[] = $this->featuresManager->getFullName($config_type, $name);
      }
    }
    return $config;
  }

  /**
   * Imports the configuration missing from the active store.
   */
  protected function importMissing() {
    $config = $this->featuresManager->getConfigCollection();
    $missing = $this->featuresManager->reorderMissing($this->missing);
    foreach ($missing as $config_name) {
      if (!isset($config[$config_name])) {
        $item = $this->featuresManager->getConfigType($config_name);
        $type = ConfigurationItem::fromConfigStringToConfigType($item['type']);
        try {
          $this->configRevert->import($type, $item['name_short']);
          $this->messenger()->addStatus($this->t('Imported @name', ['@name' => $config_name]));
        } catch (\Exception $e) {
          $this->messenger()->addError($this->t('Error importing @name : @message',
            ['@name' => $config_name, '@message' => $e->getMessage()]));
        }
      }
    }
  }

  /**
   * Updates the list of excluded config.
   *
   * @return array
   *   The list of excluded config in a simple array of full config names
   *   suitable for storing in the info.yml file.
   */
  protected function updateExcluded() {
    return $this->updateConstrained('excluded');
  }

  /**
   * Updates the list of required config.
   *
   * @return array
   *   The list of required config in a simple array of full config names
   *   suitable for storing in the info.yml file.
   */
  protected function updateRequired() {
    return $this->updateConstrained('required');
  }

  /**
   * Returns a list of constrained (excluded or required) configuration.
   *
   * @param string $constraint
   *   The constraint (excluded or required).
   *
   * @return array
   *   The list of constrained config in a simple array of full config names
   *   suitable for storing in the info.yml file.
   */
  protected function updateConstrained($constraint) {
    $constrained = [];
    foreach ($this->{$constraint} as $type => $item) {
      foreach ($item as $name => $value) {
        $constrained[] = $this->featuresManager->getFullName($type, $name);
      }
    }
    return $constrained;
  }

  /**
   * Encodes a given key.
   *
   * @param string $key
   *   The key to encode.
   *
   * @return string
   *   The encoded key.
   */
  protected function domEncode($key) {
    $replacements = $this->domEncodeMap();
    return strtr($key, $replacements);
  }

  /**
   * Decodes a given key.
   *
   * @param string $key
   *   The key to decode.
   *
   * @return string
   *   The decoded key.
   */
  protected function domDecode($key) {
    $replacements = array_flip($this->domEncodeMap());
    return strtr($key, $replacements);
  }

  /**
   * Decodes an array of option values that have been encoded by
   * features_dom_encode_options().
   *
   * @param array $options
   *   The key to encode.
   * @param bool $keys_only
   *   Whether to decode only the keys.
   *
   * @return array
   *   An array of encoded options.
   */
  protected function domDecodeOptions(array $options, $keys_only = FALSE) {
    $replacements = array_flip($this->domEncodeMap());
    $encoded = [];
    foreach ($options as $key => $value) {
      $encoded[strtr($key, $replacements)] = $keys_only ? $value : strtr($value, $replacements);
    }
    return $encoded;
  }

  /**
   * Returns encoding map for decode and encode options.
   *
   * @return array
   *   An encoding map.
   */
  protected function domEncodeMap() {
    return [
      ':' => '__' . ord(':') . '__',
      '/' => '__' . ord('/') . '__',
      ',' => '__' . ord(',') . '__',
      '.' => '__' . ord('.') . '__',
      '<' => '__' . ord('<') . '__',
      '>' => '__' . ord('>') . '__',
      '%' => '__' . ord('%') . '__',
      ')' => '__' . ord(')') . '__',
      '(' => '__' . ord('(') . '__',
    ];
  }

}
