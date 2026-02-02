# Project Research Summary

**Project:** SemanticSchemas v0.2.0 - Multi-Category Page Creation
**Domain:** MediaWiki extension for schema-driven wiki ontology management
**Researched:** 2026-02-02
**Confidence:** MEDIUM-HIGH

## Executive Summary

Multi-category page creation enables wiki pages that simultaneously belong to multiple categories (e.g., a page that is both "Person" and "Employee"), a fundamental requirement for ontology management where instances frequently have multiple types. The research confirms this feature is achievable using the existing MediaWiki/Semantic MediaWiki/PageForms stack without new dependencies—PageForms natively supports multiple templates per form, and SMW's conditional `#set` pattern prevents property value overwrites.

The critical architectural challenge is property deduplication across categories. When "Person" and "Employee" both define "Name," the composite form must show the field only once while ensuring both semantic templates receive the value. The solution leverages SemanticSchemas' existing C3 linearization infrastructure (InheritanceResolver) to determine property precedence and extends the three-template system (dispatcher/semantic/display) with conditional `{{#if:{{{param|}}}|{{#set:...}}|}}` to prevent empty value overwrites. This approach maintains backward compatibility—existing single-category functionality remains unchanged.

The main risks are: (1) Property collision causing data loss if conditional `#set` isn't implemented correctly, (2) StateManager's page-level dirty detection causing false positives in multi-category scenarios requiring refactoring to template-level hashing, and (3) PageForms' one-category-per-page philosophy creating UX complexity for form selection. Mitigation strategies are well-defined: mandatory conditional `#set` for shared properties, template-level hash tracking, and explicit primary category designation for form association.

## Key Findings

### Recommended Stack

**No new external dependencies required.** The existing stack (MediaWiki 1.39+, PHP 8.1+, Semantic MediaWiki, PageForms, ParserFunctions) provides all necessary capabilities. Key findings confirm:

- PageForms natively supports multiple `{{{for template}}}` blocks in a single form (verified in official documentation)
- SMW conditional `#set` works with ParserFunctions `#if` to prevent property overwrites (documented pattern)
- Tree checkbox UI can be custom-built extending existing hierarchy visualization code
- Action API endpoints follow existing ApiBase pattern already used in the codebase

**Core technologies (no changes):**
- **MediaWiki/SMW/PageForms**: Already in use — provides composite form generation and semantic storage
- **ParserFunctions (#if)**: Core extension — enables conditional property assignment
- **jQuery/OOUI**: Core libraries — extend existing hierarchy tree with checkboxes
- **ResourceLoader modules**: Standard pattern — register new JavaScript UI module

**Integration patterns verified:**
- **Multiple template syntax**: `{{{for template|Cat1}}}...{{{end template}}}{{{for template|Cat2}}}...{{{end template}}}`
- **Conditional #set**: `{{#if:{{{PropertyName|}}}|{{#set:PropertyName={{{PropertyName|}}}}}|}}`
- **API endpoint**: Follow ApiSemanticSchemasHierarchy pattern for multi-category resolution
- **Special page tabs**: Extend SpecialSemanticSchemas with new "multicategory" tab

**CRITICAL LIMITATION IDENTIFIED:** ParserFunctions' `#if` automatically trims whitespace from branches, which can break multi-value property separators. Use `+sep` parameter in `#set` for multi-value properties instead of manual separators.

### Expected Features

**Must have (table stakes):**
- **Category multi-select UI** — Standard pattern for selecting multiple categories (checkboxes or dropdown)
- **Property deduplication** — When property appears in multiple categories, show field once in form
- **All categories assigned on save** — Page must be member of all selected categories via `[[Category:X]]` wikilinks
- **Form preview before creation** — Users see what fields will appear (PageForms built-in)
- **Required field validation** — Prevent submission with missing fields (PageForms `mandatory=true`)
- **Property ordering preservation** — Fields appear in predictable order using C3 linearization

**Should have (differentiators):**
- **Automatic precedence via C3** — Leverage existing InheritanceResolver for deterministic property ordering
- **Visual category hierarchy preview** — Extend existing hierarchy visualization to show selected categories
- **Property source attribution** — Show which category each property comes from (e.g., "Name (from Person)")
- **Template section collapse/expand** — Accordion UI for large multi-category forms

**Defer (v2+):**
- **Smart property type conflict detection** — Warn if same property has different datatypes across categories
- **Conditional property visibility** — Show/hide fields based on category selection (requires complex JS)
- **Dry-run validation** — Check all constraints before page creation (complex validation engine)
- **Bulk category assignment** — Create multiple pages with same categories (different feature scope)

**Anti-features (explicitly avoid):**
- **Duplicate property entries** — Enforce deduplication, show property once
- **Allow conflicting property types** — Detect conflicts during form generation, refuse to generate
- **Merge schemas into single template** — Use multiple `{{{for template}}}` blocks to maintain category identity
- **Auto-select all parent categories** — Let user explicitly select; show parents for context only

### Architecture Approach

Multi-category page creation integrates with SemanticSchemas' existing three-template system (dispatcher/semantic/display), C3 linearization infrastructure, and tabbed Special page UI. The architecture uses **composition over modification**: new services and generators wrap existing components rather than modifying them.

**Major components:**

1. **MultiCategoryResolver (NEW)** — Service layer component that resolves properties across multiple unrelated categories. Delegates to existing InheritanceResolver for per-category effective resolution, then performs cross-category union/intersection logic. Returns property resolution data structure identifying shared vs category-specific properties.

2. **CompositeFormGenerator (NEW)** — Separate generator class (not extending FormGenerator) that produces PageForms markup with multiple `{{{for template}}}` blocks. Composes existing FormGenerator for individual template sections. Handles property deduplication by tracking which properties already added.

3. **TemplateGenerator Enhancement (MODIFIED)** — Add new method `generateConditionalSemanticTemplate()` to existing class. Wraps `{{#set:...}}` in `{{#if:{{{param|}}}|...store...|}}` pattern for shared properties. Prevents empty value overwrites when multiple templates set same property.

4. **ApiSemanticSchemasMultiCategory (NEW)** — Action API endpoint following exact pattern of existing ApiSemanticSchemasHierarchy. Provides multi-category property resolution data for JavaScript UI. Endpoint: `api.php?action=semanticschemas-multicategory&categories=Cat1|Cat2`.

5. **Special Page Tab Extension (MODIFIED)** — Add "multicategory" tab to SpecialSemanticSchemas following established tab pattern. Renders category selection UI, handles form generation requests, displays generated composite forms.

6. **JavaScript UI Component (NEW)** — ResourceLoader module extending existing hierarchy visualization code. Adds checkboxes to category tree, calls API for property preview, triggers composite form generation.

**Key architectural decisions:**
- **Conditional #set mechanism**: Uses MediaWiki's `#if` parser function to check parameter presence before storing. Pattern: `{{#if:{{{Shared|}}}|{{#set:Shared={{{Shared|}}}}}|}}`. Empty else branch prevents assignment when parameter empty, allowing previous template's value to persist.
- **Separation of concerns**: MultiCategoryResolver is service (testable business logic), not embedded in generators or UI.
- **Backward compatibility**: InheritanceResolver unchanged, FormGenerator unchanged (composition not modification), existing single-category functionality preserved.
- **Performance consideration**: Async API endpoint prevents blocking page load; recommend limiting maximum categories per page (suggest 5).

**Data flow changes:**
Current (single category): User creates page → PageForms displays Form:Category → User fills → Save calls one template → One semantic template stores via `#set`

New (multi-category): User visits Special:SemanticSchemas/multicategory → Selects categories → JavaScript calls API → API returns property resolution → Generate composite form → Redirects to Special:FormEdit → User fills form → PageForms saves with multiple template calls → Each semantic template uses conditional `#set` → SMW indexes all properties

### Critical Pitfalls

1. **Property Collision Without Conditional #set** — When multiple categories share a property name and both semantic templates unconditionally execute `{{#set:Property={{{Property|}}}}}`, the later template overwrites the earlier one. PageForms only passes the property value to ONE template (where the field appears), so the second template receives empty value and overwrites valid data with empty string. **PREVENTION:** Mandatory conditional `#set` pattern for ALL shared properties: `{{#if:{{{Property|}}}|{{#set:Property={{{Property|}}}}}|}}`. Apply during template generation in Phase 2.

2. **StateManager Hash Conflicts with Multi-Template Pages** — StateManager tracks page content hash for dirty detection. When page includes multiple category templates, changing ONE category's schema triggers false-positive "external modification" warnings for ALL categories on that page. Page hash changes, triggers dirty flag for unrelated categories. **PREVENTION:** Refactor to template-level hashing instead of page-level. Track hash of each template independently: `templateHashes['Template:Person/semantic'] = hash(template content)`. Only flag dirty if template was modified outside SemanticSchemas. Critical for Phase 4 (State Tracking).

3. **PageForms One-Category-Per-Page Philosophy** — PageForms architecturally designed for one category per page. `#default_form` only works for ONE category, "Edit with form" tab detection assumes single category, multiple `[[Category:X]]` tags break form detection. **PREVENTION:** Choose ONE primary category for form association. Only primary category gets `#default_form` and `[[Category:X]]` tag. Secondary categories stored as properties. Alternative: Remove `#default_form` entirely, build explicit form selection UI. Decide strategy in Phase 1 (Architecture).

4. **ParserFunctions #if Whitespace Trimming** — MediaWiki's ParserFunctions automatically trim spaces/newlines from `#if` branches. This breaks multi-value property separators (commas, semicolons) and display formatting. **PREVENTION:** Use SMW's `+sep` parameter for multi-value properties: `{{#set:Property={{{Param|}}}|+sep=,}}`. Avoid `#if` in display templates where formatting matters. Document in Phase 2 comments.

5. **Performance Degradation with Multiple Template Calls** — PageForms performs poorly with many template invocations. Users report "blank page" with 300+ templates, timeout errors, 20+ second page loads. Multi-category pages multiply template calls (2 categories = 6 templates in three-template system). **PREVENTION:** Set maximum limits (5 categories per page, 50 subobjects). Implement UI warnings when approaching limits. Tune PHP configuration (increase `max_execution_time`, `memory_limit`). Test extensively in Phase 7.

## Implications for Roadmap

Based on combined research, the feature requires careful phase ordering to manage complexity and dependency chains. The architecture suggests building from foundation (services) to UI (special page), testing at each layer.

### Phase 1: Foundation - Multi-Category Property Resolution

**Rationale:** Core business logic with no UI dependency enables isolated unit testing before building generators or UI.

**Delivers:**
- MultiCategoryResolver service (`src/Service/MultiCategoryResolver.php`)
- Property resolution algorithm (union/intersection across categories)
- Conflict detection (same property, different datatypes)
- Ancestor chain aggregation across multiple category hierarchies
- Unit test suite for resolution logic

**Addresses:**
- Property deduplication (table stakes from FEATURES.md)
- Automatic precedence via C3 (differentiator from FEATURES.md)

**Avoids:**
- Property collision pitfall by identifying shared properties early
- Multiple inheritance conflicts via validation

**Dependencies:** InheritanceResolver (exists), WikiCategoryStore (exists)

**Research flag:** SKIP — C3 linearization is established pattern, existing InheritanceResolver provides foundation

---

### Phase 2: Template Generation - Conditional Semantic Templates

**Rationale:** Must implement conditional `#set` before any forms are generated to prevent data loss. Depends on Phase 1's property resolution to know which properties are shared.

**Delivers:**
- TemplateGenerator enhancement with `generateConditionalSemanticTemplate()` method
- Conditional `#set` pattern: `{{#if:{{{param|}}}|{{#set:...}}|}}`
- Template generation for multi-category scenarios
- Integration tests for conditional template logic

**Uses:**
- ParserFunctions `#if` (from STACK.md)
- SMW `#set` with conditional wrapper (verified pattern)

**Implements:**
- TemplateGenerator enhancement (architecture component from ARCHITECTURE.md)

**Avoids:**
- Property collision pitfall (CRITICAL - Pitfall 1)
- ParserFunctions whitespace trimming issues (Pitfall 4)

**Dependencies:** MultiCategoryResolver (Phase 1)

**Research flag:** SKIP — `#if` + `#set` pattern verified in official MediaWiki/SMW documentation

---

### Phase 3: Composite Form Generation

**Rationale:** Generate PageForms markup with multiple `{{{for template}}}` blocks. Depends on Phase 2's conditional templates being available.

**Delivers:**
- CompositeFormGenerator class (`src/Generator/CompositeFormGenerator.php`)
- Multi-template form markup generation
- Property deduplication in form fields
- Section organization (shared vs category-specific)
- Integration tests for form structure

**Uses:**
- PageForms multiple template syntax (verified in STACK.md)
- Existing FormGenerator via composition (architecture pattern)

**Implements:**
- CompositeFormGenerator (architecture component from ARCHITECTURE.md)

**Addresses:**
- Property deduplication (table stakes)
- Form preview (table stakes - PageForms built-in)
- Property ordering preservation (table stakes)

**Avoids:**
- Multiple template blocks confusion via clear section labels
- Property source attribution as differentiator

**Dependencies:** MultiCategoryResolver (Phase 1), TemplateGenerator enhancements (Phase 2)

**Research flag:** SKIP — PageForms composite form pattern is standard, well-documented

---

### Phase 4: API Layer - Multi-Category Resolution Endpoint

**Rationale:** Expose resolution logic via API for JavaScript UI consumption. Enables async property preview without page refresh.

**Delivers:**
- ApiSemanticSchemasMultiCategory module (`src/Api/ApiSemanticSchemasMultiCategory.php`)
- API endpoint: `api.php?action=semanticschemas-multicategory&categories=Cat1|Cat2`
- JSON response with shared/specific properties and conflicts
- API registration in extension.json

**Uses:**
- MediaWiki Action API pattern (from STACK.md)
- Follows existing ApiSemanticSchemasHierarchy pattern

**Implements:**
- API endpoint (architecture component from ARCHITECTURE.md)

**Addresses:**
- Visual category hierarchy preview (differentiator)
- Live property resolution for UI

**Dependencies:** MultiCategoryResolver (Phase 1)

**Research flag:** SKIP — Action API pattern already established in codebase

---

### Phase 5: Special Page Tab - Multi-Category UI

**Rationale:** User-facing UI requires all backend components (resolver, generators, API) to be in place.

**Delivers:**
- "multicategory" tab in SpecialSemanticSchemas
- `showMultiCategory()` method following existing tab pattern
- Category selection interface
- Form generation trigger
- Redirect to Special:FormEdit with generated composite form

**Uses:**
- OOUI widgets (already in use, from STACK.md)
- Existing tab pattern in SpecialSemanticSchemas

**Implements:**
- Special page tab extension (architecture component)

**Addresses:**
- Category multi-select UI (table stakes)
- All categories assigned on save (table stakes)
- Primary category designation (pitfall prevention)

**Avoids:**
- PageForms one-category-per-page philosophy conflict (Pitfall 3)
- FormEdit URL complexity (Pitfall 6)

**Dependencies:** CompositeFormGenerator (Phase 3), API endpoint (Phase 4)

**Research flag:** SKIP — Special page tab pattern established, OOUI widgets already in use

---

### Phase 6: JavaScript UI - Tree with Checkboxes

**Rationale:** Enhance UX with interactive category selection and live property preview. Non-blocking—backend works without JavaScript enhancement.

**Delivers:**
- ResourceLoader module (`ext.semanticschemas.multicategory`)
- JavaScript extending existing hierarchy tree renderer
- Checkboxes for category selection
- AJAX API integration for property preview
- Conflict warnings display

**Uses:**
- jQuery (core), mediawiki.api (from STACK.md)
- Extend existing `ext.semanticschemas.hierarchy.js`

**Implements:**
- JavaScript UI component (architecture component)

**Addresses:**
- Visual category hierarchy preview (differentiator)
- Property source attribution display (differentiator)

**Dependencies:** API endpoint (Phase 4), Special page tab (Phase 5)

**Research flag:** SKIP — Extends existing hierarchy visualization, straightforward checkbox addition

---

### Phase 7: State Tracking - Template-Level Hashing

**Rationale:** Fix StateManager dirty detection for multi-category scenarios. CRITICAL for reliable schema management. Can be developed in parallel with UI phases.

**Delivers:**
- StateManager refactor for template-level hashing
- Track template content hashes instead of page content hashes
- Updated dirty detection logic for multi-template pages
- State JSON structure migration (if needed)

**Implements:**
- StateManager architecture change (addresses Pitfall 5)

**Avoids:**
- StateManager hash conflicts (CRITICAL - Pitfall 2)
- Dirty flag cascades (Pitfall 15)

**Dependencies:** Understanding of multi-category template structure (Phase 2)

**Research flag:** MODERATE — Architectural change to existing StateManager requires careful testing, but pattern is clear

---

### Phase 8: Testing & Polish

**Rationale:** End-to-end testing across all category combinations, edge cases, performance validation.

**Delivers:**
- Comprehensive test suite for multi-category workflows
- Performance testing (limits, timeout scenarios)
- Property collision tests
- Form preview/submission tests
- Documentation (user guide, developer docs)

**Addresses:**
- Required field validation (table stakes - verify PageForms integration)
- Performance limits (Pitfall 8)
- Edge cases (empty category list, non-existent categories, type conflicts)

**Avoids:**
- Multiple-instance template duplication (Pitfall 9)
- Property parameter name collisions (Pitfall 10)

**Dependencies:** All previous phases

**Research flag:** SKIP — Testing phase, no new patterns to research

---

### Phase Ordering Rationale

**Dependency-driven order:**
- Phase 1 (Resolver) has no dependencies → build first
- Phase 2 (Templates) depends on Phase 1 → build second
- Phase 3 (Forms) depends on Phase 2 → build third
- Phase 4 (API) depends on Phase 1 only → can parallelize with Phase 2-3
- Phase 5 (UI) depends on Phase 3-4 → build after backend complete
- Phase 6 (JS) depends on Phase 4-5 → build after UI complete
- Phase 7 (State) can parallelize with Phase 5-6 → independent concern
- Phase 8 (Testing) depends on all → build last

**Risk mitigation order:**
- Critical pitfalls addressed early: Conditional #set in Phase 2, StateManager in Phase 7
- Foundation tested before UI: Resolver and generators have unit tests before Special page
- Progressive complexity: Service layer → Generation → API → UI → Polish

**Architectural consistency:**
- Follows existing patterns: Service/Generator/API/Special page separation
- Composition over modification: New classes wrap existing ones
- Backward compatibility: No changes to InheritanceResolver or core FormGenerator

### Research Flags

**Phases likely needing deeper research during planning:**
- **Phase 7 (State Tracking):** StateManager refactor affects all import/export operations. Need careful analysis of state JSON structure, migration strategy, and dirty detection edge cases. MODERATE complexity.

**Phases with standard patterns (skip research-phase):**
- **Phase 1 (Resolver):** C3 linearization established pattern, existing InheritanceResolver provides template
- **Phase 2 (Templates):** Conditional `#set` pattern verified in official SMW docs
- **Phase 3 (Forms):** PageForms composite forms documented in official PageForms docs
- **Phase 4 (API):** Action API pattern already implemented in codebase (ApiSemanticSchemasHierarchy)
- **Phase 5 (UI):** Special page tab pattern established, no new patterns
- **Phase 6 (JS):** Extends existing hierarchy visualization, straightforward enhancement
- **Phase 8 (Testing):** Testing phase, no domain research needed

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | All capabilities verified in existing stack. PageForms multiple template syntax confirmed in official docs. SMW conditional `#set` pattern documented. No new dependencies required. |
| Features | MEDIUM | Feature requirements inferred from ontology management best practices and PageForms patterns. WebSearch found official docs but full PageForms documentation not fully accessible. Property deduplication algorithm designed but not yet implemented/tested. |
| Architecture | HIGH | Direct codebase analysis of existing SemanticSchemas components (InheritanceResolver, TemplateGenerator, FormGenerator, StateManager). Integration points identified with certainty. Composition patterns follow established codebase conventions. |
| Pitfalls | MEDIUM-HIGH | Critical pitfalls (1-5) verified through official MediaWiki/SMW/PageForms documentation and codebase analysis. Moderate pitfalls (6-9) based on community reports and PageForms common problems docs. StateManager-specific pitfalls (13-15) derived from direct code analysis (HIGH confidence). |

**Overall confidence:** MEDIUM-HIGH

Research is sufficient for roadmap creation and phase planning. Stack and architecture findings have HIGH confidence based on official documentation and codebase analysis. Feature requirements and some pitfalls have MEDIUM confidence based on WebSearch findings and domain pattern inference.

### Gaps to Address

**During Phase 1 (Design decisions needed):**
- **Primary vs secondary category strategy:** Decide whether to designate one category as "primary" for `#default_form` association, or remove `#default_form` entirely and build explicit form selection UI. Recommendation: Start with primary category approach (simpler), evaluate post-MVP.
- **Composite form naming convention:** Determine naming for generated forms (e.g., `Form:Person+Employee` with alphabetical order, `Form:Composite/Person_Employee`, or hash-based). Impacts form discovery and collision avoidance.

**During Phase 2 (Implementation validation):**
- **Conditional #set edge cases:** Test exhaustively with empty vs undefined parameters. Verify `{{{param|}}}` with trailing pipe defaults correctly. Test multi-value properties with `+sep` parameter instead of manual separators.

**During Phase 7 (Architectural refactor):**
- **State JSON migration strategy:** If changing from page-level to template-level hashing requires state JSON structure change, need migration path for existing installations. Consider backward compatibility or version flag in state file.

**During Phase 8 (Performance testing):**
- **Maximum limits determination:** Research suggests limiting to 5 categories per page, 50 subobjects. Actual limits need performance testing on target environment. May vary based on PHP configuration and MediaWiki setup.

**Post-MVP (Future enhancements):**
- **Property type conflict detection:** Algorithm for detecting same property with different datatypes across categories designed but not implemented. Defer to post-MVP unless conflicts discovered during testing.
- **Subobject handling in multi-category pages:** If Person has "Publications" subobject and Author has "Publications" subobject, deduplication strategy unclear. Likely needs phase-specific research if subobjects become critical.

## Sources

### Primary (HIGH confidence)

**Official MediaWiki/PageForms/SMW Documentation:**
- [Extension:Page Forms/Page Forms and templates](https://www.mediawiki.org/wiki/Extension:Page_Forms/Page_Forms_and_templates) — Multiple template syntax verified
- [Extension:Page Forms/Defining forms](https://www.mediawiki.org/wiki/Extension:Page_Forms/Defining_forms) — Composite form patterns
- [Help:Extension:ParserFunctions](https://www.mediawiki.org/wiki/Help:Extension:ParserFunctions) — `#if` syntax and whitespace behavior
- [SMW Help:Parser functions and conditional values](https://www.semantic-mediawiki.org/wiki/Help:Parser_functions_and_conditional_values) — Conditional `#set` pattern
- [SMW Help:Setting values](https://www.semantic-mediawiki.org/wiki/Help:Setting_values) — `#set` semantics and template parameter
- [Help:Parser functions in templates](https://www.mediawiki.org/wiki/Help:Parser_functions_in_templates) — Parameter evaluation order
- [API:Extensions](https://www.mediawiki.org/wiki/API:Extensions) — Action API module pattern
- [ResourceLoader/Core modules](https://www.mediawiki.org/wiki/ResourceLoader/Core_modules) — Available JS libraries

**Codebase Analysis:**
- `src/Schema/InheritanceResolver.php` — C3 linearization implementation
- `src/Generator/TemplateGenerator.php` — Semantic template generation patterns
- `src/Generator/FormGenerator.php` — PageForms markup generation
- `src/Store/StateManager.php` — Hash-based dirty detection
- `src/Special/SpecialSemanticSchemas.php` — Tab pattern and OOUI usage
- `src/Api/ApiSemanticSchemasHierarchy.php` — Existing API endpoint pattern

### Secondary (MEDIUM confidence)

**Algorithm & Architecture Patterns:**
- [C3 linearization - Wikipedia](https://en.m.wikipedia.org/wiki/C3_linearization) — Multiple inheritance resolution
- [Understanding C3 Linearization](https://medium.com/@mehmetyaman/understanding-c3-linearization-the-algorithm-behind-python-and-soliditys-method-resolution-order-f386b9a10696) — Algorithm explanation
- [Empowering OWL with Overriding Inheritance](https://www.researchgate.net/publication/221250984_Empowering_OWL_with_Overriding_Inheritance_Conflict_Resolution_and_Non-monotonic_Reasoning) — Ontology inheritance patterns

**PageForms Community & Common Problems:**
- [Extension:Page Forms/Common problems](https://www.mediawiki.org/wiki/Extension:Page_Forms/Common_problems) — Performance issues with many templates, multiple-instance duplication bug
- [Extension:Page Forms/Input types](https://www.mediawiki.org/wiki/Extension:Page_Forms/Input_types) — Tree input type and state persistence
- PageForms version history discussions — jstree adoption (2020), FancyTree deprecation

**UX Best Practices:**
- [Form Validation Best Practices - Clearout](https://clearout.io/blog/form-validation/) — Client-side validation patterns
- [Review Before Submit - FormAssembly](https://help.formassembly.com/help/preview-before-submit) — Form preview patterns
- [Categorization Design Pattern](https://ui-patterns.com/patterns/categorization) — Multi-select UI patterns

### Tertiary (LOW confidence - needs validation)

**WebSearch Findings:**
- Property deduplication algorithm inferred from PageForms patterns and SemanticSchemas architecture (not explicitly documented)
- Form preview behavior assumed based on PageForms feature mentions (unable to verify full functionality)
- Performance limits (300+ templates, 30 second timeout) from community reports (not official benchmarks)

---

**Research completed:** 2026-02-02
**Ready for roadmap:** Yes

**Next steps:** Use this summary as context for roadmap creation. Phase structure (1-8) provides starting point for milestone decomposition. Research flags indicate Phase 7 (State Tracking) may benefit from deeper analysis during planning. All other phases use established patterns and can proceed directly to requirements definition.
