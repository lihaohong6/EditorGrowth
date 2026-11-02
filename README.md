# EditorGrowth

A/B tests interface changes to measure their effect on converting readers into editors.

## Install

Requires MediaWiki 1.46+.

```php
wfLoadExtension( 'EditorGrowth' );
$wgEditorGrowthEnabledExperiments = [ 'single-edit-tab-v1' ];
```

Create the database tables:

```sh
php maintenance/run.php update
```

Experiments are declared in `includes/Experiments.php`.

## Populate data

Visit the wiki anonymously. Check `localStorage` for your bucket assignment. Click the edit button to send data to the server. Follow up with account creation (optional) for some more variety in test data.

## Report results

```sh
php maintenance/run.php EditorGrowth:ReportExperiment --experiment single-edit-tab-v1
```

## Test
All tests are written by AI and I have not read through them.

```sh
composer phpunit:entrypoint -- extensions/EditorGrowth/tests/phpunit
```
