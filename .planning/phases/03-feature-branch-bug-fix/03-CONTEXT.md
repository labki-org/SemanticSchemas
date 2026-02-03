# Phase 3: Feature Branch + Bug Fix - Context

**Gathered:** 2026-02-02
**Status:** Ready for planning

<domain>
## Phase Boundary

Create feature branch `multi-category-page-creation` and resolve required/optional property conflicts across category inheritance. When a property or subobject appears as both required and optional (directly or through inheritance), promote to required without errors. SchemaValidator warns on conflicts. Existing schemas without conflicts continue working identically.

</domain>

<decisions>
## Implementation Decisions

### Conflict promotion rules
- Always promote to required: if any ancestor says required, it's required everywhere
- Parent required always wins — child categories cannot weaken a parent's required to optional
- Resolve at schema load time in InheritanceResolver — downstream code never sees the conflict
- Silent promotion — no warnings emitted, even in diamond inheritance scenarios

### Validation messaging
- SchemaValidator warns (not errors) when property appears in both required and optional
- Warning format and aggregation: Claude's discretion based on existing validator output patterns
- Warnings visible both on Special:SemanticSchemas page during import AND in validator return data for programmatic consumers
- UI placement of warnings: Claude's discretion based on existing Special page patterns

### Subobject conflict handling
- Same promotion rules as properties — required wins, resolved at load time, no special cases
- Subobjects are shared references (pages in Subobject namespace), not inline definitions — no structural conflicts possible, only required/optional disagreement
- Warnings for subobject conflicts state the result only (no source attribution naming both categories)

### Backward compatibility / Schema cleanup
- No backward compatibility concerns — full refresh of all semantic schemas will be performed
- Auto-clean schema files during import: remove property from optional list when it's already in required
- Silent cleanup for both direct conflicts (same schema) and inherited conflicts (combining parents)
- No messages, no warnings for cleanup — models and schemas are just fixed

### Claude's Discretion
- Warning format and aggregation style (match existing validator patterns)
- Warning UI placement on Special page
- Exact cleanup implementation in the import pipeline

</decisions>

<specifics>
## Specific Ideas

- Current code crashes on required/optional conflicts — this is a crash fix, not a behavior change
- Subobjects in SemanticSchemas are pages in a Subobject namespace, not inline per-category definitions — two categories referencing the same subobject name point to the same page

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 03-feature-branch-bug-fix*
*Context gathered: 2026-02-02*
