<?php

namespace Drush\Commands\switch_to_minimal;

use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\config\ConfigImportCommands;
use Drush\Commands\DrushCommands;
use Psr\Container\ContainerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Adds a pre-command to switch profiles if necessary.
 */
#[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
class StmCommands extends DrushCommands {

  public const string DEFAULT_TARGET_PROFILE = 'minimal';

  /**
   * Builds this command.
   *
   * @param string $oldProfile
   *   The name of the existing install profile.
   * @param string $targetProfile
   *   The name of the install profile to be set.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValueFactory
   *   The key-value factory service.
   */
  public function __construct(
    protected string $oldProfile,
    protected string $targetProfile,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected KeyValueFactoryInterface $keyValueFactory,
  ) {
    parent::__construct();
  }

  /**
   * Allows creation of this class without depending on the Autowire trait.
   *
   * As of Drush 13.6.0.0, sitewide commands are not auto-discovered if they
   * depend on Autowire for instantiation. This is probably a bug.
   *
   * @param \Psr\Container\ContainerInterface $container
   *   An instance of \League\Container\Container.
   *
   * @throws \Psr\Container\ContainerExceptionInterface
   *   Thrown if a requested service or parameter is not instantiable.
   */
  public static function create(ContainerInterface $container):static {
    // The League container delegates to the Drupal container, which contains
    // a reference to itself as 'service_container'.
    /** @var \Drupal\Component\DependencyInjection\ContainerInterface $drupal_container */
    $drupal_container = $container->get('service_container');
    /** @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory */
    $key_value_factory = $drupal_container->get('keyvalue');
    /** @var \Drupal\Core\Config\ConfigFactoryInterface $config_factory */
    $config_factory = $drupal_container->get('config.factory');
    /** @var \Drupal\Core\State\StateInterface $state */
    $state = $drupal_container->get('state');
    $old_profile = strval($drupal_container->getParameter('install_profile'));
    return new static(
      $old_profile,
      self::getTargetProfile(),
      $state,
      $config_factory,
      $key_value_factory,
    );
  }

  /**
   * Finds the target profile based on exported configuration, where possible.
   *
   * @return string
   *   The machine name of the target profile. If configuration could not be
   *   read, falls back to self::DEFAULT_TARGET_PROFILE.
   */
  protected static function getTargetProfile(): string {
    // Set our fallback profile to the default, to be (hopefully) overridden
    // below.
    $target_profile = self::DEFAULT_TARGET_PROFILE;

    // Try to find the name of the new (target) profile in the exported config.
    $config_dir = realpath(DRUPAL_ROOT . '/' . (Settings::get('config_sync_directory') ?? '../config/sync'));
    if (!is_dir($config_dir) || !file_exists("$config_dir/core.extension.yml")) {
      // Try looking for core.extension.yml in any of the sibling directories.
      // This may be the case for sites that use config_split. Here we'll
      // assume that all splits will have the same profile configured, so we can
      // grab one at random.
      if (is_dir(dirname($config_dir))) {
        $files = glob(dirname($config_dir) . '/*/core.extension.yml');
        if (!empty($files)) {
          $config_dir = dirname(reset($files));
        }
      }
    }
    if (file_exists("$config_dir/core.extension.yml")) {
      try {
        $extension_data = Yaml::parseFile($config_dir . '/core.extension.yml');
        if (is_array($extension_data) && is_string($extension_data['profile'] ?? NULL)) {
          $target_profile = $extension_data['profile'];
        }
      }
      catch (ParseException) {
        // YAML parsing failed; core.extension.yml is invalid. So we'll fall
        // back to our hardcoded default target profile set above.
      }
    }
    return $target_profile;
  }

  #[CLI\Command(name: 'switch-profile')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Help(description: 'Switches to the new profile as configured in core.extension.yml, and removes the old one.')]
  #[CLI\Usage(name: 'drush switch-profile', description: 'Switches to a new profile, and removes the old one.')]
  public function switch(): int {
    if ($this->oldProfile === $this->targetProfile) {
      $this->io()->info(sprintf('Current profile is already %s', $this->targetProfile));
      return DrushCommands::EXIT_SUCCESS;
    }
    if ($this->io()->confirm(sprintf('Switch profile from %s to %s?', $this->oldProfile, $this->targetProfile))) {
      $this->doSwitchToProfile();
      return DrushCommands::EXIT_SUCCESS;
    }
    return DrushCommands::EXIT_FAILURE_WITH_CLARITY;
  }

  /**
   * Pre-command hook to ensure we are running the correct profile.
   *
   * This hook is invoked immediately before `drush config:import`.
   */
  #[CLI\Hook(HookManager::PRE_COMMAND_HOOK, target: ConfigImportCommands::IMPORT)]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function doSwitchToProfile(): void {
    if ($this->oldProfile === $this->targetProfile) {
      if ($this->io()->isVerbose()) {
        $this->io->info(sprintf('Current profile is already %s', $this->targetProfile));
      }
      return;
    }
    $schema_store = $this->keyValueFactory->get('system.schema');
    // Forces ExtensionDiscovery to rerun for profiles.
    $this->state->delete('system.profile.files');

    // Set the profile in configuration.
    $extension_config = $this->configFactory->getEditable('core.extension');
    $extension_config->set('profile', $this->targetProfile)->save();

    drupal_flush_all_caches();

    // Install profiles are also registered as enabled modules. Remove the module
    // entry for the old profile and add in the new one.
    // The installation profile is always given a weight of 1000 by the core
    // extension system.
    $extension_config
      ->clear('module.' . $this->oldProfile)
      ->set('module.' . $this->targetProfile, 1000)
      ->save();

    // Remove the schema value for the old installation profile, and set the
    // schema for the new one.
    $schema_store->delete($this->oldProfile);
    $schema_store->set($this->targetProfile, 9000);

    // Clear caches again.
    drupal_flush_all_caches();
    $this->io()->info(sprintf('Changed profile from %s to %s', $this->oldProfile, $this->targetProfile));
  }

}
