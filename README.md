# Drush switch_to_minimal

This command file adds a pre-command hook to the `deploy` command, which
ensures that the install profile is set to whatever is defined in
`core.extension.yml`. If the current profile is not the same as that
profile, the install profile is changed. This hook will run **EVERY TIME**
`drush deploy` is invoked. It is perfectly harmless to keep this code
around after you switch profiles; it will simply bail with the message
“Current profile is already \[profile-name].”

If `core.extension.yml` cannot be found, this command will fall back
to the `minimal` profile.

This package also provides the command `switch-profile` which will
perform the profile switch without invoking `drush deploy`.

As of Drush 13.6.0.0, there is a patch that must be applied to drush
itself to make the pre-command hook be detected by `drush deploy`.
A copy of this patch is included; you should add it your project’s
patchset (either `composer.patches.json` or the `extra.patches` key
in `composer.json). For more information, see
[Drush issue #6304](https://github.com/drush-ops/drush/issues/6304).

The command file will be installed in `${PROJECT_ROOT}/drush/Commands/contrib/switch_to_minimal`.

## How do I install it?

Just invoke the following in your project:

```
composer require ossobuffo/switch_to_minimal
```

## Why would I need this?

This is designed to help move projects away from abandoned or deprecated
contrib or custom profiles. If your automated deployment process is to
push code and then run `drush deploy`, this should seamlessly integrate
with that workflow.

## Why is it named “switch_to_minimal” when it is actually switching to some other profile name?

The very first iterations of this command package had the `minimal` profile
hardcoded.

## Caveats

If the profile you are moving away from includes contrib modules or libraries
in its `composer.json`, you should make sure that they are now pulled in by
your current main `composer.json`.

This could quite possibly work with older versions of Drush than
advertised in the `composer.json` file. I have not tested, and do not
consider it worth my while to do so.
