# Navne

**Entity linking for WordPress newsrooms, with editors in control.**

Navne identifies meaningful people, organizations, places, and other proper nouns in published articles. Editors can review suggestions or automate high-confidence matches, turning approved names into taxonomy terms and linking their first mention to an archive page without modifying the stored article content.

> [!IMPORTANT]
> Navne is a public beta. It is suitable for evaluation and controlled newsroom deployments, but its compatibility and operating limits have not yet been validated across a broad range of WordPress environments.

## Who Navne is for

Navne is designed for local and regional news organizations that publish with WordPress and want to make their archives easier to explore.

It is most useful when:

- The same public figures, agencies, organizations, and places appear across many stories.
- Editors spend time manually tagging articles or adding repetitive internal links.
- Local names, acronyms, and institutional language require newsroom-specific context.
- The newsroom wants automation without giving up editorial review.

Navne currently depends on the WordPress block editor and Anthropic API. It is not intended as a general-purpose SEO autolinker or a replacement for editorial judgment.

## What it does

- Detects people, organizations, places, and other significant named entities.
- Adds newsroom context through an organization definition list.
- Offers manual, assisted, and high-confidence automatic workflows.
- Presents suggestions in an Entities sidebar in the block editor.
- Creates a flat, public `navne_entity` taxonomy from approved entities.
- Links the first matching mention in an article to the entity archive page.
- Applies links at render time, leaving stored post content unchanged.
- Processes articles asynchronously with Action Scheduler.
- Bulk-indexes existing archives with date-range, mode, progress, cancellation, and retry controls.

## How it works

```text
Publish or update an article
            |
            v
Queue background analysis
            |
            v
Send article text and local definitions to Anthropic
            |
            v
Apply the selected editorial mode
            |
            v
Approve or create entity taxonomy terms
            |
            v
Link first mentions to entity archive pages at render time
```

Navne currently sends article text directly to an Anthropic model for entity extraction and confidence scoring. A separate spaCy candidate-detection stage is not part of the current implementation.

## Editorial modes

| Mode | New and updated articles | Bulk indexing |
| --- | --- | --- |
| **Safe** | Does not process automatically. An editor starts analysis from the Entities sidebar and reviews every result. | Tags only entities that already exist in the `navne_entity` taxonomy. Unmatched entities are discarded. |
| **Suggest** | Processes automatically after save. Every detected entity waits for editor approval. | Creates pending suggestions for every detected entity. |
| **YOLO** | Automatically approves entities with confidence of 75% or higher. Lower-confidence entities wait for review. | Uses the same 75% threshold for automatic approval and leaves lower-confidence results pending. |

Suggest mode is the recommended starting point for an evaluation. It exposes the complete workflow while keeping every linking decision under human control.

## Requirements

- WordPress 6.0 or later
- PHP 8.0 or later
- WordPress block editor for the editorial review interface
- An [Anthropic API](https://console.anthropic.com/) key with available credit
- Working WordPress cron or another Action Scheduler queue runner
- Composer for the current source installation process

## Installation

Navne does not yet publish a production-ready plugin ZIP. The automatic source archives attached to GitHub releases do not include Composer dependencies and cannot be uploaded directly through the WordPress plugin installer.

To install from source:

1. Clone or download this repository.
2. Open the inner `navne/` directory, which is the WordPress plugin directory.
3. Install runtime dependencies:

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

4. Copy the complete inner `navne/` directory, including the generated `vendor/` directory, to `wp-content/plugins/navne/` on the WordPress site.
5. Activate **Navne Entity Linker** from **Plugins** in WordPress.

The compiled editor assets are committed to the repository. Node.js is only required when changing those assets.

## Configuration

Open **Settings → Navne** after activation.

### 1. Configure the API key

For better secret handling, define the Anthropic API key in `wp-config.php`:

```php
define( "NAVNE_ANTHROPIC_API_KEY", "your-api-key" );
```

The settings page also accepts an API key stored in the WordPress options table, but the constant takes precedence when both are present.

### 2. Choose the model

The default model is `claude-sonnet-4-6`. Administrators can enter another valid Anthropic model identifier on the settings page.

### 3. Choose an editorial mode

Start with **Suggest** unless the newsroom has already established another review policy. See [Editorial modes](#editorial-modes) for the behavioral differences.

### 4. Choose content types

Select which public post types Navne should process. Standard WordPress posts are selected by default.

### 5. Add local definitions

Definitions help the model interpret names and acronyms that have a specific local meaning. Enter one definition per line using `Term: Description`:

```text
DOE: The local school district, not the federal Department of Energy
Gov. Smith: Governor Jane Smith, incumbent since 2020
# Lines beginning with a hash are ignored
```

Definitions are sent to Anthropic with each article processed by Navne. Do not place secrets or private source information in this field.

## Editor workflow

1. Publish or update an article.
2. Open **Entities** from the block editor's plugin sidebar.
3. Wait for the background analysis to complete. In Safe mode, select **Process this article** first.
4. Review each suggested name, type, and confidence score.
5. Approve entities that should become part of the site's entity taxonomy; dismiss the rest.
6. View the published article. The first matching mention of each approved entity links to its taxonomy archive.

If processing fails, the sidebar provides a retry action. Retries are limited to one request per article every 60 seconds.

## Bulk indexing

Open **Tools → Navne Indexing** to process an existing archive.

Available run types:

- **Index new** processes posts that have never been processed by Navne.
- **Re-index all** processes every post in scope, including previously processed posts.
- **Retry failed** creates a new run from failed items in an earlier run.

Each run can use a mode independent of the global setting and can be restricted to an optional date range. Navne previews the number of matching posts and a rough API cost before starting. The run detail screen reports live progress and supports cancellation; jobs already submitted to Action Scheduler may finish after cancellation.

Bulk processing is paced in small batches to reduce API rate-limit pressure. Actual throughput depends on WordPress cron, the Action Scheduler queue, hosting resources, and Anthropic limits.

## Content and archive behavior

Approved entities are stored as terms in the public, non-hierarchical `navne_entity` taxonomy. WordPress exposes those archives under `/entity/{term-slug}/` by default.

Navne relies on the active theme's standard taxonomy archive templates. It does not currently provide custom archive designs or enhanced entity profiles.

On singular views, Navne links only the first matching mention of each assigned entity. It avoids replacing text inside existing links and caches the link map through the WordPress object cache API.

## Privacy, data, and cost

The current provider implementation sends the following from the WordPress server to Anthropic:

- The article title
- Plain-text article content, capped at approximately 8,000 characters
- The organization definition list configured by an administrator
- Instructions requesting entity names, types, and confidence scores

Use of Navne is therefore subject to Anthropic's terms, privacy practices, availability, rate limits, and pricing. Newsrooms should review their own policies and contractual obligations before sending unpublished, embargoed, personal, or otherwise sensitive material to an external model provider.

Navne displays a rough bulk-processing estimate based on an assumed average cost per article. It is not a billing guarantee. Actual cost varies by article length, model, output, and Anthropic pricing.

## Known limitations

- Anthropic is the only implemented model provider.
- Entity extraction is LLM-only; the planned spaCy candidate-detection stage is not implemented.
- The editorial review interface requires the WordPress block editor.
- The taxonomy is currently registered only for standard WordPress posts. Although the settings page lists other public post types, those combinations are not yet fully supported.
- Taxonomy archive appearance depends on the active theme.
- The 75% YOLO confidence threshold is not configurable.
- Approved entities cannot be removed from an article through the Navne sidebar.
- Article text is truncated before analysis, so entities appearing late in long articles may be missed.
- Background processing depends on a healthy WordPress cron and Action Scheduler queue.
- Compatibility with WordPress multisite and a broad range of third-party plugins has not yet been established.

## Troubleshooting

### Suggestions never appear

Confirm that the article is published, its post type is enabled under **Settings → Navne**, and WordPress cron is running. Check **Tools → Scheduled Actions** for delayed or failed Navne jobs.

### Processing fails

Confirm that the Anthropic API key is valid, the configured model identifier exists, the account has available credit, and the hosting environment permits outbound HTTPS requests to `api.anthropic.com`.

### Approved entities do not link

Confirm that the entity term is assigned to the post and that the article contains a text match for the term name. Clear persistent object and page caches after changing entity terms if the rendered page remains stale.

### Entity archive pages return 404

Refresh the site's permalink settings under **Settings → Permalinks** and confirm that the active theme supports taxonomy archives.

## Project status and releases

Navne follows [Semantic Versioning](https://semver.org/). See the [changelog](CHANGELOG.md) for the technical history and [`docs/releases/`](docs/releases/) for narrative release notes.

The current codebase is a public beta. Before a broader production release, the project still needs an installable release package, compatibility testing, a formal license, a security reporting policy, and complete user documentation.

## Development

Run development commands from the inner `navne/` directory:

```bash
composer install
npm install
npm run build
vendor/bin/phpunit
```

Use `npm run start` for an editor-asset development watch process.

The application code lives under [`navne/includes/`](navne/includes/), tests under [`navne/tests/`](navne/tests/), and JavaScript source under [`navne/assets/js/`](navne/assets/js/).

## License

A project license has not yet been selected. Until one is published, the repository's source availability should not be interpreted as permission to use, modify, or redistribute the software.
