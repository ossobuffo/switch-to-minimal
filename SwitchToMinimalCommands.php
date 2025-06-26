<?php

namespace Drupal\Commands\switch_to_minimal;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drush\Attributes\Bootstrap;
use Drush\Attributes\Hook;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds a pre-command to switch profiles if necessary.
 */
#[Bootstrap(level: DrupalBootLevels::FULL)]
class SwitchToMinimalCommands extends DrushCommands {

  use AutowireTrait;

  const TARGET_PROFILE = 'minimal';

  /**
   * Builds this command.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The DI container.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValueFactory
   *   The key-value factory service.
   */
  public function __construct(
    protected ContainerInterface $container,
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
    protected KeyValueFactoryInterface $keyValueFactory,
  ) {
    parent::__construct();
  }

  /**
   * Pre-command hook to ensure we are running the minimal profile.
   *
   * This hook is invoked immediately before `drush deploy`.
   */
  #[Hook(HookManager::PRE_COMMAND_HOOK, target: 'deploy')]
  public function switchToMinimalProfile(CommandData $commandData): int {
    $profile_to_remove = $this->container->getParameter('install_profile');
    if ($profile_to_remove == self::TARGET_PROFILE) {
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
      ->clear('module.' . $profile_to_remove)
      ->set('module.' . self::TARGET_PROFILE, 1000)
      ->save();

    // Remove the schema value for the old installation profile, and set the
    // schema for the new one.
    $schema_store->delete($profile_to_remove);
    $schema_store->set(self::TARGET_PROFILE, 9000);

    // Clear caches again.
    drupal_flush_all_caches();
    $this->io()->info(dt('Changed profile from @old to @new', ['@old' => $profile_to_remove, '@new' => self::TARGET_PROFILE]));

    return DrushCommands::EXIT_SUCCESS;
  }

}
