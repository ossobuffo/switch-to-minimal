<?php

namespace Drush\Commands\switch_to_minimal;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
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
   * depend on Autowire for instantiation.
   *
   * @param \Psr\Container\ContainerInterface $container
   *   An instance of \League\Container\Container.
   *
   * @throws \Psr\Container\ContainerExceptionInterface
   *   Thrown if a requested service is not instantiable.
   */
  public static function create(ContainerInterface $container):static {
    /** @var \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory */
    $key_value_factory = $container->get('keyvalue');
    /** @var \Drupal\Core\Config\ConfigFactoryInterface $config_factory */
    $config_factory = $container->get('config.factory');
    /** @var \Drupal\Core\State\StateInterface $state */
    $state = $container->get('state');
    // Note that because the passed-in container is a League container, it does
    // not have a getParameter() method, so we have to dig deeper to get the
    // installation profile.
    $old_profile = strval($config_factory->get('core.extension')->get('profile'));
    // Try to find the name of the new (target) profile.
    $config_dir = realpath(DRUPAL_ROOT . '/' . (Settings::get('config_sync_directory') ?? '../config/sync'));
    $target_profile = self::DEFAULT_TARGET_PROFILE;
    if (!is_dir($config_dir)) {
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
        // Do nothing.
      }
    }
    return new static(
      $old_profile,
      $target_profile,
      $state,
      $config_factory,
      $key_value_factory,
    );
  }

  #[CLI\Command(name: 'switch-profile')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  #[CLI\Help(description: 'Switches to the new profile as configured in core.extension.yml, and removes the old one.')]
  #[CLI\Usage(name: 'drush switch-profile', description: 'Switches to a new profile, and removes the old one.')]
  public function switch(): int {
    if ($this->oldProfile === $this->targetProfile) {
      $this->io()->info(dt('Current profile is already @profile', ['@profile' => $this->targetProfile]));
      return DrushCommands::EXIT_SUCCESS;
    }
    if ($this->io()->confirm(dt('Switch profile from @old to @new?', ['@old' => $this->oldProfile, '@new' => $this->targetProfile]))) {
      return $this->doSwitchToProfile();
    }
    return DrushCommands::EXIT_FAILURE_WITH_CLARITY;
  }

  /**
   * Pre-command hook to ensure we are running the correct profile.
   *
   * This hook is invoked immediately before `drush deploy`.
   */
  #[CLI\Hook(HookManager::PRE_COMMAND_HOOK, target: 'deploy')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function doSwitchToProfile(?CommandData $commandData = NULL): int {
    if ($this->oldProfile === $this->targetProfile) {
      $this->io()->info(dt('Current profile is already @profile', ['@profile' => $this->targetProfile]));
      return DrushCommands::EXIT_SUCCESS;
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
    $this->io()->info(dt('Changed profile from @old to @new', ['@old' => $this->oldProfile, '@new' => $this->targetProfile]));

    return DrushCommands::EXIT_SUCCESS;
  }

}
