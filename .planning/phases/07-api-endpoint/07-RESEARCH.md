# Phase 7: API Endpoint - Research

**Researched:** 2026-02-02
**Domain:** MediaWiki Action API development (ApiBase)
**Confidence:** HIGH

## Summary

Phase 7 requires a MediaWiki Action API endpoint that accepts multiple category names and returns resolved property/subobject data via MultiCategoryResolver. The extension already has two working ApiBase implementations (semanticschemas-hierarchy, semanticschemas-install) that establish clear patterns for structure, registration, parameter validation, and error handling.

MediaWiki's Action API framework provides robust parameter validation via PARAM_* constants, multi-value parameter support with pipe separators, permission checking via checkUserRightsAny(), and standardized error reporting through dieWithError(). PHP 8.1+ is required per composer.json, and MediaWiki >= 1.39 per extension.json.

**Key findings:**
- Existing ApiSemanticSchemasHierarchy provides direct pattern for single-category API with virtual mode support
- MultiCategoryResolver is production-ready (Phase 5) and provides ResolvedPropertySet with all needed data
- ResolvedPropertySet includes source attribution via getPropertySources() / getSubobjectSources() maps
- JavaScript client (ext.semanticschemas.hierarchy.formpreview.js) already consumes pipe-separated multi-value parameters
- No CSRF token needed - read-only API returning resolution data
- Permission check pattern: checkUserRightsAny('edit') for consistency with semanticschemas-install

**Primary recommendation:** Create ApiSemanticSchemasMultiCategory following ApiSemanticSchemasHierarchy pattern, delegating to MultiCategoryResolver, returning flat property/subobject arrays with source attribution metadata per item.

## Standard Stack

The established libraries/tools for MediaWiki Action API development in this extension:

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| MediaWiki Core | >= 1.39 | Action API framework (ApiBase) | Required by extension, provides parameter validation, error handling, result formatting |
| PHP | >= 8.1.0 | Language runtime | Required by composer.json, enables typed properties and modern syntax |
| PHPUnit | ^9.6 | Testing framework | MediaWiki standard for API module testing |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| MediaWiki ApiBase | Core | Base class for API modules | All Action API endpoints |
| MediaWiki PermissionManager | Core | Permission checking | checkUserRightsAny() for access control |
| MediaWiki ApiResult | Core | Response formatting | addValue() to construct JSON responses |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Action API | REST API | REST API is newer but Action API is more mature, better documented, and existing extension uses it exclusively |
| Manual JSON encoding | ApiResult | ApiResult handles version 1/2 formatting, boolean normalization, and UTF-8 encoding automatically |

**Installation:**
No additional dependencies - MediaWiki core provides all necessary components.

## Architecture Patterns

### Recommended Project Structure
```
src/Api/
├── ApiSemanticSchemasHierarchy.php     # Single-category (existing)
├── ApiSemanticSchemasInstall.php       # Write operation (existing)
└── ApiSemanticSchemasMultiCategory.php # Multi-category (new)
```

### Pattern 1: Read-Only API Module Structure
**What:** Action API module extending ApiBase for read-only data retrieval
**When to use:** Data retrieval endpoints that don't modify wiki state
**Example:**
```php
// Source: Existing ApiSemanticSchemasHierarchy.php pattern
class ApiSemanticSchemasMultiCategory extends ApiBase {

    public function execute() {
        // 1. Permission check
        $this->checkUserRightsAny( 'edit' );

        // 2. Extract and validate parameters
        $params = $this->extractRequestParams();
        $categories = $params['categories'];

        // 3. Delegate to domain service
        $resolver = new MultiCategoryResolver( new InheritanceResolver( $allCategories ) );
        $resolved = $resolver->resolve( $categories );

        // 4. Format response
        $result = [
            'categories' => $resolved->getCategoryNames(),
            'properties' => $this->formatProperties( $resolved ),
            'subobjects' => $this->formatSubobjects( $resolved ),
        ];

        // 5. Add to API result
        $this->getResult()->addValue( null, $this->getModuleName(), $result );
    }

    public function getAllowedParams() {
        return [
            'categories' => [
                self::PARAM_TYPE => 'string',
                self::PARAM_ISMULTI => true,
                self::PARAM_REQUIRED => true,
            ],
        ];
    }

    public function needsToken() {
        return false; // Read-only
    }

    public function isReadMode() {
        return true; // No database writes
    }
}
```

### Pattern 2: Multi-Value Parameter Handling
**What:** Pipe-separated parameter parsing with namespace prefix normalization
**When to use:** API parameters accepting multiple values (categories, properties, etc.)
**Example:**
```php
// Source: Existing ApiSemanticSchemasHierarchy.php stripPrefix() pattern
private function stripPrefix( string $name ): string {
    return preg_replace( '/^Category:/i', '', trim( $name ) );
}

// Usage with PARAM_ISMULTI:
// Input: categories=Person|Employee|Category:Manager
// Parsed: ['Person', 'Employee', 'Category:Manager']
// Normalized: ['Person', 'Employee', 'Manager']
```

### Pattern 3: Boolean Normalization for JSON
**What:** Convert PHP boolean to integer (1/0) for reliable JSON serialization
**When to use:** Any boolean flags in API response (required, shared, etc.)
**Example:**
```php
// Source: Existing ApiSemanticSchemasHierarchy.php normalizeRequiredFlags() pattern
// Problem: MediaWiki API drops boolean false keys in some versions
// Solution: Use integers
foreach ( $properties as &$prop ) {
    if ( isset( $prop['required'] ) ) {
        $prop['required'] = $prop['required'] ? 1 : 0;
    }
    if ( isset( $prop['shared'] ) ) {
        $prop['shared'] = $prop['shared'] ? 1 : 0;
    }
}
```

### Pattern 4: Flat Property Array with Per-Item Metadata
**What:** Return properties as array of objects with metadata, not grouped structure
**When to use:** When client needs to display/filter properties in multiple ways
**Example:**
```php
// Recommended response structure (flat, flexible)
{
    "categories": ["Person", "Employee"],
    "properties": [
        {
            "name": "Email",
            "required": 1,
            "shared": 1,
            "sources": ["Person", "Employee"]
        },
        {
            "name": "Department",
            "required": 0,
            "shared": 0,
            "sources": ["Employee"]
        }
    ],
    "subobjects": [
        {
            "name": "Address",
            "required": 1,
            "shared": 1,
            "sources": ["Person", "Employee"]
        }
    ]
}

// Alternative (grouped) - less flexible for UI
{
    "categories": ["Person", "Employee"],
    "sharedProperties": ["Email"],
    "categorySpecificProperties": {
        "Employee": ["Department"]
    }
}
```

### Anti-Patterns to Avoid

- **Grouped-only response structure:** Hard-codes a single UI presentation, limits client flexibility. Use flat arrays with metadata instead.
- **Missing namespace normalization:** Clients send both "Category:Name" and "Name" formats. Always normalize with stripPrefix().
- **Boolean false in JSON:** MediaWiki API can drop false values. Use integers (1/0) for reliability.
- **Manual permission checks:** Don't use $this->getUser()->isAllowed(). Use checkUserRightsAny() for consistent error reporting.
- **Partial success on invalid input:** Don't silently skip invalid categories. User decision: fail entire request if any category is invalid.

## Don't Hand-Roll

Problems that look simple but have existing solutions:

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Multi-value parameter parsing | String splitting logic | PARAM_ISMULTI => true | Handles pipe separator, U+001F separator for values containing pipes, automatic array conversion |
| Permission checking | $user->isAllowed() | checkUserRightsAny('edit') | Standardized error messages, automatic 403 responses, OAuth/bot password integration |
| JSON response formatting | json_encode() | ApiResult::addValue() | Handles formatversion=1 vs 2, boolean normalization, UTF-8 encoding, XML fallback |
| Category name normalization | Manual regex | Existing stripPrefix() pattern | Case-insensitive, handles edge cases, proven in production |
| Error reporting | Return error codes | dieWithError( 'apierror-key' ) | Localized messages, proper HTTP codes, consistent format |
| Boolean flags in response | PHP true/false | Integers 1/0 | Avoids MediaWiki API quirk where boolean false can be dropped |

**Key insight:** MediaWiki's Action API framework has mature solutions for all common API development tasks. Custom implementations risk security issues, inconsistent error handling, and version compatibility problems.

## Common Pitfalls

### Pitfall 1: Invalid Category Handling - Partial Success
**What goes wrong:** API silently filters out invalid categories and returns partial results
**Why it happens:** Defensive programming instinct to "do the best we can" with invalid input
**How to avoid:** User decision from CONTEXT.md - "Invalid category names fail the entire request (no partial resolution)"
**Warning signs:**
- Response has fewer categories than requested
- Client gets data without knowing some input was ignored
- No error message explaining what went wrong

**Correct pattern:**
```php
// Validate ALL categories first
$allCategories = $this->categoryStore->getAllCategories();
$invalidCategories = [];

foreach ( $categoryNames as $name ) {
    if ( !isset( $allCategories[$name] ) ) {
        $invalidCategories[] = $name;
    }
}

if ( !empty( $invalidCategories ) ) {
    $this->dieWithError(
        [ 'apierror-semanticschemas-invalidcategories', implode( ', ', $invalidCategories ) ],
        'invalidcategories'
    );
}

// All valid - proceed with resolution
```

### Pitfall 2: Missing Category Limit - DoS Risk
**What goes wrong:** API accepts unlimited categories, allowing abuse (e.g., 1000 categories in one request)
**Why it happens:** Focusing on functionality, forgetting about abuse scenarios
**How to avoid:** Implement reasonable limit (10-20 categories) using PARAM_ISMULTI_LIMIT1/PARAM_ISMULTI_LIMIT2
**Warning signs:**
- API performance degrades with many categories
- Server CPU spikes from single requests
- No limit documented in API help

**Correct pattern:**
```php
public function getAllowedParams() {
    return [
        'categories' => [
            self::PARAM_TYPE => 'string',
            self::PARAM_ISMULTI => true,
            self::PARAM_REQUIRED => true,
            self::PARAM_ISMULTI_LIMIT1 => 10,  // Normal users
            self::PARAM_ISMULTI_LIMIT2 => 20,  // Bots/privileged
        ],
    ];
}
```

### Pitfall 3: Boolean False Dropped in Response
**What goes wrong:** Properties with `required: false` appear as `required: ""` or disappear entirely in JSON
**Why it happens:** MediaWiki API legacy behavior for XML compatibility (boolean false → empty string or absent)
**How to avoid:** Use integers (1/0) instead of booleans for all flags in API responses
**Warning signs:**
- Client receives inconsistent data for optional properties
- Required flag appears as empty string
- Boolean values work in testing but fail in production

**Detection:** Existing ApiSemanticSchemasHierarchy has normalizeRequiredFlags() - copy this pattern.

### Pitfall 4: Returning Property Names Without Context
**What goes wrong:** Response contains bare property names without "Property:" prefix, client can't construct wiki links
**Why it happens:** Internal data models use names without namespace prefix
**How to avoid:** Add "Property:" prefix when formatting response, following CategoryHierarchyService pattern
**Warning signs:**
- Client code has hardcoded "Property:" string concatenation
- Links to properties break if namespace prefix conventions change

**Correct pattern:**
```php
// From CategoryHierarchyService extractInheritedProperties()
$output[] = [
    'propertyTitle' => "Property:$p",  // Include namespace
    'sourceCategory' => "Category:$ancestor",  // Include namespace
    'required' => true,
];
```

### Pitfall 5: No Minimum Category Check
**What goes wrong:** API requires 2+ categories but user decision allows 1 category for consistency
**Why it happens:** Assuming "multi" means "plural"
**How to avoid:** User decision from CONTEXT.md - "Allow single-category requests (minimum 1 category, not 2)"
**Warning signs:**
- Error message says "at least 2 categories required"
- Single-category preview fails in form UI
- Inconsistent with MultiCategoryResolver which handles single category

## Code Examples

Verified patterns from existing codebase:

### Example 1: API Module Registration
**Purpose:** Register API module in extension.json
```json
// Source: extension.json existing pattern
{
    "APIModules": {
        "semanticschemas-hierarchy": "MediaWiki\\Extension\\SemanticSchemas\\Api\\ApiSemanticSchemasHierarchy",
        "semanticschemas-install": "MediaWiki\\Extension\\SemanticSchemas\\Api\\ApiSemanticSchemasInstall",
        "semanticschemas-multicategory": "MediaWiki\\Extension\\SemanticSchemas\\Api\\ApiSemanticSchemasMultiCategory"
    }
}
```

### Example 2: Permission Check Pattern
**Purpose:** Require edit permission to call API
```php
// Source: ApiSemanticSchemasInstall.php line 55
public function execute() {
    // User decision from CONTEXT.md: "Require edit permission to call the API"
    $this->checkUserRightsAny( 'edit' );

    // ... rest of implementation
}
```

### Example 3: Multi-Value Category Parameter
**Purpose:** Accept pipe-separated category names, normalize namespace prefixes
```php
// Source: Adapted from ApiSemanticSchemasHierarchy.php pattern
public function getAllowedParams() {
    return [
        'categories' => [
            self::PARAM_TYPE => 'string',
            self::PARAM_ISMULTI => true,
            self::PARAM_REQUIRED => true,
            self::PARAM_ISMULTI_LIMIT1 => 10,  // User decision: 10-20 limit
            self::PARAM_ISMULTI_LIMIT2 => 20,
            self::PARAM_HELP_MSG => 'semanticschemas-api-param-categories',
        ],
    ];
}

private function stripPrefix( string $name ): string {
    // User decision: "Accept category names both with and without Category: prefix"
    return preg_replace( '/^Category:/i', '', trim( $name ) );
}

// In execute():
$params = $this->extractRequestParams();
$categoryNames = array_map(
    [ $this, 'stripPrefix' ],
    $params['categories']
);
```

### Example 4: Delegating to MultiCategoryResolver
**Purpose:** Use Phase 5 MultiCategoryResolver for property resolution
```php
// Source: MultiCategoryResolver.php (Phase 5)
use MediaWiki\Extension\SemanticSchemas\Schema\InheritanceResolver;
use MediaWiki\Extension\SemanticSchemas\Schema\MultiCategoryResolver;
use MediaWiki\Extension\SemanticSchemas\Store\WikiCategoryStore;

public function execute() {
    $this->checkUserRightsAny( 'edit' );

    $params = $this->extractRequestParams();
    $categoryNames = array_map( [ $this, 'stripPrefix' ], $params['categories'] );

    // Load categories
    $categoryStore = new WikiCategoryStore();
    $allCategories = $categoryStore->getAllCategories();

    // Validate all categories exist
    $this->validateCategories( $categoryNames, $allCategories );

    // Resolve via MultiCategoryResolver
    $inheritanceResolver = new InheritanceResolver( $allCategories );
    $multiResolver = new MultiCategoryResolver( $inheritanceResolver );
    $resolved = $multiResolver->resolve( $categoryNames );

    // Format and return response
    $this->addResultData( $resolved );
}
```

### Example 5: Response Formatting with Source Attribution
**Purpose:** Format ResolvedPropertySet into flat API response with per-item metadata
```php
// Source: Adapted from CategoryHierarchyService.php and ResolvedPropertySet.php
private function formatProperties( ResolvedPropertySet $resolved ): array {
    $output = [];

    // Required properties
    foreach ( $resolved->getRequiredProperties() as $propName ) {
        $sources = $resolved->getPropertySources( $propName );
        $output[] = [
            'name' => $propName,
            'title' => "Property:$propName",  // Include namespace for client links
            'required' => 1,  // Integer, not boolean
            'shared' => count( $sources ) > 1 ? 1 : 0,
            'sources' => $sources,
        ];
    }

    // Optional properties
    foreach ( $resolved->getOptionalProperties() as $propName ) {
        $sources = $resolved->getPropertySources( $propName );
        $output[] = [
            'name' => $propName,
            'title' => "Property:$propName",
            'required' => 0,
            'shared' => count( $sources ) > 1 ? 1 : 0,
            'sources' => $sources,
        ];
    }

    return $output;
}

private function formatSubobjects( ResolvedPropertySet $resolved ): array {
    $output = [];

    foreach ( $resolved->getRequiredSubobjects() as $subName ) {
        $sources = $resolved->getSubobjectSources( $subName );
        $output[] = [
            'name' => $subName,
            'title' => "Subobject:$subName",
            'required' => 1,
            'shared' => count( $sources ) > 1 ? 1 : 0,
            'sources' => $sources,
        ];
    }

    foreach ( $resolved->getOptionalSubobjects() as $subName ) {
        $sources = $resolved->getSubobjectSources( $subName );
        $output[] = [
            'name' => $subName,
            'title' => "Subobject:$subName",
            'required' => 0,
            'shared' => count( $sources ) > 1 ? 1 : 0,
            'sources' => $sources,
        ];
    }

    return $output;
}
```

### Example 6: Error Handling for Invalid Categories
**Purpose:** Fail entire request if any category is invalid (user decision)
```php
// Source: User decision from CONTEXT.md
private function validateCategories( array $categoryNames, array $allCategories ): void {
    $invalidCategories = [];

    foreach ( $categoryNames as $name ) {
        if ( !isset( $allCategories[$name] ) ) {
            $invalidCategories[] = $name;
        }
    }

    if ( !empty( $invalidCategories ) ) {
        // User decision: "Invalid category names fail the entire request"
        $this->dieWithError(
            [ 'apierror-semanticschemas-invalidcategories', implode( ', ', $invalidCategories ) ],
            'invalidcategories'
        );
    }
}
```

### Example 7: API Help Messages
**Purpose:** Define localized API documentation in i18n/en.json
```json
// Source: Existing i18n/en.json pattern
{
    "semanticschemas-api-param-categories": "Category names to resolve (with or without 'Category:' prefix). Pipe-separated for multiple categories.",
    "apihelp-semanticschemas-multicategory-example-1": "Resolve properties for Person category",
    "apihelp-semanticschemas-multicategory-example-2": "Resolve properties for Person and Employee categories",
    "apihelp-semanticschemas-multicategory-example-3": "Resolve properties with namespace prefix",
    "apierror-semanticschemas-invalidcategories": "Invalid categories: $1",
    "apierror-semanticschemas-nocategories": "At least one category is required"
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| REST API for new endpoints | Action API (ApiBase) | Extension inception | Consistent with existing endpoints, mature ecosystem, better documentation |
| Grouped response (shared/specific) | Flat with metadata | Phase 7 decision | More flexible for clients, supports multiple UI presentations |
| Boolean flags in JSON | Integer 1/0 | Existing codebase pattern | Reliable across MediaWiki versions, avoids false-dropping quirk |
| Manual permission checks | checkUserRightsAny() | MediaWiki 1.30+ | Automatic error messages, OAuth integration, consistent 403 responses |
| formatversion=1 (legacy) | formatversion=2 recommended | MediaWiki 1.25+ | Cleaner JSON, proper UTF-8, actual booleans (but still use 1/0 for reliability) |

**Deprecated/outdated:**
- **dieUsage()**: Replaced by dieWithError() in MediaWiki 1.29+. Use dieWithError() for all error reporting.
- **Manual JSON encoding**: ApiResult handles versioning and encoding. Use addValue() instead of json_encode().
- **Partial success patterns**: Modern APIs fail fast with clear errors. Don't silently filter invalid input.

## Open Questions

Things that couldn't be fully resolved:

1. **Exact category limit (10 vs 20 vs custom)**
   - What we know: PARAM_ISMULTI_LIMIT1 and LIMIT2 are standard, existing hierarchy API has no limit (single category)
   - What's unclear: Optimal limit for multi-category form preview (UI can select 2-5 typically, but edge cases exist)
   - Recommendation: Use 10 (LIMIT1) and 20 (LIMIT2) per CONTEXT.md suggestion. Can adjust based on Phase 8 UI testing.

2. **Include category metadata in response?**
   - What we know: Phase 8 UI needs property/subobject data for form preview. Category parent chains available via getAncestors().
   - What's unclear: Whether UI benefits from category descriptions, parent chains, or property counts in API response
   - Recommendation: Start minimal (categories array + properties + subobjects). Phase 8 planning will determine if category metadata is needed.

3. **Support `noinherit` flag for direct properties only?**
   - What we know: MultiCategoryResolver always resolves full inheritance (by design). No `noinherit` mode exists.
   - What's unclear: Whether form preview ever needs to show only directly-declared properties (not inherited)
   - Recommendation: Defer until Phase 8 requests it. MultiCategoryResolver design assumes inheritance is always wanted.

4. **API action name convention**
   - What we know: Existing actions are `semanticschemas-hierarchy` (single category) and `semanticschemas-install`
   - What's unclear: Best name for multi-category endpoint (`semanticschemas-multicategory` vs `semanticschemas-resolve` vs `semanticschemas-compose`)
   - Recommendation: Use `semanticschemas-multicategory` for clarity and parallel with `semanticschemas-hierarchy`. Claude's discretion per CONTEXT.md.

## Sources

### Primary (HIGH confidence)
- SemanticSchemas codebase (local files):
  - `src/Api/ApiSemanticSchemasHierarchy.php` - Single-category API pattern
  - `src/Api/ApiSemanticSchemasInstall.php` - Write operation API pattern
  - `src/Schema/MultiCategoryResolver.php` - Phase 5 resolver implementation
  - `src/Schema/ResolvedPropertySet.php` - Phase 5 resolution result structure
  - `extension.json` - API module registration, MediaWiki version requirements
  - `composer.json` - PHP version requirements, testing dependencies
  - `i18n/en.json` - Message key patterns
  - `resources/ext.semanticschemas.hierarchy.formpreview.js` - Client consumption pattern

### Secondary (MEDIUM confidence)
- [API:Extensions - MediaWiki](https://www.mediawiki.org/wiki/API:Extensions) - ApiBase module creation, parameter validation
- [API:Errors and warnings - MediaWiki](https://www.mediawiki.org/wiki/API:Errors_and_warnings) - dieWithError() usage, message keys
- [API:Data formats - MediaWiki](https://www.mediawiki.org/wiki/API:Data_formats) - Multi-value parameters, pipe separators
- [API:Tokens - MediaWiki](https://www.mediawiki.org/wiki/API:Tokens) - CSRF token patterns for write operations
- [Manual:Edit token - MediaWiki](https://www.mediawiki.org/wiki/Manual:Edit_token) - Token validation, needsToken() method
- [Manual:PHP unit testing/Writing unit tests for extensions - MediaWiki](https://www.mediawiki.org/wiki/Manual:PHP_unit_testing/Writing_unit_tests_for_extensions) - PHPUnit testing patterns

### Tertiary (LOW confidence)
- None - all findings verified with codebase or official MediaWiki documentation

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - Verified from extension.json, composer.json, existing API modules
- Architecture: HIGH - Two existing API modules provide proven patterns, MultiCategoryResolver is production-ready
- Pitfalls: MEDIUM - Based on existing code patterns and MediaWiki documentation, but no multi-category API exists yet to validate edge cases

**Research date:** 2026-02-02
**Valid until:** 30 days (stable MediaWiki Action API framework, established patterns)
