# Drush switch-to-minimal

This command file adds a pre-command hook to the `deploy` command, which
ensures that the install profile is set to `minimal`. If it is not
`minimal`, the profile is changed. This hook will run **EVERY TIME**
`drush deploy` is invoked. It is perfectly harmless to keep this code
around after you switch profiles; it will simply bail with the message
“Current profile is already minimal.”

## Why would I need this?

This is designed to help move projects away from abandoned or deprecated
contrib or custom profiles. If your automated deployment process is to
push code and then run `drush deploy`, this should seamlessly integrate
with that workflow.

## Caveats

Obviously you should remove this from your project if you want to switch
to another non-minimal profile.

If the profile you are moving away from includes contrib modules or libraries
in its composer.json, you should make sure that they are now pulled in by
your current main composer.json.

This could quite possibly work with older versions of Drush and Drupal than
advertised in the composer.json file. I have not tested, and do not
consider it worth my while to do so.
