<?php

namespace Drush\Commands\switch_to_minimal;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Psr\Container\ContainerInterface;

/**
 * Adds a pre-command to switch profiles if necessary.
 */
class StmCommands extends DrushCommands {

  public const string TARGET_PROFILE = 'minimal';

  /**
   * Builds this command.
   *
   * @param string $oldProfile
   *   The name of the existing install profile.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValueFactory
   *   The key-value factory service.
   */
  public function __construct(
    protected string $oldProfile,
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
    return new static(
      $old_profile,
      $state,
      $config_factory,
      $key_value_factory,
    );
  }

  #[CLI\Command(name: 'switch-to-minimal')]
  #[CLI\Help(description: 'Switch to the ‘minimal’ Drupal install profile.')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function switch(): int {
    return $this->doSwitchToMinimalProfile();
  }

  /**
   * Pre-command hook to ensure we are running the minimal profile.
   *
   * This hook is invoked immediately before `drush deploy`.
   */
  #[CLI\Hook(HookManager::PRE_COMMAND_HOOK, target: 'deploy')]
  #[CLI\Bootstrap(level: DrupalBootLevels::FULL)]
  public function doSwitchToMinimalProfile(?CommandData $commandData = NULL): int {
    if ($this->oldProfile === self::TARGET_PROFILE) {
      $this->io()->info(dt('Current profile is already @profile', ['@profile' => self::TARGET_PROFILE]));
      return DrushCommands::EXIT_SUCCESS;
    }
    $schema_store = $this->keyValueFactory->get('system.schema');
    // Forces ExtensionDiscovery to rerun for profiles.
    $this->state->delete('system.profile.files');

    // Set the profile in configuration.
    $extension_config = $this->configFactory->getEditable('core.extension');
    $extension_config->set('profile', self::TARGET_PROFILE)->save();

    drupal_flush_all_caches();

    // Install profiles are also registered as enabled modules. Remove the module
    // entry for the old profile and add in the new one.
    // The installation profile is always given a weight of 1000 by the core
    // extension system.
    $extension_config
      ->clear('module.' . $this->oldProfile)
      ->set('module.' . self::TARGET_PROFILE, 1000)
      ->save();

    // Remove the schema value for the old installation profile, and set the
    // schema for the new one.
    $schema_store->delete($this->oldProfile);
    $schema_store->set(self::TARGET_PROFILE, 9000);

    // Clear caches again.
    drupal_flush_all_caches();
    $this->io()->info(dt('Changed profile from @old to @new', ['@old' => $this->oldProfile, '@new' => self::TARGET_PROFILE]));

    return DrushCommands::EXIT_SUCCESS;
  }

}
