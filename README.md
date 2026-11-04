# EditorGrowth

A/B tests interface changes to measure their effect on converting readers into editors.

## Install

Requires MediaWiki 1.45+.

```php
wfLoadExtension( 'EditorGrowth' );
$wgEditorGrowthEnabledExperiments = [
	'single-edit-tab-v1' => [],
	'edit-button-prominence-v1' => [],
];
```

Each experiment takes options:
- `styles` (default `true`): load the experiment's built-in styles. Set to `false` to supply your own CSS. E.g. in `MediaWiki:Common.css` create rules targeting `html.ext-editorgrowth-<experiment>-<bucket>`.

Create the database tables:

```sh
php maintenance/run.php update
```

Experiments are declared in `includes/Experiments.php`.

## How it works
The extension inserts a piece of render-blocking JavaScript in the document `<head>`. Before page render, the script assigns logged-out users to a random bucket for each enabled experiment and stores the assignment in `localStorage` so that the same browser keeps its bucket across visits.

The script then adds an `ext-editorgrowth-<experiment>-<bucket>` class to `<html>`. Each experiment provides a CSS stylesheet that shifts the page layout depending on this class. The experiment can also supply an optional piece of render-blocking JavaScript for changes CSS cannot make.

Each experiment also lists CSS selectors specifying a clickable element to track. When a tracked element is clicked, the click event is sent to a REST endpoint along with the browser's experiment and bucket. A unique browser ID is also generated when data is first sent to the server to identify the browser.

If the same browser later logs in, creates an account, or gets a temporary account, the script sends the browser ID once more. The server then records which account the ID belongs to and stops storing events for it on the backend. The frontend also stops sending new events after this point. Anything else we want to know (e.g. follow-up edits) is already stored in MediaWiki's database.

The `ExportData.php` maintenance script exports the database tables as JSON for analysis elsewhere. Cleaning is done in that analysis. For example, if a user performs some actions on a browser while logged-out, and then the same user logs in to an existing account, all actions should be discarded because we are interested only in converting readers into editors. This type of checking can be expensive when recording usage events, but doing it offline is acceptable.

## Preview

Use query param `?experiment=single-edit-tab-v1=source-only,some-experiment=some-bucket-name` to force enable an experiment with a specific bucket. This is for preview purposes and tracking is disabled for experiments shown this way.

## Populate data

Visit the wiki anonymously. Check `localStorage` for your bucket assignment. Click the edit button to send data to the server. Follow up with account creation (optional) for some more variety in test data.

## Export data

```sh
php maintenance/run.php EditorGrowth:ExportData --experiment single-edit-tab-v1 > data.json
```

## Test
All tests are written by AI and I have not read through them.

```sh
composer phpunit:entrypoint -- extensions/EditorGrowth/tests/phpunit
```
