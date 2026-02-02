# Phase 7: API Endpoint - Context

**Gathered:** 2026-02-02
**Status:** Ready for planning

<domain>
## Phase Boundary

API endpoint providing multi-category property resolution data for UI preview. Accepts multiple category names, delegates to MultiCategoryResolver, and returns resolved property/subobject data as JSON. Phase 8 (Create Page UI) consumes this endpoint for live category selection preview.

</domain>

<decisions>
## Implementation Decisions

### Response shape
- Include both properties and subobjects in the response (user decision — full picture for form preview)
- Structure, grouping, and per-property metadata: Claude's discretion based on Phase 8 UI needs
- Whether to include category metadata (parent chain, descriptions): Claude's discretion

### Error & conflict reporting
- Invalid category names fail the entire request (no partial resolution)
- Datatype conflicts are structurally impossible — properties are wiki-global entities (Phase 5 decision), no conflict section needed
- Allow single-category requests (minimum 1 category, not 2) — useful for preview consistency
- Require edit permission to call the API

### Input design
- Categories passed as pipe-separated values (`categories=Person|Employee`) — standard MediaWiki multi-value convention
- Accept category names both with and without `Category:` namespace prefix — API normalizes internally
- API action name: Claude's discretion, consistent with existing extension conventions
- Inherited properties flag: Claude's discretion based on UI needs

### Caching & performance
- Always return fresh data — no caching (schemas can change)
- Reasonable category limit per request (e.g., 10-20) to prevent abuse
- Phase 8 UI will use debounced toggle (300-500ms) — API should expect rapid sequential calls during category selection
- Ontology is shallow (2-4 levels of inheritance) — performance is not a concern

### Claude's Discretion
- Response JSON structure (grouped vs flat, metadata per property)
- API action name (semanticschemas-multicategory vs alternative)
- Whether to support a `noinherit` flag
- Category metadata inclusion in response
- Exact category count limit

</decisions>

<specifics>
## Specific Ideas

No specific requirements — open to standard approaches. Should follow existing `semanticschemas-hierarchy` API patterns in the extension.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 07-api-endpoint*
*Context gathered: 2026-02-02*
