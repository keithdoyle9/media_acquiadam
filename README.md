# Acquia DAM

This module provides Acquia DAM integration for Media entities (i.e. Media type provider plugin). When an Acquia DAM asset is added to a piece of content, this module will create a Media entity which provides a "local" copy of the asset to your site. These Media entities will be periodically synchronized with Acquia DAM via cron.

This guide provides an example for how to implement the acquiadam module on your Drupal site. See [https://docs.acquia.com/dam/integrate/drupal/]() **@todo: fixthislink** for additional documentation and guidance.

## Distinct from Acquia DAM Classic

The [Media: Acquia DAM module](https://www.drupal.org/project/media_acquiadam) provides similar functionality to this module for users of Acquia DAM Classic. It is important to ensure you use Media: Acquia DAM if you are a user of Acquia DAM Classic service and this module if you use the newer Acquia DAM service.

Sites which previously used Acquia DAM Classic and have migrated to the newer version of Acquia DAM can use this module to assist with the migration. See [Migrating from Acquia DAM Classic](#migrating-from-acquia-dam-classic).

## Module installation and configuration

Download and install the acquiadam module and all dependencies. [See here for help with installing modules](https://www.drupal.org/docs/extending-drupal/installing-modules).

Configuration of the module generally encompasses 5 steps:

1. [Complete the module configuration form](#global-configuration)
2. [Ensure cron tasks are running](#cron-tasks)
3. [Authenticate to the Acquia DAM service as a user](#authenticating-users)
4. [Configuring Media types](#configuring-media-types)
5. (optional) [Setting up Entity Browser](#configure-an-entity-browser-for-acquia-dam) 

### Global configuration

Global configuration settings for the module can be found on `admin/config/media/acquiadam`. Most fields have reasonable defaults but two must be supplied by the site administrator:

- Acquia DAM Domain - This is the domain at which you access your DAM instance. If you don't know the correct value for this domain, file a ticket with Acquia Support and they can provide it for you.
- Acquia DAM Token - This is an API token which Drupal will use when interacting with the Acquia DAM API outside a user context (e.g., when running cron to check for updates to assets). This token can be generated by any user with administrative privileges to the DAM itself (i.e., at the domain above). Instructions can be found at https://community.widen.com/collective/s/article/API-FAQs.

#### Cron Settings 

This module uses cron to periodically synchronize the mapped Media entity field values with acquiadam. The "asset refresh interval" field allows site owners to select the _minimum_ frequency on which the API will be accessed to check for updates. The frequency is still dependent on how often the site is configured to run cron so setting a value here lower than the frequency at which the site runs cron will have no impact.

The "synchronization method" field allows site administrators to select whether all assets in Drupal are updated on every cron run or if Drupal should instead check for assets which were updated in the DAM since the last time Drupal's cron ran. 

The option for "Delete unpublished Media entities in Drupal when assets removed from the DAM" causes Drupal to delete unpublished Media entities in Drupal when the DAM reports they were deleted from the DAM or if they remain in the DAM but were archived, unreleased, or expired. If Media entities which are tied to such assets are published in Drupal, the module instead writes a message to the site's watchdog logs

#### Image Configuration

The "Image size limit" field allows the site owner to control the maximum size of downloaded images in pixels. 

The "Image quality" field allows the site owner to control the quality of images downloaded from the DAM.

#### Manual Asset Synchronization

The configuration form includes a button to force the synchronization of all assets from the DAM. 

#### Acquia DAM Entity Browser Settings

The "Assets per page" field controls how many assets are displayed per page when using Entity Browser to select assets from the DAM. Use of Entity Browser is optional. See [below for configuration instructions](#configure-an-entity-browser-for-acquia-dam).

#### Misc.

The "Report asset usage" checkbox will send an [Integration Link](https://widenv1.docs.apiary.io/#reference/integration-links) to the DAM when a Media entity is created for an asset, allowing users of the DAM to see where assets are being used.

Acquia DAM also includes an [Acquia DAM Usage Report](#acquia-dam-usage-report) submodule which displays asset usage information in Drupal.

### Cron Tasks

The AcquiaDAM module makes use of Drupal's Queue API to process tasks asynchronously. As a result, some process needs to be configured to process the queues. Site administrators should ensure that either Drupal's core cron is being run on a frequency reasonable for their site or that separate scheduled jobs are being run to process the queues. 

The queues used by AcquiaDAM are:

- <u>acquiadam\_integration\_link\_report</u> - Used when the "Report asset usage" option is enabled.
- <u>acquiadam\_asset\_refresh</u> - Used to fetch updates for assets which changed in the DAM.

### Authenticating Users

Drupal users who will interact with the Acquia DAM service API must authenticate to the service. This includes administrative users who will set up Media types and content creators who will use the DAM to place assets in Drupal content.

To authenticate, users should go to their user edit page (`/user/[uid]/edit`) in Drupal and click the "Authorize with Acquia DAM." link from the Acquia DAM Authorization fieldset. User will then be directed to the configured DAM instance and prompted to login. After logging in to the DAM, users will be prompted to allow the Drupal integration access to their DAM account. On clicking "Allow", the user will be redirected back to their user page in Drupal.

#### Removing user authorization

Once a user has authenticated and allowed Drupal access to their DAM service account, the "Authorize with Acquia DAM" link on their Drupal user page will be replaced with a checkbox to "Remove Acquia DAM authorization." Ticing that box and saving will deauthorize the access token stored in Drupal for that user.

**Note**: This process not only removes the token from Drupal but also invalidates the token at the Acquia DAM service. As a result, if any other integrations are using the same token, they will fail when they next try to access the service.

### Configuring Media types

#### Recommended: 

The Acquia DAM - Example Configuration submodule will create new Media types for you with default mappings between Drupal fields and attributes from the DAM. 

#### Manual:

If you prefer not to use the Example Confuration submodule, add a new Media bundle (`admin/structure/media/add`) which uses "Acquia DAM Asset" as the "Media Source". This will automatically add a `field_acquiadam_asset_id` field to the Media type.

Selecting Acquia DAM Asset as the "Media Source" will also cause the form to display a Field Mapping fieldset on the Media type edit form. This allows you to map attributes from the DAM to fields on the Media type in Drupal. See [Field Mapping](#field-mapping) for more details.

**Note:** You do not necessarily _have_ to add a field to the Media type to store the DAM asset. The asset will still be fetched and stored to your local file system. However, if you do not create a field and map it to the asset from the DAM, the Media entity will not actually display the asset anywhere.

**Note:** It may be desirable to create separate Media bundles for different types of Acquia DAM assets (i.e. "Acquia DAM Images", "Acquia DAM Documents", "Acquia DAM videos", etc).

#### Field Mapping

**Note:** The Media bundle must be saved before adding fields. More info on creating a Media bundle can be found at: https://drupal-media.gitbooks.io/drupal8-guide/content/modules/media_entity/create_bundle.html

The Acquia DAM service provides global asset attributes and customer [configurable metadata attributes](https://community.widen.com/collective/s/article/What-are-metadata-fields). The Acquia DAM module provides a form element which lists both these attribute types. The Field Mapping fieldset when editing the Media type provides drop-downs for each attribute coming from the DAM service. Options within the drop downs are the fields configured within Drupal on the Media type. To map an attribute from the DAM service to a Drupal field, just select the Drupal field you want that DAM attribute to be written into. When Drupal syncs assets of that type with the DAM, the value for that attribute will populate the field in Drupal.

**Note:** By default, if you do not map the Publishing Status field in Drupal to a DAM attribute, the Media entities will be published on creation.

## Migrating from Acquia DAM Classic

When the Acquia DAM module is installed, if the Media: Acquia DAM module is already installed, Acquia DAM will uninstall the Media:Acquia DAM module (and its submodules) and update the asset_id field (`field_acquidam_asset_id`) type from an integer type to a text field, without changing the value of the field.

The Acquia DAM module also provides a means for changing the association of Media entities created using the Media: Acquia DAM module to be associated with the new Acquia DAM service (i.e., changing the values of the `field_acquiadam_asset_id` field). Prior to doing this, the assets must have been copied or moved from the Acquia DAM Classic service to the new Acquia DAM service. Then, in the Drupal administrative interface for this module, select the Migrate Assets tab (`/admin/config/media/acquiadam/migrate-assets`) and upload a CSV file containing a mapping of Asset IDs in Acquia DAM Classic to the matching Asset ID in the new Acquia DAM service.

The file must:
- contain a header row
- contain rows of comma separated values
- have a `.csv` extension
- contain one row per record of `Acquia-DAM-Classic-asset-ID, Acquia-DAM-asset-id`

<u>example CSV file</u>
```
Acquia DAM Classic ID, Acquia DAM ID
867, "a9b4127a-8393-4299-9b9e-1101c0f3b51c"
5309, "a9b4127a-8393-4299-9b9e-114x0gf4d65x"
```

#### Migrating a multisite installation  

**@todo: words on migrating a multisite.**

## Drush commands provided by Acquia DAM

The following Drush commands are provided by the module. For detailed instructions on each, run `drush <command> --help`

- <u>acquiadam:sync</u> - Synchronises assets in Drupal with those in the DAM, using either an `all` or `delta` option for which assets to sync.

**@todo: add detail on the new drush command when PR 46 is reviewed and merged**

## Configure an Entity Browser for Acquia DAM
In order to use the Acquia DAM asset browser you will need to create a new entity browser or add a Acquia DAM widget to an existing entity browser (/admin/config/content/entity_browser).

- NOTE: For more information on entity browser configuration please see the [Entity Browser](https://www.drupal.org/project/entity_browser) module and the [documentation](https://github.com/drupal-media/d8-guide/blob/master/modules/entity_browser/inline_entity_form.md) page on github
- NOTE: When editing and/or creating an entity browser, be aware that the "Modal" Display plugin is not compatible with the WYSIWYG media embed button.
- NOTE: When using the "Modal" Display plugin you may want to disable the "Auto open entity browser" setting.

## WYSIWYG configuration
The Media entity module provides a default embed button which can be configured at /admin/config/content/embed. It can be configured to use a specific entity browser and allow for different display modes.

- NOTE: When choosing an entity browser to use for the Media embed button, be aware that the "Modal" Display plugin is not compatible with the WYSIWYG Media embed button. You may want to use the "iFrame" display plugin or create a separate Entity Browser to use with the Media embed button

## Acquia DAM Usage Report
For a usage report, enable the acquiadam_report submodule. This report provides a count of Media referenced by other entities (nodes, blocks, etc.) as well as links back to the Acquia DAM asset source.

The usage report can be accessed beneath the Media tab at `/admin/content/media` or directly via `/acquiadam/asset/report`.

This module depends on the [Entity Usage](https://www.drupal.org/project/entity_usage) module for its Media use count and references. For configuration options, refer to the entity_usage documentation.

## Notes

- Acquia DAM asset files are downloaded locally when they are added to a piece of content. Therefore you may want to [configure private file storage](https://www.drupal.org/docs/8/core/modules/file/overview) for your site in order to prevent direct access.

- If you are using the [Crop](https://www.drupal.org/project/crop) module on your site, you should map the "Crop configuration -> Image field" to the field that you created to store the Acquia DAM asset file.
