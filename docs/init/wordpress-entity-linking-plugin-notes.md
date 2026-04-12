# WordPress Entity Linking Plugin — Brainstorm Notes

## The idea

A WordPress plugin built for news organizations. It does two things: automatically link proper nouns in published articles to their taxonomy archive pages, and automatically generate and index taxonomy terms for each entity detected.

The target user is a newsroom. People, businesses, and other entities that are mentioned, quoted, or cited in any story get assigned to a structured taxonomy — and the first mention of each entity in a post body gets hyperlinked to that term's archive page.

## Core design decisions

**Flat taxonomy.** One taxonomy for all entity types. No separate buckets for People, Organizations, or Places. Simpler to manage, simpler to build.

**First-mention linking only.** Editorial style guides link the first mention of a name in a story. The plugin follows that convention. Subsequent mentions in the same post are left alone.

**Render-time linking, not database writes.** Links are applied via WordPress's `the_content` filter at render time, not injected into stored post content. The database stays clean. If a term is deleted, merged, or renamed, the output updates automatically without a migration. For news orgs with long-lived archives, this matters.

**Render-time and caching.** The concern with render-time linking is that production news infrastructure often caches fully rendered HTML at the page level. The solution is an object cache layer (Redis or Memcached, which serious news orgs already run). On first render after publish, the LLM runs and the entity-linking map for that post gets stored in the object cache. Subsequent renders do a microsecond lookup and apply the map. Cache invalidation fires when terms are created, merged, or deleted — targeting only affected posts, not the entire site.

**Minimum mention threshold.** A plugin setting controls how many times an entity must appear across the archive before its term page goes public. The default can be as low as one mention.

**Org-specific definition list.** Before the LLM reads any post, the org configures a knowledge base: local figures with context about who they are, local jargon, acronyms, and entity definitions specific to their coverage area. This primes the LLM with local context and is the key input that makes entity disambiguation work. "DOE" means the local school district, not the federal department. "The Governor" means someone specific. The definition list is where that knowledge lives.

**Disambiguation via context.** The LLM uses article context plus the org definition list to resolve ambiguous proper nouns. "Georgia" as a state versus a country. "Apple" the company versus the fruit. The definition list handles known local cases; the LLM handles everything else.

**Duplicate name handling.** A dedicated workflow in the moderator dashboard handles people with the same name. This is not resolved automatically.

## The three operating modes

The whitelist is the source of truth for what gets linked. The LLM is a suggestion engine, not the authority.

**Safe mode (default).** The plugin only links entities that appear in the org's approved whitelist. Nothing outside the whitelist gets linked. This is the right default for news orgs — it keeps the tool predictable and avoids incorrect links on names that haven't been reviewed.

**Suggest mode.** Same behavior as safe mode in production, but on post save the editor gets a non-blocking prompt in the sidebar: "3 potential new entities detected — want to review?" They can approve entries to the whitelist, ignore them, or dismiss. Most orgs will live here day to day.

**YOLO mode.** Full automatic. The LLM extracts and links everything above the confidence threshold. New terms are created without review. A moderator queue catches problems after the fact. This is opt-in, not the default.

The whitelist-first design also solves the defamation risk: if "John Smith" isn't on the whitelist, he can't be linked to the wrong John Smith's archive page.

## The two core workflows

**Initial setup**
1. Configure the org knowledge base — local figures, jargon, acronyms, entity definitions
2. Set plugin preferences — operating mode, confidence threshold, minimum mention count, taxonomy visibility rules
3. Run a full site re-index — posts are processed in bulk, terms are created, conflicts go to the moderator dashboard for review

**On post save**
1. Post content is sent to the LLM along with the org knowledge base as context
2. The LLM returns an entity list with types and confidence scores
3. New entities are matched against the whitelist
4. In suggest mode, unmatched entities above the confidence threshold surface as sidebar suggestions for the editor
5. Approved whitelist matches get first-mention links applied at render time
6. Anything below the confidence threshold or flagged for disambiguation goes to the moderator queue

## Existing ecosystem

The closest existing tool is **WordLift**, a WordPress plugin that runs NER on post content, maintains an entity vocabulary, and suggests internal links. It's worth demoing before building — not to copy it, but to understand where the editorial friction points are in practice.

WordLift misses in two important ways. First, it's an SEO tool, not an editorial workflow tool. Its primary goal is Schema.org markup and Google visibility, not newsroom entity management. Second, it ties your entity data to their infrastructure — deactivating the plugin removes the vocabulary layer from the dashboard. That's a lock-in concern for any news org treating its entity graph as editorial infrastructure.

**TaxoPress** is the other relevant plugin. It links existing taxonomy terms to matching text in posts and has AI integrations for term suggestions. But it's keyword-matching against terms you already have, not entity extraction from new content. It has no newsroom-specific workflow.

Neither tool has an org-specific definition list, a whitelist-first operating model, a moderator queue tuned for editorial trust, or duplicate-name disambiguation. The gap is real.

## Two-stage processing architecture: NER + LLM

The plugin uses a two-stage pipeline rather than sending raw post content directly to the LLM.

**Stage 1 — NER model (spaCy or similar).** A lightweight local NER model does the brute-force detection pass. It reads the post and returns a raw candidate list: "Biden — PERSON, Georgia — GPE, Apple — ORG." This runs in milliseconds and costs nothing per call. The org definition list can be loaded into spaCy as a custom entity ruler, teaching the local model about known local entities before it reads any post.

**Stage 2 — LLM.** The LLM receives the NER candidate list plus the full article text plus the org definition list. Its job is not detection — it's judgment. It validates candidates, resolves ambiguity (is "Georgia" the state or the country?), flags entities the NER model missed using article context, scores confidence, and returns the final entity set.

The LLM has full authority over the final output. The NER model just does the first pass. This reduces token usage significantly — the LLM is processing a structured candidate list rather than scanning every word in a 1,200-word article for entity candidates.

**One key design decision:** the LLM prompt should explicitly allow it to both add entities the NER model missed and reject candidates the NER model got wrong. If spaCy tags "Christmas" as a proper noun in a holiday story, the LLM should be able to drop it. The NER list is a starting point, not a floor.

The article context is fully preserved in this model. The LLM still reads the whole post. It just isn't doing the detection work from scratch.

## What still needs design

- **LLM operations.** Which model, where it runs, cost at scale (50 stories a day is a lot of API calls), latency on save, and failure handling. If the API is down, does the post save fail, queue silently, or publish without linking? Action Scheduler is the likely pattern — async job queue, not synchronous calls on save.
- **Taxonomy term pages.** The archive page a term links to needs to be useful. Out of the box, WordPress term archives are just post lists. Whether the plugin helps scaffold those pages is an open question.
- **Entity extraction depth.** News writing is dense with names. A single article might mention 15 people. The LLM needs to distinguish between primary subjects and sources versus passing or historical references. That's a harder prompt engineering problem than simple entity extraction.
- **Term lifecycle.** People die. Companies get acquired. Politicians change roles. Terms don't update themselves. The moderator dashboard probably needs a term management layer, not just a review queue for new entries.
- **Re-index at scale.** Parallel processing of a large archive creates race conditions around term creation — two posts processed simultaneously might both try to create the same term. A locking or deduplication strategy is required.
- **The org definition list data structure.** The quality of this structure determines how useful the entire plugin is. It needs its own design session.
- **Multilingual content.** Explicitly in or out of scope — decide early.
- **Per-role trust levels.** Confidence thresholds and moderation requirements might need to differ by WordPress user role.
- **Plugin schema migrations.** Custom DB tables for the definition list, term metadata, and moderator queue need graceful migration handling on plugin updates.