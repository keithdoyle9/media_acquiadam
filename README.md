# Acquia DAM

This module provides Acquia DAM integration for Media entity (i.e. media type provider plugin). When a Acquia DAM asset is added to a piece of content, this module will create a media entity which provides a "local" copy of the asset to your site. These media entities will be periodically synchronized with Acquia DAM via cron.

## Acquia DAM API

This module uses Acquia DAM's REST API to fetch assets and all the metadata. The Acquia DAM REST API client is provided by [php-webdam-client](https://github.com/cweagans/php-webdam-client).

## API authentication

This module uses 2 types of authentication as required by the Acquia DAM API. The root API credentials for your Acquia DAM account should be configured on the Acquia DAM config page (/admin/config/media/webdam). Individual Acquia DAM user accounts should be created for each Drupal user that needs to manage and use Acquia DAM assets. Users will be prompted for their Acquia DAM credentials upon accessing the Acquia DAM asset browser.

## Module installation

Download and install the acquiadam module and all dependencies. [See here for help with installing modules](https://www.drupal.org/docs/8/extending-drupal-8/installing-drupal-8-modules).

- (2017-09-25) The current version of media_entity depends on entity (>=8.x-1.0-alpha3) which may have to be [installed manually](https://www.drupal.org/node/1499548)
- The [Crop](https://www.drupal.org/project/crop) and Entity Browser IEF modules are recommended for increased functionality

### Step-by-step configuration guide

This guide provides an example for how to implement the acquiadam module on your Drupal 8 site. See [https://docs.acquia.com/dam/integrate/drupal/](https://docs.acquia.com/dam/integrate/drupal/) for up to date information.

### Quick start configuration guide

Recommended: The Acquia DAM - Example Configuration module will create a new Media bundle and add an entity browser.

Manual:

Add a new media bundle (admin/structure/media/add) which uses "Acquia DAM Asset" as the "Type Provider".

- NOTE: The media bundle must be saved before adding fields. More info on creating a media bundle can be found at: https://drupal-media.gitbooks.io/drupal8-guide/content/modules/media_entity/create_bundle.html
- NOTE: It may be desirable to create separate media bundles for different types of Acquia DAM assets (i.e. "Acquia DAM Images", "Acquia DAM Documents", "Acquia DAM videos", etc).

Add a field to your newly created media bundle for storing the Acquia DAM Asset ID. The Asset ID field type should be "Number -> Number (integer)" and it should be limited to 1 value. The Asset ID field should not be configured with a "Default value", "Minimum", "Maximum", "Prefix", or "Suffix"

Add a field to your newly created media bundle for storing the Acquia DAM Asset File. The Asset file field type should be "Reference -> File" and it should be limited to 1 value.

- NOTE: It is not recommended to "Enable display field" for the file field as this currently causes new entities to be "Not Published" by default regardless of the "Files displayed by default" setting.
- NOTE: Acquia DAM asset files are downloaded locally when they are added to a piece of content. Therefore you may want to [configure private file storage](https://www.drupal.org/docs/8/core/modules/file/overview) for your site in order to prevent direct access.
- NOTE: You must configure the list of allowed file types for this field which will be specific to this media bundle. Therefore you can create separate media bundles for different types of Acquia DAM assets (i.e. "Acquia DAM Images", "Acquia DAM Documents", "Acquia DAM videos", etc).

Return to the bundle configuration and set "Field with source information" to use the assetID field and set the field map to the file field.

Optional:

Additional fields may be added to store the Acquia DAM asset metadata. Here is a list of metadata fields which can be mapped by the "Acquia DAM Asset" type provider and the recommended field type.

- colorspace: Text -> Text (plain)
- datecaptured: General -> String
- datecaptured_date: General -> Date
- datecaptured_unix: General -> Number (integer)
- datecreated: General -> String
- datecreated_date: General -> Date
- datecreated_unix: General -> Number (integer)
- datemodified: General -> String
- datemodified_date: General -> Date
- datemodified_unix: General -> Number (integer)
- description: Text -> Text (plain)
- filename: Text -> Text (plain)
- filesize: Number -> Number (decimal)
- filetype: Text -> Text (plain)
- folderID: Number -> Number (integer)
- height: Number -> Number (integer)
- id: Number -> Number (integer)
- status: General -> Boolean
- type: Text -> Text (plain)
- type_id: Number -> Number (integer)
- version: Number -> Number (integer)
- width: Number -> Number (integer)

Additional XMP metadata field mapping options, depending on the fields enabled in Acquia DAM, will also be available (ex. city, state, customfield1 etc.)

- NOTE: Do not use the "Timestamp" field type for capturing dates. It is not meant for general data storage and will not hold the synchronized metadata.

Return to the media bundle configuration page and set the field mappings for the fields that you created. When a Acquia DAM asset is added to a piece of content, this module will create a media entity which provides a "local" copy of the asset to your site. When the media entity is created the Acquia DAM values will be mapped to the entity fields that you have configured. The mapped field values will be periodically synchronized with Acquia DAM via cron.

- REQUIRED: You must create a field for the Acquia DAM asset ID and set the "Type provider configuration" to use this field as the "Field with source information".
- REQUIRED: You must create a field for the Acquia DAM asset file and map this field to "File" under field mappings.

#### Asset status

If you want your site to reflect the Acquia DAM asset status you should map the "Status" field to "Publishing status" in the media bundle configuration. This will set the published value (i.e. status) on the media entity that gets created when a Acquia DAM asset is added to a piece of content. This module uses cron to periodically synchronize the mapped media entity field values with acquiadam.

- NOTE: If you are using the asset expiration feature in Acquia DAM, be aware that that the published status will not get updated in Drupal until the next time that cron runs (after the asset has expired in Acquia DAM).
- (2017-09-26) When an inactive asset is synchronized the entity status will show blank because of [this issue](https://www.drupal.org/node/2855630)

#### Date created and date modified

If you want your site to reflect the Acquia DAM values for when the asset was created or modified you should map the "Date created" field to the "Created" and the "Date modified" field to "Changed" in the media bundle configuration. This will set the "created" and "changed" values on the media entity that gets created when a Acquia DAM asset is added to a piece of content. This module uses cron to periodically synchronize the mapped media entity field values with Acquia DAM.

### Configure Acquia DAM API credentials and Cron settings

Your acquiadam API credentials and Cron settings should be configured at /admin/config/media/acquiadam. This module uses cron to periodically synchronize the mapped media entity field values with acquiadam. The synchronize interval will be dependent on how often your site is configured to run cron.

- NOTE: The Password and Client Secret fields will appear blank after saving.

#### Crop configuration
If you are using the [Crop](https://www.drupal.org/project/crop) module on your site, you should map the "Crop configuration -> Image field" to the field that you created to store the Acquia DAM asset file.

### Configure an Entity Browser for Acquia DAM
In order to use the Acquia DAM asset browser you will need to create a new entity browser or add a Acquia DAM widget to an existing entity browser (/admin/config/content/entity_browser).

- NOTE: For more information on entity browser configuration please see the [Entity Browser](https://www.drupal.org/project/entity_browser) module and the [documentation](https://github.com/drupal-media/d8-guide/blob/master/modules/entity_browser/inline_entity_form.md) page on github
- NOTE: When editing and/or creating an entity browser, be aware that the "Modal" Display plugin is not compatible with the WYSIWYG media embed button.
- NOTE: When using the "Modal" Display plugin you may want to disable the "Auto open entity browser" setting.

### Add a media field
In order to add a Acquia DAM asset to a piece of content you will need to add a media field to one of your content types.

- NOTE: For more information on media fields please see the [Media Entity](https://www.drupal.org/project/media_entity) module and the [Drupal 8 Media Guide](https://drupal-media.gitbooks.io/drupal8-guide/content/modules/media_entity/intro.html)
- NOTE: The default display mode for media fields will only show a the media entity label. If you are using a media field for images you will likely want to change this under the display settings (Manage Display).

### WYSIWYG configuration
The media entity module provides a default embed button which can be configured at /admin/config/content/embed. It can be configured to use a specific entity browser and allow for different display modes.

- NOTE: When choosing an entity browser to use for the media embed button, be aware that the "Modal" Display plugin is not compatible with the WYSIWYG media embed button. You may want to use the "iFrame" display plugin or create a separate Entity Browser to use with the media embed button

### Acquia DAM Usage Report
For a usage report, enable the acquiadam_report module. This report provides a count of media referenced by other entities (nodes, blocks, etc.) as well as links back to the Acquia DAM asset source.

The usage report can be accessed beneath the Media tab at /admin/content/media or directly via /acquiadam/asset/report.

This module depends on the entity_usage module for it's media use count and references. For configuration options, refer to the entity_usage documentation: https://www.drupal.org/docs/8/modules/entity-usage.

- NOTE: Usage count currently includes revisions. A node, with versioning enabled and a media item attached to it, will display a use count for each revision that references the media item. Track the discussion and improvements to this behavior here: https://www.drupal.org/project/entity_usage/issues/2952210

Project page: http://drupal.org/project/acquiadam

## TODO Items
@todo: Wildcat. Remove the lightning_acquiadam submodule.
