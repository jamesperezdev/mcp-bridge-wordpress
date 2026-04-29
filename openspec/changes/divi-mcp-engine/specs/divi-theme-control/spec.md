# divi-theme-control Specification

## Purpose

Enable remote update of Divi's global theme settings (color palettes, typography, layout defaults, button styles) via REST API, supporting automated design system application and theme-wide changes.

## Requirements

### Requirement: Divi Theme Settings Endpoint

The system SHALL expose POST /divi/theme-settings to update Divi global options stored in wp_options. The endpoint MUST validate the setting key against an allowlist before writing.

#### Scenario: Update color palette

- GIVEN the request body contains {"setting": "et_divi[primary_nav_background]", "value": "#234567"}
- WHEN the user has admin capability
- THEN the system SHALL update the wp_option for et_divi with the new primary_nav_background value and return {success:true, setting:"primary_nav_background", value:"#234567"}

#### Scenario: Update typography setting

- GIVEN the request body contains {"setting": "et_divi[body_font]", "value": "Open Sans"}
- WHEN the user has admin capability
- THEN the system SHALL update the body_font in et_divi options and return {success:true, setting:"body_font", value:"Open Sans"}

#### Scenario: Invalid setting key

- GIVEN the request body contains {"setting": "et_divi[random_invalid_key]", "value": "test"}
- WHEN the setting key is not in the allowlist
- THEN the system SHALL return HTTP 400 with {success:false, error:"Setting key not in allowlist"}

### Requirement: Batch Settings Update

The system SHALL accept an array of setting/value pairs in a single request and apply them transactionally, rolling back all changes if any single update fails.

#### Scenario: Batch successful update

- GIVEN the request body contains {"settings":[{"setting":"et_divi[accent_color]","value":"#ff0000"},{"setting":"et_divi[body_font]","value":"Roboto"}]}
- WHEN all settings are valid
- THEN the system SHALL update both options and return {success:true, updated:2}

#### Scenario: Batch partial failure

- GIVEN the request body contains {"settings":[{"setting":"et_divi[accent_color]","value":"#ff0000"},{"setting":"et_divi[invalid_key]","value":"test"}]}
- WHEN one setting is invalid
- THEN the system SHALL NOT update any settings and return {success:false, error:"Batch aborted: invalid setting detected", valid_index:0, invalid_key:"invalid_key"}

### Requirement: Settings Retrieval

The system SHALL provide GET /divi/theme-settings to retrieve current Divi theme settings for a given set of keys. If no keys provided, return all Divi options.

#### Scenario: Get specific settings

- GIVEN the request query params contain keys="accent_color,body_font"
- WHEN the user has admin capability
- THEN the system SHALL return {success:true, settings:{accent_color:"#ff0000", body_font:"Open Sans"}}

#### Scenario: Get all settings

- GIVEN the request query params contain no keys parameter
- WHEN the user has admin capability
- THEN the system SHALL return all et_divi options as a key-value map

### Requirement: Divi Active Check

The system SHALL verify that the Divi theme is active before accepting any theme settings update. Requests to update Divi settings when Divi is not active MUST be rejected.

#### Scenario: Divi inactive

- GIVEN the current active theme is not Divi
- WHEN a request is made to POST /divi/theme-settings
- THEN the system SHALL return HTTP 400 with {success:false, error:"Divi is not the active theme. Activate Divi to use this endpoint."}