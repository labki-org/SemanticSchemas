# Phase 4: Conditional Templates - Context

**Gathered:** 2026-02-02
**Status:** Ready for planning

<domain>
## Phase Boundary

Wrap semantic template `#set` calls in `#if` conditions to prevent empty value overwrites in multi-category pages. Switch multi-value properties to `+sep` parameter. Changes are to TemplateGenerator output — the generated wikitext pattern, not the generator architecture.

</domain>

<decisions>
## Implementation Decisions

### Conditional wrapping pattern
- Claude's discretion on readability vs compactness of generated wikitext
- Claude's discretion on per-property vs per-template granularity of #if guards
- Claude's discretion on check mechanism (parameter test vs other)
- Claude's discretion on whether to include comments in generated templates

### Multi-value separator handling
- Claude's discretion on +sep implementation — research current separator approach in codebase first
- Claude's discretion on append vs last-wins for multi-source multi-value properties
- Claude's discretion on whether +sep applies to all multi-value properties or only shared ones
- Claude's discretion on separator character choice

### Backward compatibility
- No special migration needed — existing regeneration path handles the change
- Old-style templates (without #if guards) should be flagged as dirty by StateManager (natural consequence of hash change from new generated content)
- Always apply conditional #if pattern to ALL generated semantic templates, not just multi-category ones — consistent, future-proof
- Warn before overwriting templates that were manually modified (hash mismatch)

### Empty value semantics
- Claude's discretion on whitespace handling in empty checks
- Guard all properties equally — required properties skip #set if empty, same as optional. Form validation enforces required fields, not the template layer
- Claude's discretion on boolean/checkbox handling (how to distinguish unchecked from false)
- Conflicting non-empty values from manual page edits: researcher should investigate how SMW handles conflicting #set calls on same property; planner decides handling

### Claude's Discretion
- Conditional wrapping syntax and nesting style (readability, granularity, check mechanism, comments)
- Multi-value separator implementation details (+sep scope, separator character, append behavior)
- Whitespace treatment in empty checks
- Boolean property handling in conditional guards

</decisions>

<specifics>
## Specific Ideas

- User noted that conflicting non-empty values "shouldn't be possible through our forms" since shared properties appear once in first template section (Phase 6 design). Manual page edits could theoretically cause conflicts — edge case worth understanding but not a primary concern for this phase.
- Regeneration is the migration path — no special migration scripts needed. When users regenerate templates, the new conditional pattern takes effect.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 04-conditional-templates*
*Context gathered: 2026-02-02*
