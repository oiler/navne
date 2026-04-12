# Pressure Test Notes

**LLM operational concerns**

Which LLM, and where does it run? A news org publishing 50 stories a day is making a lot of API calls. Latency on post save, cost at scale, and what happens when the API is down or rate-limited — does the post save fail, queue silently, or publish without linking? You need a clear failure mode. A job queue (like Action Scheduler, which is already in the WP ecosystem) is probably the right pattern here rather than synchronous LLM calls on save.

**The linking target problem**

When you link "Governor Brian Kemp" to its taxonomy term page, that page needs to actually exist and have meaningful content. Out of the box, WordPress taxonomy archive pages are just post lists. For a news org this might be fine — but it's worth asking if the plugin should also help scaffold what those term pages look like, or if that's out of scope.

**Entity extraction quality on news content**

News writing is dense with names. A single article might mention 15 people. Do all 15 get terms? Does a passing reference to a historical figure ("echoing Lincoln's approach") generate a term? You probably want the LLM to distinguish between subjects/sources of a story vs. incidental mentions — but that's a harder prompt engineering problem than simple entity extraction.

**Taxonomy term lifecycle**

What happens when a person dies, a company is acquired, or a politician changes roles? Terms don't update themselves. Does the org knowledge base handle this, or does the moderator dashboard need a term management layer beyond just reviewing new ones?

**Multilingual content**

If the news org publishes in more than one language, entity extraction and linking gets complicated fast. Worth deciding if that's in scope or explicitly out of scope.

**Author and editorial trust levels**

Should a senior editor's post auto-publish linked content while a junior contributor's goes to the moderator queue first? Confidence thresholds might need to be per-user-role, not just per-post.

**The re-index order of operations**

On a large archive, re-indexing sequentially is slow. But parallel processing creates race conditions around term creation — two posts processed simultaneously might both try to create the "Jane Smith" term. You'll need a locking or deduplication strategy there.

**Plugin updates and schema migrations**

The org knowledge base, term metadata, moderator queue — these all need custom DB tables or post meta structures. When the plugin updates and that schema changes, migrations need to be handled gracefully. News orgs don't love surprise downtime.

**Legal and editorial liability**

This one's softer but real for news orgs specifically. If the plugin incorrectly links "John Smith" in a crime story to the wrong John Smith's taxonomy page, that's a potential defamation issue. The human moderator workflow isn't just a nice-to-have — it might need to be positioned as a safeguard against exactly this kind of error.

That last point might actually shape how you market and document the plugin more than anything else.

Which of these feels most unresolved to you?